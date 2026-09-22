<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;

/**
 * The styling under everything a Markdown block draws.
 *
 * A message from the agent is dim, one from the user is not, and that applies to the
 * prose without overriding what the markdown itself asked for. The background is kept
 * separate from the rest because it is applied at the padding stage instead — a
 * background that stops where the text stops leaves a ragged edge.
 */
final readonly class DefaultTextStyle
{
    /**
     * @param Closure(string): string|null $colour     applied to plain text
     * @param Closure(string): string|null $background applied to the whole padded line
     */
    public function __construct(
        public ?Closure $colour = null,
        public ?Closure $background = null,
        public bool $bold = false,
        public bool $italic = false,
        public bool $strikethrough = false,
        public bool $underline = false,
    ) {
    }
}
