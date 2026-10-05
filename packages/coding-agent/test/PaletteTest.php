<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\CodingAgent\Theme\Colour;
use Pig\CodingAgent\Theme\Palette;
use Pig\Test\AssertsThrows;
use Pig\Tui\Ansi;

/** The named colours, and the arithmetic that fits them onto a 256-colour terminal. */
final class PaletteTest extends TestCase
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

    // ---- the named colours -----------------------------------------------------------

    public function testBothThemesDefineTheSameNames(): void
    {
        // A component asking for a colour must not work on one theme and throw on the
        // other, which is the only way this can go wrong.
        $dark = Palette::dark(true);
        $light = Palette::light(true);
        $labra = Palette::labra(true);

        foreach (self::NAMES as $name) {
            $this->assertSame('x', Ansi::strip($dark->fg($name, 'x')), "dark {$name}");
            $this->assertSame('x', Ansi::strip($light->fg($name, 'x')), "light {$name}");
            $this->assertSame('x', Ansi::strip($labra->fg($name, 'x')), "labra {$name}");
        }
    }

    public function testAColourResetsOnlyItsOwnLayer(): void
    {
        // So that a colour used inside something bold, or on a background, does not
        // cancel it on the way out.
        $this->assertStringEndsWith("\e[39m", Palette::dark(true)->fg('accent', 'x'));
        $this->assertStringEndsWith("\e[49m", Palette::dark(true)->bg('selectedBg', 'x'));
    }

    public function testAVariableIsFollowedToItsHex(): void
    {
        // dark's `border` is #5fa8cc (95, 168, 204).
        $this->assertStringStartsWith("\e[38;2;95;168;204m", Palette::dark(true)->fg('border', 'x'));
    }

    public function testAnUnknownColourIsAnErrorRatherThanNothing(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Palette::dark(true)->fg('chartreuse', 'x'),
            "No colour called 'chartreuse'",
        );
    }

    public function testOfFailsAtWiringTimeNotAtRenderTime(): void
    {
        // The point of resolving in of(): a typo shows up where the component is built,
        // not as an uncoloured string halfway through a frame.
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Palette::dark(true)->of('chartreuse'),
            'No colour',
        );
    }

    public function testAnUnknownThemeNamesTheOnesThereAre(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Palette::named('solarized'),
            'dark, light, labra',
        );
    }

    public function testCustomThemesAreDiscoveredAndLoaded(): void
    {
        $tmp = sys_get_temp_dir() . '/pig-test-themes-' . bin2hex(random_bytes(6));
        $themesDir = $tmp . '/.pig/themes';
        mkdir($themesDir, 0777, true);

        try {
            file_put_contents($themesDir . '/nord.json', json_encode([
                'name' => 'nord',
                'vars' => ['accent' => '#88c0d0'],
                'colors' => [
                    'accent' => 'accent',
                    'border' => '#81a1c1',
                ],
            ]));

            $names = Palette::names($tmp);
            $this->assertContains('nord', $names);

            $palette = Palette::named('nord', true, $tmp);
            $this->assertSame("\e[38;2;136;192;208mx\e[39m", $palette->fg('accent', 'x'));
        } finally {
            unlink($themesDir . '/nord.json');
            rmdir($themesDir);
            rmdir($tmp . '/.pig');
            rmdir($tmp);
        }
    }

    // ---- what it hands to pig/tui ----------------------------------------------------

    public function testTheMarkdownThemeHighlightsCodeWithTheThemesColours(): void
    {
        $markdown = Palette::dark(true)->markdownTheme();
        $lines = ($markdown->highlightCode)("return 1;", 'php');

        // #69add0 (105, 173, 208) is dark's syntaxKeyword.
        $this->assertStringContainsString("\e[38;2;105;173;208mreturn", $lines[0]);
    }

    public function testEveryThinkingLevelHasABorderColour(): void
    {
        $palette = Palette::dark(true);

        foreach (ThinkingLevel::cases() as $level) {
            $paint = $palette->thinkingBorder($level);
            $this->assertSame('|', Ansi::strip($paint('|')), $level->value);
        }
    }

    public function testTheEditorCarriesTheSelectListColoursWithIt(): void
    {
        $editor = Palette::light(true)->editorTheme();

        $this->assertSame('x', Ansi::strip(($editor->border)('x')));
        $this->assertSame('x', Ansi::strip(($editor->selectList->description)('x')));
    }

    /**
     * Every name a component may ask for.
     *
     * Written out rather than read off one of the themes, so that deleting a colour from
     * both of them still fails here — which is the point.
     */
    private const array NAMES = [
        'accent', 'border', 'borderAccent', 'borderMuted', 'success', 'error', 'warning',
        'muted', 'dim', 'text', 'thinkingText',
        'selectedBg', 'searchMatchBg', 'searchMatchText', 'userMessageBg', 'userMessageText', 'customMessageBg',
        'customMessageText', 'customMessageLabel', 'scrollbarTrack', 'scrollbarThumb', 'toolPendingBg', 'toolSuccessBg',
        'toolErrorBg', 'toolTitle', 'toolOutput',
        'mdHeading', 'mdLink', 'mdLinkUrl', 'mdCode', 'mdCodeBlock', 'mdCodeBlockBorder',
        'mdQuote', 'mdQuoteBorder', 'mdHr', 'mdListBullet',
        'toolDiffAdded', 'toolDiffRemoved', 'toolDiffContext',
        'syntaxComment', 'syntaxKeyword', 'syntaxFunction', 'syntaxVariable',
        'syntaxString', 'syntaxNumber', 'syntaxType', 'syntaxOperator', 'syntaxPunctuation',
        'thinkingOff', 'thinkingMinimal', 'thinkingLow', 'thinkingMedium', 'thinkingHigh',
        'thinkingXhigh', 'thinkingMax', 'bashMode',
    ];

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
