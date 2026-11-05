<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\CapturesViewportInput;
use Pig\Tui\Component;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SelectListLayout;
use Pig\Tui\Components\SettingItem;
use Pig\Tui\Components\SettingsList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;

/**
 * The `/settings` theme row's submenu — upstream's `ThemeSubmenu` in `settings-selector.ts`.
 *
 * Two screens. **Single**: one theme for everything, picked from a list whose first entries are
 * the system theme and `automatic`. **Automatic**: a theme for when the terminal is light and
 * one for when it is dark, saved as the `light/dark` pair `Themes::parseAutoThemeSetting()`
 * reads and the theme controller already follows — the setting existed; this is its screen.
 * Moving the cursor previews; Escape puts the original setting back; Enter (single) or Apply
 * (automatic) hands the setting to `$done`, which saves it.
 */
final class ThemeSubmenu extends Container implements InputHandler, CapturesViewportInput
{
    /** The `automatic` entry's value in the single list: no theme is named with a slash. */
    private const string AUTOMATIC = '/';

    private ?Component $input = null;

    private string $single;

    private string $light;

    private string $dark;

    private bool $automatic;

    /**
     * @param list<string> $themes the names there are, the system theme among them
     * @param Closure(string): void $preview draw a theme or a `light/dark` pair, without saving it
     * @param Closure(?string): void $done the setting to save, or null for Escape
     */
    public function __construct(
        private readonly string $original,
        private readonly array $themes,
        private readonly Closure $preview,
        private readonly Closure $done,
    ) {
        $pair = Themes::parseAutoThemeSetting($original);
        $fixed = $pair === null && !str_contains($original, '/') ? $original : null;
        $this->automatic = $pair !== null;
        $this->light = $pair['lightTheme'] ?? $this->preferred($fixed);
        $this->dark = $pair['darkTheme'] ?? $this->preferred($fixed);
        $this->single = $this->preferred($fixed ?? ($pair !== null ? $this->activeAutomatic() : null));

        $this->automatic ? $this->showAutomatic() : $this->showSingle();
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->input instanceof InputHandler) {
            $this->input->handleInput($data);
        }
    }

    #[\Override]
    public function capturesViewportInput(): bool
    {
        return true;
    }

    private function showSingle(): void
    {
        $this->automatic = false;
        $items = [];
        $at = 0;

        // The system theme first, then automatic, then the rest — upstream's `singleModeThemeItems()`.
        $names = array_values(array_filter($this->themes, static fn (string $n): bool => $n !== Themes::SYSTEM_THEME_NAME));
        $ordered = in_array(Themes::SYSTEM_THEME_NAME, $this->themes, true) ? [Themes::SYSTEM_THEME_NAME, self::AUTOMATIC, ...$names] : [self::AUTOMATIC, ...$names];

        foreach ($ordered as $index => $name) {
            if ($name === self::AUTOMATIC) {
                $items[] = new SelectItem(self::AUTOMATIC, 'automatic', 'Use separate themes for light and dark terminal appearance');

                continue;
            }

            $items[] = new SelectItem($name, $name . ($name === $this->single ? ' ·' : ''), self::description($name));

            if ($name === $this->single) {
                $at = $index;
            }
        }

        $list = new SelectList($items, min(count($items), 10), Themes::getSelectListTheme(), SelectListLayout::compact());
        $list->setSelectedIndex($at);
        $list->setSelectionChangeHandler(function (SelectItem $item): void {
            ($this->preview)($item->value === self::AUTOMATIC ? $this->pair() : $item->value);
        });
        $list->setSelectHandler(function (SelectItem $item): void {
            if ($item->value === self::AUTOMATIC) {
                ($this->preview)($this->pair());
                $this->showAutomatic();

                return;
            }

            $this->single = $item->value;
            ($this->done)($item->value);
        });
        $list->setCancelHandler($this->cancel(...));

        $this->show(new SettingsSubmenu(
            $list,
            Themes::theme()->fg('accent', 'Theme'),
            Themes::theme()->fg('muted', '  Enter to select · Esc to go back'),
        ), $list);
    }

    private function showAutomatic(): void
    {
        $this->automatic = true;
        $theme = Themes::theme();

        $rows = [
            new SettingItem('light-theme', 'Light theme', $this->light, 'Theme to use in automatic mode when the terminal is light',
                submenu: fn (string $current, Closure $done): Component => $this->pick('Light Theme', $current, $done, function (string $name): void {
                    $this->light = $name;
                })),
            new SettingItem('dark-theme', 'Dark theme', $this->dark, 'Theme to use in automatic mode when the terminal is dark',
                submenu: fn (string $current, Closure $done): Component => $this->pick('Dark Theme', $current, $done, function (string $name): void {
                    $this->dark = $name;
                })),
            new SettingItem('apply', 'Apply', 'save and go back', 'Save and go back', values: ['save and go back']),
            new SettingItem('single-mode', 'Change mode', 'switch to single theme', 'Switch to one theme for light and dark', values: ['switch to single theme']),
        ];

        $list = new SettingsList($rows, count($rows), Themes::getSettingsListTheme());
        $list->setChangeHandler(function (string $id): void {
            match ($id) {
                'apply' => ($this->done)($this->pair()),
                'single-mode' => (function (): void {
                    $this->single = $this->activeAutomatic();
                    ($this->preview)($this->single);
                    $this->showSingle();
                })(),
                default => null,
            };
        });
        $list->setCloseHandler($this->cancel(...));

        $content = new Container();
        $content->addChild(new Text($theme->bold($theme->fg('accent', 'Automatic Theme')), 0, 0));
        $content->addChild(new Spacer(1));
        $content->addChild(new Text($theme->fg('muted', 'Choose themes for terminal light and dark appearance.'), 0, 0));
        $content->addChild(new Text($theme->fg('muted', 'Light/dark detection requires terminal support.'), 0, 0));
        $content->addChild(new Spacer(1));
        $content->addChild($list);

        $this->show($content, $list);
    }

    /** One of the two automatic rows' pickers: every theme, previewed as the cursor moves. @param Closure(string): void $chosen */
    private function pick(string $title, string $current, Closure $done, Closure $chosen): Component
    {
        $items = [];
        $at = 0;

        foreach ($this->themes as $index => $name) {
            $items[] = new SelectItem($name, $name . ($name === $current ? ' ·' : ''), self::description($name));

            if ($name === $current) {
                $at = $index;
            }
        }

        $list = new SelectList($items, min(count($items), 10), Themes::getSelectListTheme(), SelectListLayout::compact());
        $list->setSelectedIndex($at);
        $list->setSelectionChangeHandler(function (SelectItem $item): void {
            ($this->preview)($item->value);
        });
        $list->setSelectHandler(function (SelectItem $item) use ($chosen, $done): void {
            $chosen($item->value);
            ($this->preview)($this->pair());
            $done($item->value);
        });
        $list->setCancelHandler(function () use ($done): void {
            ($this->preview)($this->pair());
            $done(null);
        });

        return new SettingsSubmenu(
            $list,
            Themes::theme()->fg('accent', $title),
            Themes::theme()->fg('muted', '  Enter to select · Esc to go back'),
        );
    }

    private function show(Component $content, Component $input): void
    {
        $this->clear();
        $this->addChild($content);
        $this->input = $input;
    }

    private function cancel(): void
    {
        ($this->preview)($this->original);
        ($this->done)(null);
    }

    private function pair(): string
    {
        return "{$this->light}/{$this->dark}";
    }

    /** The automatic theme the terminal is showing now. */
    private function activeAutomatic(): string
    {
        return Themes::getTerminalTheme() === 'light' ? $this->light : $this->dark;
    }

    /** `$name` when it is a theme there is, else the system theme, else the first — upstream's `preferredTheme()`. */
    private function preferred(?string $name): string
    {
        if ($name !== null && in_array($name, $this->themes, true)) {
            return $name;
        }

        if (in_array(Themes::SYSTEM_THEME_NAME, $this->themes, true)) {
            return Themes::SYSTEM_THEME_NAME;
        }

        return $this->themes[0] ?? Themes::SYSTEM_THEME_NAME;
    }

    public static function description(string $name): string
    {
        return match ($name) {
            Themes::SYSTEM_THEME_NAME => "Generated from the terminal's own colours",
            'dark' => 'Default dark theme',
            'light' => 'Default light theme',
            'labra' => 'Cyberpunk dark olive & hot pink theme (from omarchy-labra)',
            default => 'Custom theme',
        };
    }
}
