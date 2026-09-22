<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * Colour and emphasis, as SGR sequences.
 *
 * Upstream uses chalk. This is the part of chalk a TUI actually needs: wrap a string in a
 * style and close it again. Every method is `(string): string`, which is the shape the
 * components take as a theme — `Style::dim(...)` makes a first-class callable out of any
 * of them, and `Style::of()` builds one for a combination.
 *
 * Nothing here checks whether the terminal supports colour. That decision belongs to
 * whoever assembles the theme, not to the function drawing one line.
 */
final class Style
{
    private const string RESET = "\x1b[0m";

    /**
     * What turns each attribute back off again.
     *
     * Not `\e[0m`. A full reset closes everything that was open, including a colour
     * somebody else opened around this text — so a bold word inside a red line would
     * leave the rest of that line un-red. Each attribute has its own off-code, and
     * closing with that one leaves everything else exactly as it was found.
     */
    private const string FOREGROUND_OFF = "\x1b[39m";

    private const string BACKGROUND_OFF = "\x1b[49m";

    /** @var array<string, int> */
    private const array NAMED = [
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,
        'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'brightBlack' => 90, 'brightRed' => 91, 'brightGreen' => 92, 'brightYellow' => 93,
        'brightBlue' => 94, 'brightMagenta' => 95, 'brightCyan' => 96, 'brightWhite' => 97,
    ];

    /**
     * A style built from raw SGR parameters, as a callable.
     *
     * The only one here that closes with a full reset: it is handed arbitrary codes, so
     * it cannot know which off-code belongs to each of them. A combination is a leaf
     * style — use the named methods to nest one inside another.
     *
     * @param int|string ...$codes e.g. `1, 31` for bold red, or `'38;5;240'`
     * @return Closure(string): string
     */
    public static function of(int|string ...$codes): Closure
    {
        $open = "\x1b[" . implode(';', $codes) . 'm';

        return static fn (string $text): string => $open . $text . self::RESET;
    }

    /** Text in one of the sixteen named colours. */
    public static function colour(string $name, string $text): string
    {
        $code = self::NAMED[$name] ?? throw new TuiError("Unknown colour '{$name}'");

        return "\x1b[{$code}m{$text}" . self::FOREGROUND_OFF;
    }

    /** Background in one of the sixteen named colours. */
    public static function onColour(string $name, string $text): string
    {
        $code = self::NAMED[$name] ?? throw new TuiError("Unknown colour '{$name}'");

        return "\x1b[" . ($code + 10) . "m{$text}" . self::BACKGROUND_OFF;
    }

    public static function black(string $text): string
    {
        return self::colour('black', $text);
    }

    public static function red(string $text): string
    {
        return self::colour('red', $text);
    }

    public static function green(string $text): string
    {
        return self::colour('green', $text);
    }

    public static function yellow(string $text): string
    {
        return self::colour('yellow', $text);
    }

    public static function blue(string $text): string
    {
        return self::colour('blue', $text);
    }

    public static function magenta(string $text): string
    {
        return self::colour('magenta', $text);
    }

    public static function cyan(string $text): string
    {
        return self::colour('cyan', $text);
    }

    public static function white(string $text): string
    {
        return self::colour('white', $text);
    }

    public static function gray(string $text): string
    {
        return self::colour('brightBlack', $text);
    }

    public static function bold(string $text): string
    {
        return "\x1b[1m{$text}\x1b[22m";
    }

    public static function dim(string $text): string
    {
        return "\x1b[2m{$text}\x1b[22m";
    }

    public static function italic(string $text): string
    {
        return "\x1b[3m{$text}\x1b[23m";
    }

    public static function underline(string $text): string
    {
        return "\x1b[4m{$text}\x1b[24m";
    }

    public static function inverse(string $text): string
    {
        return "\x1b[7m{$text}\x1b[27m";
    }

    public static function strikethrough(string $text): string
    {
        return "\x1b[9m{$text}\x1b[29m";
    }

    /** One of the 256 palette colours. */
    public static function ansi256(int $colour, string $text): string
    {
        return "\x1b[38;5;" . self::checkByte($colour) . "m{$text}" . self::FOREGROUND_OFF;
    }

    public static function onAnsi256(int $colour, string $text): string
    {
        return "\x1b[48;5;" . self::checkByte($colour) . "m{$text}" . self::BACKGROUND_OFF;
    }

    public static function rgb(int $red, int $green, int $blue, string $text): string
    {
        return "\x1b[38;2;" . self::rgbParameters($red, $green, $blue) . "m{$text}" . self::FOREGROUND_OFF;
    }

    public static function onRgb(int $red, int $green, int $blue, string $text): string
    {
        return "\x1b[48;2;" . self::rgbParameters($red, $green, $blue) . "m{$text}" . self::BACKGROUND_OFF;
    }

    private static function rgbParameters(int $red, int $green, int $blue): string
    {
        return self::checkByte($red) . ';' . self::checkByte($green) . ';' . self::checkByte($blue);
    }

    /**
     * An out-of-range value would emit a sequence the terminal reads as something else
     * entirely — `\e[38;5;300m` is not a dark colour, it is a parse error mid-line.
     */
    private static function checkByte(int $value): int
    {
        if ($value < 0 || $value > 255) {
            throw new TuiError("Colour component must be 0-255, got {$value}");
        }

        return $value;
    }
}
