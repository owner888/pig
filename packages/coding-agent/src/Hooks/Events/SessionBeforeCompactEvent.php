<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Async\AbortSignal;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * The context is about to be summarised.
 *
 * `$messages` is what would be replaced and `$request` is the prompt the summariser
 * would be sent, so a handler can veto the compaction or do it itself — returning its
 * own `CompactionSummary` in a `SessionBeforeCompactResult` and saving the round trip.
 */
final readonly class SessionBeforeCompactEvent implements HookEvent
{
    /** @param list<mixed> $messages the part of the conversation being replaced */
    public function __construct(
        public array $messages,
        public string $request,
        public ?string $customInstructions = null,
        public ?AbortSignal $signal = null,
    ) {
    }

    public function type(): string
    {
        return 'session_before_compact';
    }
}
