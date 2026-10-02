<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use RuntimeException;

/** A JSON-RPC error the server answered with, or one this client raises in the same shape. */
class McpError extends RuntimeException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message);
    }
}
