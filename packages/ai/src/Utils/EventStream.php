<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Closure;
use Generator;
use IteratorAggregate;
use Pig\Async\Deferred;
use Pig\Async\Future;

/**
 * A push-driven stream of events that also carries one final result.
 *
 * This is the load-bearing abstraction of the whole port: providers push token
 * deltas into it, the agent loop iterates it, and result() hands back the finished
 * message. Producer and consumer run as separate coroutines — push() never blocks,
 * and the consumer suspends when the queue runs dry.
 *
 * @template T
 * @template R
 * @implements IteratorAggregate<int, T>
 */
class EventStream implements IteratorAggregate
{
    /** @var list<T> */
    private array $queue = [];

    /** @var list<Deferred<T|null>> */
    private array $waiting = [];

    private bool $done = false;

    /** @var Deferred<R> */
    private readonly Deferred $finalResult;

    /**
     * @param Closure(T): bool $isComplete   marks the event that carries the final result
     * @param Closure(T): R    $extractResult pulls the final result out of that event
     */
    public function __construct(
        private readonly Closure $isComplete,
        private readonly Closure $extractResult,
    ) {
        $this->finalResult = new Deferred();
    }

    /** @param T $event */
    public function push(mixed $event): void
    {
        if ($this->done) {
            return;
        }

        if (($this->isComplete)($event)) {
            $this->done = true;
            $this->finalResult->complete(($this->extractResult)($event));
        }

        // Hand it to a consumer that is already waiting, otherwise buffer it.
        $waiter = array_shift($this->waiting);

        if ($waiter !== null) {
            $waiter->complete($event);

            return;
        }

        $this->queue[] = $event;
    }

    /**
     * Close the stream, optionally supplying the final result.
     *
     * @param R|NoResult $result
     */
    public function end(mixed $result = new NoResult()): void
    {
        $this->done = true;

        // Upstream leans on a JS promise ignoring its second resolve: the agent loop
        // pushes the completing event and then calls end() with the same result.
        // Deferred throws on double-complete, so the leniency is spelled out here.
        if (!$result instanceof NoResult && !$this->finalResult->isComplete()) {
            $this->finalResult->complete($result);
        }

        while ($this->waiting !== []) {
            array_shift($this->waiting)->complete(null);
        }
    }

    /**
     * Iterate events until the stream closes.
     *
     * Suspends the consuming coroutine while the queue is empty, so this must run
     * inside Async::run(). Consumers share one queue: an event goes to whoever
     * asks first, exactly as upstream's async iterator behaves.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        while (true) {
            if ($this->queue !== []) {
                yield array_shift($this->queue);

                continue;
            }

            if ($this->done) {
                return;
            }

            $waiter = new Deferred();
            $this->waiting[] = $waiter;

            // null is the closed marker — every event is an object.
            $event = $waiter->future->await();

            if ($event === null) {
                return;
            }

            yield $event;
        }
    }

    /** @return Future<R> */
    public function result(): Future
    {
        return $this->finalResult->future;
    }
}
