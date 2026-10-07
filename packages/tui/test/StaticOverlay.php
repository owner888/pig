<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Pig\Tui\Component;

/**
 * Fixed lines regardless of width — the `StaticOverlay` fixture of upstream's overlay tests. It
 * records the width it was last asked to render at.
 */
final class StaticOverlay implements Component
{
    public ?int $requestedWidth = null;

    /** @param list<string> $lines */
    public function __construct(public array $lines)
    {
    }

    #[\Override]
    public function render(int $width): array
    {
        $this->requestedWidth = $width;

        return $this->lines;
    }

    #[\Override]
    public function invalidate(): void
    {
    }
}
