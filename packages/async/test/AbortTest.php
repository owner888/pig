<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;

final class AbortTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testASignalStartsUnabortedAndStaysThatWay(): void
    {
        $controller = new AbortController();

        $this->assertFalse($controller->signal->aborted());
        $this->assertSame('', $controller->signal->reason());

        $controller->abort('user pressed esc');

        $this->assertTrue($controller->signal->aborted());
        $this->assertSame('user pressed esc', $controller->signal->reason());
    }

    public function testTheFirstReasonIsTheOneThatSticks(): void
    {
        $controller = new AbortController();
        $controller->abort('first');
        $controller->abort('second');

        $this->assertSame('first', $controller->signal->reason());
    }

    public function testThrowIfAbortedIsTheCheckpoint(): void
    {
        $controller = new AbortController();
        $controller->signal->throwIfAborted();

        $controller->abort('called off');

        $error = $this->assertThrows(AbortError::class, fn () => $controller->signal->throwIfAborted());
        $this->assertSame('called off', $error->getMessage());
    }

    public function testListenersFireOnceWithTheReason(): void
    {
        $seen = [];

        Async::run(function () use (&$seen): void {
            $controller = new AbortController();
            $controller->signal->onAbort(function (string $reason) use (&$seen): void {
                $seen[] = "a:{$reason}";
            });
            $controller->signal->onAbort(function (string $reason) use (&$seen): void {
                $seen[] = "b:{$reason}";
            });

            $controller->abort('stop');
            $controller->abort('stop again');

            Async::delay(0.005);
        });

        $this->assertSame(['a:stop', 'b:stop'], $seen);
    }

    public function testALateListenerStillFires(): void
    {
        $seen = [];

        Async::run(function () use (&$seen): void {
            $controller = new AbortController();
            $controller->abort('already gone');

            // Subscribing after the fact must not silently miss the abort it exists for.
            $controller->signal->onAbort(function (string $reason) use (&$seen): void {
                $seen[] = $reason;
            });

            Async::delay(0.005);
        });

        $this->assertSame(['already gone'], $seen);
    }

    public function testARemovedListenerDoesNotFire(): void
    {
        $fired = false;

        Async::run(function () use (&$fired): void {
            $controller = new AbortController();
            $id = $controller->signal->onAbort(function () use (&$fired): void {
                $fired = true;
            });
            $controller->signal->removeListener($id);
            $controller->abort();

            Async::delay(0.005);
        });

        $this->assertFalse($fired);
    }

    public function testNeverIsASignalNobodyHoldsTheOtherEndOf(): void
    {
        $signal = AbortSignal::never();

        $this->assertFalse($signal->aborted());
        $signal->throwIfAborted();
    }
}
