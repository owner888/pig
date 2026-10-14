<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Colour;

/**
 * The "Pig" wordmark at the top of the startup header: upstream's `piWordmark()`, which colours
 * "P" and "i" with the logo's coral and yellow. Upstream's header draws the half-block logo only
 * where the terminal renders it and this wordmark everywhere else; pig draws the wordmark alone,
 * as the developer asked — the pixel pig is gone.
 *
 * The brand colours stay fixed across themes, as upstream's do; they follow the terminal's colour
 * mode (24-bit, or the nearest of the 256).
 */
final class PigLogo
{
    private const string RESET = "\x1b[0m";

    /** One pink per letter, darkest first: rose 500, pink 400, pink 500. */
    private const array LETTERS = [
        ['P', '#f43f5e'],
        ['i', '#f472b6'],
        ['g', '#ec4899'],
    ];

    public static function wordmark(): string
    {
        $truecolor = Colour::truecolor();
        $out = '';

        foreach (self::LETTERS as [$letter, $colour]) {
            $out .= Colour::foreground($colour, $truecolor) . $letter . self::RESET;
        }

        return $out;
    }
}
