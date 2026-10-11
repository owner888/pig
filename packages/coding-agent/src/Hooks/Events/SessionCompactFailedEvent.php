<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A compaction did not happen — upstream's `SessionCompactFailedEvent`, beside `session_compact`
 * so a hook that reacts to one is not left guessing about the other.
 *
 * `$reason` is what started it: `manual` for `/compact` (or a hook's `$ctx->compact()`),
 * `threshold` for the window filling up, `overflow` for a turn the provider refused as too
 * long. `$aborted` is escape or a hook's cancel, with `$errorMessage` null; anything else
 * carries the error, prefixed as upstream's is (`Compaction failed: …`, `Auto-compaction
 * failed: …`, `Context overflow recovery failed: …`). `$willRetry` is false, as upstream's
 * always is here: a turn whose recovery failed is not sent again. `$fromExtension` says the
 * content that failed came from a `session_before_compact` handler.
 */
final readonly class SessionCompactFailedEvent implements HookEvent
{
    /** @param 'manual'|'threshold'|'overflow' $reason */
    public function __construct(
        public string $reason,
        public bool $aborted,
        public ?string $errorMessage = null,
        public bool $willRetry = false,
        public bool $fromExtension = false,
    ) {
    }

    public function type(): string
    {
        return 'session_compact_failed';
    }
}
