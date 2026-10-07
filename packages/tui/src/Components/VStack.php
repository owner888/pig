<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\LayoutViewport;

/** Children top to bottom — upstream's `VStack` in `components/v-stack.ts`. */
final class VStack extends Stack
{
    #[\Override]
    protected function layoutType(): string
    {
        return 'vstack';
    }

    #[\Override]
    public function render(int $width): array
    {
        $viewport = new LayoutViewport(max(1, $width), PHP_INT_MAX);
        $entries = self::visibleStackEntries($this->entries, $viewport);
        $rendered = array_map(static fn ($entry): array => $entry->component->render($viewport->width), $entries);
        $sizes = self::allocateStackSizes($entries, array_map('count', $rendered), null, $this->gap);
        $lines = [];
        foreach ($entries as $index => $_) {
            if ($index > 0) {
                for ($gap = 0; $gap < $this->gap; $gap++) {
                    $lines[] = '';
                }
            }
            $childLines = array_slice($rendered[$index], 0, $sizes[$index]);
            array_push($lines, ...$childLines);
            for ($padding = count($childLines); $padding < $sizes[$index]; $padding++) {
                $lines[] = '';
            }
        }

        return $lines;
    }
}
