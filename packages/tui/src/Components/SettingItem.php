<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;

/**
 * One row of a `SettingsList`: a name, what it is set to, and how it is changed.
 *
 * Two ways to change it, and a row has exactly one of them. `$values` cycles: pressing
 * Enter moves to the next one and wraps, which is right for a switch and for a choice of
 * two. `$submenu` opens a list instead, which is right once there are more options than
 * anyone wants to press Enter through, or once the options need describing.
 *
 * Readonly, unlike upstream's, which writes the new value back into the item. The current
 * value lives in the list rather than here, so a declaration built in one place stays the
 * declaration — `SettingsList::valueOf()` is what answers what it is set to now.
 */
final readonly class SettingItem
{
    /**
     * @param string       $id     what `onChange` is told; unique within one list
     * @param list<string> $values pressed through in order, wrapping. Empty with no
     *        `$submenu` makes the row a label: it draws and cannot be changed
     * @param Closure(string, Closure(?string): void): Component|null $submenu given the
     *        current value and a callback — called with the chosen value, or with null for
     *        a submenu that was escaped out of
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $value,
        public ?string $description = null,
        public array $values = [],
        public ?Closure $submenu = null,
    ) {
    }
}
