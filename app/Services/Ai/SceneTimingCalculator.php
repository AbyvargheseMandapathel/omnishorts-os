<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;

/**
 * Scene synchronization — the images must appear exactly when their narration
 * is spoken. Uses real narration timing when available; only falls back to
 * proportional division when the voice provider gave us no timing at all.
 */
class SceneTimingCalculator
{
    public function calculate(AiVideoJob $job): void
    {
        $scenes = $job->scenes ?? [];
        if ($scenes === []) {
            return;
        }

        $sentences = $job->voice['sentences'] ?? [];
        $audioDuration = (float) ($job->voice['duration'] ?? 0);

        if ($sentences === [] || $audioDuration <= 0) {
            $this->proportional($scenes, $audioDuration > 0 ? $audioDuration : 10.0);
        } else {
            $this->fromNarration($scenes, $sentences);
        }

        $job->scenes = $scenes;
        $job->save();
    }

    /**
     * Greedy alignment: walk the spoken sentences in order and assign each
     * scene the contiguous run of sentences whose total word count covers the
     * scene's narration. Word-token matching is robust to the punctuation /
     * spacing gaps that exist between separately-transcribed sentences.
     *
     * @param  array<int, array{text: string, offset_ms: int, duration_ms: int}>  $sentences
     */
    private function fromNarration(array &$scenes, array $sentences): void
    {
        $normalize = fn (string $text): string => (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($text));
        $words = fn (string $text): array => array_values(array_filter(preg_split('/\s+/u', $normalize($text)) ?: []));

        $cursor = 0;
        $count = count($sentences);
        $totalSentences = $count;

        foreach ($scenes as &$scene) {
            $targetWords = $words((string) ($scene['narration'] ?? ''));
            if ($targetWords === []) {
                $scene['start_time'] = 0;
                $scene['end_time'] = 0;
                $scene['duration'] = 0;

                continue;
            }

            $startMs = null;
            $endMs = null;
            $consumedWords = 0;

            while ($cursor < $count) {
                $sentence = $sentences[$cursor];
                $consumedWords += count($words((string) ($sentence['text'] ?? '')));
                $startMs ??= (int) ($sentence['offset_ms'] ?? 0);
                $endMs = (int) (($sentence['offset_ms'] ?? 0) + ($sentence['duration_ms'] ?? 0));
                $cursor++;

                if ($consumedWords >= count($targetWords) || $cursor >= $count) {
                    break;
                }
            }

            $startMs ??= 0;
            $endMs ??= $startMs;
            $scene['start_time'] = round((float) $startMs / 1000, 3);
            $scene['end_time'] = round((float) max($startMs, $endMs) / 1000, 3);
            $scene['duration'] = round(max(0.0, (float) $scene['end_time'] - (float) $scene['start_time']), 3);
        }

        // If alignment ran out of sentences early, stretch the final scene to
        // the end of the audio so no narration is left un-synced.
        $last = $scenes[count($scenes) - 1] ?? null;
        if ($last && $cursor < $totalSentences) {
            $final = $sentences[$totalSentences - 1];
            $last['end_time'] = round(((float) ($final['offset_ms'] ?? 0) + (float) ($final['duration_ms'] ?? 0)) / 1000, 3);
            $last['duration'] = round(max(0.0, (float) $last['end_time'] - (float) $last['start_time']), 3);
            $scenes[count($scenes) - 1] = $last;
        }
    }

    private function proportional(array &$scenes, float $duration): void
    {
        $total = count($scenes);
        $each = $duration / $total;

        foreach ($scenes as $index => &$scene) {
            $scene['start_time'] = round($index * $each, 3);
            $scene['end_time'] = round(($index + 1) * $each, 3);
            $scene['duration'] = round($each, 3);
        }
    }
}
