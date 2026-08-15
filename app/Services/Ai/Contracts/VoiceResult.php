<?php

namespace App\Services\Ai\Contracts;

/**
 * Result of a voice synthesis call: the audio file plus sentence-level timing.
 */
final class VoiceResult
{
    /**
     * @param  array<int, array{text: string, offset_ms: int, duration_ms: int}>  $sentences
     */
    public function __construct(
        public readonly string $audioPath,
        public readonly float $duration,
        public readonly array $sentences,
    ) {}

    public function toArray(): array
    {
        return [
            'audio_path' => $this->audioPath,
            'duration' => $this->duration,
            'sentences' => $this->sentences,
        ];
    }
}
