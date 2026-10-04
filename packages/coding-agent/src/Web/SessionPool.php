<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Rpc\RpcClient;

/**
 * One `pig --mode rpc` child per conversation, shared by the browsers looking at it.
 *
 * pi-web's `rpcSessions`, `createManagedSession()` and `cleanupIfIdle()` from `server.js`, as a
 * class. This is step ② of the web mode's process model (CLAUDE.md, "Three ways in"): the thing
 * that lets two tabs run two turns at once, because each tab is a process and not a `switchTo()`
 * on the one session `HttpServer` used to hold.
 *
 * The key is `cwd::basename(sessionFile)` — the same conversation opened from two browsers lands
 * on one child, which is what makes a hook's question in one tab answerable from the other. A
 * *new* conversation has no file yet, so its key carries the connection's own id instead
 * (`cwd::__new__::<client>`); once the child reports a `sessionFile` in a `get_state` answer, that
 * key is registered too, so a second browser can find the conversation by its file later.
 *
 * Three rules, each pi-web's and each a consequence of children costing something:
 *
 * - **A child with no browser and no turn in flight dies after `IDLE_TTL`.** Not at once: a page
 *   reload is a disconnect and a reconnect a second apart, and respawning a child per reload is
 *   a cold start the person feels. Not never: a tab closed on Friday is a `php` process over the
 *   weekend. The timer is cancelled by a client attaching or a turn starting, and re-armed when
 *   the last of either goes away.
 * - **A child that exits is unregistered and its browsers are told**, so a tab whose agent died
 *   shows `session_ended` rather than a spinner. Nothing restarts it here: the browser's next
 *   `start_session` for that key spawns a fresh one, which is the restart, and it is the
 *   browser's to ask for because only the browser knows whether anybody is still looking.
 * - **Events fan out to the bound connections only.** `HttpServer` used to broadcast every
 *   agent event to every socket, which was right for one session and is wrong for N: a tool
 *   result in tab A must not be drawn in tab B.
 *
 * `shutdown()` stops every child through `RpcClient::stop()`, which closes stdin and lets
 * `RpcMode` run its own shutdown — the `session_shutdown` hook fires in each. This is what
 * `pig web stop`'s SIGTERM has to reach, and why `WebMode` grows a signal handler in this step.
 */
final class SessionPool
{
    /** Seconds a child with nobody looking and nothing running is kept, before it is stopped. */
    public const float IDLE_TTL = 60.0;

    /** @var array<string, ManagedSession> by key; a session with two keys appears twice */
    private array $byKey = [];

    /** @var array<string, ManagedSession> by "$connectionId:$tabId" subscription key */
    private array $bySubscription = [];

    /**
     * @param Closure(string $cwd, ?string $sessionPath): RpcClient $spawn how a child is made —
     *        injectable so a test can hand back a client over a fixture rather than `bin/pig`
     * @param Closure(ManagedSession, array<string, mixed>): void $onEvent every event from
     *        any child, with the session it came from; `HttpServer` fans it out
     */
    public function __construct(
        private readonly Closure $spawn,
        private readonly Closure $onEvent,
        private readonly float $idleTtl = self::IDLE_TTL,
    ) {
    }

    public static function key(string $cwd, ?string $sessionFile, ?string $clientId = null): string
    {
        // Resolved, as pi-web's `resolve(cwd)` is: the page says `/tmp/x` and the child, whose
        // `cwd()` is what `getcwd()` answered, says `/private/tmp/x` — and a key built from each
        // is two keys for one conversation. Measured: a reload re-bound under the child's
        // spelling, missed the pool entry under the page's, and spawned a second child for a
        // file one was already on.
        $cwd = rtrim(realpath($cwd) ?: $cwd, '/') ?: '/';

        if ($sessionFile !== null && $sessionFile !== '') {
            return $cwd . '::' . basename($sessionFile);
        }

        return $cwd . '::__new__::' . ($clientId ?? 'shared');
    }

    /**
     * Bind a connection and tab to the session for `$cwd`/`$sessionFile`, starting a child if
     * there is none. Rebinding to the same session is a no-op; binding elsewhere detaches first.
     *
     * @throws \Throwable whatever `RpcClient::start()` throws when the child cannot be started —
     *         the caller turns that into an `error` line for the browser
     */
    public function bind(
        int $connectionId,
        string $cwd,
        ?string $sessionFile,
        string $clientId,
        ?string $tabId = null,
    ): ManagedSession {
        $tabId ??= 'default';
        $subKey = "{$connectionId}:{$tabId}";
        $key = self::key($cwd, $sessionFile, $clientId);

        $current = $this->bySubscription[$subKey] ?? null;

        if ($current !== null && isset($current->keys[$key]) && !$current->closing) {
            $this->clearIdleTimer($current);

            return $current;
        }

        $this->detach($connectionId, $tabId);

        $managed = $this->byKey[$key] ?? null;

        if ($managed === null || $managed->closing) {
            $managed = $this->spawnSession($cwd, $sessionFile, $key);
        }

        $managed->clients[$connectionId][$tabId] = true;
        $this->bySubscription[$subKey] = $managed;
        $this->clearIdleTimer($managed);

        return $managed;
    }

    /** The session a connection and tab is bound to, or null. */
    public function boundTo(int $connectionId, ?string $tabId = null): ?ManagedSession
    {
        if ($tabId !== null && $tabId !== '') {
            $managed = $this->bySubscription["{$connectionId}:{$tabId}"] ?? null;

            return $managed !== null && !$managed->closing ? $managed : null;
        }

        // Fallback for callers that name no tab: any live subscription on this connection
        foreach ($this->bySubscription as $subKey => $managed) {
            if (str_starts_with($subKey, "{$connectionId}:") && !$managed->closing) {
                return $managed;
            }
        }

        return null;
    }

