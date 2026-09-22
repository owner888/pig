<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use Pig\Async\Async;
use Pig\Async\AsyncError;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\Async\Loop;
use PHPUnit\Framework\TestCase;
use Pig\Test\AssertsThrows;
use RuntimeException;

final class FutureTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testAwaitOutsideACoroutineIsAnError(): void
    {
        $deferred = new Deferred();

        $this->assertThrows(
            AsyncError::class,
            fn () => $deferred->future->await(),
            'must be called inside a coroutine',
        );
    }

    public function testAnAlreadyCompleteFutureAwaitsWithoutSuspending(): void
    {
        // No Async::run(): a complete Future returns without touching the loop.
        $this->assertSame('ready', Future::complete('ready')->await());
    }

    public function testAwaitResumesWhenTheValueArrives(): void
    {
        $result = Async::run(function () {
            $deferred = new Deferred();
            Loop::get()->delay(0.01, static fn () => $deferred->complete('later'));

            return $deferred->future->await();
        });

        $this->assertSame('later', $result);
    }

    public function testAwaitRethrowsAtTheSuspensionPoint(): void
    {
        $error = $this->assertThrows(RuntimeException::class, static fn () => Async::run(function (): void {
            $deferred = new Deferred();
            Loop::get()->delay(0.01, static fn () => $deferred->error(new RuntimeException('provider exploded')));
            $deferred->future->await();
        }));

        $this->assertSame('provider exploded', $error->getMessage());
    }

    public function testCompletingTwiceIsAProgrammingError(): void
    {
        $deferred = new Deferred();
        $deferred->complete(1);

        $this->assertThrows(AsyncError::class, static fn () => $deferred->complete(2), 'already complete');
    }

    public function testCoroutinesInterleaveOnTheirOwnTimers(): void
    {
        $order = [];

        Async::run(function () use (&$order): void {
            $slow = Async::spawn(function () use (&$order): string {
                $order[] = 'slow:start';
                Async::delay(0.03);
                $order[] = 'slow:end';

                return 'SLOW';
            });

            $fast = Async::spawn(function () use (&$order): string {
                $order[] = 'fast:start';
                Async::delay(0.01);
                $order[] = 'fast:end';

                return 'FAST';
            });

            $this->assertSame('SLOW', $slow->await());
            $this->assertSame('FAST', $fast->await());
        });

        $this->assertSame(['slow:start', 'fast:start', 'fast:end', 'slow:end'], $order);
    }

    public function testAwaitingAFutureNobodyCompletesIsReportedNotHung(): void
    {
        $this->assertThrows(
            AsyncError::class,
            static fn () => Async::run(static function (): void {
                (new Deferred())->future->await();
            }),
            'ran out of work',
        );
    }

    public function testRunReturnsWhenAWatcherOutlivesTheCoroutine(): void
    {
        if (!function_exists('pcntl_alarm')) {
            self::markTestSkipped('needs ext-pcntl for the watchdog');
        }

        // A watcher on a socket nothing will ever write to — a listening server, in the
        // case that found this. The tick that finishes the coroutine used to carry on
        // into poll() and block on it forever.
        [$idle, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        Loop::get()->onReadable($idle, static function (): void {
        });

        // A regression here hangs the whole suite, so make it a failure instead.
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static fn () => throw new RuntimeException('Async::run() never returned'));
        pcntl_alarm(5);

        try {
            $result = Async::run(static function (): string {
                Async::delay(0.01);

                return 'finished';
            });
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }

        $this->assertSame('finished', $result);
        $this->assertTrue(is_resource($peer));
    }

    public function testACoroutineThatFailsUnobservedStillSurfaces(): void
    {
        $error = $this->assertThrows(RuntimeException::class, static fn () => Async::run(static function (): void {
            // Nobody awaits this one — upstream's floating `(async () => {...})()`.
            Async::spawn(static fn () => throw new RuntimeException('tool crashed'));
            Async::delay(0.01);
        }));

        $this->assertSame('tool crashed', $error->getMessage());
    }

    public function testAnAwaitedFailureIsNotAlsoReportedAsUnobserved(): void
    {
        $seen = null;

        Async::run(function () use (&$seen): void {
            $future = Async::spawn(static fn () => throw new RuntimeException('handled'));

            try {
                $future->await();
            } catch (RuntimeException $error) {
                $seen = $error->getMessage();
            }
        });

        $this->assertSame('handled', $seen);
    }
}
