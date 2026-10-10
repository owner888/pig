<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Http\WebSocket;

/**
 * Upstream's `CachedWebSocketConnection`: one session's Codex WebSocket, kept between requests,
 * and what the last request on it left behind for the next to continue from.
 *
 * `idleSince` stands for upstream's `idleTimer`: pig's loop has no unref, so a timer would keep a
 * `-p` run alive for the five minutes; the connection is instead found expired when next asked for.
 *
 * @internal
 */
final class CachedWebSocketConnection
{
    /** Milliseconds; null while a request has it. */
    public ?int $idleSince = null;

    /**
     * Upstream's `CachedWebSocketContinuationState`.
     *
     * @var array{lastRequestBody: array<string, mixed>, lastResponseId: string, lastResponseItems: list<mixed>}|null
     */
    public ?array $continuation = null;

    public function __construct(
        public readonly WebSocket $socket,
        public bool $busy,
        public readonly int $createdAt,
    ) {
    }
}
