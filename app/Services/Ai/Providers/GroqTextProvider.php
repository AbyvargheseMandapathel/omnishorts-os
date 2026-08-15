<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Exceptions\AiProviderException;

class GroqTextProvider extends BaseAiProvider implements TextProvider
{
    public function complete(string $systemPrompt, string $userPrompt, array $config = []): string
    {
        $response = $this->http()
            ->withToken($this->key())
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => (float) ($config['temperature'] ?? $this->option('temperature', 0.7)),
                'max_tokens' => (int) ($config['max_tokens'] ?? $this->option('max_tokens', 4096)),
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            $this->throwForFailedStatus($response->status());
        }

        $text = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('Groq returned an empty response.');
        }

        return $text;
    }
}
