<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\LayoutViewport;
use Pig\Tui\Width;

/** Children left to right — upstream's `HStack` in `components/h-stack.ts`. */
final class HStack extends Stack
{
    #[\Override]
    protected function layoutType(): string
    {
        return 'hstack';
    }

    #[\Override]
    public function render(int $width): array
    {
        $safeWidth = max(1, $width);
        $entries = self::visibleStackEntries($this->entries, new LayoutViewport($safeWidth, PHP_INT_MAX));
        if ($entries === []) {
            return [];
        }

        $intrinsicWidths = array_map(static function ($entry) use ($safeWidth): int {
            $max = 0;
            foreach ($entry->component->render($safeWidth) as $line) {
                $max = max($max, Width::visible($line));
            }

            return $max;
        }, $entries);
        $widths = self::allocateStackSizes($entries, $intrinsicWidths, $safeWidth, $this->gap);
        $rendered = [];
        foreach ($entries as $index => $entry) {
            $rendered[] = $widths[$index] === 0 ? [] : $entry->component->render($widths[$index]);
        }
        $height = 0;
        foreach ($rendered as $lines) {
            $height = max($height, count($lines));
        }
        $result = array_fill(0, $height, '');
        $x = 0;
        foreach ($rendered as $index => $lines) {
            $childWidth = $widths[$index];
            $offset = match ($this->align) {
                'center' => intdiv($height - count($lines), 2),
                'end' => $height - count($lines),
                default => 0,
            };
            foreach ($lines as $row => $line) {
                $target = $row + $offset;
                if ($target < 0 || $target >= $height) {
                    continue;
                }
                $result[$target] = Width::composite($result[$target], $line, $x, $childWidth, $safeWidth);
            }
            $x += $childWidth + $this->gap;
        }

        return $result;
    }
}
