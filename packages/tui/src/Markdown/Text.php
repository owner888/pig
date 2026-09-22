<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** Plain text, with every escape already resolved. */
final readonly class Text implements InlineToken
{
    public function __construct(public string $text)
    {
    }
}
