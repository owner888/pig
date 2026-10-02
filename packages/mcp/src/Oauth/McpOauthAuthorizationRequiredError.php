<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/** The user has to sign in: there is no token to refresh, or the server wants more scope. */
final class McpOauthAuthorizationRequiredError extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('MCP OAuth authorization requires user interaction');
    }
}
