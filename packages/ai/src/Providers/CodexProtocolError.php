<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use RuntimeException;
use Throwable;

/** Upstream's `CodexProtocolError`: a Codex stream frame that is not JSON, with the frame. */
final class CodexProtocolError extends RuntimeException
{
    public function __construct(string $message, public readonly mixed $payload = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
