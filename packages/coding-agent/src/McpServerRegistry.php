<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;

/**
 * Servers registered by the extensions of one runtime — upstream's `McpServerRegistry` in
 * `core/mcp-servers.ts`.
 *
 * One per process, reached through `current()`, for `Models::register()`'s reason: every
 * `ExtensionApi` registers into the same list and the hook runner reads the same list, and a
 * registry only some of them could see is a server that connects until you use another door.
 * Upstream makes one per extension runtime; `reset()` is the reload and the test boundary.
 */
final class McpServerRegistry
{
    private static ?self $current = null;

    /** @var array<string, RegisteredMcpServer> by name, in registration order */
    private array $servers = [];

    /** @var (Closure(): void)|null */
    private ?Closure $changeListener = null;

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /** Forget every registration: the runtime is being replaced, or a test is starting over. */
    public static function reset(): void
    {
        self::$current = null;
    }

    /** Register or replace a server. The caller checks ownership. */
    public function register(RegisteredMcpServer $server): void
    {
        unset($this->servers[$server->name]);
        $this->servers[$server->name] = $server;
        ($this->changeListener ?? static fn () => null)();
    }

    /** Remove a server registered by `$extensionPath`. Servers of other extensions are left alone. */
    public function unregister(string $name, string $extensionPath): void
    {
        if (($this->servers[$name] ?? null)?->extensionPath !== $extensionPath) {
            return;
        }

        unset($this->servers[$name]);
        ($this->changeListener ?? static fn () => null)();
    }

    public function get(string $name): ?RegisteredMcpServer
    {
        return $this->servers[$name] ?? null;
    }

    /**
     * The registered servers, in registration order. Readonly objects, so no copy is needed
     * where upstream clones the config.
     *
     * @return list<RegisteredMcpServer>
     */
    public function list(): array
    {
        return array_values($this->servers);
    }

    /**
     * Called after every change. The runner sets it when it is initialized, to emit
     * `mcp_servers_change`.
     *
     * @param (Closure(): void)|null $listener
     */
    public function setChangeListener(?Closure $listener): void
    {
        $this->changeListener = $listener;
    }
}
