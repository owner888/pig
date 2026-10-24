<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * `$…$`, `$$…$$`, `\(…\)` or `\[…\]` inside a line — upstream's `latex` token.
 *
 * `$text` is the math between the delimiters and `$raw` the source with them. A pending span is an
 * opening delimiter still waiting for its close while an answer streams; it shows `$raw` as written
 * until the close arrives.
 */
final readonly class LatexSpan implements InlineToken
{
    public function __construct(public string $text, public string $raw, public bool $pending = false)
    {
    }
}
