<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A compaction did not happen. Upstream's `session_compact_failed`, beside `session_compact`
 * so a hook that reacts to one is not left guessing about the other.
 *
 * `$reason` is what started it: `manual` for `/compact` (or a hook's `$ctx->compact()`),
 * `threshold` for the window filling up, `overflow` for a turn the provider refused as too
 * long. `$aborted` is escape or a hook's cancel, with `$errorMessage` null; anything else
 * carries the error. `$willRetry` is true when the turn that overflowed would have been sent
 * again afterwards — it will not be now.
 */
final readonly class SessionCompactFailedEvent implements HookEvent
{
    /** @param 'manual'|'threshold'|'overflow' $reason */
    public function __construct(
        public string $reason,
        public bool $aborted,
        public ?string $errorMessage = null,
        public bool $willRetry = false,
        public bool $fromHook = false,
    ) {
    }

    public function type(): string
    {
        return 'session_compact_failed';
    }
}
