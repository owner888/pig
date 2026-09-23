<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use Pig\Ai\Utils\EventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use PHPUnit\Framework\TestCase;
use Pig\Test\AssertsThrows;
use RuntimeException;

final class EventStreamTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testBufferedEventsIterateWithoutSuspending(): void
    {
        $stream = $this->stream();
        $stream->push('one');
        $stream->push('two');
        $stream->push('stop');

        // Everything is already queued and the stream is closed, so no coroutine is needed.
        $this->assertSame(['one', 'two', 'stop'], iterator_to_array($stream->getIterator()));
    }

    public function testPushAfterTheCompletingEventIsDropped(): void
    {
        $stream = $this->stream();
        $stream->push('stop');
        $stream->push('too late');

        $this->assertSame(['stop'], iterator_to_array($stream->getIterator()));
    }

    public function testTheCompletingEventResolvesTheResult(): void
    {
        $stream = $this->stream();
        $this->assertFalse($stream->result()->isComplete());

        $stream->push('stop');

        $this->assertTrue($stream->result()->isComplete());
        $this->assertSame('RESULT:stop', $stream->result()->await());
    }

    public function testAConsumerSuspendsUntilTheProducerPushes(): void
    {
        $seen = [];

        $result = Async::run(function () use (&$seen) {
            $stream = $this->stream();

            Async::spawn(static function () use ($stream): void {
                Async::delay(0.005);
                $stream->push('first');
                Async::delay(0.005);
                $stream->push('second');
                $stream->push('stop');
                // Upstream ends with the same result it already pushed; the second
                // resolve must be tolerated rather than blowing up.
                $stream->end('RESULT:stop');
            });

            foreach ($stream as $event) {
                $seen[] = $event;
            }

            return $stream->result()->await();
        });

        $this->assertSame(['first', 'second', 'stop'], $seen);
        $this->assertSame('RESULT:stop', $result);
    }

    public function testEndReleasesAWaitingConsumer(): void
    {
        $seen = [];

        Async::run(function () use (&$seen): void {
            $stream = $this->stream();

            Async::spawn(static function () use ($stream): void {
                Async::delay(0.005);
                $stream->push('only');
                Async::delay(0.005);
                $stream->end();
            });

            foreach ($stream as $event) {
                $seen[] = $event;
            }
        });

        $this->assertSame(['only'], $seen);
    }

    public function testEndWithoutAResultLeavesTheResultPending(): void
    {
        $stream = $this->stream();
        $stream->end();

        $this->assertSame([], iterator_to_array($stream->getIterator()));
        $this->assertFalse($stream->result()->isComplete());
    }

    // ---- a producer that threw --------------------------------------------------------

    public function testAFailedStreamThrowsAtTheConsumerRatherThanEndingQuietly(): void
    {
        $stream = $this->stream();
        $stream->fail(new RuntimeException('DNS is on fire'));

        // A stream that failed is not a stream that ended: a consumer reading this as an
        // empty answer would treat a dead connection as the model having nothing to say.
        $this->assertThrows(
            RuntimeException::class,
            static fn () => iterator_to_array($stream->getIterator()),
            'DNS is on fire',
        );
    }

    public function testEventsPushedBeforeTheFailureAreStillDelivered(): void
    {
        $stream = $this->stream();
        $stream->push('one');
        $stream->push('two');
        $stream->fail(new RuntimeException('and then it broke'));

        $seen = [];

        try {
            foreach ($stream as $event) {
                $seen[] = $event;
            }
            $this->fail('the failure should have been thrown');
        } catch (RuntimeException $error) {
            $this->assertSame('and then it broke', $error->getMessage());
        }

        // Work that did happen is not thrown away because what came after it did not.
        $this->assertSame(['one', 'two'], $seen);
    }

    public function testAFailureReachesAConsumerAlreadyParked(): void
    {
        $caught = null;

        Async::run(function () use (&$caught): void {
            $stream = $this->stream();

            Async::spawn(static function () use ($stream): void {
                Async::delay(0.005);
                $stream->fail(new RuntimeException('too late'));
            });

            try {
                foreach ($stream as $ignored) {
                    // nothing ever arrives
                }
            } catch (RuntimeException $error) {
                $caught = $error->getMessage();
            }
        });

        $this->assertSame('too late', $caught);
    }

    public function testAwaitingTheResultOfAFailedStreamThrowsToo(): void
    {
        $caught = null;

        Async::run(function () use (&$caught): void {
            $stream = $this->stream();
            $stream->fail(new RuntimeException('nothing is coming'));

            try {
                $stream->result()->await();
            } catch (RuntimeException $error) {
                $caught = $error->getMessage();
            }
        });

        // Both ways of consuming a stream have to hear about it: iterating it and waiting
        // for what it produced.
        $this->assertSame('nothing is coming', $caught);
    }

    public function testFailingAStreamThatAlreadyEndedChangesNothing(): void
    {
        $stream = $this->stream();
        $stream->push('stop');
        $stream->end('RESULT:stop');
        $stream->fail(new RuntimeException('ignored'));

        // A producer that ended cleanly and then threw on the way out has already said what
        // it had to say; turning that into a failure would lose the result it gave.
        $this->assertSame('RESULT:stop', $stream->result()->await());
    }

    /** @return EventStream<string, string> */
    private function stream(): EventStream
    {
        return new EventStream(
            static fn (string $event): bool => $event === 'stop',
            static fn (string $event): string => "RESULT:{$event}",
        );
    }
}
