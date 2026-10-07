<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Colour;
use Pig\Test\AssertsThrows;

/** `Colour`: the arithmetic that fits a hex colour onto a 256-colour terminal, used by the pig logo. */
final class ColourTest extends TestCase
{
    use AssertsThrows;

    // ---- picking a colour the terminal has ------------------------------------------

    public function testTruecolorSendsTheHexStraightThrough(): void
    {
        $this->assertSame("\e[38;2;138;190;183m", Colour::foreground('#8abeb7', true));
        $this->assertSame("\e[48;2;58;58;74m", Colour::background('#3a3a4a', true));
    }

    public function testAnEmptyColourMeansTheTerminalsOwn(): void
    {
        // `"text": ""` in the theme is "leave it alone", not "black".
        $this->assertSame("\e[39m", Colour::foreground('', true));
        $this->assertSame("\e[49m", Colour::background('', false));
    }

    public function testAPaletteIndexIsUsedAsGiven(): void
    {
        $this->assertSame("\e[38;5;42m", Colour::foreground(42, true));
    }

    public function testWithoutTruecolorTheNearestOf256IsChosen(): void
    {
        // #ff0000 is in the cube exactly: 16 + 36*5 = 196.
        $this->assertSame(196, Colour::to256(255, 0, 0));
        $this->assertSame(16, Colour::to256(0, 0, 0));
        $this->assertSame(231, Colour::to256(255, 255, 255));
    }

    public function testANeutralGreyGoesToTheGreyRamp(): void
    {
        // The ramp has far more steps than the cube's six, so a grey that is not one of
        // those six lands much closer on it.
        $index = Colour::to256(128, 128, 128);

        $this->assertGreaterThanOrEqual(232, $index);
        $this->assertLessThanOrEqual(255, $index);
    }

    public function testATintedGreyKeepsItsTint(): void
    {
        // A grey ramp entry would be numerically closer here, and taking it would drain
        // the colour out of every muted tone in the theme.
        $this->assertLessThan(232, Colour::to256(120, 128, 140));
    }

    public function testNotAColourIsAnError(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Colour::rgb('#12345'),
            'Not a colour',
        );
    }

    // ---- what the environment says the terminal can do ------------------------------

    public function testTruecolorIsReadOffColorterm(): void
    {
        $saved = [getenv('COLORTERM'), getenv('WT_SESSION')];
        putenv('WT_SESSION');

        try {
            putenv('COLORTERM=truecolor');
            $this->assertTrue(Colour::truecolor());

            putenv('COLORTERM=24bit');
            $this->assertTrue(Colour::truecolor());

            putenv('COLORTERM=');
            $this->assertFalse(Colour::truecolor());
        } finally {
            self::restore(['COLORTERM' => $saved[0], 'WT_SESSION' => $saved[1]]);
        }
    }

    public function testWindowsTerminalIsRecognisedButNotByAnEmptyVariable(): void
    {
        $saved = [getenv('COLORTERM'), getenv('WT_SESSION')];
        putenv('COLORTERM');

        try {
            putenv('WT_SESSION=8a1b');
            $this->assertTrue(Colour::truecolor());

            // Exported without a value is not Windows Terminal: `getenv()` answers '' rather
            // than false, and upstream reads the same variable through JS truthiness.
            putenv('WT_SESSION=');
            $this->assertFalse(Colour::truecolor());
        } finally {
            self::restore(['COLORTERM' => $saved[0], 'WT_SESSION' => $saved[1]]);
        }
    }

    /** @param array<string, string|false> $variables */
    private static function restore(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}
