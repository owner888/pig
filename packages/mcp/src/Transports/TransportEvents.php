<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Closure;
use Pig\Mcp\Protocol\JsonRpc;
use Throwable;

/**
 * Listener bookkeeping shared by the transports. `emitClose()` fires at most once.
 *
 * Each `on*()` hands back the closure that removes that listener, as upstream's does.
 */
abstract class TransportEvents implements Transport
{
    public const int DEFAULT_MAX_MESSAGE_BYTES = 16 * 1024 * 1024;

    /** @var array<int, Closure> */
    private array $messageListeners = [];

    /** @var array<int, Closure> */
    private array $errorListeners = [];

    /** @var array<int, Closure> */
    private array $closeListeners = [];

    private int $nextListener = 0;

    private bool $closeEmitted = false;

    #[\Override]
    public function onMessage(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->messageListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->messageListeners[$id]);
        };
    }

    #[\Override]
    public function onError(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->errorListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->errorListeners[$id]);
        };
    }

    #[\Override]
    public function onClose(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->closeListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->closeListeners[$id]);
        };
    }

    #[\Override]
    public function setProtocolVersion(string $version): void
    {
        // Most transports have nothing to do with it; the HTTP one overrides this.
    }

    /** @param array<string, mixed> $message */
    protected function emitMessage(array $message): void
    {
        foreach ($this->messageListeners as $listener) {
            $listener($message);
        }
    }

    protected function emitError(mixed $error): void
    {
        $normalized = JsonRpc::toError($error);

        foreach ($this->errorListeners as $listener) {
            $listener($normalized);
        }
    }

    protected function emitClose(): void
    {
        if ($this->closeEmitted) {
            return;
        }

        $this->closeEmitted = true;

        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }
}
