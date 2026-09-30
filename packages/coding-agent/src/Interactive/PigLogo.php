<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Colour;

/**
 * The pig mascot logo: 4 cells wide and 2 lines tall, matching upstream pi-logo.ts.
 *
 * Each cell displays two square pixels using Unicode half blocks (▀, U+2580):
 *
 *   [deep pink ear]   [soft pink head]    [soft pink head]    [deep pink ear]
 *   [soft pink cheek] [dark eye]          [dark eye]          [soft pink cheek]
 *   --------------------------------------------------------------------------
 *   [soft pink cheek] [deep pink snout]   [deep pink snout]   [soft pink cheek]
 *   [transparent]     [dark nostril]      [dark nostril]      [transparent]
 *
 * Rendered using vibrant 24-bit TrueColor pinks, with automatic fallback for 256-color terminals.
 */
final class PigLogo
{
    private const string RESET = "\x1b[0m";

    // Pig mascot palette:
    private const string EAR = '#f43f5e';    // Rose 500 (Vibrant ear tip)
    private const string FACE = '#f9a8d4';   // Pink 300 (Soft pink cheeks & forehead)
    private const string SNOUT = '#ec4899';  // Pink 500 (Signature pig snout)
    private const string EYE = '#0f172a';    // Slate 900 (Cute dark eyes & nostrils)

    /**
     * @return array{0: string, 1: string} top and bottom lines of the pig logo
     */
    public static function lines(): array
    {
        $truecolor = Colour::truecolor();

        $fgEar = Colour::foreground(self::EAR, $truecolor);
        $fgFace = Colour::foreground(self::FACE, $truecolor);
        $fgSnout = Colour::foreground(self::SNOUT, $truecolor);

        $bgFace = Colour::background(self::FACE, $truecolor);
        $bgEye = Colour::background(self::EYE, $truecolor);

        // Top line: ears, forehead, and two cute dark eyes
        $top = $fgEar . $bgFace . '▀' . self::RESET
            . $fgFace . $bgEye . '▀' . self::RESET
            . $fgFace . $bgEye . '▀' . self::RESET
            . $fgEar . $bgFace . '▀' . self::RESET;

        // Bottom line: cheeks, snout, and two dark nostrils
        $bottom = $fgFace . '▀' . self::RESET
            . $fgSnout . $bgEye . '▀' . self::RESET
            . $fgSnout . $bgEye . '▀' . self::RESET
            . $fgFace . '▀' . self::RESET;

        return [$top, $bottom];
    }
}
