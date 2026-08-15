<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Exceptions\AiProviderException;

interface ImageProvider
{
    /**
     * Generate one image. Returns the raw image bytes (PNG/JPEG/WEBP).
     *
     * @param  array<string, mixed>  $config  Provider-specific options (size, negative prompt, ...)
     *
     * @throws AiProviderException
     */
    public function generate(string $prompt, int $width, int $height, array $config = []): string;
}
