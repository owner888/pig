<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** Ordinary prose. */
final readonly class Paragraph implements BlockToken
{
    /** @param list<InlineToken> $children */
    public function __construct(public array $children)
    {
    }
}
