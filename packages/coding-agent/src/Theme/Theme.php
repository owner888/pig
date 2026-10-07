<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Closure;
use InvalidArgumentException;
use Pig\Agent\ThinkingLevel;
use Pig\Tui\Color;
use Pig\Tui\Colors;
use Pig\Tui\IndexedColor;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;
use Pig\Tui\TextAttributes;

/**
 * Upstream's `Theme` class in `theme.ts`. The module around it — loading, the global theme,
 * terminal colors, the TUI helpers — is `Themes`.
 *
 * Tokens are upstream's `ThemeColor` (foreground) and `ThemeBg` (background) names, as strings.
 * Upstream's `appearance` and `colors` getters are the methods `appearance()` and `colors()`.
 * Upstream's `TerminalColorMode` is `'truecolor'|'256color'` (see `Pig\Tui\Colors`).
 * Upstream's `sourceInfo` is not carried: pig has no `SourceInfo` yet (it arrives with the
 * resource loader).
 */
final class Theme
{
    /** Upstream's `OptionalThemeColor`: foreground tokens a theme may omit, with their fallbacks. */
    private const array OPTIONAL_FOREGROUNDS = [
        'scrollbarTrack' => 'muted',
        'scrollbarThumb' => 'text',
        'thinkingMax' => 'thinkingXhigh',
        'searchMatchText' => 'text',
    ];

    /** Assumed terminal default colors when the terminal does not report them. */
    private const array GUESSED_DEFAULT_COLORS = [
        'dark' => ['foreground' => '#e5e5e7', 'background' => '#000000'],
        'light' => ['foreground' => '#000000', 'background' => '#ffffff'],
    ];

    /** @var 'truecolor'|'256color' */
    private string $mode;

    /**
     * Precomputed escape sequences keep fg()/bg() on the render hot path to a lookup and concat.
     *
     * @var array<string, string>
     */
    private array $fgAnsi = [];

    /** @var array<string, string> */
    private array $bgAnsi = [];

    /**
     * Tokens set to "" have no color of their own; `colors()` fills them from the terminal defaults.
     *
     * @var array<string, Color>
     */
    private array $concreteColors = [];

    /** @var list<string> */
    private array $defaultForegroundTokens = [];

    /** @var list<string> */
    private array $defaultBackgroundTokens = [];

    /**
     * Foreground tokens rendered faint (SGR 2) on top of their color, as a set.
     *
     * @var array<string, true>
     */
    private array $dimTokens;

    /** @var 'dark'|'light'|null */
    private ?string $ownAppearance;

    /** @var array{terminal: TerminalColors, colors: array<string, Color>}|null */
    private ?array $resolvedColors = null;

    /**
     * @param array<string, string|int> $fgColors foreground token to color value (hex, OKLCH, OKHSL, 256-color index, or "" for the terminal default)
     * @param array<string, string|int> $bgColors background token to color value
     * @param 'truecolor'|'256color' $mode
     * @param 'dark'|'light'|null $appearance
     * @param list<string> $dim Foreground tokens to render faint (SGR 2).
     */
    public function __construct(
        array $fgColors,
        array $bgColors,
        string $mode,
        public readonly ?string $name = null,
        public readonly ?string $sourcePath = null,
        ?string $appearance = null,
        array $dim = [],
    ) {
        $this->mode = $mode;
        $this->dimTokens = array_fill_keys($dim, true);
        $foregrounds = $fgColors;
        foreach (self::OPTIONAL_FOREGROUNDS as $token => $fallback) {
            if (!array_key_exists($token, $foregrounds) && array_key_exists($fallback, $fgColors)) {
                $foregrounds[$token] = $fgColors[$fallback];
            }
        }
        $backgrounds = $bgColors;
        if (!array_key_exists('searchMatchBg', $backgrounds) && array_key_exists('selectedBg', $bgColors)) {
            $backgrounds['searchMatchBg'] = $bgColors['selectedBg'];
        }
        $concreteForegrounds = [];
        $concreteBackgrounds = [];
        // Returns the escape sequence for the token's own slot.
        $addToken = function (string $token, string|int $value, bool $isBackground) use ($mode, &$concreteForegrounds, &$concreteBackgrounds): string {
            if ($value === '') {
                if ($isBackground) {
                    $this->defaultBackgroundTokens[] = $token;
                } else {
                    $this->defaultForegroundTokens[] = $token;
                }

                return $isBackground ? "\x1b[49m" : "\x1b[39m";
            }
            $color = Colors::parseColor($value);
            $this->concreteColors[$token] = $color;
            if ($isBackground) {
                $concreteBackgrounds[] = $color;
            } else {
                $concreteForegrounds[] = $color;
            }

            return $isBackground ? Colors::backgroundAnsi($color, $mode) : Colors::foregroundAnsi($color, $mode);
        };
        foreach ($foregrounds as $token => $value) {
            $this->fgAnsi[$token] = $addToken($token, $value, false);
        }
        foreach ($backgrounds as $token => $value) {
            $this->bgAnsi[$token] = $addToken($token, $value, true);
        }
        $this->ownAppearance = $appearance ?? self::detectAppearance($concreteForegrounds, $concreteBackgrounds);
    }

