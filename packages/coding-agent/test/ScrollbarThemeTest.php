<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\ThemeJson;
use Pig\CodingAgent\Theme\Themes;

/** Upstream's `scrollbar-theme.test.ts`: the optional scrollbar and search highlight colors. */
final class ScrollbarThemeTest extends TestCase
{
    use ThemeTestEnvironment;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
        Themes::setThemeJsonValidator(ThemeJson::validateThemeJson(...));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    /** @param array<string, mixed> $theme */
    private function writeTheme(array $theme): string
    {
        $testDir = $this->themeTestRoot . '/scrollbar-' . bin2hex(random_bytes(4));
        mkdir($testDir);
        $themePath = "{$testDir}/{$theme['name']}.json";
        file_put_contents($themePath, json_encode($theme, JSON_THROW_ON_ERROR));

        return $themePath;
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function fallbacks(): iterable
    {
        yield 'scrollbarTrack' => ['scrollbarTrack', 'muted'];
        yield 'scrollbarThumb' => ['scrollbarThumb', 'text'];
    }

    #[DataProvider('fallbacks')]
    public function testFallsBackWhenOmitted(string $token, string $fallback): void
    {
        $themeJson = self::builtinThemeJson('dark');
        $themeJson['name'] = "missing-{$token}-theme";
        unset($themeJson['colors'][$token]);

        $loadedTheme = Themes::loadThemeFromPath($this->writeTheme($themeJson), 'truecolor');
        $this->assertSame($loadedTheme->getFgAnsi($fallback), $loadedTheme->getFgAnsi($token));
    }

    public function testUsesExplicitlyConfiguredScrollbarColors(): void
    {
        $themeJson = self::builtinThemeJson('dark');
        $themeJson['name'] = 'custom-scrollbar-theme';
        $themeJson['colors']['scrollbarTrack'] = '#654321';
        $themeJson['colors']['scrollbarThumb'] = '#123456';

        $loadedTheme = Themes::loadThemeFromPath($this->writeTheme($themeJson), 'truecolor');
        $this->assertSame("\x1b[38;2;101;67;33m", $loadedTheme->getFgAnsi('scrollbarTrack'));
        $this->assertSame("\x1b[38;2;18;52;86m", $loadedTheme->getFgAnsi('scrollbarThumb'));
    }

    public function testFallsBackToExistingSelectionAndTextColorsForSearchHighlights(): void
    {
        $themeJson = self::builtinThemeJson('dark');
        $themeJson['name'] = 'legacy-search-theme';
        unset($themeJson['colors']['searchMatchBg'], $themeJson['colors']['searchMatchText']);

        $loadedTheme = Themes::loadThemeFromPath($this->writeTheme($themeJson), 'truecolor');
        $this->assertSame($loadedTheme->getBgAnsi('selectedBg'), $loadedTheme->getBgAnsi('searchMatchBg'));
        $this->assertSame($loadedTheme->getFgAnsi('text'), $loadedTheme->getFgAnsi('searchMatchText'));
    }

    public function testUsesExplicitlyConfiguredSearchHighlightColors(): void
    {
        $themeJson = self::builtinThemeJson('dark');
        $themeJson['name'] = 'custom-search-theme';
        $themeJson['colors']['searchMatchBg'] = '#112233';
        $themeJson['colors']['searchMatchText'] = '#223344';

        $loadedTheme = Themes::loadThemeFromPath($this->writeTheme($themeJson), 'truecolor');
        $this->assertSame("\x1b[48;2;17;34;51m", $loadedTheme->getBgAnsi('searchMatchBg'));
        $this->assertSame("\x1b[38;2;34;51;68m", $loadedTheme->getFgAnsi('searchMatchText'));
    }
}
