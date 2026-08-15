<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Turns a stored AI connection into a concrete provider instance. The
 * pipeline never talks to a vendor directly — it asks the ProviderManager
 * for a TextProvider/ImageProvider/VoiceProvider and the config system
 * decides which one that is.
 */
class ProviderManager
{
    /**
     * @throws AiProviderException
     */
    public function make(AiConnection $connection): TextProvider|ImageProvider|VoiceProvider
    {
        $entry = config("ai.providers.{$connection->provider}");
        if (! is_array($entry) || ! class_exists($entry['class'] ?? '')) {
            throw AiProviderException::permanent("Unknown AI provider '{$connection->provider}' on connection '{$connection->name}'.");
        }

        if (($entry['type'] ?? null) !== $connection->type) {
            throw AiProviderException::permanent("Connection '{$connection->name}' type '{$connection->type}' does not match provider '{$connection->provider}'.");
        }

        $provider = app($entry['class']);
        if (! $provider instanceof TextProvider && ! $provider instanceof ImageProvider && ! $provider instanceof VoiceProvider) {
            throw AiProviderException::permanent("Provider class for '{$connection->provider}' does not implement a known contract.");
        }

        if (method_exists($provider, 'configure')) {
            $provider->configure($connection);
        }

        return $provider;
    }

    public function providerKind(string $provider): string
    {
        return (string) (config("ai.providers.{$provider}.type") ?? '');
    }
}
