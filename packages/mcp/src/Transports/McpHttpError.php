<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use RuntimeException;

/** An HTTP answer that was not a success, with the status and the start of the body kept. */
class McpHttpError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }
}
