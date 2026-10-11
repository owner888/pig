<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Session\SessionEntry;

/**
 * What a move in the session tree would leave behind — upstream's `TreePreparation`, the
 * `preparation` of `session_before_tree`.
 */
final readonly class TreePreparation
{
    /**
     * @param string|null        $targetId           where the move goes; null is before the first message
     * @param string|null        $oldLeafId          where the conversation is now, being left
     * @param string|null        $commonAncestorId   the deepest entry both positions share
     * @param list<SessionEntry> $entriesToSummarize the entries being left, oldest first
     *                                               (`BranchSummarization::collectEntriesForBranchSummary()`)
     * @param bool               $userWantsSummary   whether a summary of them was asked for
     * @param string|null        $customInstructions what to focus the summary on
     * @param bool|null          $replaceInstructions whether those instructions replace the default prompt
     * @param string|null        $label              the label to put on the summary entry
     */
    public function __construct(
        public ?string $targetId,
        public ?string $oldLeafId,
        public ?string $commonAncestorId,
        public array $entriesToSummarize,
        public bool $userWantsSummary,
        public ?string $customInstructions = null,
        public ?bool $replaceInstructions = null,
        public ?string $label = null,
    ) {
    }
}
