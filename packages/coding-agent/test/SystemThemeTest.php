<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\SystemTheme;
use Pig\CodingAgent\Theme\SystemThemeInput;
use Pig\CodingAgent\Theme\ThemeStyle;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Colors;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;

/** Upstream's `system-theme.test.ts`. */
final class SystemThemeTest extends TestCase
{
    use ThemeTestEnvironment;

    private const array PANELS = ['userMessageBg', 'toolPendingBg', 'toolSuccessBg', 'toolErrorBg', 'selectedBg'];

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    private static function rgb(string $hex): RgbColor
    {
        return Colors::colorToRgb(Colors::parseColor($hex));
    }

    private static function lightness(RgbColor $color): float
    {
        return Colors::colorToOklch(Colors::rgbColor($color->r, $color->g, $color->b))->l;
    }

    private static function dracula(): SystemThemeInput
    {
        return new SystemThemeInput(
            background: self::rgb('#282a36'),
            foreground: self::rgb('#f8f8f2'),
            palette: array_map(self::rgb(...), [
                '#21222c', '#ff5555', '#50fa7b', '#f1fa8c', '#bd93f9', '#ff79c6', '#8be9fd', '#f8f8f2',
                '#6272a4', '#ff6e6e', '#69ff94', '#ffffa5', '#d6acff', '#ff92df', '#a4ffff', '#ffffff',
            ]),
        );
    }

    /**
     * Dark with a palette, light with an unreadable foreground, background only, and mid-gray.
     *
     * @return array<string, SystemThemeInput>
     */
    private static function terminals(): array
    {
        return [
            'dracula' => self::dracula(),
            'solarizedLight' => new SystemThemeInput(background: self::rgb('#fdf6e3'), foreground: self::rgb('#657b83')),
            'backgroundOnly' => new SystemThemeInput(background: self::rgb('#1e1e1e')),
            'midGray' => new SystemThemeInput(background: self::rgb('#808080'), foreground: self::rgb('#ffffff')),
        ];
    }

    private static function resolved(SystemThemeInput $input, string $token): RgbColor
    {
        $value = SystemTheme::generateSystemThemeColors($input)->colors[$token];
        if ($value === '') {
            $color = in_array($token, self::PANELS, true) ? $input->background : $input->foreground;
            self::assertNotNull($color);

            return $color;
        }
        self::assertIsString($value);

        return self::rgb($value);
    }

    public function testKeepsBodyTextReadableOnTheBackgroundAndItsPanels(): void
    {
        foreach (self::terminals() as $name => $input) {
            $text = self::resolved($input, 'text');
            foreach ([$input->background, self::resolved($input, 'selectedBg')] as $surface) {
                $this->assertGreaterThanOrEqual(4.5, SystemTheme::wcagContrast($text, $surface), $name);
            }
            $this->assertGreaterThanOrEqual(
                4.5,
                SystemTheme::wcagContrast(self::resolved($input, 'toolTitle'), self::resolved($input, 'toolErrorBg')),
                $name,
            );
        }
    }

    public function testOrdersForegroundRolesByContrastAndKeepsPanelsCloseToTheBackground(): void
    {
        foreach (self::terminals() as $name => $input) {
            $background = self::lightness($input->background);
            $offset = static fn (string $token): float => self::lightness(self::resolved($input, $token)) - $background;
            // On mid-gray the levels collapse to the strongest reachable color.
            if ($name !== 'midGray') {
                $this->assertGreaterThan(abs($offset('muted')), abs($offset('text')), $name);
                $this->assertGreaterThan(abs($offset('dim')), abs($offset('muted')), $name);
            }
            $lighter = SystemTheme::generateSystemThemeColors($input)->appearance === 'dark';
            foreach (self::PANELS as $panel) {
                $this->assertLessThan(2, SystemTheme::wcagContrast(self::resolved($input, $panel), $input->background), "{$name} {$panel}");
                $this->assertSame($lighter, $offset($panel) > 0, "{$name} {$panel}");
            }
        }
    }

