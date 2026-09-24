<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Style;

/**
 * How a `SettingsList` is painted.
 *
 * Passed in for the same reason `SelectListTheme` is: the component draws rows and does not
 * know which of pig's two themes is on.
 *
 * `label` and `value` are told whether their row is the selected one, because a settings row
 * has two columns and highlighting them together is what makes it read as one row — a name
 * in bright text beside a value in grey reads as two separate things.
 */
final readonly class SettingsListTheme
{
    /**
     * @param Closure(string, bool): string $label       the left column, and whether it is selected
     * @param Closure(string, bool): string $value       the right column, same
     * @param Closure(string): string       $description the selected row's explanation
     * @param Closure(string): string       $hint        the keys line, and "(3/6)" when it scrolls
     */
    public function __construct(
        public Closure $label,
        public Closure $value,
        public Closure $description,
        public Closure $hint,
        public string $cursor = '→ ',
    ) {
    }

    /** A theme that reads on any terminal with sixteen colours. */
    public static function default(): self
    {
        return new self(
            label: static fn (string $text, bool $selected): string => $selected ? Style::cyan($text) : $text,
            value: static fn (string $text, bool $selected): string => $selected
                ? Style::cyan($text)
                : Style::dim($text),
            description: Style::dim(...),
            hint: Style::dim(...),
        );
    }
}
