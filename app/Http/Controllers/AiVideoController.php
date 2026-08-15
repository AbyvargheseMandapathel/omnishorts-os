<?php

namespace App\Http\Controllers;

use App\Models\AiVideoJob;
use App\Services\Ai\AiVideoPipeline;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\PipelineConfig;
use App\Services\Ai\VideoApprover;
use App\Services\VideoProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AiVideoController extends Controller
{
    public function index()
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $jobs = AiVideoJob::query()
            ->where('user_id', Auth::id())
            ->where('channel_id', $channel->id)
            ->with('video')
            ->latest()
            ->paginate(10);

        return view('ai.index', compact('channel', 'jobs'));
    }

    public function create()
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        return view('ai.create', [
            'channel' => $channel,
            'contentTypes' => config('ai.content_types'),
            'sceneOptions' => config('ai.scenes_count_options'),
            'languages' => config('ai.languages'),
            'tones' => config('ai.tones'),
            'defaultScenes' => config('ai.scenes_count'),
            // Per content type, the resolved provider chain shown on the form.
            'contentTypeConfig' => $this->contentTypeSummary(),
        ]);
    }

    public function store(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $validated = $request->validate([
            'video_file' => ['required', 'file', 'mimes:mp4,mov,avi,webm', 'max:102400'],
            'topic' => ['required', 'string', 'max:1000'],
            'content_type' => ['required', 'in:'.implode(',', config('ai.content_types'))],
            'scenes_count' => ['required', 'in:'.implode(',', config('ai.scenes_count_options'))],
            'language' => ['required', 'in:'.implode(',', config('ai.languages'))],
            'tone' => ['required', 'in:'.implode(',', config('ai.tones'))],
            'audience' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audio_mode' => ['nullable', 'in:mute,keep,reduce'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);

        // Every AI kind must be resolvable for this content type before we
        // accept the job — fail fast with a pointer to Settings.
        $config = app(PipelineConfig::class);
        $missing = [];
        foreach (['text', 'image', 'voice'] as $kind) {
            if ($config->connectionsFor(Auth::id(), $validated['content_type'], $kind) === []) {
                $missing[] = $kind;
            }
        }
        if ($missing !== []) {
            return back()
                ->withErrors(['ai_config' => 'No '.implode(', ', $missing).' AI connection is configured for the '
                    .$validated['content_type'].' content type. Set up connections in Settings → AI Connections, '
                    .'assign them to '.$validated['content_type'].', then pick them in Settings → Content Type AI.'])
                ->withInput();
        }

        $filePath = $request->file('video_file')->store('ai_backgrounds', $this->videoDisk());

        $duration = null;
        try {
            $duration = app(VideoProbe::class)->durationSeconds($request->file('video_file')->getRealPath());
        } catch (Throwable) {
            // Duration is probed properly again in the analyzing stage.
        }

        $job = AiVideoJob::create([
            'user_id' => Auth::id(),
            'channel_id' => $channel->id,
            'content_type' => $validated['content_type'],
            'topic' => $validated['topic'],
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'language' => $validated['language'],
            'tone' => $validated['tone'],
            'audience' => $validated['audience'] ?? null,
            'scenes_count' => (int) $validated['scenes_count'],
            'background_path' => $filePath,
            'background_duration' => $duration,
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
            'progress' => ['stages' => $this->initialStageStatuses(), 'audio_mode' => $validated['audio_mode'] ?? 'mute'],
        ]);

        $this->kick($job);

        return redirect()->route('ai.videos.show', $job)
            ->with('success', 'AI video generation started. The pipeline runs in the background — this page updates automatically.');
    }

    public function show(AiVideoJob $job)
    {
        $this->authorizeJob($job);

        return view('ai.show', [
            'job' => $job,
            'finalUrl' => $job->final_path ? $this->videoDiskUrl($job->final_path) : null,
            'stageLabels' => AiVideoJob::STAGES,
        ]);
    }

    public function progress(AiVideoJob $job)
    {
        $this->authorizeJob($job);

        $scenes = array_map(function ($scene) use ($job) {
            return [
                'scene_number' => $scene['scene_number'] ?? null,
                'image_status' => $scene['image_status'] ?? 'pending',
                'image_error' => $scene['image_error'] ?? null,
                'image_url' => ($scene['image_status'] ?? '') === 'done' && filled($scene['image_path'] ?? null)
                    ? route('ai.videos.image', [$job, $scene['scene_number']])
                    : null,
            ];
        }, $job->scenes ?? []);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'stage_label' => $job->stage_label,
            'error' => $job->error,
            'stages' => $job->progress['stages'] ?? [],
            'audio_mode' => $job->progress['audio_mode'] ?? 'mute',
            'scenes' => $scenes,
            'providers_used' => $job->providers_used ?? [],
            'final_url' => $job->final_path ? $this->videoDiskUrl($job->final_path) : null,
            'completed_at' => $job->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * Serve a generated scene image (private disk — auth + ownership enforced).
     */
    public function image(AiVideoJob $job, int $sceneNumber)
    {
        $this->authorizeJob($job);

        $scene = collect($job->scenes ?? [])->firstWhere('scene_number', $sceneNumber);
        $path = $scene['image_path'] ?? null;
        if (! $path || ! Storage::disk('ai')->exists($path)) {
            abort(404);
        }

        return response(Storage::disk('ai')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function edit(AiVideoJob $job)
    {
        $this->authorizeJob($job);

        if ($job->status === AiVideoJob::STATUS_QUEUED || $job->status === AiVideoJob::STATUS_RUNNING) {
            return redirect()->route('ai.videos.show', $job)->with('error', 'Wait for generation to finish before editing.');
        }

        return view('ai.edit', [
            'job' => $job,
            'stageLabels' => AiVideoJob::STAGES,
        ]);
    }

    public function saveEdit(Request $request, AiVideoJob $job)
    {
        $this->authorizeJob($job);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'narration' => ['required', 'string'],
            'scenes' => ['required', 'array', 'min:1'],
            'scenes.*.scene_number' => ['required', 'integer'],
            'scenes.*.narration' => ['required', 'string'],
            'scenes.*.image_prompt' => ['required', 'string'],
            'audio_mode' => ['nullable', 'in:mute,keep,reduce'],
        ]);

        // Rebuild scenes keeping generated images where the prompt didn't change.
        $existing = collect($job->scenes ?? [])->keyBy('scene_number');
        $scenes = [];
        foreach ($validated['scenes'] as $incoming) {
            $n = (int) $incoming['scene_number'];
            $old = $existing->get($n, []);
            $promptChanged = ($old['image_prompt'] ?? null) !== trim($incoming['image_prompt']);
            $scenes[] = [
                'scene_number' => $n,
                'narration' => trim($incoming['narration']),
                'image_prompt' => trim($incoming['image_prompt']),
                'image_status' => $promptChanged || ($old['image_status'] ?? '') === 'failed' ? 'pending' : ($old['image_status'] ?? 'pending'),
                'image_path' => ! $promptChanged ? ($old['image_path'] ?? null) : null,
                'image_error' => null,
            ];
        }

        $progress = $job->progress ?? [];
        $progress['audio_mode'] = $validated['audio_mode'] ?? ($progress['audio_mode'] ?? 'mute');

        $job->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'scenes' => $scenes,
            'script' => array_merge($job->script ?? [], [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? '',
                'narration' => $validated['narration'],
            ]),
            'voice' => null,
            'captions_path' => null,
            'final_path' => null,
            'stage' => 'images',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
            'progress' => $progress,
        ]);

        $this->kick($job);

        return redirect()->route('ai.videos.show', $job)
            ->with('success', 'Edits saved — images, voice, captions, and rendering will regenerate.');
    }

    /**
     * Retry a stage from the progress page.
     */
    public function retry(Request $request, AiVideoJob $job)
    {
        $this->authorizeJob($job);

        $stage = (string) $request->route('stage');
        $pipeline = app(AiVideoPipeline::class);

        try {
            match ($stage) {
                'script' => $pipeline->retryScript($job),
                'images' => $pipeline->retryAllImages($job),
                'voice' => $pipeline->retryVoice($job),
                'captions' => $pipeline->retryCaptions($job),
                'scenes' => $pipeline->retryTiming($job),
                'render' => $pipeline->retryRender($job),
                default => throw new \InvalidArgumentException('Unknown stage.'),
            };
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        $this->kick($job);

        return back()->with('success', 'Retry queued for '.ucfirst($stage).' — it resumes from that stage.');
    }

    /**
     * Regenerate a single scene's image.
     */
    public function regenerateScene(Request $request, AiVideoJob $job, int $sceneNumber)
    {
        $this->authorizeJob($job);

        $pipeline = app(AiVideoPipeline::class);
        $pipeline->retryImage($job, $sceneNumber);
        $this->kick($job);

        return back()->with('success', "Scene {$sceneNumber} image regeneration queued.");
    }

    public function cancel(AiVideoJob $job)
    {
        $this->authorizeJob($job);

        if ($job->isFinished()) {
            return back()->with('error', 'This job has already finished.');
        }

        $job->update(['status' => AiVideoJob::STATUS_CANCELLED]);

        return back()->with('success', 'Job cancelled.');
    }

    /**
     * Approve the rendered video — creates a normal library Video row so the
     * existing scheduling / publishing workflow takes over from here.
     */
    public function approve(AiVideoJob $job)
    {
        $this->authorizeJob($job);

        try {
            $video = app(VideoApprover::class)->approve($job);
        } catch (AiProviderException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return redirect()
            ->route('videos.show', $video)
            ->with('success', 'Video approved — it is now in your Content Library. Schedule or publish it from here.');
    }

    // ------------------------------------------------------------------

    private function authorizeJob(AiVideoJob $job): void
    {
        abort_if($job->user_id !== Auth::id(), 403);
    }

    /**
     * Run the pipeline now in local/dev (sync queue); production relies on the
     * cron command (ai:process-jobs) picking it up within a minute.
     */
    private function kick(AiVideoJob $job): void
    {
        if (config('queue.default') === 'sync') {
            try {
                app(AiVideoPipeline::class)->process($job);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function initialStageStatuses(): array
    {
        return collect(AiVideoJob::STAGES)
            ->map(fn () => ['status' => 'pending'])
            ->all();
    }

    private function videoDisk(): string
    {
        return (string) config('filesystems.video_disk', 'public');
    }

    private function videoDiskUrl(string $path): ?string
    {
        return Storage::disk($this->videoDisk())->exists($path)
            ? Storage::disk($this->videoDisk())->url($path)
            : null;
    }

    /**
     * For the create form: resolved primary/fallback per content type.
     */
    private function contentTypeSummary(): array
    {
        $summary = [];
        foreach (config('ai.content_types') as $type) {
            foreach (['text', 'image', 'voice'] as $kind) {
                $connections = app(PipelineConfig::class)->connectionsFor(Auth::id(), $type, $kind);
                $summary[$type][$kind] = array_map(
                    fn ($c) => ['name' => $c->name, 'provider' => $c->provider, 'model' => $c->effectiveModel()],
                    $connections
                );
            }
        }

        return $summary;
    }
}
