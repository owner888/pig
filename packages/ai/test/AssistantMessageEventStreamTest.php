<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;

final class AssistantMessageEventStreamTest extends TestCase
{
    public function testDoneCarriesTheFinishedMessageOut(): void
    {
        $stream = new AssistantMessageEventStream();
        $finished = $this->message(StopReason::Stop, 'all done');

        $stream->push(new StartEvent($this->message(StopReason::Stop, '')));
        $stream->push(new TextDeltaEvent(0, 'all done', $this->message(StopReason::Stop, 'all done')));

        $this->assertFalse($stream->result()->isComplete());

        $stream->push(new DoneEvent(StopReason::Stop, $finished));

        $this->assertTrue($stream->result()->isComplete());
        // The stream times the response, so the result is the message with `durationMs` set.
        $this->assertEquals($finished->withDurationMs((int) $stream->result()->await()->durationMs), $stream->result()->await());
    }

    public function testAFailureIsAResultNotAnException(): void
    {
        $stream = new AssistantMessageEventStream();
        $failed = $this->message(StopReason::Error, '', 'upstream returned 529');

        $stream->push(new ErrorEvent(StopReason::Error, $failed));

        $result = $stream->result()->await();

        $this->assertEquals($failed->withDurationMs((int) $result->durationMs), $result);
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('upstream returned 529', $result->errorMessage);
    }

    public function testEventsBeforeTheEndAreStillDelivered(): void
    {
        $stream = new AssistantMessageEventStream();
        $stream->push(new StartEvent($this->message(StopReason::Stop, '')));
        $stream->push(new TextDeltaEvent(0, 'hi', $this->message(StopReason::Stop, 'hi')));
        $stream->push(new DoneEvent(StopReason::Stop, $this->message(StopReason::Stop, 'hi')));

        $types = array_map(
            static fn (object $event): string => $event::class,
            iterator_to_array($stream->getIterator()),
        );

        $this->assertSame([StartEvent::class, TextDeltaEvent::class, DoneEvent::class], $types);
    }

    public function testTheFinalMessageIsTimedFromTheStreamsStart(): void
    {
        $stream = new AssistantMessageEventStream();
        usleep(20_000);
        $stream->push(new DoneEvent(StopReason::Stop, $this->message(StopReason::Stop, 'hi')));

        $result = $stream->result()->await();

        $this->assertGreaterThanOrEqual(15, $result->durationMs);
        $this->assertSame($result, iterator_to_array($stream)[0]->message);
    }

    public function testAMessageAlreadyTimedOrFromBeforeTheStreamIsLeftAlone(): void
    {
        $timed = new AssistantMessageEventStream();
        $timed->push(new DoneEvent(StopReason::Stop, $this->message(StopReason::Stop, 'hi')->withDurationMs(7)));
        $this->assertSame(7, $timed->result()->await()->durationMs);

        // A response that started elsewhere — a deferred result fetched later — stays untimed.
        $forwarded = new AssistantMessageEventStream();
        $forwarded->push(new DoneEvent(StopReason::Stop, $this->message(StopReason::Stop, 'hi', timestamp: $forwarded->startedAt() - 1000)));
        $this->assertNull($forwarded->result()->await()->durationMs);
    }

    public function testEndTimesTheResultItIsGiven(): void
    {
        $stream = new AssistantMessageEventStream();
        $stream->end($this->message(StopReason::Stop, 'hi'));

        $this->assertNotNull($stream->result()->await()->durationMs);
    }

    private function message(StopReason $reason, string $text, ?string $errorMessage = null, ?int $timestamp = null): AssistantMessage
    {
        return new AssistantMessage(
            $text === '' ? [] : [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(),
            $reason,
            $errorMessage,
            $timestamp,
        );
    }
}
