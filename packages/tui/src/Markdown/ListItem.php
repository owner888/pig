<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** One entry of a list. Holds blocks, so a nested list is just another child. */
final readonly class ListItem implements BlockToken
{
    /** @param list<BlockToken> $children */
    public function __construct(public array $children)
    {
    }
}
