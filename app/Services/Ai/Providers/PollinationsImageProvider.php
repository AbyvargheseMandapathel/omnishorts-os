<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Str;

/**
 * Pollinations image generation — https://gen.pollinations.ai/image/{prompt}.
 * One API for text/image/video/audio/embeddings; OpenAI-compatible auth
 * (Bearer key from enter.pollinations.ai).
 */
class PollinationsImageProvider extends BaseAiProvider implements ImageProvider
{
    public function generate(string $prompt, int $width, int $height, array $config = []): string
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://gen.pollinations.ai', '/');

        $response = $this->http(180)
            ->withToken($this->key())
            ->accept('image/*')
            ->get($base.'/image/'.rawurlencode($prompt), array_filter([
                'model' => $this->model() ?: null,
                'width' => $width,
                'height' => $height,
                'n' => 1,
            ], fn ($v) => $v !== null));

        $body = $response->body();

        if ($response->failed()) {
            // Prefer the API's own message — real shape (verified live):
            // {"success":false,"error":{"message":"...","code":"..."},"status":401}
            $error = $response->json('error.message');
            if (is_string($error)) {
                throw new AiProviderException(
                    'Pollinations image generation failed: '.$error.' (HTTP '.$response->status().').',
                    $response->status() >= 500 || $response->status() === 429
                );
            }

            $this->throwForFailedStatus($response->status());
        }

        // A binary image body is the success case; JSON here means a rejected
        // request that somehow came back 2xx.
        if (Str::startsWith(ltrim($body), '{')) {
            $error = $response->json('error.message')
                ?? (is_string($response->json('error')) ? $response->json('error') : 'unknown error');

            throw new AiProviderException('Pollinations image generation failed: '.$error.'.');
        }

        if ($body === '') {
            throw new AiProviderException('Pollinations returned an empty image.');
        }

        return $body;
    }
}
