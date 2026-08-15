<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Exceptions\AiProviderException;

interface VoiceProvider
{
    /**
     * Synthesize narration audio into $outputPath (absolute local path).
     *
     * The returned VoiceResult carries sentence-level timing (offset_ms +
     * duration_ms). Providers without real timestamps should return them
     * estimated from the text — the caller never divides time blindly.
     *
     * @param  array<string, mixed>  $config  Provider-specific options (voice, rate, ...)
     *
     * @throws AiProviderException
     */
    public function synthesize(string $text, string $voice, string $outputPath, array $config = []): VoiceResult;
}
