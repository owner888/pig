<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * What a long conversation was boiled down to.
 *
 * Replaces the messages it summarises, so the conversation carries on from a page of
 * notes rather than from everything that was ever said. An app message like
 * `BashExecution`: it sits in the transcript where the old messages were, and
 * `CodingAgent` turns it into a user message on the way to the model.
 *
 * The file lists are separate from the prose because they are the part a model needs
 * exactly right — a summary that says "read some files" sends it to read them again.
 *
 * Ported from upstream's `CompactionEntry` and `CompactionDetails`, `firstKeptEntryId`
 * included: pig used to store a count of replaced messages instead, which said the same
 * thing in a way pi could not read.
 */
final readonly class CompactionSummary
{
    public int $timestamp;

    /**
     * @param list<string> $readFiles     looked at and left alone
     * @param list<string> $modifiedFiles written or edited
     * @param int $tokensBefore what the conversation cost before this replaced it
     * @param string|null $firstKeptEntryId the entry the kept part of the conversation
     *                    starts at. **This is what the file stores and what replaying it
     *                    uses** — everything on the branch before it is what this summary
     *                    stands in for. Null means the whole conversation before this one.
     * @param int $replaced how many messages that worked out to, for a line on the screen.
     *                      Derived when the file is read, never written: it is the same
     *                      fact counted from the other end, and a file holding both is a
     *                      file that can disagree with itself.
     */
    public function __construct(
        public string $summary,
        public array $readFiles = [],
        public array $modifiedFiles = [],
        public int $tokensBefore = 0,
        public ?string $firstKeptEntryId = null,
        public int $replaced = 0,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** The summary as the model reads it, with the file lists tagged on. */
    public function toText(): string
    {
        $text = "The conversation so far, summarised:\n\n" . $this->summary;

        if ($this->readFiles !== []) {
            $text .= "\n\n<read-files>\n" . implode("\n", $this->readFiles) . "\n</read-files>";
        }

        if ($this->modifiedFiles !== []) {
            $text .= "\n\n<modified-files>\n" . implode("\n", $this->modifiedFiles) . "\n</modified-files>";
        }

        return $text;
    }
}
