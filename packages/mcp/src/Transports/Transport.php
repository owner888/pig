<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Closure;
use Pig\Async\AbortSignal;

/**
 * What the client needs of a wire — upstream's `McpTransport`.
 *
 * `start()` and `send()` suspend the calling fiber until done; messages, errors and the close
 * arrive on listeners, from the loop. A transport is started once and closed once.
 */
interface Transport
{
    public function start(): void;

    /**
     * @param array<string, mixed> $message
     * @param AbortSignal|null     $signal upstream's `McpTransportSendOptions.signal`: "Aborted once
     *        the sender no longer waits for a reply (the request was cancelled, timed out, answered,
     *        or the client closed). A transport must not dispatch the message after that, for example
     *        when retrying it after an authorization refresh. It does not undo a message already
     *        dispatched."
     */
    public function send(array $message, ?AbortSignal $signal = null): void;

    public function close(): void;

    /** @param Closure(array<string, mixed>): void $listener */
    public function onMessage(Closure $listener): Closure;

    /** @param Closure(\Throwable): void $listener */
    public function onError(Closure $listener): Closure;

    /** @param Closure(): void $listener */
    public function onClose(Closure $listener): Closure;

    /** The version the server picked at `initialize`, for transports that have to say it per request. */
    public function setProtocolVersion(string $version): void;
}
