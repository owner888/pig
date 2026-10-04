<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\CodingAgent\Rpc\RpcClient;

/**
 * One child in the `SessionPool`: the `RpcClient` driving it and the bookkeeping the pool keeps
 * about it. pi-web's `managed` object literal, as a class with its fields named.
 *
 * Mutable on purpose — the pool is the only writer, and a readonly record that the pool rebuilt
 * on every change would be a copy per event.
 */
final class ManagedSession
{
    /** @var array<string, true> the pool keys this session answers to */
    public array $keys = [];

    /** @var array<int, array<string, true>> connectionId => [tabId => true] subscribers */
    public array $clients = [];

    /** True between `agent_start` and `agent_end`: a turn in flight keeps an idle child alive. */
    public bool $running = false;

    /** The loop timer that will stop this child for being idle, when one is armed. */
    public ?string $idleTimer = null;

    /** Set once `close()` has begun; a closing session answers no new binds. */
    public bool $closing = false;

    public function __construct(
        public readonly string $cwd,
        public readonly ?string $sessionFile,
        public readonly RpcClient $client,
    ) {
    }
}
