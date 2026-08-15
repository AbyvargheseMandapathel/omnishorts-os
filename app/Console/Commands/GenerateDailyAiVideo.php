<?php

namespace App\Console\Commands;

use App\Models\AiVideoJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\PipelineConfig;
use App\Services\Ai\ProviderManager;
use App\Services\PlaceholderVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The hands-free daily AI video flow:
 *
 *   pick today's topic → script + scene prompts → scene images → narration
 *   audio → captions → scene timing → FFmpeg render
 *
 * The topic rotates through the list configured in Settings (the text AI
 * proposes one when the list is empty). The background is the path configured
 * in Settings when it exists on the video disk, otherwise a generated black
 * 720x1280 video is used. Jobs land in the Content Library automatically when
 * auto-approve is on — no manual steps anywhere.
 *
 * Runs every minute through the scheduler; internal guards make it run at
 * most once per day (after the configured time) unless --force is given.
 */
class GenerateDailyAiVideo extends Command
{
    protected $signature = 'ai:generate-daily {--user= : User ID or email — defaults to the first user} {--force : Generate even if already generated today or before the scheduled time}';

    protected $description = 'Daily hands-free AI video: topic → script → images → voice → render (black background when none configured)';

    public function handle(ProviderManager $manager): int
    {
        if (! $this->enabled() && ! $this->option('force')) {
            $this->line('Daily auto-generation is disabled (Settings → Daily Auto-Generation).');

            return self::SUCCESS;
        }

        if (! $this->due() && ! $this->option('force')) {
            $this->line('Already generated today or before the scheduled time ('.Setting::get('ai.daily.time', config('ai.daily.time')).').');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No user found'.($this->option('user') ? " for '{$this->option('user')}'" : '').'.');

            return self::FAILURE;
        }

        $channel = $user->currentChannel() ?? $user->channels()->first();
        if (! $channel) {
            $this->setLastError('The user has no channel — create one first.');

            return self::FAILURE;
        }

        $contentType = (string) (Setting::get('ai.daily.content_type') ?: config('ai.daily.content_type', 'video'));

        // Every AI kind must be resolvable before we queue a doomed job.
        $pipelineConfig = app(PipelineConfig::class);
        $missing = [];
        foreach (['text', 'image', 'voice'] as $kind) {
            if ($pipelineConfig->connectionsFor($user->id, $contentType, $kind) === []) {
                $missing[] = $kind;
            }
        }
        if ($missing !== []) {
            $message = 'No '.implode(', ', $missing).' AI connection is configured for the '
                .$contentType.' content type — nothing generated today.';
            $this->warn($message);
            $this->setLastError($message);

            return self::FAILURE;
        }

        $topic = $this->topicForToday($user, $contentType, $pipelineConfig, $manager);
        if (! $topic) {
            $this->setLastError('Could not pick a topic for today (topic list empty and the text AI failed).');

            return self::FAILURE;
        }

        [$backgroundPath, $backgroundDuration] = $this->backgroundForToday();

        $job = AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => $contentType,
            'topic' => $topic,
            'language' => 'en',
            'tone' => 'engaging',
            'scenes_count' => (int) config('ai.scenes_count', 5),
            'background_path' => $backgroundPath,
            'background_duration' => $backgroundDuration,
            'auto_approve' => Setting::get('ai.daily.auto_approve', '1') === '1',
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
            'progress' => [
                'stages' => collect(AiVideoJob::STAGES)->map(fn () => ['status' => 'pending'])->all(),
                'audio_mode' => 'mute',
            ],
        ]);

        Setting::set('ai.daily.last_run', now()->toDateString());
        Setting::set('ai.daily.last_error', null);

        $this->info("Created daily AI video job #{$job->id}: \"{$topic}\" — background: {$backgroundPath}"
            .', auto-approve: '.($job->auto_approve ? 'yes' : 'no')
            .'. The pipeline (ai:process-jobs) picks it up within a minute.');

        return self::SUCCESS;
    }

    private function enabled(): bool
    {
        return Setting::get('ai.daily.enabled', '0') === '1';
    }

    private function due(): bool
    {
        if (Setting::get('ai.daily.last_run') === now()->toDateString()) {
            return false;
        }

        $time = (string) (Setting::get('ai.daily.time') ?: config('ai.daily.time', '06:00'));

        return now()->format('H:i') >= $time;
    }

    private function resolveUser(): ?User
    {
        $option = $this->option('user');
        if ($option) {
            return is_numeric($option)
                ? User::find((int) $option)
                : User::where('email', $option)->first();
        }

        return User::query()->orderBy('id')->first() ?: null;
    }

    /**
     * Rotate through the configured topic list (one per day, deterministic —
     * no state needed); when the list is empty, ask the text AI to propose one.
     */
    private function topicForToday(User $user, string $contentType, PipelineConfig $config, ProviderManager $manager): ?string
    {
        $raw = (string) Setting::get('ai.daily.topics', '');
        $list = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $raw) ?: []
        )));

        if ($list === []) {
            $list = (array) config('ai.daily.topics', []);
        }

        if ($list !== []) {
            $index = ((int) now()->format('z')) % count($list);

            return $list[$index];
        }

        try {
            $connections = $config->connectionsFor($user->id, $contentType, 'text');
            if ($connections === []) {
                return null;
            }

            $proposal = $manager->make($connections[0])->complete(
                'You propose one topic for a short-form vertical video. Return ONLY the topic itself — no quotes, no numbering, no extra text.',
                'Propose one engaging topic.'
            );

            $topic = trim(trim($proposal), "\"'“”");

            return $topic !== '' ? $topic : null;
        } catch (Throwable $e) {
            $this->warn('Topic AI proposal failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Use the configured background when it exists on the video disk;
     * otherwise generate a black 720x1280 placeholder and use that.
     *
     * @return array{0: string, 1: ?float} [path on the video disk, duration]
     */
    private function backgroundForToday(): array
    {
        $diskName = (string) config('filesystems.video_disk', 'public');
        $configured = (string) Setting::get('ai.daily.background_path', '');

        if ($configured !== '' && Storage::disk($diskName)->exists($configured)) {
            $this->line("  using configured background: {$configured}");

            return [$configured, null];
        }

        $path = 'ai_backgrounds/daily-'.now()->toDateString().'.mp4';
        if (! Storage::disk($diskName)->exists($path)) {
            Storage::disk($diskName)->put($path, PlaceholderVideo::mp4());
        }

        $this->line('  no configured background — using a generated black video');

        return [$path, PlaceholderVideo::DURATION_SECONDS];
    }

    private function setLastError(string $message): void
    {
        Setting::set('ai.daily.last_error', $message);
    }
}
