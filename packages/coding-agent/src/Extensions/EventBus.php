<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\CodingAgent\Logger;
use Throwable;

/**
 * A channel extensions talk to each other on. Upstream's `pi.events` (`event-bus.ts`).
 *
 * One bus per load: every extension `ExtensionLoader::load()` reads is handed the same one, so
 * `$pi->events()->emit('git:changed', $data)` in one file reaches `on('git:changed', …)` in
 * another, and `/reload` — which loads again — starts with an empty bus rather than one still
 * holding handlers from closures that no longer exist.
 *
 * Synchronous, where upstream's goes through Node's `EventEmitter` and an `async` wrapper: a
 * handler that wants to wait can spawn. A handler that throws is reported and the rest still
 * run, which is upstream's `console.error` and pig's `Logger::warning()`: one extension's bug is
 * not another's, and nothing a channel carries is a question anybody is waiting on.
 */
final class EventBus
{
    /** @var array<string, array<int, Closure(mixed): void>> */
    private array $handlers = [];

    private int $next = 0;

    /**
     * @param Closure(mixed): void $handler
     * @return Closure(): void call it to stop listening
     */
    public function on(string $channel, Closure $handler): Closure
    {
        $id = $this->next++;
        $this->handlers[$channel][$id] = $handler;

        return function () use ($channel, $id): void {
            unset($this->handlers[$channel][$id]);
        };
    }

    public function emit(string $channel, mixed $data = null): void
    {
        foreach ($this->handlers[$channel] ?? [] as $handler) {
            try {
                $handler($data);
            } catch (Throwable $error) {
                Logger::warning("Event handler error ({$channel}): " . $error::class . ': ' . $error->getMessage());
            }
        }
    }

    public function clear(): void
    {
        $this->handlers = [];
    }
}
