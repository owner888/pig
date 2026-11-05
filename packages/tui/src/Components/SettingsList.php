<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\CapturesViewportInput;
use Pig\Tui\Component;
use Pig\Tui\Focusable;
use Pig\Tui\Fuzzy;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\MouseHandler;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;
use Pig\Tui\TextWrap;
use Pig\Tui\Width;

/**
 * A list of settings and what each one is set to, changed in place.
 *
 * The difference from `SelectList`, which is why this exists at all: a select list is
 * answered once and closed, so it has one column and an Enter that means "this one". A
 * settings list is *lived in* — several things are changed in one visit and the screen has
 * to keep saying what each of them is now. So it has two columns, Enter changes the row
 * under the cursor rather than closing the list, and Escape means "done", not "cancelled".
 *
 * A row with more options than cycling can carry opens a submenu, which is any component at
 * all: it takes the whole area and every key until it hands back a value or nothing. That is
 * one mechanism rather than a second kind of list, so a submenu can be a `SelectList`, and
 * the thinking-level row is one.
 *
 * With `enableSearch` (upstream's `SettingsListOptions`), a search line sits above the rows and
 * every key no binding claims types into it; the rows are those the query matches, best first,
 * by the same fuzzy ranking the model picker uses. `/settings` has it; the submenus do not.
 *
 * Ported from upstream's `tui/components/settings-list.ts`.
 */
final class SettingsList implements Component, Focusable, InputHandler, MouseHandler, CapturesViewportInput
{
    public bool $focused = false;

    /** @var list<SettingItem> */
    private array $items;

    /** The rows the query leaves, in its order; every row while there is no search. @var list<SettingItem> */
    private array $filtered;

    /** The search line; null without `enableSearch`. */
    private readonly ?Input $search;

    /**
     * What each row is set to now, by id.
     *
     * Held here rather than in the item, so `SettingItem` can stay readonly — a declaration
     * built once and handed over is not the place to keep something that changes.
     *
     * @var array<string, string>
     */
    private array $current = [];

    private int $selected = 0;

    /** The row a primary press landed on, which the click that follows it activates. */
    private ?int $mousePressedIndex = null;

    private ?Component $submenu = null;

    /** Where the cursor was when the submenu opened, so Escape comes back to that row. */
    private ?int $submenuRow = null;

    /** @var Closure(string, string): void|null */
    private ?Closure $onChange = null;

    /** @var Closure(): void|null */
    private ?Closure $onClose = null;

    private readonly SettingsListTheme $theme;

    /** The widest a label may be before the value column starts, in columns. */
    private const int LABEL_MAX_WIDTH = 30;

    /** Columns left empty at the right-hand end of a row, as upstream leaves. */
    private const int RIGHT_MARGIN = 2;

    /**
     * @param list<SettingItem> $items
     * @param bool $enableSearch upstream's option: a search line above the rows
     */
    public function __construct(
        array $items,
        private readonly int $maxVisible = 10,
        ?SettingsListTheme $theme = null,
        bool $enableSearch = false,
    ) {
        $this->items = array_values($items);
        $this->filtered = $this->items;
        $this->search = $enableSearch ? new Input() : null;

        foreach ($this->items as $item) {
            $this->current[$item->id] = $item->value;
        }

        // Not a default parameter: a theme is made of closures, and a default value has to
        // be a constant expression.
        $this->theme = $theme ?? SettingsListTheme::default();
    }

    /** What a row is set to now. Null for an id no row has. */
    public function valueOf(string $id): ?string
    {
        return $this->current[$id] ?? null;
    }

    /**
     * Say what a row is set to, without telling anybody it changed.
     *
     * For a setting something else can change while this is open — the thinking level moves
     * when a model that cannot reason is chosen — so the screen agrees with the truth rather
     * than with what was last pressed here.
     */
    public function setValue(string $id, string $value): void
    {
        if (array_key_exists($id, $this->current)) {
            $this->current[$id] = $value;
        }
    }

