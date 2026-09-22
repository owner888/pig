<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `` `code` `` — literal, so nothing inside it is markup. */
final readonly class CodeSpan implements InlineToken
{
    public function __construct(public string $code)
    {
    }
}
