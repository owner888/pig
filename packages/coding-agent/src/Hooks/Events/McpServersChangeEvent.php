<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\RegisteredMcpServer;

/**
 * Fired when an extension registers or unregisters an MCP server after the hooks are initialized
 * (see `ExtensionApi::registerMcpServer()`). Servers registered while extensions load are read
 * with `$pi->getMcpServers()` on `session_start`. Handling this event marks an extension as the
 * one that connects registered servers.
 */
final readonly class McpServersChangeEvent implements HookEvent
{
    /** @param list<RegisteredMcpServer> $servers every registered server after the change */
    public function __construct(
        public array $servers,
    ) {
    }

    public function type(): string
    {
        return 'mcp_servers_change';
    }
}
