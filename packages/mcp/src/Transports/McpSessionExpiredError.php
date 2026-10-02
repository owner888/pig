<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

/** A 404 on a request that carried a session id: the server has forgotten the session. */
final class McpSessionExpiredError extends McpHttpError
{
    public function __construct(string $body = '')
    {
        parent::__construct(404, 'MCP session expired', $body);
    }
}
