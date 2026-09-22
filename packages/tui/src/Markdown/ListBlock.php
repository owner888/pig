<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * A run of list items at one level.
 *
 * Named ListBlock rather than List: `list` is a reserved word in PHP.
 */
final readonly class ListBlock implements BlockToken
{
    /** @param list<ListItem> $items */
    public function __construct(public array $items, public bool $ordered = false)
    {
    }
}
