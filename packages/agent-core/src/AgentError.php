<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Usage;
use RuntimeException;

/**
 * Something the agent could not do.
 *
 * Thrown by a tool, it is the tool's failure: the model reads the message. `$details` is what
 * the UI is shown beside it — upstream's `{ isError: true, details }` result shape — so a tool
 * that failed half way can still show the half that ran (codemode's nested calls), and
 * `$usage` is what the half that ran spent on models, billed like `AgentToolResult::$usage`.
 * `$structuredContent` is upstream's on an `isError` result — "`details` and `structuredContent`
 * are kept for the UI and programmatic callers" — which is how a codemode script still gets `bash`'s
 * `{output, exit_code, …}` for a command that exited non-zero.
 */
final class AgentError extends RuntimeException
{
    public function __construct(string $message, public readonly mixed $details = null, ?\Throwable $previous = null, public readonly ?Usage $usage = null, public readonly mixed $structuredContent = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
