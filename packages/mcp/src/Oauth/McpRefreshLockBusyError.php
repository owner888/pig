<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/**
 * Another process held the server's token-refresh lock for the whole wait — upstream's
 * `McpRefreshLockBusyError`. A connection's refresh fails with it; a sign-in that meets it goes
 * to the browser instead of waiting (`refreshBeforeSignIn()` answers `REDIRECT`).
 */
final class McpRefreshLockBusyError extends RuntimeException
{
    public function __construct()
    {
        parent::__construct("Another process is refreshing the MCP server's tokens");
    }
}
