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
    /**
     * @param int  $start the first item's own number, which is not always 1
     * @param bool $loose  whether the author put blank lines between the items, which is a fact
     *                     about how it is drawn rather than about what it says
     */
    public function __construct(
        public array $items,
        public bool $ordered = false,
        public int $start = 1,
        public bool $loose = false,
    )
    {
    }
}
