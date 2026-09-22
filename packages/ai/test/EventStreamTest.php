<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use Pig\Ai\Utils\EventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use PHPUnit\Framework\TestCase;

final class EventStreamTest extends TestCase
{
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

    /** @return EventStream<string, string> */
    private function stream(): EventStream
    {
        return new EventStream(
            static fn (string $event): bool => $event === 'stop',
            static fn (string $event): string => "RESULT:{$event}",
        );
    }
}
