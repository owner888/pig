<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\CodingAgent\Session\CompactionSummary;

/**
 * Whether to compact, and optionally the summary to use instead of asking the model.
 *
 * A hook that supplies its own summary has done the compaction itself, and the session
 * writes it down exactly as it would have written the model's.
 */
final readonly class SessionBeforeCompactResult
{
    public function __construct(
        public bool $cancel = false,
        public ?CompactionSummary $compaction = null,
    ) {
    }
}
