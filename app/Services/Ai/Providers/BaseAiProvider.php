<?php

namespace App\Services\Ai\Providers;

use App\Models\AiConnection;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for concrete providers: connection wiring, API key access,
 * model/base URL resolution, and consistent HTTP error mapping.
 */
abstract class BaseAiProvider
{
    protected ?AiConnection $connection = null;

    public function configure(AiConnection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    protected function connection(): AiConnection
    {
        if (! $this->connection) {
            throw AiProviderException::permanent('Provider was not configured with an AI connection.');
        }

        return $this->connection;
    }

    protected function key(): string
    {
        $key = $this->connection()->api_key;
        if (! filled($key)) {
            throw AiProviderException::permanent('No API key configured for this AI connection.');
        }

        return $key;
    }

    /**
     * Key without the hard requirement — providers whose endpoints currently
     * accept anonymous requests (e.g. Pollinations chat/audio) send the token
     * when present and proceed without one otherwise.
     */
    protected function optionalKey(): ?string
    {
        return filled($this->connection()->api_key) ? $this->connection()->api_key : null;
    }

    protected function model(): string
    {
        return $this->connection()->effectiveModel() ?: '';
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->connection()->config[$key] ?? $default;
    }

    protected function http(int $timeout = 90): PendingRequest
    {
        // retry(..., throw: false): without it, Laravel's retry mechanism
        // throws RequestException once retries are exhausted instead of
        // returning the failed response — which would bypass every
        // $response->failed() check below and hide the real error.
        return Http::timeout($timeout)->retry(2, 500, null, false);
    }

    protected function throwForFailedStatus(int $status): never
    {
        $message = match (true) {
            $status === 401 || $status === 403 => 'Provider rejected the API key (HTTP '.$status.').',
            $status === 404 => 'Provider model or endpoint not found (HTTP 404).',
            $status === 429 => 'Provider rate limit reached (HTTP 429).',
            $status >= 500 => 'Provider is having trouble right now (HTTP '.$status.').',
            default => 'Provider request failed (HTTP '.$status.').',
        };

        throw new AiProviderException($message, $status === 429 || $status >= 500);
    }
}
