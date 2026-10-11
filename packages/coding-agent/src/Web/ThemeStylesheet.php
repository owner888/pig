<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Theme\Themes;

/**
 * The web UI's colours, written from the TUI theme files so the page and the terminal show the same
 * theme: `/theme.css`, one `:root[data-theme="<name>"]` block of `--color-*` per theme, and
 * `/api/themes`, the list the theme picker shows.
 *
 * Every theme colour is there as `--color-<kebab-case name>` (`userMessageBg` is
 * `--color-user-message-bg`), and the page's semantic tokens (SEMANTIC) are mapped onto them.
 * Where a name is both, the semantic one wins: `--color-border` is the theme's `borderMuted`, and
 * the theme's `border` is `--color-primary`.
 */
final class ThemeStylesheet
{
    /** The theme a browser shows before it has picked one. */
    public const string DEFAULT_THEME = 'dark';

    /** Semantic token => theme colour. */
    private const array SEMANTIC = [
        'primary' => 'border',
        'secondary' => 'muted',
        'accent' => 'accent',
        'success' => 'success',
        'warning' => 'warning',
        'error' => 'error',
        'info' => 'mdLink',
        'text-primary' => 'text',
        'text-secondary' => 'muted',
        'border' => 'borderMuted',
        'disabled' => 'dim',
    ];

    /**
     * Every theme the TUI lists but `system`, which is generated from the colours the terminal
     * reports, and a browser has no terminal.
     *
     * @return list<string>
     */
    public static function themeNames(): array
    {
        return array_values(array_filter(
            Themes::getAvailableThemes(),
            static fn (string $name): bool => $name !== Themes::SYSTEM_THEME_NAME,
        ));
    }

    /** @return array<string, string> token name without `--color-` => CSS colour */
    public static function tokens(string $theme): array
    {
        $colors = Themes::getResolvedThemeColors($theme);
        $tokens = [];
        foreach ($colors as $name => $value) {
            $tokens[strtolower((string) preg_replace('/[A-Z]/', '-$0', $name))] = $value;
        }
        foreach (self::SEMANTIC as $token => $color) {
            $tokens[$token] = $colors[$color];
        }

        // As upstream's export: the theme's `export` section, else derived from `userMessageBg`.
        $export = Themes::getThemeExportColors($theme);
        $derived = HtmlExport::deriveExportColors($colors['userMessageBg']);
        $tokens['background'] = $export['pageBg'] ?? $derived['pageBg'];
        $tokens['surface'] = $export['cardBg'] ?? $derived['cardBg'];
        // Popovers are told apart from cards by their shadow, not by a colour of their own.
        $tokens['surface-elevated'] = $tokens['surface'];
        $tokens['text-on-primary'] = $tokens['background'];

        return $tokens;
    }

    public static function css(): string
    {
        $css = '';
        foreach (self::themeNames() as $name) {
            $selector = ':root[data-theme="' . addcslashes($name, '"\\') . '"]';
            if ($name === self::DEFAULT_THEME) {
                $selector .= ', :root:not([data-theme])';
            }
            $css .= "{$selector} {\n  color-scheme: " . (Themes::isLightTheme($name) ? 'light' : 'dark') . ";\n";
            foreach (self::tokens($name) as $token => $value) {
                $css .= "  --color-{$token}: {$value};\n";
            }
            $css .= "}\n";
        }

        return $css;
    }

    /** @return array{default: string, themes: list<array{name: string, appearance: string, swatches: list<string>}>} */
    public static function payload(): array
    {
        $themes = [];
        foreach (self::themeNames() as $name) {
            $tokens = self::tokens($name);
            $themes[] = [
                'name' => $name,
                'appearance' => Themes::isLightTheme($name) ? 'light' : 'dark',
                'swatches' => [$tokens['background'], $tokens['surface'], $tokens['primary'], $tokens['accent']],
            ];
        }

        return ['default' => self::DEFAULT_THEME, 'themes' => $themes];
    }
}
