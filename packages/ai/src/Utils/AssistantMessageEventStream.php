<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use LogicException;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageEvent;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Timestamp;

/**
 * The stream every provider returns: assistant events out, one finished message at the end.
 *
 * A failed response is still an AssistantMessage — one whose stopReason is Error or
 * Aborted and whose errorMessage says why. Providers therefore never throw mid-stream;
 * the failure arrives as a value like any other result.
 *
 * It also times the response, as upstream's does: the final message (the `DoneEvent` or
 * `ErrorEvent`, or the result passed to `end()`) gets `durationMs`, measured with a monotonic
 * clock from the stream's creation, unless the message already has one or its `timestamp`
 * predates the stream — so a stream that forwards a response which started elsewhere, such as a
 * deferred result fetched later, leaves it untimed. Upstream assigns the field on the message;
 * a message here is readonly, so the event is pushed with a timed copy instead.
 *
 * @extends EventStream<AssistantMessageEvent, AssistantMessage>
 */
final class AssistantMessageEventStream extends EventStream
{
    private readonly int $startedAt;

    /** `hrtime()` nanoseconds — upstream's `performance.now()`. */
    private readonly int|float $startedAtMonotonic;

    public function __construct()
    {
        $this->startedAt = Timestamp::nowMs();
        $this->startedAtMonotonic = hrtime(true);

        parent::__construct(
            static fn (AssistantMessageEvent $event): bool
                => $event instanceof DoneEvent || $event instanceof ErrorEvent,
            static fn (AssistantMessageEvent $event): AssistantMessage => match (true) {
                $event instanceof DoneEvent => $event->message,
                $event instanceof ErrorEvent => $event->error,
                // Unreachable: the predicate above admits no other event.
                default => throw new LogicException('Unexpected event type for final result'),
            },
        );
    }

    /** Wall-clock start of this stream, for messages created before the response could start. */
    public function startedAt(): int
    {
        return $this->startedAt;
    }

    /** @param AssistantMessageEvent $event */
    public function push(mixed $event): void
    {
        $event = match (true) {
            $event instanceof DoneEvent => new DoneEvent($event->reason, $this->time($event->message)),
            $event instanceof ErrorEvent => new ErrorEvent($event->reason, $this->time($event->error)),
            default => $event,
        };

        parent::push($event);
    }

    /** @param AssistantMessage|NoResult $result */
    public function end(mixed $result = new NoResult()): void
    {
        parent::end($result instanceof AssistantMessage ? $this->time($result) : $result);
    }

    private function time(AssistantMessage $message): AssistantMessage
    {
        if ($this->done || $message->durationMs !== null || $message->timestamp < $this->startedAt) {
            return $message;
        }

        return $message->withDurationMs(max(0, (int) round((hrtime(true) - $this->startedAtMonotonic) / 1e6)));
    }
}
