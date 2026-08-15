<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Storage;

/**
 * Generates one image per scene on the configured image AI. Successful
 * scenes are never regenerated — retrying a single scene only re-runs that
 * scene, so a partial failure doesn't restart the whole pipeline.
 */
class SceneImageGenerator
{
    public const WIDTH = 720;

    public const HEIGHT = 1280;

    public function __construct(private readonly PipelineConfig $config) {}

    public function generateMissing(AiVideoJob $job): void
    {
        $scenes = $job->scenes ?? [];
        $style = (string) config('ai.image_style');

        foreach ($scenes as &$scene) {
            if (($scene['image_status'] ?? null) === 'done' && filled($scene['image_path'] ?? null)) {
                continue;
            }

            $scene['image_status'] = 'running';
            $scene['image_error'] = null;
            $job->scenes = $scenes;
            $job->markProgress($this->stages($job, 'images'), 'images', 'Generating images');

            try {
                $usedConnection = null;
                $bytes = $this->config->withFallback(
                    $job->user_id,
                    $job->content_type,
                    'image',
                    function ($provider, $connection) use ($scene, $style, &$usedConnection) {
                        $usedConnection = $connection;
                        $prompt = trim(($scene['image_prompt'] ?? '').' '.$style);
                        $negative = (string) ($connection->config['negative_prompt'] ?? '');

                        return $provider->generate($prompt, self::WIDTH, self::HEIGHT, [
                            'negative_prompt' => $negative,
                        ]);
                    }
                );

                $path = $job->workDir().'/images/scene-'
                    .str_pad((string) $scene['scene_number'], 2, '0', STR_PAD_LEFT).'.png';
                Storage::disk('ai')->put($path, $bytes);

                $scene['image_path'] = $path;
                $scene['image_status'] = 'done';
                $scene['image_error'] = null;
                $job->noteProviderUsed('image', $usedConnection);
            } catch (AiProviderException $e) {
                $scene['image_status'] = 'failed';
                $scene['image_error'] = $e->getMessage();
            }

            $job->scenes = $scenes;
            $job->save();
        }
    }

    /**
     * Reset one scene back to pending so only it gets regenerated.
     */
    public function resetScene(AiVideoJob $job, int $sceneNumber): void
    {
        $scenes = $job->scenes ?? [];
        foreach ($scenes as &$scene) {
            if ((int) ($scene['scene_number'] ?? 0) === $sceneNumber) {
                $scene['image_status'] = 'pending';
                $scene['image_path'] = null;
                $scene['image_error'] = null;
            }
        }
        $job->scenes = $scenes;
        $job->save();
    }

    public function resetAllScenes(AiVideoJob $job): void
    {
        $scenes = $job->scenes ?? [];
        foreach ($scenes as &$scene) {
            $scene['image_status'] = 'pending';
            $scene['image_path'] = null;
            $scene['image_error'] = null;
        }
        $job->scenes = $scenes;
        $job->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function stages(AiVideoJob $job, string $active): array
    {
        $stages = $job->progress['stages'] ?? [];

        return $stages;
    }
}