    /**
     * @param list<Color> $colors
     */
    private static function averageLightness(array $colors): ?float
    {
        // Palette colors 0-15 follow the user's terminal palette, so they say nothing about the theme.
        $fixed = array_filter($colors, static fn (Color $color): bool => !$color instanceof IndexedColor || $color->index >= 16);
        if ($fixed === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($fixed as $color) {
            $sum += Colors::colorToOklch($color)->l;
        }

        return $sum / count($fixed);
    }

    /**
     * Detect the background a theme is designed for from the lightness of its own colors.
     *
     * @param list<Color> $foregrounds
     * @param list<Color> $backgrounds
     * @return 'dark'|'light'|null
     */
    private static function detectAppearance(array $foregrounds, array $backgrounds): ?string
    {
        $fg = self::averageLightness($foregrounds);
        $bg = self::averageLightness($backgrounds);
        if ($fg !== null && $bg !== null) {
            return $bg < $fg ? 'dark' : 'light';
        }
        if ($bg !== null) {
            return $bg < 0.5 ? 'dark' : 'light';
        }
        if ($fg !== null) {
            return $fg > 0.5 ? 'dark' : 'light';
        }

        return null;
    }

    /**
     * The background the theme is designed for: declared in the theme JSON, detected from its colors,
     * or, for themes without usable colors, the terminal's appearance. Upstream's `appearance` getter.
     *
     * @return 'dark'|'light'
     */
    public function appearance(): string
    {
        return $this->ownAppearance ?? Themes::getTerminalTheme();
    }

    /**
     * Concrete colors for all tokens. Tokens set to "" (terminal default) use the terminal's reported
     * default colors, or a guess based on `appearance()` when the terminal did not report them. Faint
     * tokens are approximated by mixing their color toward the background. Upstream's `colors` getter.
     *
     * @return array<string, Color>
     */
    public function colors(): array
    {
        $terminal = Themes::terminalColorsForThemes();
        if ($this->resolvedColors === null || $this->resolvedColors['terminal'] !== $terminal) {
            $guess = self::GUESSED_DEFAULT_COLORS[$this->appearance()];
            $toColor = static fn (?RgbColor $rgb, string $fallback): Color => $rgb !== null
                ? Colors::rgbColor($rgb->r, $rgb->g, $rgb->b)
                : Colors::parseColor($fallback);
            $foreground = $toColor($terminal->foreground, $guess['foreground']);
            $background = $toColor($terminal->background, $guess['background']);
            $colors = $this->concreteColors;
            foreach ($this->defaultForegroundTokens as $token) {
                $colors[$token] = $foreground;
            }
            foreach ($this->defaultBackgroundTokens as $token) {
                $colors[$token] = $background;
            }
            foreach (array_keys($this->dimTokens) as $token) {
                $color = $colors[$token] ?? null;
                if ($color !== null) {
                    $colors[$token] = Colors::mixColors($color, $background, 0.4);
                }
            }
            $this->resolvedColors = ['terminal' => $terminal, 'colors' => $colors];
        }

        return $this->resolvedColors['colors'];
    }

    /**
     * Tokens are only accepted in their own slot, because "" (terminal default) means the default foreground
     * or background depending on the slot. Use `colors()[$token]` to use a token's color in the other slot.
     */
    public function style(string $text, ThemeStyle $options): string
    {
        $fg = $options->fg;
        $bg = $options->bg;
        $attributes = $options;
        if (is_string($fg) && isset($this->dimTokens[$fg])) {
            $attributes = new TextAttributes(
                bold: $options->bold,
                dim: true,
                italic: $options->italic,
                underline: $options->underline,
                inverse: $options->inverse,
                strikethrough: $options->strikethrough,
            );
        }

        return Colors::styleTextWithAnsi(
            $text,
            $fg === null ? null : (is_string($fg) ? $this->tokenAnsi($this->fgAnsi, $fg) : Colors::foregroundAnsi($fg, $this->mode)),
            $bg === null ? null : (is_string($bg) ? $this->tokenAnsi($this->bgAnsi, $bg) : Colors::backgroundAnsi($bg, $this->mode)),
            $attributes,
        );
    }

    public function fg(string $color, string $text): string
    {
        $ansi = $this->tokenAnsi($this->fgAnsi, $color);
        if (isset($this->dimTokens[$color])) {
            return "{$ansi}\x1b[2m{$text}\x1b[22;39m";
        }

        return "{$ansi}{$text}\x1b[39m";
    }

    public function bg(string $color, string $text): string
    {
        $ansi = $this->tokenAnsi($this->bgAnsi, $color);

        return "{$ansi}{$text}\x1b[49m";
    }

    /** @param array<string, string> $ansi */
    private function tokenAnsi(array $ansi, string $token): string
    {
        return $ansi[$token] ?? throw new InvalidArgumentException("Unknown theme color: {$token}");
    }

    /** Upstream's `chalk.bold()`: SGR 1, closed with 22. */
    public function bold(string $text): string
    {
        return "\x1b[1m{$text}\x1b[22m";
    }

    public function italic(string $text): string
    {
        return "\x1b[3m{$text}\x1b[23m";
    }

    public function underline(string $text): string
    {
        return "\x1b[4m{$text}\x1b[24m";
    }

    public function inverse(string $text): string
    {
        return "\x1b[7m{$text}\x1b[27m";
    }

    public function strikethrough(string $text): string
    {
        return "\x1b[9m{$text}\x1b[29m";
    }

    /** Opening escape sequence for a foreground token. Faint tokens include SGR 2, which `\x1b[22m` closes. */
    public function getFgAnsi(string $color): string
    {
        $ansi = $this->tokenAnsi($this->fgAnsi, $color);

        return isset($this->dimTokens[$color]) ? "{$ansi}\x1b[2m" : $ansi;
    }

    public function getBgAnsi(string $color): string
    {
        return $this->tokenAnsi($this->bgAnsi, $color);
    }

    /** @return 'truecolor'|'256color' */
    public function getColorMode(): string
    {
        return $this->mode;
    }

    /** @return Closure(string): string */
    public function getThinkingBorderColor(ThinkingLevel $level): Closure
    {
        // Map thinking levels to dedicated theme colors. Matched on the value, as upstream's string
        // union: pig's `ThinkingLevel` has no `max` case yet, and its arm waits for it.
        return match ($level->value) {
            'off' => fn (string $str): string => $this->fg('thinkingOff', $str),
            'minimal' => fn (string $str): string => $this->fg('thinkingMinimal', $str),
            'low' => fn (string $str): string => $this->fg('thinkingLow', $str),
            'medium' => fn (string $str): string => $this->fg('thinkingMedium', $str),
            'high' => fn (string $str): string => $this->fg('thinkingHigh', $str),
            'xhigh' => fn (string $str): string => $this->fg('thinkingXhigh', $str),
            'max' => fn (string $str): string => $this->fg('thinkingMax', $str),
            default => fn (string $str): string => $this->fg('thinkingOff', $str),
        };
    }

    /** @return Closure(string): string */
    public function getBashModeBorderColor(): Closure
    {
        return fn (string $str): string => $this->fg('bashMode', $str);
    }
}
