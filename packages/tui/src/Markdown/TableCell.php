<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** One cell of a table. */
final readonly class TableCell
{
    /** @param list<InlineToken> $children */
    public function __construct(public array $children)
    {
    }
}
