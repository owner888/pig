<?php

declare(strict_types=1);

namespace Pig\Agent;

use RuntimeException;

/**
 * Something the agent could not do.
 *
 * Thrown by a tool, it is the tool's failure: the model reads the message. `$details` is what
 * the UI is shown beside it — upstream's `{ isError: true, details }` result shape — so a tool
 * that failed half way can still show the half that ran (codemode's nested calls).
 */
final class AgentError extends RuntimeException
{
    public function __construct(string $message, public readonly mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
