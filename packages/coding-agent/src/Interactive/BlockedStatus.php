<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

/** What a dialog is waiting for, as `ProgramStatusReporter` reports it. Upstream's `BlockedStatus`. */
final readonly class BlockedStatus
{
    /** @param 'permission'|'question'|'auth' $kind */
    public function __construct(
        public string $kind,
        public string $message,
    ) {
    }
}
