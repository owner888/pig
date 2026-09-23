<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * What happened down the road not taken.
 *
 * `/tree` goes back to an earlier point and carries on from there. The branch that was
 * left is still in the session file, but the model no longer sees it — and the model is
 * usually the one that did the work on it. This is that work, written down and appended to
 * the branch being joined, so an hour of exploring is not thrown away by moving.
 *
 * Unlike a `CompactionSummary`, this **replaces nothing**: the messages it describes were
 * never on this branch. So there is no `replaced` count, and reading the log back never
 * splices anything out on its account.
 *
 * `$fromId` is the leaf it was written about, which is what makes it possible to say
 * *which* road later — the file still has it, and the id still points at it.
 *
 * Ported from upstream's `BranchSummaryEntry` and `BranchSummaryDetails`.
 */
final readonly class BranchSummary
{
    public int $timestamp;

    /**
     * @param list<string> $readFiles     looked at on that branch and left alone
     * @param list<string> $modifiedFiles written or edited on that branch
     * @param string|null  $fromId        the leaf this is about, still in the file
     * @param bool         $fromHook      written by a hook rather than by the model, which
     *        is why the file lists are not carried forward from it: a hook's summary is
     *        prose pig did not produce and cannot assume anything about
     */
    public function __construct(
        public string $summary,
        public array $readFiles = [],
        public array $modifiedFiles = [],
        public ?string $fromId = null,
        public bool $fromHook = false,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** The summary as the model reads it, with the file lists tagged on. */
    public function toText(): string
    {
        $text = BranchSummarization::PREAMBLE . $this->summary;

        if ($this->readFiles !== []) {
            $text .= "\n\n<read-files>\n" . implode("\n", $this->readFiles) . "\n</read-files>";
        }

        if ($this->modifiedFiles !== []) {
            $text .= "\n\n<modified-files>\n" . implode("\n", $this->modifiedFiles) . "\n</modified-files>";
        }

        return $text;
    }
}
