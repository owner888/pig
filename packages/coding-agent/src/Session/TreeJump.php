<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What came of asking to move to another point in the tree.
 *
 * Four outcomes, and a caller has to be able to tell them apart: it moved; it moved and
 * wrote a summary of what was left behind; it did not move because summarising was
 * called off part-way, which is escape being pressed and means "put me back where I was,
 * and show me that list again"; or there was nowhere to go because that point is already
 * where the conversation is — `moved` false with `aborted` false, which is not a failure
 * and must not be reported as a cancellation.
 *
 * Upstream returns `{cancelled, aborted, editorText}` from `navigateTree` for the same
 * reasons. A hook refusing the jump is the fifth outcome and stays an exception here, as
 * it was.
 *
 * `editorText` is what was said at the point being gone back to, for a point that is
 * something *somebody said*: that message leaves the conversation and its words go back
 * into the prompt, which is what going back to a question means — asking it differently.
 */
final readonly class TreeJump
{
    public function __construct(
        public bool $moved,
        public ?BranchSummary $summary = null,
        public bool $aborted = false,
        public ?string $editorText = null,
    ) {
    }
}
