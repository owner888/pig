<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use InvalidArgumentException;

/**
 * A colour, as the terminal in front of us can render it.
 *
 * A palette is written in hex because that is how anyone picking colours thinks, and a
 * terminal either takes hex directly (truecolor) or does not (256-colour), in which case
 * the nearest of 256 fixed colours has to be chosen. That choice is what most of this
 * file is, and it is worth getting right: the lazy answer — snap each channel to the
 * 6×6×6 cube — turns every near-grey into a visible tint.
 *
 * Ported from upstream's `theme.ts`.
 */
final class Colour
{
    /** The channel values the 216-colour cube is built from. */
    private const array CUBE = [0, 95, 135, 175, 215, 255];

    /** How dark each of the 24 greys at the end of the palette is. */
    private const array GREYS = [
        8, 18, 28, 38, 48, 58, 68, 78, 88, 98, 108, 118,
        128, 138, 148, 158, 168, 178, 188, 198, 208, 218, 228, 238,
    ];

    /**
     * Whether this terminal takes 24-bit colour.
     *
     * Asked of the environment rather than of the terminal, because the terminal's own
     * answer would arrive on stdin and there is nothing here that could wait for it.
     */
    public static function truecolor(): bool
    {
        if (in_array(getenv('COLORTERM'), ['truecolor', '24bit'], true)) {
            return true;
        }

        // Windows Terminal takes 24-bit colour and says so nowhere else.
        return getenv('WT_SESSION') !== false;
    }

    /**
     * The escape that turns the foreground $colour on.
     *
     * @param string|int $colour '#rrggbb', a palette index, or '' for the default
     */
    public static function foreground(string|int $colour, bool $truecolor): string
    {
        return self::escape($colour, $truecolor, 38, "\e[39m");
    }

    /** @param string|int $colour as above */
    public static function background(string|int $colour, bool $truecolor): string
    {
        return self::escape($colour, $truecolor, 48, "\e[49m");
    }

    /**
     * The closest of the 256 palette colours to this one.
     *
     * Both the colour cube and the grey ramp are searched, and the grey only wins when
     * the colour was nearly neutral to begin with. Without that guard a slightly warm
     * grey snaps to a pure grey and the theme loses its tint; with it, a colour with any
     * real saturation keeps its hue even when a grey happens to be numerically nearer.
     */
    public static function to256(int $red, int $green, int $blue): int
    {
        $redIndex = self::nearest(self::CUBE, $red);
        $greenIndex = self::nearest(self::CUBE, $green);
        $blueIndex = self::nearest(self::CUBE, $blue);

        $cubeDistance = self::distance(
            $red,
            $green,
            $blue,
            self::CUBE[$redIndex],
            self::CUBE[$greenIndex],
            self::CUBE[$blueIndex],
        );

        $luma = (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue);
        $greyIndex = self::nearest(self::GREYS, $luma);
        $grey = self::GREYS[$greyIndex];
        $greyDistance = self::distance($red, $green, $blue, $grey, $grey, $grey);

        $spread = max($red, $green, $blue) - min($red, $green, $blue);

        return $spread < 10 && $greyDistance < $cubeDistance
            ? 232 + $greyIndex
            : 16 + 36 * $redIndex + 6 * $greenIndex + $blueIndex;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $digits = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $digits) !== 1) {
            throw new InvalidArgumentException("Not a colour: {$hex}");
        }

        return [
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
        ];
    }

    private static function escape(string|int $colour, bool $truecolor, int $layer, string $default): string
    {
        if ($colour === '') {
            return $default;
        }

        if (is_int($colour)) {
            return "\e[{$layer};5;{$colour}m";
        }

        [$red, $green, $blue] = self::rgb($colour);

        return $truecolor
            ? "\e[{$layer};2;{$red};{$green};{$blue}m"
            : "\e[{$layer};5;" . self::to256($red, $green, $blue) . 'm';
    }

    /**
     * Human eyes weigh green most and blue least, so a plain Euclidean distance picks
     * the wrong neighbour for anything green.
     */
    private static function distance(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float
    {
        return ($r1 - $r2) ** 2 * 0.299 + ($g1 - $g2) ** 2 * 0.587 + ($b1 - $b2) ** 2 * 0.114;
    }

    /**
     * @param list<int> $values
     * @return int the index of the closest one
     */
    private static function nearest(array $values, int $wanted): int
    {
        $best = 0;
        $closest = PHP_INT_MAX;

        foreach ($values as $index => $value) {
            $distance = abs($wanted - $value);

            if ($distance < $closest) {
                $closest = $distance;
                $best = $index;
            }
        }

        return $best;
    }
}
