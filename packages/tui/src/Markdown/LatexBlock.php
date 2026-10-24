<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * Display math, `$$ … $$` or `\[ … \]` on lines of its own — upstream's `latexBlock` token.
 *
 * `$text` is the math and `$raw` the source lines. A pending block has not seen its closing
 * delimiter yet and shows `$raw` as written.
 */
final readonly class LatexBlock implements BlockToken
{
    public function __construct(public string $text, public string $raw, public bool $pending = false)
    {
    }
}
