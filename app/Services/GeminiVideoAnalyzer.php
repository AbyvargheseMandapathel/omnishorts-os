<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Video;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Analyzes uploaded videos with the Gemini API, entirely server-side.
 *
 * The API key lives in the settings table (encrypted) or .env — never in the
 * browser, responses, or logs. Every method here fails soft: a Gemini problem
 * must never block the scheduled upload pipeline.
 */
class GeminiVideoAnalyzer
{
    /** Models offered in the settings UI. */
    public const MODELS = [
        'gemini-1.5-flash',
        'gemini-1.5-pro',
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
    ];

    public const PROMPT = <<<'PROMPT'
Analyze this YouTube Shorts video. Study the actual visual content, spoken audio,
context, and important moments. Do NOT invent facts, events, quotes, or claims —
only what is actually in the video.

Generate exactly ONE optimized result for each field, primarily for YouTube Shorts:

- hook: the single strongest attention-grabbing first-line hook. Must immediately create curiosity.
- title: one highly clickable but accurate title.
- description: one concise, engaging, natural Shorts description.
- hashtags: exactly 5 directly relevant hashtags (without '#' prefix).
- thumbnail_text: one short compelling on-screen thumbnail text, ideally 2-5 words.
- best_moment: the single strongest moment in the video with start/end timestamps (e.g. "0:12") and a reason.
- category: the main video category (one or two words).
- target_audience: the most likely target audience.
- virality_score: integer 1-100.
- improvement: one concise, actionable recommendation.

Return ONLY valid JSON in exactly this shape:
{
  "hook": "",
  "title": "",
  "description": "",
  "hashtags": ["", "", "", "", ""],
  "thumbnail_text": "",
  "best_moment": {"start": "", "end": "", "reason": ""},
  "category": "",
  "target_audience": "",
  "virality_score": 0,
  "improvement": ""
}
PROMPT;

    /**
     * Whether Gemini analysis is enabled in settings.
     */
    public function enabled(): bool
    {
        return Setting::get('gemini.enabled') === '1';
    }

    public function model(): string
    {
        return (string) Setting::get('gemini.model', 'gemini-1.5-flash');
    }

    /**
     * Resolve the API key: settings (encrypted) first, then .env fallback.
     * Never returns a value into any response/log path by itself.
     */
    public function apiKey(): ?string
    {
        $stored = Setting::get('gemini.api_key');
        if (filled($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (Throwable) {
                return null;
            }
        }

        return env('GEMINI_API_KEY') ?: null;
    }

    /**
     * Verify the configured key + model against the Gemini API. Returns
     * ['ok' => bool, 'message' => string] — no secrets in the message.
     */
    public function testConnection(): array
    {
        $key = $this->apiKey();
        if (! $key) {
            return ['ok' => false, 'message' => 'No Gemini API key configured. Save one in Settings first.'];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout(30)
                ->get("https://generativelanguage.googleapis.com/v1beta/models/{$this->model()}");

            if ($response->ok()) {
                return ['ok' => true, 'message' => "Connected. Model {$this->model()} is available."];
            }

            return ['ok' => false, 'message' => $this->friendlyHttpError($response->status())];
        } catch (Throwable $e) {
            Log::warning('Gemini test connection failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach the Gemini API.'];
        }
    }

    /**
     * Analyze a video. Never throws: on any failure the video is marked
     * 'failed' (existing metadata untouched) and null is returned so the
     * caller falls back to existing metadata.
     *
     * @return array<string, mixed>|null
     */
    public function analyze(Video $video): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = $this->apiKey();
        if (! $key) {
            $this->markFailed($video, 'no API key configured');

            return null;
        }

        if (! $video->file_path || ! Storage::disk($this->videoDisk())->exists($video->file_path)) {
            $this->markFailed($video, 'video file missing');

            return null;
        }

        $video->update(['analysis_status' => 'processing']);

        try {
            $mime = $this->mimeType($video);
            $fileUri = $this->uploadFile($key, $video, $mime);
            $text = $this->generateContent($key, $this->model(), $fileUri, $mime);
            $analysis = $this->parseAndValidate($text);

            $video->update([
                'ai_hook' => $analysis['hook'],
                'ai_title' => $analysis['title'],
                'ai_description' => $analysis['description'],
                'ai_hashtags' => $analysis['hashtags'],
                'ai_thumbnail_text' => $analysis['thumbnail_text'],
                'ai_best_moment' => $analysis['best_moment'],
                'ai_category' => $analysis['category'],
                'ai_target_audience' => $analysis['target_audience'],
                'ai_virality_score' => $analysis['virality_score'],
                'ai_improvement' => $analysis['improvement'],
                'analysis_status' => 'completed',
                'model_used' => $this->model(),
                'analyzed_at' => now(),
                // Mirror into ai_data so the existing mockup/publish UI and
                // bulk scheduling pick up the AI fields automatically.
                'ai_data' => array_merge($video->ai_data ?? [], [
                    'hook' => $analysis['hook'],
                    'caption' => $analysis['description'],
                    'hashtags' => implode(' ', $analysis['hashtags']),
                    'virality_score' => $analysis['virality_score'],
                ]),
                'title' => $analysis['title'],
                'description' => $analysis['description'],
            ]);

            return $analysis;
        } catch (Throwable $e) {
            $this->markFailed($video, $e->getMessage());

            return null;
        }
    }