    /** @param Closure(string, string): void|null $handler told the row's id and its new value */
    public function setChangeHandler(?Closure $handler): void
    {
        $this->onChange = $handler;
    }

    /** @param Closure(): void|null $handler Escape, or Ctrl+C — the list is finished with */
    public function setCloseHandler(?Closure $handler): void
    {
        $this->onClose = $handler;
    }

    /** What has been typed into the search line; empty without one. */
    public function filter(): string
    {
        return $this->search?->value() ?? '';
    }

    /** Move the selection to the row with this id; an id no row has leaves it where it is. */
    public function selectItem(string $id): void
    {
        foreach ($this->filtered as $index => $item) {
            if ($item->id === $id) {
                $this->selected = $index;

                return;
            }
        }
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->search?->invalidate();
        $this->submenu?->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        // The submenu takes the whole area rather than drawing under the list: it already
        // says which setting it is for, and a list behind it would be a list whose cursor
        // moves for no reason.
        if ($this->submenu !== null) {
            return $this->submenu->render($width);
        }

        // The search line first, then a blank one, as upstream draws them. Focused, the input
        // puts the cursor marker at its caret, so an input method composes at the search line.
        $lines = [];

        if ($this->search !== null) {
            $this->search->focused = $this->focused;
            $lines = [...$this->search->render($width), ''];
        }

        if ($this->items === []) {
            return [...$lines, ...$this->hint('Nothing to set', $width)];
        }

        if ($this->filtered === []) {
            return [...$lines, ($this->theme->hint)(Width::truncate('  No matching settings', max(1, $width), '')), '', ...$this->hint($this->hintText(), $width)];
        }

        $total = count($this->filtered);
        [$start, $end] = $this->visibleRange();

        // The label column is the widest of *every* row, not of the rows the query leaves —
        // upstream's choice, so the value column does not jump about as you type.
        $labelWidth = $this->labelWidth($width);

        for ($index = $start; $index < $end; $index++) {
            $lines[] = $this->row($this->filtered[$index], $index === $this->selected, $labelWidth, $width);
        }

        if ($start > 0 || $end < $total) {
            $position = $this->selected + 1;
            // Truncated rather than wrapped, which `SelectList` and upstream both do to this
            // line: half of `(3/30)` on a second row says nothing.
            $lines[] = ($this->theme->hint)(Width::truncate("  ({$position}/{$total})", max(1, $width - 2), ''));
        }

        $description = $this->filtered[$this->selected]->description ?? null;

        if ($description !== null) {
            $lines[] = '';
            $lines[] = ($this->theme->description)('  ' . Width::truncate($description, max(1, $width - 4), ''));
        }

        $lines[] = '';

        return [...$lines, ...$this->hint($this->hintText(), $width)];
    }

    /**
     * Space is named because Space works. Upstream says `Enter/Space to change · Esc to cancel`;
     * pig keeps its own second half, because Escape here means done rather than cancelled, and
     * had quietly dropped the first — a key that works and is not on the list is the Ctrl+G rule
     * from the other side. With a search line, upstream's `Type to search` leads.
     */
    private function hintText(): string
    {
        return ($this->search !== null ? 'Type to search · ' : '') . 'Enter or Space to change · Esc when done';
    }

    /**
     * An open submenu takes the event; otherwise the wheel moves the selection, a primary press
     * selects the row under it and the click that follows changes it — upstream's `handleMouse()`.
     * With a search line, row 0 is the input's, row 1 the blank under it, and the rows are counted
     * from 2, as upstream counts them.
     */
    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        if ($this->submenu !== null) {
            $result = $this->submenu instanceof MouseHandler ? $this->submenu->handleMouse($event) : null;

            return $result?->withFocus();
        }

        if ($this->search !== null) {
            if ($event->y === 0) {
                return $this->search->handleMouse($event)?->withFocus();
            }

            if ($event->y === 1) {
                return null;
            }
        }

