<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `*italic*`. */
final readonly class Emphasis implements InlineToken
{
    /** @param list<InlineToken> $children */
    public function __construct(public array $children)
    {
    }
}
