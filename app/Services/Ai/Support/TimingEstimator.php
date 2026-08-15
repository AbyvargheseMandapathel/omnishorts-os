<?php

namespace App\Services\Ai\Support;

/**
 * Fallback sentence timing for voice providers without real timestamps.
 * Roughly 15 characters per second of speech (~65ms/char), min 700ms per
 * sentence. This keeps scene sync + captions working even when the provider
 * returns only raw audio.
 */
final class TimingEstimator
{
    /**
     * @return array<int, array{text: string, offset_ms: int, duration_ms: int}>
     */
    public static function estimate(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $parts = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [];

        $offset = 0;
        $sentences = [];
        foreach ($parts as $part) {
            $sentence = trim($part);
            if ($sentence === '') {
                continue;
            }
            $duration = max(700, (int) round(mb_strlen($sentence) / 15 * 1000));
            $sentences[] = [
                'text' => $sentence,
                'offset_ms' => $offset,
                'duration_ms' => $duration,
            ];
            $offset += $duration;
        }

        if ($sentences === [] && $text !== '') {
            $sentences[] = [
                'text' => $text,
                'offset_ms' => 0,
                'duration_ms' => max(700, (int) round(mb_strlen($text) / 15 * 1000)),
            ];
        }

        return $sentences;
    }

    public static function totalDurationMs(array $sentences): float
    {
        $last = end($sentences);

        return $last === false ? 0 : (float) ($last['offset_ms'] + $last['duration_ms']);
    }
}
