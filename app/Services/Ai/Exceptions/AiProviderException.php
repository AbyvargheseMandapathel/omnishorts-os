<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * A provider-level failure (timeout, rate limit, HTTP error, network).
 * The pipeline catches these to try the fallback provider or mark the stage
 * failed. Never contains API keys or secrets.
 */
class AiProviderException extends RuntimeException
{
    /**
     * Whether this failure is likely transient (timeout / 5xx / 429) and
     * therefore worth trying the fallback for. Permanent failures (bad key,
     * model not found, missing permissions) are reported directly.
     */
    public function __construct(
        string $message,
        public readonly bool $transient = true,
    ) {
        parent::__construct($message);
    }

    public static function permanent(string $message): self
    {
        return new self($message, false);
    }
}
