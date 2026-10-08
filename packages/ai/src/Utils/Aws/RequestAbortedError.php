<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;
use Throwable;

/**
 * The SDK's abort: `Request aborted`, named `AbortError`, whether the signal fired before the request,
 * while it waited for an answer, between retries, or while the body was being read.
 */
final class RequestAbortedError extends RuntimeException
{
    public function __construct(string $message = 'Request aborted', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
