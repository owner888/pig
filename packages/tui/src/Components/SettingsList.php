<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
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
 * Ported from upstream's `tui/components/settings-list.ts`.
 */
final class SettingsList implements Component, InputHandler
{
    /** @var list<SettingItem> */
    private array $items;

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

    /** @param list<SettingItem> $items */
    public function __construct(
        array $items,
        private readonly int $maxVisible = 10,
        ?SettingsListTheme $theme = null,
    ) {
        $this->items = array_values($items);

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

    #[\Override]
    public function invalidate(): void
    {
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

        if ($this->items === []) {
            return [($this->theme->hint)('  Nothing to set')];
        }

        $total = count($this->items);
        $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), $total - $this->maxVisible));
        $end = min($start + $this->maxVisible, $total);

        // One column for every label, so the values line up. Measured in columns rather
        // than characters, so a CJK label does not push the value column off the screen.
        $labelWidth = min(self::LABEL_MAX_WIDTH, max(array_map(
            static fn (SettingItem $item): int => Width::visible($item->label),
            $this->items,
        )));

        $lines = [];

        for ($index = $start; $index < $end; $index++) {
            $lines[] = $this->row($this->items[$index], $index === $this->selected, $labelWidth, $width);
        }

        if ($start > 0 || $end < $total) {
            $position = $this->selected + 1;
            $lines[] = ($this->theme->hint)("  ({$position}/{$total})");
        }

        $description = $this->items[$this->selected]->description ?? null;

        if ($description !== null) {
            $lines[] = '';
            $lines[] = ($this->theme->description)('  ' . Width::truncate($description, max(1, $width - 4), ''));
        }

        $lines[] = '';
        $lines[] = ($this->theme->hint)('  Enter to change · Esc when done');

        return $lines;
    }

    private function row(SettingItem $item, bool $isSelected, int $labelWidth, int $width): string
    {
        $cursor = $isSelected ? $this->theme->cursor : str_repeat(' ', Width::visible($this->theme->cursor));
        $label = Width::truncate($item->label, $labelWidth, '');
        $label .= str_repeat(' ', max(0, $labelWidth - Width::visible($label)));

        $used = Width::visible($cursor . $label) + 2;
        $value = Width::truncate($this->current[$item->id], max(1, $width - $used - 2), '');

        return $cursor
            . ($this->theme->label)($label, $isSelected)
            . '  '
            . ($this->theme->value)($value, $isSelected);
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

        $last = count($this->items) - 1;

        match (true) {
            $last < 0 => $this->close(),
            Keys::isArrowUp($data) => $this->selected = $this->selected === 0 ? $last : $this->selected - 1,
            Keys::isArrowDown($data) => $this->selected = $this->selected === $last ? 0 : $this->selected + 1,
            // Space as well as Enter, as upstream does: a row that is a switch reads like
            // a checkbox, and a checkbox is toggled with the space bar.
            Keys::isEnter($data) || $data === ' ' => $this->activate(),
            Keys::isEscape($data) || Keys::isCtrlC($data) => $this->close(),
            default => null,
        };
    }

    private function activate(): void
    {
        $item = $this->items[$this->selected] ?? null;

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
