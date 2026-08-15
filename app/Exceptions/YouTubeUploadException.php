<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A YouTube upload failure. When $transient is true the failure is expected
 * to resolve itself (quota, 5xx, timeouts) and the scheduler retries the
 * publication with backoff; false means permanent (invalid grant, forbidden,
 * bad request) and the post is marked failed.
 */
class YouTubeUploadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient = false
    ) {
        parent::__construct($message);
    }
}
