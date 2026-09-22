<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * What was kept, what was dropped, and which limit did it.
 *
 * The counts are here so the tool can tell the model exactly where to pick up — "showing
 * lines 1-2000 of 8431, use offset=2001" is worth far more than "output truncated".
 */
final readonly class Truncation
{
    /**
     * @param string      $content     what survived
     * @param string|null $by          'lines' or 'bytes', or null when nothing was cut
     * @param bool        $lastPartial a line was cut mid-way (tail truncation only)
     * @param bool        $firstTooBig the very first line was over the byte limit on its own
     */
    public function __construct(
        public string $content,
        public bool $truncated,
        public ?string $by,
        public int $totalLines,
        public int $totalBytes,
        public int $outputLines,
        public int $outputBytes,
        public bool $lastPartial = false,
        public bool $firstTooBig = false,
    ) {
    }
}
