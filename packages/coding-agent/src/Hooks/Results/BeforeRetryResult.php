<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * What a `before_retry` handler decided.
 *
 * `delaySeconds` replaces the wait; `resetAttempts` starts the count over, for a hook that
 * changed the thing that was failing (a fresh account's quota is a fresh set of attempts);
 * `reason` is what the screen says beside the countdown; `cancel` ends the retrying and the
 * turn fails as it stands. The first handler to answer is the one that decides.
 */
final readonly class BeforeRetryResult
{
    public function __construct(
        public ?float $delaySeconds = null,
        public bool $resetAttempts = false,
        public ?string $reason = null,
        public bool $cancel = false,
    ) {
    }
}
