<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\AssistantMessage;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A turn failed and the session is about to wait and try again.
 *
 * pig's own, with no upstream counterpart because pig's retry is its own machinery
 * (`Session\Retry` has none either). A handler may answer `BeforeRetryResult` to **change the
 * wait** — zero, for a hook that has just fixed the reason — or to **stop** the retrying. The
 * case it was written for: an extension holding several accounts for one provider switches to
 * the next on a 429 and asks for the retry at once, where the session's own backoff would have
 * waited out a quota that no longer applies.
 *
 * `$attempt` is the attempt about to be made, 1 for the first retry; `$delaySeconds` is what
 * the session was going to wait.
 */
final readonly class BeforeRetryEvent implements HookEvent
{
    public function __construct(
        public AssistantMessage $failed,
        public string $error,
        public int $attempt,
        public int $maxAttempts,
        public float $delaySeconds,
    ) {
    }

    public function type(): string
    {
        return 'before_retry';
    }
}
