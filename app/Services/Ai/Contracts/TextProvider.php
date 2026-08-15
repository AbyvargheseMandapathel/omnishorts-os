<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Exceptions\AiProviderException;

interface TextProvider
{
    /**
     * Complete a chat-style request. Returns the raw model text.
     *
     * @param  array<string, mixed>  $config  Provider-specific options (temperature, etc.)
     *
     * @throws AiProviderException
     */
    public function complete(string $systemPrompt, string $userPrompt, array $config = []): string;
}
