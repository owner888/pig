<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** A fenced or indented block of code, kept exactly as written. */
final readonly class CodeBlock implements BlockToken
{
    public function __construct(public string $code, public string $language = '')
    {
    }
}
