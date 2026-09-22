<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `## A heading`. */
final readonly class Heading implements BlockToken
{
    /**
     * @param int               $level    1 to 6
     * @param list<InlineToken> $children
     */
    public function __construct(public int $level, public array $children)
    {
    }
}
