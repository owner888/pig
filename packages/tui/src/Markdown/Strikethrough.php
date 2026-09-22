<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `~~struck out~~`. */
final readonly class Strikethrough implements InlineToken
{
    /** @param list<InlineToken> $children */
    public function __construct(public array $children)
    {
    }
}
