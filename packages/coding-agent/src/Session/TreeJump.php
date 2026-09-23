<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What came of asking to move to another point in the tree.
 *
 * Three outcomes, and a caller has to be able to tell them apart: it moved; it moved and
 * wrote a summary of what was left behind; or it did not move because summarising was
 * called off part-way, which is escape being pressed and means "put me back where I was,
 * and show me that list again".
 *
 * Upstream returns `{cancelled, aborted}` from `navigateTree` for the same reason. A hook
 * refusing the jump is the fourth outcome and stays an exception here, as it was.
 */
final readonly class TreeJump
{
    public function __construct(
        public bool $moved,
        public ?BranchSummary $summary = null,
        public bool $aborted = false,
    ) {
    }
}