    public function testUsesTheTerminalForegroundAndPaletteHues(): void
    {
        $this->assertSame('', SystemTheme::generateSystemThemeColors(self::dracula())->colors['text']);
        // Solarized's foreground is below 4.5:1 on its own background, so text is darkened.
        $this->assertNotSame('', SystemTheme::generateSystemThemeColors(self::terminals()['solarizedLight'])->colors['text']);
        $hue = static fn (RgbColor $c): float => Colors::colorToOklch(Colors::rgbColor($c->r, $c->g, $c->b))->h;
        $this->assertLessThan(8, abs($hue(self::resolved(self::dracula(), 'error')) - $hue(self::dracula()->palette[1])));
    }

    // https://github.com/earendil-works/pi/issues/10255
    public function testKeepsPastelPaletteColorsPastelAtOtherLightnesses(): void
    {
        $frappe = new SystemThemeInput(
            background: self::rgb('#303446'),
            foreground: self::rgb('#c6d0f5'),
            palette: array_map(self::rgb(...), [
                '#51576d', '#e78284', '#a6d189', '#e5c890', '#8caaee', '#f4b8e4', '#81c8be', '#b5bfe2',
                '#626880', '#e67172', '#8ec772', '#d9ba73', '#7b9ef0', '#f2a4db', '#5abfb5', '#a5adce',
            ]),
        );
        $chroma = static fn (RgbColor $c): float => Colors::colorToOklch(Colors::rgbColor($c->r, $c->g, $c->b))->c;
        $pink = $frappe->palette[5];
        $accent = self::resolved($frappe, 'accent');
        // The accent is darker than the pink, but must not gain chroma (it was 2x before the cap).
        $this->assertLessThan(self::lightness($pink) - 0.05, self::lightness($accent));
        $this->assertLessThanOrEqual($chroma($pink) * 1.03, $chroma($accent));
        foreach (['userMessageBg', 'customMessageBg'] as $panel) {
            $this->assertLessThanOrEqual(0.1, $chroma(self::resolved($frappe, $panel)), $panel);
        }
    }

    public function testRendersGrayscaleAtZeroSaturation(): void
    {
        $dracula = self::dracula();
        $colors = SystemTheme::generateSystemThemeColors(new SystemThemeInput(
            foreground: $dracula->foreground,
            background: $dracula->background,
            palette: $dracula->palette,
            saturation: 0,
        ))->colors;
        $this->assertLessThan(0.005, Colors::colorToOklch(Colors::parseColor($colors['error']))->c);
    }

    public function testFallsBackToPaletteIndicesAndFaintTextWithoutABackground(): void
    {
        $generated = SystemTheme::generateSystemThemeColors(new SystemThemeInput(appearanceHint: 'light'));
        $this->assertSame('light', $generated->appearance);
        $this->assertSame([1, '', ''], [$generated->colors['error'], $generated->colors['text'], $generated->colors['userMessageBg']]);
        $this->assertContains('muted', $generated->dim);
        $this->assertSame('', SystemTheme::generateSystemThemeColors(new SystemThemeInput(saturation: 0))->colors['error']);
    }

    // ---- system theme ---------------------------------------------------------------

    public function testSystemThemeIsListedFirstHasNoExportColorsAndIsGeneratedFromTheTerminalColors(): void
    {
        $this->assertSame('system', Themes::getAvailableThemes()[0]);
        $this->assertSame([], Themes::getThemeExportColors('system'));

        $dracula = self::dracula();
        Themes::setTerminalColors(new TerminalColors($dracula->foreground, $dracula->background, $dracula->palette));
        $theme = Themes::getThemeByName('system');
        $this->assertNotNull($theme);
        $this->assertSame('dark', $theme->appearance());
        $this->assertSame("\x1b[39m", $theme->getFgAnsi('text'));
        $this->assertMatchesRegularExpression('/^\x1b\[38;/', $theme->getFgAnsi('error'));
    }

    public function testSystemThemeRendersFaintTokensWithSgr2AndClosesIt(): void
    {
        $theme = Themes::getThemeByName('system');
        $this->assertNotNull($theme);
        $this->assertSame("\x1b[39m\x1b[2mx\x1b[22;39m", $theme->fg('muted', 'x'));
        $this->assertSame("\x1b[39m\x1b[2mx\x1b[22m\x1b[39m", $theme->style('x', new ThemeStyle(fg: 'muted')));
    }
}
