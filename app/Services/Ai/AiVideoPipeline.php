<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The AI video pipeline. Runs the full chain:
 *
 *   analyzing → script → images → voice → captions → scenes → render → finalize
 *
 * Every stage is independently retryable: a failed (or manually retried)
 * stage re-runs from that point and continues to completion without
 * regenerating successful work (e.g. images already generated are skipped).
 */
class AiVideoPipeline
{
    public function __construct(
        private readonly FfmpegProbe $probe,
        private readonly ScriptGenerator $scripts,
        private readonly SceneImageGenerator $imageGenerator,
        private readonly VoiceGenerator $voice,
        private readonly SrtGenerator $srt,
        private readonly SceneTimingCalculator $timing,
        private readonly VideoRenderer $renderer,
    ) {}

    public function process(AiVideoJob $job): void
    {
        $job->update([
            'status' => AiVideoJob::STATUS_RUNNING,
            'started_at' => $job->started_at ?? now(),
            'error' => null,
        ]);

        try {
            $stages = array_keys(AiVideoJob::STAGES);
            $start = (int) (array_search($job->stage, $stages, true) ?: 0);

            for ($i = $start; $i < count($stages); $i++) {
                $stage = $stages[$i];
                $this->runStage($job, $stage);
            }

            $job->update([
                'status' => AiVideoJob::STATUS_COMPLETED,
                'stage' => null,
                'stage_label' => null,
                'completed_at' => now(),
            ]);
        } catch (AiProviderException $e) {
            $this->fail($job, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('AI video pipeline crashed', [
                'job' => $job->id,
                'stage' => $job->stage,
                'error' => $e->getMessage(),
            ]);
            $this->fail($job, 'Unexpected pipeline error: '.$e->getMessage());
        }
    }

    private function runStage(AiVideoJob $job, string $stage): void
    {
        $stages = $this->stageStatuses($job);
        $stages[$stage] = ['status' => 'running'];
        $job->markProgress($stages, $stage, AiVideoJob::STAGES[$stage]);

        match ($stage) {
            'analyzing' => $this->analyze($job),
            'script' => $this->generateScript($job),
            'images' => $this->generateImages($job),
            'voice' => $this->generateVoice($job),
            'captions' => $this->generateCaptions($job),
            'scenes' => $this->timeScenes($job),
            'render' => $this->render($job),
            'finalize' => $this->finalize($job),
        };

        $stages = $this->stageStatuses($job);
        $stages[$stage] = ['status' => 'done'];
        $job->markProgress($stages, $job->stage, $job->stage_label);
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    private function analyze(AiVideoJob $job): void
    {
        // Materialize the background locally once — the render stage reuses it.
        $local = storage_path('app/ai/'.$job->workDir().'/background_src.mp4');
        if (! is_file($local)) {
            $videoDisk = (string) config('filesystems.video_disk', 'public');
            $stream = Storage::disk($videoDisk)->readStream($job->background_path);
            if ($stream === false) {
                throw new AiProviderException("Background video is missing on the \"{$videoDisk}\" disk.");
            }
            $dir = dirname($local);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $target = fopen($local, 'wb');
            if ($target === false) {
                fclose($stream);

                throw new AiProviderException('Could not prepare the background video locally.');
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);
        }

        $info = $this->probe->probe($local);
        $job->update([
            'background_duration' => $info['duration'],
            'background_width' => $info['width'],
            'background_height' => $info['height'],
        ]);
    }

    private function generateScript(AiVideoJob $job): void
    {
        $script = $this->scripts->generate($job);
        $job->update([
            'script' => $script,
            'scenes' => $script['scenes'],
            'title' => $job->title ?: $script['title'],
            'description' => $job->description ?: $script['description'],
        ]);
    }

    private function generateImages(AiVideoJob $job): void
    {
        $this->imageGenerator->generateMissing($job);

        $failed = collect($job->scenes ?? [])->filter(fn ($s) => ($s['image_status'] ?? '') === 'failed');
        if ($failed->isNotEmpty()) {
            $first = $failed->first();
            throw new AiProviderException(
                'Scene image generation failed for scene #'.$first['scene_number'].': '.($first['image_error'] ?? 'unknown error')
            );
        }
    }

    private function generateVoice(AiVideoJob $job): void
    {
        $this->voice->generate($job);
    }

    private function generateCaptions(AiVideoJob $job): void
    {
        $sentences = $job->voice['sentences'] ?? [];
        if ($sentences === []) {
            throw AiProviderException::permanent('Cannot generate captions — no voice timing available.');
        }

        $path = $job->workDir().'/captions/subtitles.srt';
        $this->srt->generate($sentences, storage_path('app/ai/'.$path), (int) config('ai.words_per_caption_line'));
        $job->update(['captions_path' => $path]);
    }

    private function timeScenes(AiVideoJob $job): void
    {
        $this->timing->calculate($job);
    }

    private function render(AiVideoJob $job): void
    {
        $this->renderer->render($job);
    }

    private function finalize(AiVideoJob $job): void
    {
        // Nothing to finalize beyond what render already persisted — kept as
        // a distinct stage so the pipeline shape matches the documented flow.
        $job->update(['stage' => 'finalize']);
    }

    // ------------------------------------------------------------------
    // Retries (each wipes only what it must redo)
    // ------------------------------------------------------------------

    public function retryScript(AiVideoJob $job): void
    {
        $job->update([
            'script' => null,
            'scenes' => null,
            'voice' => null,
            'captions_path' => null,
            'final_path' => null,
            'stage' => 'script',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    public function retryImage(AiVideoJob $job, int $sceneNumber): void
    {
        $this->imageGenerator->resetScene($job, $sceneNumber);
        $job->update([
            'final_path' => null,
            'stage' => 'images',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    public function retryAllImages(AiVideoJob $job): void
    {
        $this->imageGenerator->resetAllScenes($job);
        $job->update([
            'final_path' => null,
            'stage' => 'images',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    public function retryVoice(AiVideoJob $job): void
    {
        $job->update([
            'voice' => null,
            'captions_path' => null,
            'final_path' => null,
            'stage' => 'voice',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
        // Drop timing so it is recomputed from the new audio.
        foreach ($job->scenes ?? [] as &$scene) {
            unset($scene['start_time'], $scene['end_time'], $scene['duration']);
        }
        $job->scenes = $job->scenes ?? [];
        $job->save();
    }

    public function retryCaptions(AiVideoJob $job): void
    {
        $job->update([
            'captions_path' => null,
            'final_path' => null,
            'stage' => 'captions',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    public function retryTiming(AiVideoJob $job): void
    {
        foreach ($job->scenes ?? [] as &$scene) {
            unset($scene['start_time'], $scene['end_time'], $scene['duration']);
        }
        $job->scenes = $job->scenes ?? [];
        $job->update([
            'final_path' => null,
            'stage' => 'scenes',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    public function retryRender(AiVideoJob $job): void
    {
        $job->update([
            'final_path' => null,
            'stage' => 'render',
            'status' => AiVideoJob::STATUS_QUEUED,
            'error' => null,
        ]);
    }

    // ------------------------------------------------------------------

    private function fail(AiVideoJob $job, string $message): void
    {
        $stages = $this->stageStatuses($job);
        if ($job->stage && isset($stages[$job->stage])) {
            $stages[$job->stage] = ['status' => 'failed', 'error' => $message];
        }
        $job->update([
            'status' => AiVideoJob::STATUS_FAILED,
            'error' => $message,
            'progress' => $stages,
        ]);
    }

    private function stageStatuses(AiVideoJob $job): array
    {
        return $job->progress['stages'] ?? [];
    }
}
