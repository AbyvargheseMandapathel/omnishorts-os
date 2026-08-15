<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Exceptions\AiProviderException;

class GeminiTextProvider extends BaseAiProvider implements TextProvider
{
    public function complete(string $systemPrompt, string $userPrompt, array $config = []): string
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://generativelanguage.googleapis.com', '/');
        $model = $this->model();

        $response = $this->http(120)
            ->withHeaders(['x-goog-api-key' => $this->key()])
            ->post("{$base}/v1beta/models/{$model}:generateContent", [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => [
                    'temperature' => (float) ($config['temperature'] ?? $this->option('temperature', 0.7)),
                    'maxOutputTokens' => (int) ($config['max_tokens'] ?? $this->option('max_tokens', 4096)),
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if ($response->failed()) {
            $this->throwForFailedStatus($response->status());
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('Gemini returned an empty response.');
        }

        return $text;
    }
}
