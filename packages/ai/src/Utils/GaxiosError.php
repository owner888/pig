<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use RuntimeException;
use Throwable;

/**
 * gaxios's `GaxiosError`, the error `google-auth-library` throws for a token or metadata request that
 * failed: the message as gaxios (or the library, on top of it) words it, the HTTP status when there was
 * an answer, and the answer's data.
 *
 * It always has a `status` property, set or not, which is what makes upstream's `retryGoogleRequest()`
 * count it as a provider error — so `GoogleVertex` hands it to the retry policy as one.
 */
final class GaxiosError extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly mixed $data = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
