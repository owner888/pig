<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\CodingAgent\Session\CompactionResult;

/**
 * Whether to compact, and optionally the content to use instead of asking the model —
 * upstream's `SessionBeforeCompactResult`.
 *
 * A hook that supplies `compaction` has done the compaction itself, and the session writes it
 * down as given — its `firstKeptEntryId` and `tokensBefore` included — marked as the hook's.
 */
final readonly class SessionBeforeCompactResult
{
    public function __construct(
        public bool $cancel = false,
        public ?CompactionResult $compaction = null,
    ) {
    }
}
