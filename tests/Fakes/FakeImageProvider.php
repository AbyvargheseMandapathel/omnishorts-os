<?php

namespace Tests\Fakes;

use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Deterministic image provider for tests. Returns a 1x1 PNG by default,
 * or throws when a failure is queued (per-call).
 */
class FakeImageProvider implements ImageProvider
{
    /** @var array<int, \Throwable|null> */
    public array $failures = [];

    public int $calls = 0;

    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function generate(string $prompt, int $width, int $height, array $config = []): string
    {
        $this->calls++;

        $failure = array_shift($this->failures);
        if ($failure instanceof \Throwable) {
            throw $failure;
        }

        return base64_decode(self::TINY_PNG, true);
    }

    public static function transient(string $message = 'rate limited'): AiProviderException
    {
        return new AiProviderException($message, true);
    }
}