    /** Unbind a connection's tab (or all its tabs if $tabId is null); the session may start idle countdown. */
    public function detach(int $connectionId, ?string $tabId = null): void
    {
        if ($tabId !== null && $tabId !== '') {
            $subKey = "{$connectionId}:{$tabId}";
            $managed = $this->bySubscription[$subKey] ?? null;

            if ($managed === null) {
                return;
            }

            unset($managed->clients[$connectionId][$tabId], $this->bySubscription[$subKey]);

            if (($managed->clients[$connectionId] ?? null) === []) {
                unset($managed->clients[$connectionId]);
            }

            $this->reapIfIdle($managed);

            return;
        }

        // Detach every tab subscription on this connection (e.g. browser disconnected)
        foreach ($this->bySubscription as $subKey => $managed) {
            if (str_starts_with($subKey, "{$connectionId}:")) {
                $tId = substr($subKey, strlen((string) $connectionId) + 1);
                unset($managed->clients[$connectionId][$tId], $this->bySubscription[$subKey]);

                if (($managed->clients[$connectionId] ?? null) === []) {
                    unset($managed->clients[$connectionId]);
                }

                $this->reapIfIdle($managed);
            }
        }
    }

    /** The session that holds `$cwd`/`$sessionFile`, if a child is running it. */
    public function find(string $cwd, string $sessionFile): ?ManagedSession
    {
        $managed = $this->byKey[self::key($cwd, $sessionFile)] ?? null;

        return $managed !== null && !$managed->closing ? $managed : null;
    }

    /** @return list<ManagedSession> every live session, once each */
    public function all(): array
    {
        $seen = [];

        foreach ($this->byKey as $managed) {
            if (!$managed->closing) {
                $seen[spl_object_id($managed)] = $managed;
            }
        }

        return array_values($seen);
    }

    /** Stop every child, cleanly. What `pig web stop` has to reach. */
    public function shutdown(): void
    {
        foreach ($this->all() as $managed) {
            $this->close($managed);
        }

        $this->byKey = [];
        $this->bySubscription = [];
    }

    // ---- internals -------------------------------------------------------------------------

    private function spawnSession(string $cwd, ?string $sessionFile, string $key): ManagedSession
    {
        $client = ($this->spawn)($cwd, $sessionFile);
        $managed = new ManagedSession($cwd, $sessionFile, $client);
        $managed->keys[$key] = true;
        $this->byKey[$key] = $managed;

        $client->onEvent(function (array $event) use ($managed): void {
            $this->observe($managed, $event);
            ($this->onEvent)($managed, $event);
        });

        // The child going away is told to the browsers as an event of its own, `session_ended`
        // — pi-web's name — so a tab whose agent died shows that rather than a spinner. The
        // pool unregisters first, so a browser's next `start_session` for this key spawns a
        // fresh child: that is the restart, and it is the browser's to ask for.
        $client->onClose(function (string $because) use ($managed): void {
            if (!$managed->closing) {
                $managed->closing = true;
                $this->clearIdleTimer($managed);
                $this->unregister($managed);
            }
            ($this->onEvent)($managed, ['type' => 'session_ended', 'reason' => $because]);
        });

        $client->start();

        return $managed;
    }

    /**
     * What the pool itself needs from the event stream: whether a turn is in flight (the idle
     * rule), the session file once the child names it (a second key), and the child's death.
     */
    private function observe(ManagedSession $managed, array $event): void
    {
        $type = $event['type'] ?? null;

        if ($type === 'agent_start') {
            $managed->running = true;
            $this->clearIdleTimer($managed);
        } elseif ($type === 'agent_end') {
            $managed->running = false;
            $this->reapIfIdle($managed);
        } elseif ($type === 'response' && ($event['command'] ?? null) === 'get_state' && ($event['success'] ?? false) === true) {
            $file = $event['data']['sessionFile'] ?? null;

            if (is_string($file) && $file !== '') {
                $key = self::key($managed->cwd, $file);
                $managed->keys[$key] = true;
                $this->byKey[$key] = $managed;
            }
        }
    }

    private function reapIfIdle(ManagedSession $managed): void
    {
        if ($managed->closing || $managed->clients !== [] || $managed->running) {
            $this->clearIdleTimer($managed);

            return;
        }

        if ($managed->idleTimer !== null) {
            return;
        }

        $managed->idleTimer = Loop::get()->delay($this->idleTtl, function () use ($managed): void {
            $managed->idleTimer = null;

            if (!$managed->closing && $managed->clients === [] && !$managed->running) {
                // `RpcClient::stop()` parks its fiber while the child winds down, and a loop
                // timer's callback is not in one — so the reap runs in a fiber of its own.
                Async::spawn(fn () => $this->close($managed));
            }
        });
    }

    private function clearIdleTimer(ManagedSession $managed): void
    {
        if ($managed->idleTimer !== null) {
            Loop::get()->cancel($managed->idleTimer);
            $managed->idleTimer = null;
        }
    }

    private function close(ManagedSession $managed): void
    {
        if ($managed->closing) {
            return;
        }

        $managed->closing = true;
        $this->clearIdleTimer($managed);
        $this->unregister($managed);
        $managed->client->stop();
    }

    private function unregister(ManagedSession $managed): void
    {
        foreach (array_keys($managed->keys) as $key) {
            if (($this->byKey[$key] ?? null) === $managed) {
                unset($this->byKey[$key]);
            }
        }

        foreach ($this->bySubscription as $subKey => $session) {
            if ($session === $managed) {
                unset($this->bySubscription[$subKey]);
            }
        }

        $managed->keys = [];
    }
}
