<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\ThemeStyle;
use Pig\CodingAgent\Theme\Themes;
use Pig\Test\AssertsThrows;
use Pig\Tui\Colors;
use Pig\Tui\OklchColorValue;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;
use Pig\Tui\TextStyle;

/** Upstream's `theme-style.test.ts`. */
final class ThemeStyleTest extends TestCase
{
    use AssertsThrows;
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

    /**
     * Load a copy of a built-in theme, modified by `$edit`.
     *
     * @param 'dark'|'light' $base
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $edit
     */
    private function loadTheme(string $base, ?\Closure $edit = null): Theme
    {
        $themeJson = self::builtinThemeJson($base);
        if ($edit !== null) {
            $themeJson = $edit($themeJson);
        }
        $dir = $this->themeTestRoot . '/style-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = "{$dir}/{$themeJson['name']}.json";
        file_put_contents($path, json_encode($themeJson, JSON_THROW_ON_ERROR));

        return Themes::loadThemeFromPath($path, 'truecolor');
    }

    public function testRendersThemeTokensTheSameAsTheGenericTextStyler(): void
    {
        $theme = $this->loadTheme('dark');
        $this->assertSame(
            Colors::styleText('Ready', new TextStyle(fg: $theme->colors()['success'], bg: $theme->colors()['toolSuccessBg'], bold: true), 'truecolor'),
            $theme->style('Ready', new ThemeStyle(fg: 'success', bg: 'toolSuccessBg', bold: true)),
        );
    }

    public function testRejectsUnknownTokensAndTokensInTheWrongSlot(): void
    {
        $theme = $this->loadTheme('dark');
        $this->assertThrows(InvalidArgumentException::class, static fn () => $theme->style('x', new ThemeStyle(fg: 'notAToken')), 'Unknown theme color: notAToken');
        // Background tokens are not foreground colors; use $theme->colors()['userMessageBg'].
        $this->assertThrows(InvalidArgumentException::class, static fn () => $theme->style('x', new ThemeStyle(fg: 'userMessageBg')), 'Unknown theme color: userMessageBg');
    }

    public function testLoadsOklchThemeValues(): void
    {
        $theme = $this->loadTheme('dark', static function (array $json): array {
            $json['colors']['accent'] = 'oklch(62% 0.1 200)';

            return $json;
        });
        $this->assertEquals(new OklchColorValue(0.62, 0.1, 200), $theme->colors()['accent']);
    }

    public function testLoadsOkhslThemeValuesIncludingThroughVariables(): void
    {
        $theme = $this->loadTheme('dark', static function (array $json): array {
            $json['vars'] = [...$json['vars'], 'brand' => 'okhsl(250 60% 55%)'];
            $json['colors']['accent'] = 'brand';
            $json['colors']['error'] = 'okhsl(20 90% 60%)';

            return $json;
        });
        $this->assertSame(Colors::colorToHex(Colors::okhslColor(250, 0.6, 0.55)), Colors::colorToHex($theme->colors()['accent']));
        $this->assertSame(Colors::colorToHex(Colors::okhslColor(20, 0.9, 0.6)), Colors::colorToHex($theme->colors()['error']));
    }

    public function testDetectsTheAppearanceUnlessItIsDeclared(): void
    {
        $this->assertSame('dark', $this->loadTheme('dark')->appearance());
        $this->assertSame('light', $this->loadTheme('light')->appearance());
        // Without a declaration, the appearance is detected from the theme's own colors.
        foreach (['dark', 'light'] as $base) {
            $withoutAppearance = $this->loadTheme($base, static function (array $json): array {
                unset($json['appearance']);

                return $json;
            });
            $this->assertSame($base, $withoutAppearance->appearance());
        }
        $declared = $this->loadTheme('dark', static function (array $json): array {
            $json['appearance'] = 'light';

            return $json;
        });
        $this->assertSame('light', $declared->appearance());

        // Palette colors 0-15 follow the terminal palette, so such themes follow the terminal background.
        $paletteOnly = $this->loadTheme('dark', static function (array $json): array {
            unset($json['appearance']);
            foreach (array_keys($json['colors']) as $key) {
                $json['colors'][$key] = str_ends_with($key, 'Bg') ? 0 : 7;
            }

            return $json;
        });
        $this->assertSame('dark', $paletteOnly->appearance());
        Themes::setTerminalColors(new TerminalColors(background: new RgbColor(250, 250, 250)));
        $this->assertSame('light', $paletteOnly->appearance());
    }

    public function testRendersEmptyTokensAsTerminalDefaultsAndReportsConcreteColorsForThem(): void
    {
        $theme = $this->loadTheme('dark', static function (array $json): array {
            $json['colors']['text'] = '';
            $json['colors']['userMessageBg'] = '';

            return $json;
        });
        $this->assertSame("\x1b[39mx\x1b[39m", $theme->fg('text', 'x'));
        $this->assertSame("\x1b[49mx\x1b[49m", $theme->bg('userMessageBg', 'x'));
        $this->assertSame('#e5e5e7', Colors::colorToHex($theme->colors()['text']));
        $this->assertSame('#000000', Colors::colorToHex($theme->colors()['userMessageBg']));

        Themes::setTerminalColors(new TerminalColors(new RgbColor(200, 210, 220), new RgbColor(10, 20, 30)));
        $this->assertSame('#c8d2dc', Colors::colorToHex($theme->colors()['text']));
        $this->assertSame('#0a141e', Colors::colorToHex($theme->colors()['userMessageBg']));
    }
}
