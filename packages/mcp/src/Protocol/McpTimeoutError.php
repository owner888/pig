<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use RuntimeException;

/** A request the server did not answer in time. Progress notifications reset the clock. */
final class McpTimeoutError extends RuntimeException
{
    public function __construct(public readonly float $timeoutMs)
    {
        parent::__construct(sprintf('MCP request timed out after %dms', (int) $timeoutMs));
    }
}
