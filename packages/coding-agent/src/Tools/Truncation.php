<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * What was kept, what was dropped, and which limit did it.
 *
 * The counts are here so the tool can tell the model exactly where to pick up — "showing
 * lines 1-2000 of 8431, use offset=2001" is worth far more than "output truncated".
 *
 * **Field for field under upstream's names**, because this goes into a tool result's `details`
 * and `details` is written to the session file — which is pi's file. pig used shorter names for
 * three of them (`by`, `lastPartial`, `firstTooBig`) and left `maxLines`/`maxBytes` out, so pi
 * opening a conversation pig had written found a truncation record it could not read and drew no
 * warning for it. See the rename table in CLAUDE.md.
 *
 * `maxLines` and `maxBytes` are what *this* call was given rather than the constants: `ls`,
 * `find` and `grep` pass `PHP_INT_MAX` for the line limit, since the entry, result and match
 * limits above them already cap how many there are.
 */
final readonly class Truncation
{
    /**
     * @param string|null $truncatedBy           'lines' or 'bytes', or null when nothing was cut
     * @param bool        $lastLinePartial       a line was cut mid-way (tail truncation only)
     * @param bool        $firstLineExceedsLimit the very first line was over the byte limit on
     *        its own
     */
    public function __construct(
        public string $content,
        public bool $truncated,
        public ?string $truncatedBy,
        public int $totalLines,
        public int $totalBytes,
        public int $outputLines,
        public int $outputBytes,
        public int $maxLines,
        public int $maxBytes,
        public bool $lastLinePartial = false,
        public bool $firstLineExceedsLimit = false,
    ) {
    }
}
