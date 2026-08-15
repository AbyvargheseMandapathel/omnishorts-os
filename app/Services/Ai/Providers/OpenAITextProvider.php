<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Str;

class OpenAITextProvider extends BaseAiProvider implements TextProvider
{
    public function complete(string $systemPrompt, string $userPrompt, array $config = []): string
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://api.openai.com/v1', '/');
        $endpoint = Str::endsWith($base, '/chat/completions') ? $base : $base.'/chat/completions';

        $request = $this->http();
        if (($key = $this->optionalKey()) !== null) {
            $request = $request->withToken($key);
        }

        $response = $request->post($endpoint, [
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
            throw new AiProviderException('Provider returned an empty response.');
        }

        return $text;
    }
}
