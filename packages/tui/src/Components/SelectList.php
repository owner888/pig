<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * A scrolling list of choices, driven by the arrow keys.
 *
 * The selection wraps at both ends, which matters more than it sounds: a command palette
 * with forty entries is faster to reach the last of by pressing Up once.
 */
final class SelectList implements Component, InputHandler
{
    /** @var list<SelectItem> */
    private array $items;

    /** @var list<SelectItem> */
    private array $filtered;

    private int $selected = 0;

    /** @var Closure(SelectItem): void|null */
    private ?Closure $onSelect = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    /** @var Closure(SelectItem): void|null */
    private ?Closure $onSelectionChange = null;

    /** Width below which descriptions are dropped: two columns need room to be two columns. */
    private const int DESCRIPTION_MIN_WIDTH = 40;

    /** Where the description column starts, and the widest a label may be before it. */
    private const int DESCRIPTION_COLUMN = 32;
    private const int LABEL_MAX_WIDTH = 30;

    private readonly SelectListTheme $theme;

    /** @param list<SelectItem> $items */
    public function __construct(
        array $items,
        private readonly int $maxVisible = 5,
        ?SelectListTheme $theme = null,
    ) {
        $this->items = array_values($items);
        $this->filtered = $this->items;
        // Not a default parameter: a theme is made of closures, and a default value has
        // to be a constant expression.
        $this->theme = $theme ?? SelectListTheme::default();
    }

    /** Keep the items whose value starts with $filter. */
    public function setFilter(string $filter): void
    {
        $needle = mb_strtolower($filter, 'UTF-8');

        $this->filtered = array_values(array_filter(
            $this->items,
            static fn (SelectItem $item): bool => str_starts_with(mb_strtolower($item->value, 'UTF-8'), $needle),
        ));

        $this->selected = 0;
    }

    public function setSelectedIndex(int $index): void
    {
        $this->selected = max(0, min($index, count($this->filtered) - 1));
    }

    public function selectedItem(): ?SelectItem
    {
        return $this->filtered[$this->selected] ?? null;
    }

    /** @param Closure(SelectItem): void|null $handler */
    public function setSelectHandler(?Closure $handler): void
    {
        $this->onSelect = $handler;
    }

    /** @param Closure(): void|null $handler */
    public function setCancelHandler(?Closure $handler): void
    {
        $this->onCancel = $handler;
    }

    /** @param Closure(SelectItem): void|null $handler */
    public function setSelectionChangeHandler(?Closure $handler): void
    {
        $this->onSelectionChange = $handler;
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: at most maxVisible rows are ever drawn.
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->filtered === []) {
            return [($this->theme->noMatch)('  No matches')];
        }

        $total = count($this->filtered);
        // Keep the selection near the middle of the window, without scrolling past the end.
        $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), $total - $this->maxVisible));
        $end = min($start + $this->maxVisible, $total);

        $lines = [];

        for ($index = $start; $index < $end; $index++) {
            $lines[] = $this->row($this->filtered[$index], $index === $this->selected, $width);
        }

        if ($start > 0 || $end < $total) {
            $position = $this->selected + 1;
            $lines[] = ($this->theme->scrollInfo)(Width::truncate("  ({$position}/{$total})", $width - 2, ''));
        }

        return $lines;
    }

    private function row(SelectItem $item, bool $isSelected, int $width): string
    {
        $prefix = $isSelected ? '→ ' : '  ';
        $label = $item->display();

        if ($item->description === null || $width <= self::DESCRIPTION_MIN_WIDTH) {
            return $this->style($prefix . Width::truncate($label, $width - 4, ''), $isSelected);
        }

        $shortLabel = Width::truncate($label, min(self::LABEL_MAX_WIDTH, $width - 6), '');

        // Deviation from upstream, which measures the label with JavaScript's `.length`.
        // Here it is measured in columns, so a CJK label does not push the description
        // column half off the screen.
        $gap = str_repeat(' ', max(1, self::DESCRIPTION_COLUMN - Width::visible($shortLabel)));
        $remaining = $width - (Width::visible($prefix . $shortLabel . $gap)) - 2;

        if ($remaining <= 10) {
            return $this->style($prefix . Width::truncate($label, $width - 4, ''), $isSelected);
        }

        $description = Width::truncate($item->description, $remaining, '');

        // The highlight covers the whole row, description included, so the selected row
        // reads as one thing rather than as a label with someone else's grey text after it.
        return $isSelected
            ? ($this->theme->selectedText)($prefix . $shortLabel . $gap . $description)
            : $prefix . $shortLabel . ($this->theme->description)($gap . $description);
    }

    private function style(string $line, bool $isSelected): string
    {
        return $isSelected ? ($this->theme->selectedText)($line) : $line;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $last = count($this->filtered) - 1;

        match (true) {
            Keys::isArrowUp($data) => $this->moveTo($this->selected === 0 ? $last : $this->selected - 1),
            Keys::isArrowDown($data) => $this->moveTo($this->selected === $last ? 0 : $this->selected + 1),
            Keys::isEnter($data) => $this->choose(),
            Keys::isEscape($data) || Keys::isCtrlC($data) => $this->cancel(),
            default => null,
        };
    }

    private function moveTo(int $index): void
    {
        $this->selected = max(0, $index);
        $item = $this->selectedItem();

        if ($item !== null && $this->onSelectionChange !== null) {
            ($this->onSelectionChange)($item);
        }
    }

    private function choose(): void
    {
        $item = $this->selectedItem();

        if ($item !== null && $this->onSelect !== null) {
            ($this->onSelect)($item);
        }
    }

    private function cancel(): void
    {
        if ($this->onCancel !== null) {
            ($this->onCancel)();
        }
    }
}
