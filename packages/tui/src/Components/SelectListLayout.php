<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;

/**
 * Upstream's `SelectListLayoutOptions`: how wide a `SelectList`'s label column is.
 *
 * The column is the widest label plus a two-column gap, clamped between the two bounds; a bound
 * left out takes the other's value, and with neither it is 32 — the fixed column every list had
 * before. `$truncatePrimary` shortens a label that does not fit, in place of cutting its end.
 */
final readonly class SelectListLayout
{
    /**
     * @param Closure(string $text, int $maxWidth, int $columnWidth, SelectItem $item, bool $isSelected): string|null $truncatePrimary
     */
    public function __construct(
        public ?int $minPrimaryColumnWidth = null,
        public ?int $maxPrimaryColumnWidth = null,
        public ?Closure $truncatePrimary = null,
    ) {
    }

    /** Upstream's `{ minPrimaryColumnWidth: 12, maxPrimaryColumnWidth: 32 }`, which most pickers use. */
    public static function compact(): self
    {
        return new self(12, 32);
    }
}
