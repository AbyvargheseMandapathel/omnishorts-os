<?php

namespace Tests\Feature;

use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\Ai\AiVideoPipeline;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\VideoApprover;
use App\Services\PlaceholderVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeAiVideoPipeline;
use Tests\TestCase;

class DailyAiGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithStack(): User
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Daily Channel']);

        Artisan::call('ai:setup-defaults', ['--user' => (string) $user->id]);

        return $user;
    }

    private function enableDaily(array $overrides = []): void
    {
        Setting::set('ai.daily.enabled', '1');
        Setting::set('ai.daily.time', '00:00');
        Setting::set('ai.daily.auto_approve', '1');
        foreach ($overrides as $key => $value) {
            Setting::set('ai.daily.'.$key, $value);
        }
    }

    public function test_creates_one_job_per_day_with_rotating_topic_and_black_background(): void
    {
        $user = $this->userWithStack();
        $this->enableDaily(['topics' => "First topic\nSecond topic"]);

        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]);
        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]); // same day → skipped

        $this->assertSame(1, AiVideoJob::count(), 'Only one job per day.');

        $job = AiVideoJob::first();
        $topics = ['First topic', 'Second topic'];
        $this->assertSame($topics[(int) now()->format('z') % 2], $job->topic);
        $this->assertSame('video', $job->content_type);
        $this->assertTrue($job->auto_approve);
        $this->assertSame(Setting::get('ai.daily.last_run'), now()->toDateString());

        // No background configured → a generated black video is used.
        $this->assertSame('ai_backgrounds/daily-'.now()->toDateString().'.mp4', $job->background_path);
        $this->assertSame((float) PlaceholderVideo::DURATION_SECONDS, $job->background_duration);
        $this->assertTrue(Storage::disk('public')->exists($job->background_path));

        Storage::disk('public')->delete($job->background_path);
    }

    public function test_uses_configured_background_when_it_exists(): void
    {
        $user = $this->userWithStack();
        Storage::disk('public')->put('ai_backgrounds/my-bg.mp4', 'bg');
        $this->enableDaily(['topics' => 'A topic', 'background_path' => 'ai_backgrounds/my-bg.mp4']);

        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]);

        $job = AiVideoJob::first();
        $this->assertSame('ai_backgrounds/my-bg.mp4', $job->background_path);
        $this->assertNull($job->background_duration, 'Probed during the analyzing stage.');

        Storage::disk('public')->delete('ai_backgrounds/my-bg.mp4');
    }

    public function test_skips_when_disabled(): void
    {
        $user = $this->userWithStack();
        Setting::set('ai.daily.enabled', '0');

        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]);

        $this->assertSame(0, AiVideoJob::count());
    }

    public function test_skips_when_ai_not_configured_and_records_error(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'No AI']);
        $this->enableDaily(['topics' => 'A topic']);

        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]);

        $this->assertSame(0, AiVideoJob::count());
        $this->assertNotNull(Setting::get('ai.daily.last_error'));
        $this->assertStringContainsString('No', Setting::get('ai.daily.last_error'));
    }

    public function test_ai_generates_topic_when_list_is_empty(): void
    {
        $user = $this->userWithStack();
        $this->enableDaily(['topics' => '']);

        // Fake the text provider response via the Groq endpoint.
        Http::fake([
            'api.groq.com/*' => Http::response(
                '{"choices":[{"message":{"content":"{\\"topic\\":\\"The deep sea\\"}"}}]}',
                200
            ),
        ]);

        Artisan::call('ai:generate-daily', ['--user' => (string) $user->id]);

        $job = AiVideoJob::first();
        $this->assertNotNull($job);
        $this->assertNotSame('', $job->topic);
    }

    public function test_processor_auto_approves_completed_daily_job(): void
    {
        $user = $this->userWithStack();
        $channel = $user->channels()->first();

        $job = AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => 'video',
            'topic' => 'Auto topic',
            'scenes_count' => 3,
            'background_path' => 'ai_backgrounds/x.mp4',
            'auto_approve' => true,
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
            'progress' => ['stages' => [], 'audio_mode' => 'mute'],
        ]);

        // Fake pipeline: completes the job and writes a final file.
        $this->app->instance(AiVideoPipeline::class, app(FakeAiVideoPipeline::class));

        Artisan::call('ai:process-jobs');

        $this->assertSame(1, Video::count(), 'Finished daily job landed in the Content Library.');
        $this->assertNotNull($job->fresh()->video_id);
        $this->assertSame('Auto topic', Video::first()->title);

        Storage::disk('public')->delete('ai_daily/final-'.$job->id.'.mp4');
    }

    public function test_processor_does_not_auto_approve_manual_jobs(): void
    {
        $user = $this->userWithStack();
        $channel = $user->channels()->first();

        $job = AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => 'video',
            'topic' => 'Manual topic',
            'scenes_count' => 3,
            'background_path' => 'ai_backgrounds/x.mp4',
            'auto_approve' => false,
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
            'progress' => ['stages' => [], 'audio_mode' => 'mute'],
        ]);

        $this->app->instance(AiVideoPipeline::class, app(FakeAiVideoPipeline::class));

        Artisan::call('ai:process-jobs');

        $this->assertSame(0, Video::count(), 'Manual jobs still wait for the Approve button.');

        Storage::disk('public')->delete('ai_daily/manual-'.$job->id.'.mp4');
    }

    public function test_approver_rejects_unfinished_job(): void
    {
        $user = $this->userWithStack();
        $channel = $user->channels()->first();
        $job = AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => 'video',
            'topic' => 'T',
            'scenes_count' => 3,
            'background_path' => 'ai_backgrounds/x.mp4',
            'status' => AiVideoJob::STATUS_RUNNING,
            'stage' => 'render',
        ]);

        try {
            app(VideoApprover::class)->approve($job);
            $this->fail('Expected an AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }
    }

    public function test_daily_settings_save_endpoint(): void
    {
        $user = $this->userWithStack();

        $this->actingAs($user)->post(route('settings.ai.daily.save'), [
            'enabled' => '1',
            'time' => '07:30',
            'content_type' => 'shorts',
            'topics' => "Topic A\nTopic B",
            'background_path' => 'ai_backgrounds/bg.mp4',
            'auto_approve' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('1', Setting::get('ai.daily.enabled'));
        $this->assertSame('07:30', Setting::get('ai.daily.time'));
        $this->assertSame('shorts', Setting::get('ai.daily.content_type'));
        $this->assertSame("Topic A\nTopic B", Setting::get('ai.daily.topics'));
        $this->assertSame('ai_backgrounds/bg.mp4', Setting::get('ai.daily.background_path'));
    }
}
