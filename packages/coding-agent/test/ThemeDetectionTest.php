<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Images\TerminalCapabilities;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;

/** Upstream's `theme-detection.test.ts`. */
final class ThemeDetectionTest extends TestCase
{
    use ThemeTestEnvironment;

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

    public function testDetectColorFgBgThemeClassifiesTheLastFieldByPaletteIndexLikeVim(): void
    {
        $this->assertSame('dark', Themes::detectColorFgBgTheme(['COLORFGBG' => '15;0']));
        $this->assertSame('light', Themes::detectColorFgBgTheme(['COLORFGBG' => '0;7;15']));
        // Solarized Dark's background is bright black.
        $this->assertSame('dark', Themes::detectColorFgBgTheme(['COLORFGBG' => '12;8']));
        // rxvt writes "default" when the background is not a palette color.
        $this->assertNull(Themes::detectColorFgBgTheme(['COLORFGBG' => '15;default']));
        $this->assertNull(Themes::detectColorFgBgTheme([]));
    }

    public function testDetectTerminalThemePrefersTheBackgroundThenTheReportedSchemeThenColorFgBgThenDark(): void
    {
        $env = ['COLORFGBG' => '0;15'];
        $this->assertSame('dark', Themes::detectTerminalTheme(new TerminalColors(background: new RgbColor(8, 8, 8)), 'light', $env));
        $this->assertSame('dark', Themes::detectTerminalTheme(new TerminalColors(), 'dark', $env));
        $this->assertSame('light', Themes::detectTerminalTheme(new TerminalColors(), null, $env));
        $this->assertSame('dark', Themes::detectTerminalTheme(new TerminalColors(), null, []));
    }

    public function testDetectTerminalThemeFollowsTheForegroundWhenTextIsReadableThatWay(): void
    {
        $background = new RgbColor(118, 118, 118);
        $this->assertSame('light', Themes::detectTerminalTheme(new TerminalColors(background: $background)));
        $this->assertSame('dark', Themes::detectTerminalTheme(new TerminalColors(foreground: new RgbColor(255, 255, 255), background: $background)));
        // White text cannot reach 4.5:1 on mid-gray.
        $midGray = new RgbColor(128, 128, 128);
        $this->assertSame('light', Themes::detectTerminalTheme(new TerminalColors(foreground: new RgbColor(255, 255, 255), background: $midGray)));
    }

    public function testThemeColorModeUsesTerminalCapabilities(): void
    {
        TerminalImage::setCapabilities(new TerminalCapabilities(null, false, false));
        $ansi256Theme = Themes::getThemeByName('dark');
        $this->assertNotNull($ansi256Theme, 'dark theme not found');
        $this->assertSame('256color', $ansi256Theme->getColorMode());
        $this->assertMatchesRegularExpression('/^\x1b\[38;5;\d+m$/', $ansi256Theme->getFgAnsi('accent'));

        TerminalImage::setCapabilities(new TerminalCapabilities(null, true, false));
        $truecolorTheme = Themes::getThemeByName('dark');
        $this->assertNotNull($truecolorTheme, 'dark theme not found');
        $this->assertSame('truecolor', $truecolorTheme->getColorMode());
        $this->assertMatchesRegularExpression('/^\x1b\[38;2;\d+;\d+;\d+m$/', $truecolorTheme->getFgAnsi('accent'));
    }

    public function testThemeSettingHelpersParseAndResolveAutomaticThemeSettings(): void
    {
        $this->assertSame(['lightTheme' => 'light', 'darkTheme' => 'dark'], Themes::parseAutoThemeSetting('light/dark'));
        $this->assertSame('dark', Themes::resolveThemeSetting('dark', 'light'));
        $this->assertSame('light', Themes::resolveThemeSetting('light/dark', 'light'));
        $this->assertSame('dark', Themes::resolveThemeSetting('light/dark', 'dark'));
        $this->assertNull(Themes::resolveThemeSetting('light/dark/extra', 'dark'));
    }
}
