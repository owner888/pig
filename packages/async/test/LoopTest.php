<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use Pig\Async\AsyncError;
use Pig\Async\Loop;
use PHPUnit\Framework\TestCase;
use Pig\Test\AssertsThrows;

final class LoopTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testDeferredCallbacksRunInOrderOnTheNextTick(): void
    {
        $loop = Loop::get();
        $order = [];

        $loop->defer(function () use (&$order): void {
            $order[] = 'first';
        });
        $loop->defer(function () use (&$order): void {
            $order[] = 'second';
        });

        $this->assertSame([], $order);

        $loop->tick();

        $this->assertSame(['first', 'second'], $order);
    }

    public function testACallbackThatDefersDoesNotStarveTheSameTick(): void
    {
        $loop = Loop::get();
        $ticks = 0;

        $loop->defer($again = function () use ($loop, &$ticks, &$again): void {
            $ticks++;

            if ($ticks < 3) {
                $loop->defer($again);
            }
        });

        $loop->tick();
        $this->assertSame(1, $ticks, 'a callback queued during the tick must wait for the next one');

        $loop->tick();
        $loop->tick();
        $this->assertSame(3, $ticks);
    }

    public function testTimersFireInTimeOrderNotInsertionOrder(): void
    {
        $loop = Loop::get();
        $order = [];

        $loop->delay(0.03, function () use (&$order): void {
            $order[] = 'late';
        });
        $loop->delay(0.01, function () use (&$order): void {
            $order[] = 'early';
        });

        $loop->run();

        $this->assertSame(['early', 'late'], $order);
    }

    public function testReadableFiresOnTheWatcherThatOwnsTheStream(): void
    {
        $loop = Loop::get();
        // Both peers must stay in scope: drop one and it is garbage collected, which
        // puts the surviving end at EOF and makes it permanently "readable".
        [$idleRead, $idlePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$busyRead, $busyWrite] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($busyWrite, 'payload');

        $fired = [];

        $idleWatcher = $loop->onReadable($idleRead, function () use (&$fired): void {
            $fired[] = 'idle';
        });
        $busyWatcher = $loop->onReadable($busyRead, function ($stream) use (&$fired, $loop, &$busyWatcher): void {
            $fired[] = fread($stream, 1024);
            $loop->cancel($busyWatcher);
        });

        // The busy watcher cancels itself, then nothing is left but the idle one.
        $loop->tick();
        $loop->cancel($idleWatcher);

        $this->assertSame(['payload'], $fired);
        $this->assertTrue(is_resource($idlePeer));
    }

    public function testCancelDisarmsAWatcherBeforeItFires(): void
    {
        $loop = Loop::get();
        $fired = false;

        $watcher = $loop->delay(0.001, function () use (&$fired): void {
            $fired = true;
        });
        $loop->cancel($watcher);
        $loop->run();

        $this->assertFalse($fired);
        $this->assertTrue($loop->isIdle());
    }

    public function testIsIdleTracksOutstandingWork(): void
    {
        $loop = Loop::get();
        $this->assertTrue($loop->isIdle());

        $watcher = $loop->delay(10.0, function (): void {
        });
        $this->assertFalse($loop->isIdle());

        $loop->cancel($watcher);
        $this->assertTrue($loop->isIdle());
    }

    public function testStopEndsRunEvenWithWorkOutstanding(): void
    {
        $loop = Loop::get();
        $ran = 0;

        $loop->delay(0.001, function () use ($loop, &$ran): void {
            $ran++;
            $loop->stop();
        });
        $loop->delay(60.0, function () use (&$ran): void {
            $ran++;
        });

        $loop->run();

        $this->assertSame(1, $ran);
        $this->assertFalse($loop->isIdle(), 'the 60s timer is still armed');
    }

    public function testASignalDuringSelectDoesNotKillTheLoop(): void
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('needs ext-pcntl');
        }

        $loop = Loop::get();
        pcntl_async_signals(true);
        $signals = 0;
        pcntl_signal(SIGALRM, function () use (&$signals): void {
            $signals++;
        });

        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $loop->onReadable($stream, function (): void {
        });

        // SIGALRM stands in for SIGWINCH: any signal interrupts a blocked select() the
        // same way, and pcntl_alarm() needs no fork — a forked child would run this
        // process's shutdown handlers on exit and corrupt the test report.
        // pcntl_alarm() takes whole seconds, hence the deliberately slow test.
        pcntl_alarm(1);
        $loop->delay(10.0, function (): void {
        });

        $started = microtime(true);
        $loop->tick();
        $elapsed = microtime(true) - $started;

        $this->assertSame(1, $signals);
        $this->assertTrue($elapsed < 5.0, 'tick() returned on EINTR rather than waiting out the 10s timer');
        $this->assertTrue(is_resource($peer));

        pcntl_signal(SIGALRM, SIG_DFL);
    }

    public function testAWatcherOnAClosedStreamNamesItself(): void
    {
        $loop = Loop::get();
        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $watcher = $loop->onReadable($stream, function (): void {
        });
        fclose($stream);

        $this->assertThrows(AsyncError::class, fn () => $loop->tick(), "Reader {$watcher} watches a closed stream");
        $this->assertTrue(is_resource($peer));
    }
}
