<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Colors;
use Pig\Tui\OklchColorValue;
use Pig\Tui\RgbColor;
use Pig\Tui\RgbColorValue;
use Pig\Tui\TextStyle;

/** Upstream's `colors.test.ts`. */
final class ColorsTest extends TestCase
{
    private function assertThrowsMatching(string $pattern, \Closure $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $error) {
            $this->assertMatchesRegularExpression($pattern, $error->getMessage());

            return;
        }
        $this->fail("Expected a throw matching {$pattern}");
    }

    public function testParsesHexAndOklchColorsAndRejectsEverythingElse(): void
    {
        $this->assertEquals(new RgbColorValue(170, 187, 204), Colors::parseColor('#abc'));
        $this->assertEquals(new OklchColorValue(0.62, 0.1, 200), Colors::parseColor('oklch(62% 0.1 200)'));
        $this->assertThrowsMatching('/Invalid color value/', static fn () => Colors::parseColor(''));
        $this->assertThrowsMatching('/Invalid color value/', static fn () => Colors::parseColor('red'));
    }

    public function testGamutMapsOklchToSrgbIncludingTheLightnessLimits(): void
    {
        $this->assertEquals(new RgbColor(255, 0, 0), Colors::colorToRgb(Colors::oklchColor(0.627955, 0.257683, 29.2339)));
        $this->assertEquals(new RgbColor(255, 255, 255), Colors::colorToRgb(Colors::oklchColor(1, 0.3, 150)));
        $this->assertEquals(new RgbColor(0, 0, 0), Colors::colorToRgb(Colors::oklchColor(0, 0.3, 150)));
    }

    public function testParsesOkhslColorsAndRoundTripsThem(): void
    {
        // Full saturation at the red cusp is pure sRGB red.
        $this->assertEquals(Colors::rgbColor(255, 0, 0), Colors::parseColor('okhsl(29.23 100% 56.8%)'));
        $this->assertEquals(Colors::okhslColor(250, 0.6, 0.55), Colors::parseColor('OKHSL(250deg 60% 55%)'));
        $this->assertThrowsMatching('/s must be between 0 and 1/', static fn () => Colors::parseColor('okhsl(250 160% 55%)'));
        foreach (['#4f8eb3', '#20242a', '#f8f9fa'] as $hex) {
            $okhsl = Colors::colorToOkhsl(Colors::parseColor($hex));
            $this->assertSame($hex, Colors::colorToHex(Colors::okhslColor($okhsl->h, $okhsl->s, $okhsl->l)));
        }
    }

    public function testStylesTextAndClosesSequencesInReverseOrder(): void
    {
        $this->assertSame(
            "\x1b[38;2;18;52;86m\x1b[48;5;9m\x1b[1m\x1b[3mReady\x1b[23m\x1b[22m\x1b[49m\x1b[39m",
            Colors::styleText('Ready', new TextStyle(fg: Colors::rgbColor(18, 52, 86), bg: Colors::indexedColor(9), bold: true, italic: true), 'truecolor'),
        );
        $this->assertMatchesRegularExpression(
            '/^\x1b\[38;5;\d+mReady\x1b\[39m$/',
            Colors::styleText('Ready', new TextStyle(fg: Colors::rgbColor(18, 52, 86)), '256color'),
        );
    }
}
