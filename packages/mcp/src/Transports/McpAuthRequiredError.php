<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

/** A 401: the server wants a sign-in, and `WWW-Authenticate` says what kind. */
final class McpAuthRequiredError extends McpHttpError
{
    public function __construct(public readonly ?string $wwwAuthenticate, string $body = '')
    {
        parent::__construct(401, 'MCP server requires authentication', $body);
    }
}
