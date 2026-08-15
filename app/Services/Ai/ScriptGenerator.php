<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Generates the script + scene image prompts via the configured text AI.
 *
 * Mirrors the reference behavior: structured JSON output, exact scene count,
 * non-empty prompt validation, and automatic retry on invalid responses.
 */
class ScriptGenerator
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly PipelineConfig $config) {}

    /**
     * @return array{title: string, description: string, narration: string, scenes: array<int, array{scene_number: int, narration: string, image_prompt: string}>}
     *
     * @throws AiProviderException
     */
    public function generate(AiVideoJob $job): array
    {
        $scenesCount = max(1, (int) $job->scenes_count);
        $language = $job->language ?: 'en';
        $tone = $job->tone ?: 'engaging';

        $system = $this->systemPrompt($job->content_type, $scenesCount, $language, $tone);
        $user = $this->userPrompt($job);

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $text = $this->requestScript($job, $system, $user);
            try {
                return $this->parseAndValidate($text, $scenesCount);
            } catch (\InvalidArgumentException $e) {
                $lastError = $e->getMessage();
                $user .= "\n\nYour previous response was rejected because: {$lastError}. Return ONLY valid JSON in the exact required shape.";
            }
        }

        throw new AiProviderException("Text AI produced an invalid script {$scenesCount} times: {$lastError}");
    }

    private function requestScript(AiVideoJob $job, string $system, string $user): string
    {
        $usedConnection = null;

        $text = $this->config->withFallback($job->user_id, $job->content_type, 'text', function ($provider, $connection) use ($system, $user, &$usedConnection) {
            $usedConnection = $connection;

            return $provider->complete($system, $user);
        });

        $job->noteProviderUsed('text', $usedConnection);

        return $text;
    }

    private function systemPrompt(string $contentType, int $scenesCount, string $language, string $tone): string
    {
        $langNames = [
            'en' => 'English', 'hi' => 'Hindi', 'es' => 'Spanish', 'fr' => 'French',
            'de' => 'German', 'pt' => 'Portuguese', 'ar' => 'Arabic', 'bn' => 'Bengali',
        ];
        $langName = $langNames[$language] ?? 'English';

        return <<<PROMPT
You write scripts for {$contentType} short-form videos that will be rendered into
vertical (9:16) video with generated narration, scene images, and captions.

Rules:
- The narration must be written entirely in {$langName}.
- Tone: {$tone}. No markdown, no bullet lists in the narration.
- Split the narration into EXACTLY {$scenesCount} scenes. Each scene is one
  distinct visual beat and its narration chunk must flow naturally when the
  scene chunks are concatenated in order.
- For EVERY scene provide an image_prompt in English describing a photorealistic
  vertical image: subject, environment, action, camera/viewpoint when useful,
  and relevant visual details.
- Image prompts must NEVER contain captions, on-screen text, logos, watermarks,
  or the narration text itself.
- The narration word count should suit a {$contentType} reel (roughly 130-160
  words total).

Return ONLY valid JSON with EXACTLY this shape (no prose, no code fences):
{
  "title": "compelling title",
  "description": "one concise engaging description",
  "narration": "the complete narration, scene chunks separated by spaces",
  "scenes": [
    {
      "scene_number": 1,
      "narration": "first scene narration chunk",
      "image_prompt": "visual description of scene one"
    }
  ]
}
The "scenes" array MUST contain exactly {$scenesCount} entries numbered 1..{$scenesCount}.
PROMPT;
    }

    private function userPrompt(AiVideoJob $job): string
    {
        $parts = ["Topic: {$job->topic}"];
        if (filled($job->audience)) {
            $parts[] = "Target audience: {$job->audience}";
        }

        return implode("\n", $parts);
    }

    /**
     * Extract + parse JSON and validate every rule the pipeline depends on.
     *
     * @return array{title: string, description: string, narration: string, scenes: array<int, array{scene_number: int, narration: string, image_prompt: string}>}
     */
    private function parseAndValidate(string $text, int $scenesCount): array
    {
        $json = $text;
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
            $json = $m[1];
        }

        $first = strpos($json, '{');
        $last = strrpos($json, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $json = substr($json, $first, $last - $first + 1);
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('the model did not return valid JSON');
        }

        foreach (['title', 'description', 'narration'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || trim($data[$field]) === '') {
                throw new \InvalidArgumentException("missing non-empty '{$field}'");
            }
        }

        if (! is_array($data['scenes'] ?? null) || count($data['scenes']) !== $scenesCount) {
            throw new \InvalidArgumentException("expected exactly {$scenesCount} scenes");
        }

        $scenes = [];
        foreach ($data['scenes'] as $index => $scene) {
            if (! is_array($scene)) {
                throw new \InvalidArgumentException("scene {$index} is not an object");
            }
            $narration = trim((string) ($scene['narration'] ?? ''));
            $prompt = trim((string) ($scene['image_prompt'] ?? ''));
            if ($narration === '' || $prompt === '') {
                throw new \InvalidArgumentException("scene {$index} has empty narration or image prompt");
            }

            $scenes[] = [
                'scene_number' => (int) ($scene['scene_number'] ?? $index + 1),
                'narration' => $narration,
                'image_prompt' => $prompt,
                'image_status' => 'pending',
                'image_path' => null,
                'image_error' => null,
            ];
        }

        return [
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'narration' => trim((string) $data['narration']),
            'scenes' => $scenes,
        ];
    }
}
