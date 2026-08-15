<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Str;

class HuggingFaceImageProvider extends BaseAiProvider implements ImageProvider
{
    public function generate(string $prompt, int $width, int $height, array $config = []): string
    {
        $model = $this->model();
        $endpoint = $this->connection()->effectiveBaseUrl()
            ? rtrim($this->connection()->effectiveBaseUrl(), '/').'/models/'.$model
            : "https://api-inference.huggingface.co/models/{$model}";

        $response = $this->http(180)
            ->withToken($this->key())
            ->withHeaders(['X-Use-Cache' => 'false'])
            ->accept('image/*')
            ->withBody($prompt, 'text/plain')
            ->post($endpoint);

        if ($response->failed()) {
            $this->throwForFailedStatus($response->status());
        }

        $body = $response->body();

        // HF answers JSON (usually with "error") when the model is loading or
        // the request was rejected — a binary image body is the success case.
        if (Str::startsWith(ltrim($body), '{')) {
            $error = (string) ($response->json('error') ?? 'unknown error');
            if (str_contains($error, 'loading')) {
                throw new AiProviderException("Hugging Face model is still loading: {$error}");
            }

            throw new AiProviderException("Hugging Face image generation failed: {$error}", false);
        }

        if ($body === '') {
            throw new AiProviderException('Hugging Face returned an empty image.');
        }

        return $body;
    }
}
