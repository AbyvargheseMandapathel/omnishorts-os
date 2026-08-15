<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Synthesizes the full narration into audio with sentence-level timing
 * (offset_ms + duration_ms). Providers without real timestamps get estimated
 * ones — downstream stages never divide time blindly.
 */
class VoiceGenerator
{
    public function __construct(private readonly PipelineConfig $config) {}

    /**
     * @throws AiProviderException
     */
    public function generate(AiVideoJob $job): void
    {
        $narration = collect($job->scenes ?? [])
            ->pluck('narration')
            ->filter()
            ->implode(' ');

        if (trim($narration) === '') {
            throw AiProviderException::permanent('Cannot generate voice — the narration is empty.');
        }

        $voice = $this->voiceFor($job);
        $outputPath = storage_path('app/ai/'.$job->workDir().'/voice/narration.mp3');

        $usedConnection = null;
        $result = $this->config->withFallback(
            $job->user_id,
            $job->content_type,
            'voice',
            function ($provider, $connection) use ($narration, $voice, $outputPath, &$usedConnection) {
                $usedConnection = $connection;

                return $provider->synthesize($narration, $voice, $outputPath, [
                    'voice' => $voice,
                ]);
            }
        );

        $job->noteProviderUsed('voice', $usedConnection);
        $job->voice = $result->toArray();
        $job->save();
    }

    /**
     * Voice name: connection config wins, then language default, then global default.
     */
    public function voiceFor(AiVideoJob $job): string
    {
        $language = substr((string) ($job->language ?: 'en'), 0, 2);

        return (string) (config("ai.voices.{$language}")
            ?? config('ai.defaults.edge_tts.voice')
            ?? 'en-US-ChristopherNeural');
    }
}
