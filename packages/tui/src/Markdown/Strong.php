<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `**bold**`. */
final readonly class Strong implements InlineToken
{
    /** @param list<InlineToken> $children */
    public function __construct(public array $children)
    {
    }
}
