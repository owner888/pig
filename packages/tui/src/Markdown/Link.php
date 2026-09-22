<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** `[text](url)`, `<url>`, or a bare URL. */
final readonly class Link implements InlineToken
{
    /**
     * @param list<InlineToken> $children the link text
     * @param string            $label    the same text unstyled, for comparing against $href
     */
    public function __construct(
        public array $children,
        public string $href,
        public string $label,
    ) {
    }
}
