<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Colors;

/** Upstream's `theme-export.test.ts` (`getThemeExportColors`). */
final class ThemeExportTest extends TestCase
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

    /** @param array<string, mixed> $theme */
    private function writeCustomTheme(array $theme): void
    {
        file_put_contents(
            $this->customThemesDir() . "/{$theme['name']}.json",
            json_encode($theme, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    public function testResolvesExportVariableReferencesUsingTheSameSyntaxAsColors(): void
    {
        $darkTheme = self::builtinThemeJson('dark');
        $this->writeCustomTheme([
            ...$darkTheme,
            'name' => 'custom-export-vars',
            'vars' => [
                ...($darkTheme['vars'] ?? []),
                'pageBgVar' => '#112233',
                'pageBgAlias' => 'pageBgVar',
                'infoBgVar' => '#445566',
                'cardBgVar' => '#223344',
            ],
            'export' => [
                'pageBg' => 'pageBgAlias',
                'cardBg' => 'cardBgVar',
                'infoBg' => 'infoBgVar',
            ],
        ]);

        $this->assertSame(
            ['pageBg' => '#112233', 'cardBg' => '#223344', 'infoBg' => '#445566'],
            Themes::getThemeExportColors('custom-export-vars'),
        );
    }

    public function testConvertsOkhslExportColorsToHexBecauseCssDoesNotSupportThem(): void
    {
        $darkTheme = self::builtinThemeJson('dark');
        $this->writeCustomTheme([
            ...$darkTheme,
            'name' => 'custom-export-okhsl',
            'vars' => ['card' => 'okhsl(250 20% 20%)'],
            'export' => ['pageBg' => 'okhsl(250 20% 15%)', 'cardBg' => 'card', 'infoBg' => 'oklch(30% 0.05 80)'],
        ]);

        $this->assertSame(
            [
                'pageBg' => Colors::colorToHex(Colors::okhslColor(250, 0.2, 0.15)),
                'cardBg' => Colors::colorToHex(Colors::okhslColor(250, 0.2, 0.2)),
                'infoBg' => 'oklch(30% 0.05 80)',
            ],
            Themes::getThemeExportColors('custom-export-okhsl'),
        );
    }

    public function testResolvesRecursiveVarsAndConverts256ColorExportValuesToHex(): void
    {
        $darkTheme = self::builtinThemeJson('dark');
        $this->writeCustomTheme([
            ...$darkTheme,
            'name' => 'custom-export-recursive',
            'vars' => [
                ...($darkTheme['vars'] ?? []),
                'deepPageBg' => '#abcdef',
                'pageBgAlias' => 'deepPageBg',
                'cardBgAnsi' => 24,
            ],
            'export' => [
                'pageBg' => 'pageBgAlias',
                'cardBg' => 'cardBgAnsi',
                'infoBg' => '',
            ],
        ]);

        $this->assertSame(
            ['pageBg' => '#abcdef', 'cardBg' => '#005f87', 'infoBg' => null],
            Themes::getThemeExportColors('custom-export-recursive'),
        );
    }
}
