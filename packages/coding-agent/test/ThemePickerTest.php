<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\ThemeInfo;
use Pig\CodingAgent\Theme\Themes;

/** Upstream's `theme-picker.test.ts`, plus pig's own theme directories and builtins. */
final class ThemePickerTest extends TestCase
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

    public function testUsesCustomThemeContentNamesInsteadOfFileNames(): void
    {
        $customTheme = [...self::builtinThemeJson('dark'), 'name' => 'bar'];
        $themePath = $this->customThemesDir() . '/foo.json';
        file_put_contents($themePath, json_encode($customTheme, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->assertContains('bar', Themes::getAvailableThemes());
        $this->assertNotContains('foo', Themes::getAvailableThemes());
        $this->assertContainsEquals(new ThemeInfo('bar', $themePath), Themes::getAvailableThemesWithPaths());
        $this->assertSame([], array_filter(Themes::getAvailableThemesWithPaths(), static fn (ThemeInfo $theme): bool => $theme->name === 'foo'));
    }

    // ---- pig's own ------------------------------------------------------------------

    public function testListsTheSystemThemeFirstThenBuiltinsIncludingLabra(): void
    {
        $this->assertSame(['system', 'dark', 'labra', 'light'], Themes::getAvailableThemes());
        $labra = Themes::getThemeByName('labra');
        $this->assertNotNull($labra);
        $this->assertSame('dark', $labra->appearance());
    }

    public function testFindsThemesInPigsHomeAndTheProjectAndLoadsThemByContentName(): void
    {
        $project = $this->themeTestRoot . '/project';
        mkdir("{$project}/.pig/themes", 0o777, true);
        mkdir("{$project}/.pi/themes", 0o777, true);
        $write = static function (string $path, string $name): void {
            file_put_contents($path, json_encode([...self::builtinThemeJson('dark'), 'name' => $name], JSON_THROW_ON_ERROR));
        };
        $write($this->themeTestRoot . '/pig/themes/home.json', 'from-pig-home');
        $write("{$project}/.pig/themes/a.json", 'from-pig-project');
        $write("{$project}/.pi/themes/b.json", 'from-pi-project');

        $this->assertNotContains('from-pig-project', Themes::getAvailableThemes());
        Themes::setCustomThemesCwd($project);
        $names = Themes::getAvailableThemes();
        foreach (['from-pig-home', 'from-pig-project', 'from-pi-project'] as $name) {
            $this->assertContains($name, $names);
            $this->assertSame($name, Themes::getThemeByName($name)?->name);
        }
    }

    public function testReportsInvalidCustomThemesInsteadOfDroppingThemSilently(): void
    {
        file_put_contents($this->customThemesDir() . '/broken.json', '{ not json');

        $this->assertNotContains('broken', Themes::getAvailableThemes());
        $errors = Themes::getCustomThemeErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('broken.json', $errors[0]);
        $this->assertStringContainsString('Failed to parse theme', $errors[0]);
    }
}
