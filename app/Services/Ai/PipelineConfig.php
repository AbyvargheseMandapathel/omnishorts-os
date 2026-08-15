<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Content-Type AI configuration: for a given content type + AI kind, resolve
 * the configured primary connection and (when available) the fallback. Only
 * connections that are active AND assigned to that content type count.
 */
class PipelineConfig
{
    public function __construct(private readonly ProviderManager $manager) {}

    /**
     * Connections configured for a role, in priority order, that are usable.
     *
     * @return array<int, AiConnection>
     */
    public function connectionsFor(int $userId, string $contentType, string $kind): array
    {
        $roles = match ($kind) {
            'text' => ['text_primary', 'text_fallback'],
            'image' => ['image_primary', 'image_fallback'],
            'voice' => ['voice_primary', 'voice_fallback'],
            default => [],
        };

        $connections = [];
        foreach ($roles as $role) {
            $connection = AiContentTypeConfig::resolve($userId, $contentType, $role);
            if ($connection && $connection->is_active && $connection->isAssignedTo($contentType)) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * Resolve a provider for the given kind, trying the configured fallback
     * when the primary fails transiently.
     *
     * @param  callable(TextProvider|ImageProvider|VoiceProvider, AiConnection): mixed  $call
     *
     * @throws AiProviderException When no connection is configured or all fail.
     */
    public function withFallback(int $userId, string $contentType, string $kind, callable $call): mixed
    {
        $connections = $this->connectionsFor($userId, $contentType, $kind);
        if ($connections === []) {
            throw AiProviderException::permanent(
                "No {$kind} AI connection is configured for the '{$contentType}' content type — set one up in Settings → Content Type AI."
            );
        }

        $lastError = null;
        foreach ($connections as $connection) {
            try {
                return $call($this->manager->make($connection), $connection);
            } catch (AiProviderException $e) {
                $lastError = $e;
                // Permanent failures (bad key, wrong model) are reported as-is —
                // only transient failures (timeout / 429 / 5xx) try the fallback.
                if (! $e->transient || count($connections) === 1) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                $lastError = new AiProviderException($e->getMessage());
            }
        }

        throw new AiProviderException(
            'All configured providers failed for '.$kind.': '.($lastError?->getMessage() ?? 'unknown error')
        );
    }
}
