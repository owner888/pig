<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `> quoted`. */
final readonly class Blockquote implements BlockToken
{
    /** @param list<BlockToken> $children quotes hold blocks, so they nest */
    public function __construct(public array $children)
    {
    }
}
