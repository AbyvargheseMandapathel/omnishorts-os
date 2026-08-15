<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Contracts\VoiceResult;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Support\TimingEstimator;

/**
 * Pollinations text-to-speech — https://gen.pollinations.ai/audio/{text}?voice=...
 * Returns raw MP3; sentence timing is estimated (the provider exposes no
 * word boundaries), which keeps scene sync + captions working.
 */
class PollinationsVoiceProvider extends BaseAiProvider implements VoiceProvider
{
    public function synthesize(string $text, string $voice, string $outputPath, array $config = []): VoiceResult
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://gen.pollinations.ai', '/');

        // The pipeline's language defaults are Edge TTS voice names (e.g.
        // en-US-ChristopherNeural). Pollinations voices are single words
        // (nova, alloy, echo, ...): connection config wins, then a
        // Pollinations-shaped voice, then the global default.
        $voice = (string) ($this->option('voice')
            ?: (preg_match('/^[a-z]+$/', $voice) ? $voice : 'nova'));

        // The audio endpoint accepts anonymous requests today — send the key
        // when configured, never demand it.
        $request = $this->http(180)->accept('audio/*');
        if (($key = $this->optionalKey()) !== null) {
            $request = $request->withToken($key);
        }

        $response = $request->get($base.'/audio/'.rawurlencode($text), array_filter([
            'voice' => $voice,
            'model' => $this->model() ?: null,
        ], fn ($v) => $v !== null));

        $audio = $response->body();

        if ($response->failed()) {
            $error = $response->json('error.message');
            if (is_string($error)) {
                throw new AiProviderException(
                    'Pollinations audio generation failed: '.$error.' (HTTP '.$response->status().').',
                    $response->status() >= 500 || $response->status() === 429
                );
            }

            $this->throwForFailedStatus($response->status());
        }

        if ($audio === '' || str_starts_with(ltrim($audio), '{')) {
            $error = $response->json('error.message')
                ?? (is_string($response->json('error')) ? $response->json('error') : 'empty response');

            throw new AiProviderException('Pollinations audio generation failed: '.$error.'.');
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outputPath, $audio);

        $sentences = TimingEstimator::estimate($text);

        return new VoiceResult(
            $outputPath,
            max(0.1, (float) TimingEstimator::totalDurationMs($sentences) / 1000),
            $sentences
        );
    }
}
