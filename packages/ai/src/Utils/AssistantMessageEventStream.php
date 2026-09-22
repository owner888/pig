<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use LogicException;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageEvent;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;

/**
 * The stream every provider returns: assistant events out, one finished message at the end.
 *
 * A failed response is still an AssistantMessage — one whose stopReason is Error or
 * Aborted and whose errorMessage says why. Providers therefore never throw mid-stream;
 * the failure arrives as a value like any other result.
 *
 * @extends EventStream<AssistantMessageEvent, AssistantMessage>
 */
final class AssistantMessageEventStream extends EventStream
{
    public function __construct()
    {
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
}
