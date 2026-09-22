<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * Something that occupies whole lines: a paragraph, a heading, a list.
 *
 * A marker interface rather than a union, because the renderer takes arrays of these and
 * PHP cannot name a union or type an array's elements with one.
 */
interface BlockToken
{
}
