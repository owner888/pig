<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;
use Throwable;

/**
 * A plain `Error` with a `name`, as the SDK's event-stream unmarshaller throws one: an `error` message
 * (named by its `:error-code`), an `exception` message of a type the client does not model (named by its
 * `:exception-type`, the payload as the message), and Node's `aborted` when the body stops coming.
 * Not a Bedrock service exception, so upstream prints its message without a prefix.
 */
final class StreamError extends RuntimeException
{
    public function __construct(string $message, public readonly string $name, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