    /**
     * Rebuild the analysis array from stored columns (for a completed video).
     *
     * @return array<string, mixed>
     */
    public function storedAnalysis(Video $video): array
    {
        return [
            'hook' => $video->ai_hook,
            'title' => $video->ai_title,
            'description' => $video->ai_description,
            'hashtags' => $video->ai_hashtags ?? [],
            'thumbnail_text' => $video->ai_thumbnail_text,
            'best_moment' => $video->ai_best_moment,
            'category' => $video->ai_category,
            'target_audience' => $video->ai_target_audience,
            'virality_score' => $video->ai_virality_score,
            'improvement' => $video->ai_improvement,
        ];
    }

    private function videoDisk(): string
    {
        return (string) config('filesystems.video_disk', 'public');
    }

    private function markFailed(Video $video, string $reason): void
    {
        Log::warning("Gemini analysis failed for video #{$video->id}: {$reason}");
        $video->update(['analysis_status' => 'failed']);
    }

    private function mimeType(Video $video): string
    {
        $extension = strtolower(pathinfo((string) $video->file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }

    /**
     * Upload the video to the Gemini Files API (resumable protocol) and
     * return the file URI used as a generation input.
     */
    private function uploadFile(string $key, Video $video, string $mime): string
    {
        $start = Http::withHeaders([
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $key,
        ])->timeout(120)->post('https://generativelanguage.googleapis.com/upload/v1beta/files', [
            'file' => [
                'display_name' => pathinfo((string) $video->file_path, PATHINFO_BASENAME),
                'mime_type' => $mime,
            ],
        ]);

        if ($start->failed()) {
            throw new RuntimeException('Gemini file upload start failed (HTTP '.$start->status().').');
        }

        $uploadUri = $start->header('X-Goog-Upload-URL');
        if (! $uploadUri) {
            throw new RuntimeException('Gemini file upload returned no upload URL.');
        }

        $bytes = Storage::disk($this->videoDisk())->get($video->file_path);
        if ($bytes === null) {
            throw new RuntimeException('Video file could not be read for Gemini analysis.');
        }

        $upload = Http::withHeaders([
            'X-Goog-Upload-Command' => 'upload, finalize',
            'X-Goog-Upload-Offset' => '0',
            'Content-Type' => $mime,
            'x-goog-api-key' => $key,
        ])->withBody($bytes, $mime)->timeout(300)->put($uploadUri);

        if ($upload->failed()) {
            throw new RuntimeException('Gemini file upload failed (HTTP '.$upload->status().').');
        }

        $file = $upload->json('file', []);
        if (($file['state'] ?? '') === 'FAILED' || empty($file['uri'])) {
            throw new RuntimeException('Gemini file upload was not finalized.');
        }

        return $file['uri'];
    }

    private function generateContent(string $key, string $model, string $fileUri, string $mime): string
    {
        $response = Http::withHeaders(['x-goog-api-key' => $key])
            ->timeout(300)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['file_data' => ['mime_type' => $mime, 'file_uri' => $fileUri]],
                        ['text' => self::PROMPT],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->friendlyHttpError($response->status()));
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini returned no analysis text.');
        }

        return $text;
    }

    /**
     * Extract + parse JSON from the model response, tolerating code fences and
     * surrounding prose, then validate every required field.
     *
     * @return array<string, mixed>
     */
    private function parseAndValidate(string $text): array
    {
        $json = $text;

        // Strip markdown fences if present.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
            $json = $m[1];
        }

        // Fall back to the outermost JSON object in the text.
        $first = strpos($json, '{');
        $last = strrpos($json, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $json = substr($json, $first, $last - $first + 1);
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new RuntimeException('Gemini returned malformed JSON.');
        }

        $required = ['hook', 'title', 'description', 'hashtags', 'thumbnail_text', 'best_moment', 'category', 'target_audience', 'virality_score', 'improvement'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new RuntimeException("Gemini analysis missing field '{$field}'.");
            }
        }

        $hashtags = collect((array) ($data['hashtags'] ?? []))
            ->map(fn ($tag) => trim(ltrim((string) $tag, '#')))
            ->filter()
            ->take(5)
            ->values()
            ->all();

        $moment = is_array($data['best_moment']) ? $data['best_moment'] : [];
        $score = max(1, min(100, (int) ($data['virality_score'] ?? 0)));

        return [
            'hook' => trim((string) $data['hook']),
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'hashtags' => $hashtags,
            'thumbnail_text' => trim((string) $data['thumbnail_text']),
            'best_moment' => [
                'start' => (string) ($moment['start'] ?? ''),
                'end' => (string) ($moment['end'] ?? ''),
                'reason' => (string) ($moment['reason'] ?? ''),
            ],
            'category' => trim((string) $data['category']),
            'target_audience' => trim((string) $data['target_audience']),
            'virality_score' => $score,
            'improvement' => trim((string) $data['improvement']),
        ];
    }

    /**
     * Short, secret-free description of a Gemini HTTP failure.
     */
    private function friendlyHttpError(int $status): string
    {
        return match ($status) {
            401, 403 => 'Gemini rejected the API key (HTTP '.$status.').',
            404 => 'Gemini model not found (HTTP 404).',
            429 => 'Gemini rate limit reached (HTTP 429).',
            default => 'Gemini API error (HTTP '.$status.').',
        };
    }
}
