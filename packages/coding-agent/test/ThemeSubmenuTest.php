<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\ThemeSubmenu;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Ansi;

/**
 * The `/settings` theme submenu: one theme, or a `light/dark` pair for automatic mode. What is
 * tested is the setting it hands back, and that moving the cursor previews without saving.
 */
final class ThemeSubmenuTest extends TestCase
{
    use ThemeTestEnvironment;

    private const array THEMES = ['system', 'dark', 'labra', 'light'];

    /** @var list<string> */
    private array $previews = [];

    /** @var list<string|null> */
    private array $done = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
        Themes::initTheme('dark');
        $this->previews = [];
        $this->done = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    private function submenu(string $current): ThemeSubmenu
    {
        return new ThemeSubmenu(
            $current,
            self::THEMES,
            function (string $setting): void {
                $this->previews[] = $setting;
            },
            function (?string $setting): void {
                $this->done[] = $setting;
            },
        );
    }

    private static function text(ThemeSubmenu $menu): string
    {
        return Ansi::strip(implode("\n", $menu->render(80)));
    }

    public function testASingleThemeListsTheSystemThemeThenAutomaticThenTheRest(): void
    {
        $text = self::text($this->submenu('dark'));

        $this->assertMatchesRegularExpression('/system.*\n.*automatic.*\n.*dark ·/s', $text, 'system, automatic, then the current one marked');
        $this->assertStringContainsString('Theme', $text);
    }

    public function testEnterOnAThemeHandsItBack(): void
    {
        $menu = $this->submenu('dark');
        $menu->handleInput("\x1b[B"); // from dark to labra
        $menu->handleInput("\r");

        $this->assertSame(['labra'], $this->done);
        $this->assertSame(['labra'], $this->previews, 'moving the cursor previewed it');
    }

    public function testEscapePutsTheOriginalBackAndHandsBackNothing(): void
    {
        $menu = $this->submenu('dark');
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\e");

        $this->assertSame(['labra', 'dark'], $this->previews, 'the preview, then the original again');
        $this->assertSame([null], $this->done);
    }

    public function testAutomaticOpensTheLightDarkScreenAndApplySavesThePair(): void
    {
        $menu = $this->submenu('dark');
        $menu->handleInput("\x1b[A"); // up from dark to automatic
        $menu->handleInput("\r");

        $text = self::text($menu);
        $this->assertStringContainsString('Automatic Theme', $text);
        $this->assertStringContainsString('Light theme', $text);
        $this->assertStringContainsString('Dark theme', $text);
        $this->assertSame('dark/dark', end($this->previews), 'both halves start from the single theme');

        // Light theme row → pick "light" (from dark, two down in system, dark, labra, light)
        $menu->handleInput("\r");
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\r");
        $this->assertSame('light/dark', end($this->previews));

        // down twice: Dark theme, Apply
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\r");

        $this->assertSame(['light/dark'], $this->done);
    }

    public function testAPairOpensOnTheAutomaticScreenAndChangeModeGoesBackToOne(): void
    {
        $menu = $this->submenu('light/labra');

        $this->assertStringContainsString('Automatic Theme', self::text($menu));
        $this->assertStringContainsString('labra', self::text($menu));

        // down three: Dark theme, Apply, Change mode
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\r");

        $text = self::text($menu);
        $this->assertStringNotContainsString('Automatic Theme', $text);
        $this->assertStringContainsString('automatic', $text, 'back on the single list, automatic among the entries');
        $this->assertSame([], $this->done, 'nothing saved yet');
    }
}
