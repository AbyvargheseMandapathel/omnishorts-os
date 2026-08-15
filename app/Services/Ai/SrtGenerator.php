<?php

namespace App\Services\Ai;

/**
 * Generates SRT captions from sentence-level timing.
 *
 * Each sentence is split into groups of ~$wordsPerLine words and the
 * sentence's duration is distributed proportionally across the groups, so a
 * 12-word sentence gets 3 caption lines of roughly equal on-screen time.
 * Mirrors the reference implementation's word-grouping + proportional timing.
 */
class SrtGenerator
{
    /**
     * @param  array<int, array{text: string, offset_ms: int, duration_ms: int}>  $sentences
     */
    public function generate(array $sentences, string $outputPath, int $wordsPerLine = 5): string
    {
        $blocks = [];
        $index = 1;

        foreach ($sentences as $sentence) {
            $text = trim((string) ($sentence['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $words = preg_split('/\s+/u', $text) ?: [];
            if ($words === []) {
                continue;
            }

            $startMs = (float) ($sentence['offset_ms'] ?? 0);
            $durationMs = max(1.0, (float) ($sentence['duration_ms'] ?? 0));
            $groups = array_chunk($words, max(1, $wordsPerLine));

            $cursor = $startMs;
            foreach ($groups as $group) {
                $groupDuration = $durationMs * (count($group) / count($words));
                $blocks[] = [
                    'start' => (int) round($cursor),
                    'end' => (int) round($cursor + $groupDuration),
                    'text' => implode(' ', $group),
                ];
                $cursor += $groupDuration;
            }
        }

        $lines = [];
        foreach ($blocks as $block) {
            $lines[] = (string) $index++;
            $lines[] = $this->formatTimestamp($block['start']).' --> '.$this->formatTimestamp($block['end']);
            $lines[] = $this->sanitize($block['text']);
            $lines[] = '';
        }

        $srt = implode("\n", $lines);
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outputPath, $srt);

        return $srt;
    }

    private function formatTimestamp(int $ms): string
    {
        $hours = intdiv($ms, 3600000);
        $minutes = intdiv($ms % 3600000, 60000);
        $seconds = intdiv($ms % 60000, 1000);
        $millis = $ms % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $millis);
    }

    /**
     * SRT is plain text — strip control characters that could confuse ffmpeg
     * subtitles rendering.
     */
    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return trim($text);
    }
}
