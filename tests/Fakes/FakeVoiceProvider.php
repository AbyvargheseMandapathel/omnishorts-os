<?php

namespace Tests\Fakes;

use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Contracts\VoiceResult;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Deterministic voice provider for tests: writes a small audio file and
 * returns per-sentence timing computed from the text.
 */
class FakeVoiceProvider implements VoiceProvider
{
    public int $calls = 0;

    public function synthesize(string $text, string $voice, string $outputPath, array $config = []): VoiceResult
    {
        $this->calls++;

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outputPath, "fake-mp3-bytes-{$voice}");

        // One sentence per punctuation group, 900ms each.
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $sentences = [];
        $offset = 0;
        foreach ($parts as $part) {
            $sentences[] = [
                'text' => trim($part),
                'offset_ms' => $offset,
                'duration_ms' => 900,
            ];
            $offset += 900;
        }
        if ($sentences === []) {
            $sentences[] = ['text' => trim($text), 'offset_ms' => 0, 'duration_ms' => 900];
            $offset = 900;
        }

        return new VoiceResult($outputPath, $offset / 1000, $sentences);
    }

    public static function transient(string $message = 'rate limited'): AiProviderException
    {
        return new AiProviderException($message, true);
    }
}
