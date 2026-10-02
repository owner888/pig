<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Pig\Async\Loop;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use Throwable;

/**
 * Two ends of a pipe in one process — upstream's `transports/in-memory.ts`, which exists for
 * tests: a server written in PHP on one end, the client on the other, no socket and no child.
 *
 * Delivery is deferred to the next tick, as upstream's `queueMicrotask` is, so a reply cannot
 * arrive inside the call that sent the request.
 */
final class InMemoryTransport extends TransportEvents
{
    private ?self $peer = null;

    private bool $started = false;

    private bool $closed = false;

    /** @return array{client: self, server: self} */
    public static function pair(): array
    {
        $client = new self();
        $server = new self();
        $client->connectPeer($server);
        $server->connectPeer($client);

        return ['client' => $client, 'server' => $server];
    }

    public function connectPeer(self $peer): void
    {
        if ($this->peer !== null) {
            throw new \LogicException('In-memory MCP transport already has a peer');
        }

        $this->peer = $peer;
    }

    #[\Override]
    public function start(): void
    {
        if ($this->closed) {
            throw new McpConnectionClosedError();
        }

        $this->started = true;
    }

    #[\Override]
    public function send(array $message): void
    {
        if (!$this->started || $this->closed) {
            throw new McpConnectionClosedError();
        }

        $peer = $this->peer;

        if ($peer === null || !$peer->started || $peer->closed) {
            throw new McpConnectionClosedError('In-memory MCP peer is not connected');
        }

        // A copy, as upstream's `structuredClone`: the two ends must not share one array. PHP
        // arrays copy on assignment, so passing it is enough.
        Loop::get()->defer(static function () use ($peer, $message): void {
            $peer->deliver($message);
        });
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->emitClose();
        $this->peer?->close();
    }

    /** Exposed so tests can simulate transport-level failures. */
    public function fail(Throwable $error): void
    {
        $this->emitError($error);
    }

    /** @param array<string, mixed> $message */
    public function deliver(array $message): void
    {
        if ($this->closed) {
            return;
        }

        $this->emitMessage($message);
    }
}
