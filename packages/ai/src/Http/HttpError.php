<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use RuntimeException;
use Throwable;

/** A response that could not be framed, parsed, or completed. */
final class HttpError extends RuntimeException
{
    /**
     * @param bool $unprocessed the server never acted on the request: it went out on no stream, or a GOAWAY
     *        said its stream was not processed. Sending it again is safe whatever its method (RFC 9113 §8.7).
     */
    public function __construct(string $message, public readonly bool $unprocessed = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
