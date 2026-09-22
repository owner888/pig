<?php

declare(strict_types=1);

namespace Pig\Async;

use Throwable;

/**
 * The write side of a Future: whoever holds it decides the value.
 *
 * Completing twice is a programming error and throws — unlike a JS promise, which
 * silently ignores the second resolve. Where ported code relies on that leniency the
 * call site guards with isComplete(), so the leniency stays visible instead of global.
 *
 * @template T
 */
final class Deferred
{
    private readonly FutureState $state;

    /** @var Future<T> */
    public readonly Future $future;

    public function __construct()
    {
        $this->state = new FutureState();
        $this->future = new Future($this->state);
    }

    public function isComplete(): bool
    {
        return $this->state->isComplete();
    }

    /** @param T $result */
    public function complete(mixed $result = null): void
    {
        $this->state->complete($result);
    }

    public function error(Throwable $error): void
    {
        $this->state->fail($error);
    }
}
