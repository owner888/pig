<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Fuzzy;
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

    /** What has been typed into the list, which is also whether to draw the search line. */
    private string $query = '';

    /** @var Closure(SelectItem): void|null */
    private ?Closure $onSelect = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    /** @var Closure(SelectItem): void|null */
    private ?Closure $onSaveDefault = null;

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

    /**
     * Keep the items $filter matches, best first.
     *
     * Was a `startsWith` over the *value*, which for the model picker means over `0`, `1`, `2` —
     * nothing anyone could type. Now upstream's fuzzy ranking over each row's own haystack.
     */
    public function setFilter(string $filter): void
    {
        $this->query = $filter;
        $this->filtered = Fuzzy::filter(
            $this->items,
            $filter,
            static fn (SelectItem $item): string => $item->haystack(),
        );

        // Upstream's two halves: a query puts the selection on its best match, and clearing one
        // leaves it where it was rather than throwing you back to the top of a list you had
        // scrolled down. Clamped either way, because the list it indexes into just changed.
        $this->selected = $filter !== ''
            ? 0
            : min($this->selected, max(0, count($this->filtered) - 1));
    }

    /** What has been typed into the list so far. */
    public function filter(): string
    {
        return $this->query;
    }

    public function setSelectedIndex(int $index): void
    {
        $this->selected = max(0, min($index, count($this->filtered) - 1));
    }

    public function selectedItem(): ?SelectItem
    {
        return $this->filtered[$this->selected] ?? null;
    }

    /**
     * Where the selection sits in the list as it currently stands.
     *
     * For a caller holding a parallel array of richer objects — the editor holds the
     * autocomplete items these rows were made from — this is how it finds its own.
     * Filtering renumbers the rows, so the index is only meaningful against the list
     * the caller last saw.
     */
    public function selectedIndex(): int
    {
        return $this->selected;
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

    /**
     * Called when Ctrl+S is pressed on an item, to set it as a persistent default.
     * Upstream's `onSelectAsDefault`.
     *
     * @param Closure(SelectItem): void|null $handler
     */
    public function setSaveDefaultHandler(?Closure $handler): void
    {
        $this->onSaveDefault = $handler;
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
        // Drawn only once something has been typed, which is what keeps this out of the way of
        // every list that is never searched — the editor's completions above all, which are
        // filtered by the line you are writing and never see a printable key of their own.
        $search = $this->query === ''
            ? []
            : [($this->theme->description)(Width::truncate('  /' . $this->query, $width - 2, ''))];

        // `No matches` rather than upstream's `No matching commands`: this list is the models, the
        // sessions, the themes and the sign-ins as well as the commands.
        if ($this->filtered === []) {
            return [...$search, ($this->theme->noMatch)('  No matches')];
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

        return [...$search, ...$lines];
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
            Keys::isCtrlS($data) && $this->onSaveDefault !== null => $this->saveDefault(),
            Keys::isEscape($data) || Keys::isCtrlC($data) => $this->cancel(),
            Keys::isBackspace($data) => $this->backspace(),
            self::isPrintable($data) => $this->setFilter($this->query . $data),
            default => null,
        };
    }

    /**
     * One character off the end of the query, by characters and not by bytes.
     *
     * Escape still cancels the list rather than clearing the query, which is upstream's
     * arrangement: the way out of a picker should not depend on whether you typed in it.
     */
    private function backspace(): void
    {
        if ($this->query !== '') {
            $this->setFilter(mb_substr($this->query, 0, -1, 'UTF-8'));
        }
    }

    /**
     * Whether this keystroke is text rather than a key.
     *
     * Anything starting with escape is a sequence — an arrow, a function key, a mouse report —
     * and the control range is the ctrl chords, which every list above reserves. What is left is
     * text, including a paste, which arrives as one string and belongs in the query whole.
     */
    private static function isPrintable(string $data): bool
    {
        return $data !== ''
            && !str_starts_with($data, "\x1b")
            && preg_match('/^[^\x00-\x1f\x7f]+$/u', $data) === 1;
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

    private function saveDefault(): void
    {
        $item = $this->selectedItem();

        if ($item !== null && $this->onSaveDefault !== null) {
            ($this->onSaveDefault)($item);
        }
    }

    private function cancel(): void
    {
        if ($this->onCancel !== null) {
            ($this->onCancel)();
        }
    }
}
