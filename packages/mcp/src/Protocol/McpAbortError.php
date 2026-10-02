<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use RuntimeException;

/** A request the caller gave up on. */
final class McpAbortError extends RuntimeException
{
    public function __construct(string $message = 'MCP request aborted')
    {
        parent::__construct($message);
    }
}
