<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Contracts\VoiceResult;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Support\TimingEstimator;
use Illuminate\Support\Facades\Storage;

class ElevenLabsVoiceProvider extends BaseAiProvider implements VoiceProvider
{
    public function synthesize(string $text, string $voice, string $outputPath, array $config = []): VoiceResult
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://api.elevenlabs.io', '/');
        $model = $this->model() ?: 'eleven_multilingual_v2';

        $response = $this->http(180)
            ->withHeaders([
                'xi-api-key' => $this->key(),
                'Accept' => 'application/json',
            ])
            ->post("{$base}/v1/text-to-speech/{$voice}/with-timestamps", [
                'text' => $text,
                'model_id' => $model,
            ]);

        if ($response->failed()) {
            $this->throwForFailedStatus($response->status());
        }

        $b64 = (string) ($response->json('audio_base64') ?? '');
        if ($b64 === '') {
            throw new AiProviderException('ElevenLabs returned no audio.');
        }

        $audio = base64_decode($b64, true);
        if ($audio === false || $audio === '') {
            throw new AiProviderException('ElevenLabs returned invalid audio data.');
        }

        $this->ensureDirectory($outputPath);
        Storage::disk('ai')->put($this->relativeToAi($outputPath), $audio);

        // Character-level alignment -> sentence timing.
        $chars = (array) ($response->json('alignment.characters') ?? []);
        $starts = (array) ($response->json('alignment.character_start_times_seconds') ?? []);
        $ends = (array) ($response->json('alignment.character_end_times_seconds') ?? []);

        if ($chars !== [] && count($chars) === count($starts)) {
            $sentences = $this->alignSentences($text, $chars, $starts, $ends);
        } else {
            $sentences = TimingEstimator::estimate($text);
        }

        $duration = $sentences === []
            ? max(1.0, (float) strlen($audio) / 16000)
            : (float) (end($sentences)['offset_ms'] + end($sentences)['duration_ms']) / 1000;

        return new VoiceResult($outputPath, max(0.1, $duration), $sentences);
    }

    /**
     * @param  array<int, string>  $chars
     * @param  array<int, float>  $starts
     * @param  array<int, float>  $ends
     * @return array<int, array{text: string, offset_ms: int, duration_ms: int}>
     */
    private function alignSentences(string $text, array $chars, array $starts, array $ends): array
    {
        $tokens = preg_split('/(\s+)/u', $text) ?: [];

        $sentences = [];
        $current = [];
        $currentStart = null;
        $charIndex = 0;
        $pendingPunct = false;

        foreach ($tokens as $token) {
            if (trim($token) === '') {
                continue;
            }

            $len = mb_strlen($token);
            $charSlice = array_slice($chars, $charIndex, $len);
            $startSlice = array_slice($starts, $charIndex, $len);
            $endSlice = array_slice($ends, $charIndex, $len);
            $charIndex += $len;

            $start = $startSlice !== [] ? (float) array_sum($startSlice) / count($startSlice) : null;
            $end = $endSlice !== [] ? (float) end($endSlice) : null;

            if ($currentStart === null && $start !== null) {
                $currentStart = $start;
            }

            $current[] = $token;
            $pendingPunct = preg_match('/[.!?…]$/u', $token) === 1;

            if ($pendingPunct && $start !== null && $end !== null) {
                $sentenceText = trim(implode(' ', $current));
                if ($sentenceText !== '') {
                    $sentences[] = [
                        'text' => $sentenceText,
                        'offset_ms' => (int) round($currentStart * 1000),
                        'duration_ms' => max(100, (int) round(($end - $currentStart) * 1000)),
                    ];
                }
                $current = [];
                $currentStart = null;
                $pendingPunct = false;
            }
        }

        if ($current !== []) {
            $sentenceText = trim(implode(' ', $current));
            if ($sentenceText !== '') {
                $lastStart = $currentStart ?? 0;
                $sentences[] = [
                    'text' => $sentenceText,
                    'offset_ms' => (int) round($lastStart * 1000),
                    'duration_ms' => 100,
                ];
            }
        }

        return $sentences === [] ? TimingEstimator::estimate($text) : $sentences;
    }

    private function ensureDirectory(string $absolutePath): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function relativeToAi(string $absolutePath): string
    {
        $root = str_replace('\\', '/', storage_path('app/ai'));

        return str_replace($root.'/', '', str_replace('\\', '/', $absolutePath));
    }
}
