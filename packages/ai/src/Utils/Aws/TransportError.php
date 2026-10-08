<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;
use Throwable;

/**
 * A request that never got an answer — Node's network errors (`ECONNREFUSED`, `ECONNRESET`,
 * `ENOTFOUND`, …), which the SDK's retry policy counts as transient.
 */
final class TransportError extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
