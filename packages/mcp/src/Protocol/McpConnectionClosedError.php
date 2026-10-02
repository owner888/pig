<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use RuntimeException;

/** The transport went away under a request, or was never there. */
final class McpConnectionClosedError extends RuntimeException
{
    public function __construct(string $message = 'MCP connection closed')
    {
        parent::__construct($message);
    }
}
