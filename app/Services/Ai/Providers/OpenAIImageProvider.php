<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Str;

class OpenAIImageProvider extends BaseAiProvider implements ImageProvider
{
    public function generate(string $prompt, int $width, int $height, array $config = []): string
    {
        $base = rtrim($this->connection()->effectiveBaseUrl() ?? 'https://api.openai.com/v1', '/');
        $model = $this->model();

        $response = $this->http(180)
            ->withToken($this->key())
            ->post($base.'/images/generations', [
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => $this->sizeFor($width, $height),
                'response_format' => 'b64_json',
            ]);

        if ($response->failed()) {
            $this->throwForFailedStatus($response->status());
        }

        $b64 = data_get($response->json(), 'data.0.b64_json');
        if (! is_string($b64) || $b64 === '') {
            $url = data_get($response->json(), 'data.0.url');
            if (is_string($url) && $url !== '') {
                $image = $this->http(120)->get($url)->body();

                return $image !== '' ? $image : throw new AiProviderException('OpenAI returned an empty image.');
            }

            throw new AiProviderException('OpenAI returned no image data.');
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new AiProviderException('OpenAI returned invalid image data.');
        }

        return $bytes;
    }

    /**
     * Map the requested frame size to the model's supported sizes.
     */
    private function sizeFor(int $width, int $height): string
    {
        $tall = $height >= $width;
        $isDalle = Str::startsWith($this->model(), 'dall-e');

        return match (true) {
            $isDalle && $tall => '1024x1792',
            $isDalle => '1792x1024',
            $tall => '1024x1536',
            default => '1536x1024',
        };
    }
}