        if ($this->filtered === []) {
            return null;
        }
        if ($event->type === 'wheel' && $event->wheelDelta !== null && $event->wheelDelta !== 0) {
            $delta = $event->wheelDelta < 0 ? -1 : 1;
            $previousIndex = $this->selected;
            $this->selected = max(0, min(count($this->filtered) - 1, $this->selected + $delta));

            return new TuiMouseEventResult(handled: true, render: $this->selected !== $previousIndex);
        }
        // Hover must not change selection: the visible range is centered on it.
        if ($event->button !== 'left' || ($event->type !== 'press' && $event->type !== 'click')) {
            return null;
        }

        [$start, $end] = $this->visibleRange();
        $itemIndex = $start + $event->y - ($this->search !== null ? 2 : 0);
        if ($itemIndex < $start || $itemIndex >= $end) {
            return null;
        }
        if ($event->type === 'press') {
            $this->mousePressedIndex = $itemIndex;
            $this->selected = $itemIndex;

            return new TuiMouseEventResult(handled: true, focus: true);
        }

        $this->selected = $this->mousePressedIndex ?? $itemIndex;
        $this->mousePressedIndex = null;
        $this->activate();

        return new TuiMouseEventResult(handled: true);
    }

    /**
     * The window of rows drawn: the selection kept near the middle, without scrolling past the end.
     *
     * @return array{0: int, 1: int} start inclusive, end exclusive
     */
    private function visibleRange(): array
    {
        $total = count($this->filtered);
        $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), $total - $this->maxVisible));

        return [$start, min($start + $this->maxVisible, $total)];
    }

    /**
     * The label column, wide enough for the widest name and narrow enough to leave a value.
     *
     * Measured in columns rather than characters, so a CJK label does not push the value
     * column off the screen — and **clamped to the terminal**, which upstream does not do.
     * Upstream caps it at 30 and then writes the label out in full anyway, so a row is as
     * wide as it likes; here a line wider than the terminal is not cosmetic, because
     * `TuiBase::checkWidth()` throws on one rather than letting it wrap and corrupt every cursor
     * move below it. Reproduced before it was fixed: `/settings` on a terminal 32 columns or
     * narrower took the session down from inside `render()`.
     */
    private function labelWidth(int $width): int
    {
        $widest = max(array_map(
            static fn (SettingItem $item): int => Width::visible($item->label),
            $this->items,
        ));

        // What is left after the cursor, the two-column separator, the right margin and one
        // column of value — which is the point of the screen and so is never given up.
        $room = $width - Width::visible($this->theme->cursor) - 2 - self::RIGHT_MARGIN - 1;

        return max(1, min(self::LABEL_MAX_WIDTH, $widest, $room));
    }

    private function row(SettingItem $item, bool $isSelected, int $labelWidth, int $width): string
    {
        $cursor = $isSelected ? $this->theme->cursor : str_repeat(' ', Width::visible($this->theme->cursor));
        // Truncate first, then pad: the column is fixed, so a long label is cut to it and a short
        // one is filled to it. `Width::pad()` is the padding, in columns — `str_pad()` here is the
        // trap on it, and 主题 is 6 bytes and 4 columns.
        $label = Width::pad(Width::truncate($item->label, $labelWidth, ''), $labelWidth);

        $room = $width - Width::visible($cursor) - $labelWidth - 2 - self::RIGHT_MARGIN;
        $value = $room < 1 ? '' : Width::truncate($this->current[$item->id], $room, '');

        return $cursor
            . ($this->theme->label)($label, $isSelected)
            . '  '
            . ($this->theme->value)($value, $isSelected);
    }

    /**
     * A hint, wrapped rather than cut.
     *
     * Which is how every other hint in pig is drawn — `TerminalUi` puts each one in a `Text`
     * — and a hint is the one line on the screen that has to be readable: `Esc when don` is
     * worse than two rows. The indent goes on *after* the wrap, or the second row hangs in
     * the margin; that is the per-line-prefix trap, one line long.
     *
     * @return list<string>
     */
    private function hint(string $text, int $width): array
    {
        $lines = [];

        foreach (TextWrap::wrap($text, max(1, $width - 2)) as $line) {
            $lines[] = ($this->theme->hint)('  ' . $line);
        }

        return $lines;
    }

    /** Upstream's `capturesViewportInput = true`: in fullscreen, PageUp/PageDown page the list. */
    #[\Override]
    public function capturesViewportInput(): bool
    {
        return true;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        // Every key, while one is open — including Escape, which is how a submenu is left.
        // Handing Escape to the list instead would close the whole screen from inside a
        // submenu, which is one press doing two things.
        if ($this->submenu !== null) {
            if ($this->submenu instanceof InputHandler) {
                $this->submenu->handleInput($data);
            }

            return;
        }

        $last = count($this->filtered) - 1;
        $kb = Keybindings::getKeybindings();
        $pageIndex = Keybindings::getSelectPageIndex($kb, $data, $this->selected, count($this->filtered), $this->maxVisible);

        match (true) {
            // Any key leaves a list with nothing in it, where upstream answers only Escape and
            // moves a cursor through no rows for everything else. Unreachable from `/settings`,
            // which always has rows; the alternative is a screen that cannot be left by the key
            // somebody presses first. A query that matches nothing is different: the rows are
            // still there behind it, and the key is typed into the search line.
            $this->items === [] => $this->close(),
            $kb->matches($data, 'tui.select.cancel') => $this->close(),
            $last < 0 => $this->type($data),
            $kb->matches($data, 'tui.select.up') => $this->selected = $this->selected === 0 ? $last : $this->selected - 1,
            $kb->matches($data, 'tui.select.down') => $this->selected = $this->selected === $last ? 0 : $this->selected + 1,
            $pageIndex !== null => $this->selected = $pageIndex,
            // Space as well as Enter, as upstream does: a row that is a switch reads like
            // a checkbox, and a checkbox is toggled with the space bar — unless something has
            // been typed into the search line, where Space is a character of the query.
            $kb->matches($data, 'tui.select.confirm') || ($data === ' ' && $this->filter() === '') => $this->activate(),
            default => $this->type($data),
        };
    }

    /** A key no binding claimed: into the search line, and the rows are those it matches now. */
    private function type(string $data): void
    {
        if ($this->search === null) {
            return;
        }

        $this->search->handleInput($data);
        $this->filtered = Fuzzy::filter($this->items, $this->search->value(), static fn (SettingItem $item): string => $item->label);
        // Upstream's `applyFilter()`: the best match is the selection.
        $this->selected = 0;
    }

    private function activate(): void
    {
        $item = $this->filtered[$this->selected] ?? null;

        if ($item === null) {
            return;
        }

        if ($item->submenu !== null) {
            $this->submenuRow = $this->selected;
            $this->submenu = ($item->submenu)(
                $this->current[$item->id],
                function (?string $chosen) use ($item): void {
                    if ($chosen !== null) {
                        $this->change($item->id, $chosen);
                    }

                    $this->submenu = null;
                    // Back to the row it was opened from. Without this, a submenu that
                    // moved the selection would come back to a different setting.
                    $this->selected = $this->submenuRow ?? $this->selected;
                    $this->submenuRow = null;
                },
            );

            return;
        }

        if ($item->values === []) {
            return;
        }

        $at = array_search($this->current[$item->id], $item->values, true);
        // An unknown current value starts the cycle at the first option rather than
        // nowhere: a settings file holding `theme: solarized` should still be changeable.
        $next = $at === false ? 0 : ($at + 1) % count($item->values);

        $this->change($item->id, $item->values[$next]);
    }

    private function change(string $id, string $value): void
    {
        $this->current[$id] = $value;

        if ($this->onChange !== null) {
            ($this->onChange)($id, $value);
        }
    }

    private function close(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }
}
