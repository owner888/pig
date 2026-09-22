<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * A blank line between blocks.
 *
 * Kept as a token rather than thrown away, because the renderer puts a blank line after
 * most blocks and needs to know when the document already has one there.
 */
final readonly class Blank implements BlockToken
{
}
