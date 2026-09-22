<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\Component;

/** Blank lines, for putting air between things. */
final class Spacer implements Component
{
    public function __construct(private int $lines = 1)
    {
    }

    public function setLines(int $lines): void
    {
        $this->lines = $lines;
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: the output depends on nothing but the count.
    }

    #[\Override]
    public function render(int $width): array
    {
        return array_fill(0, max(0, $this->lines), '');
    }
}
