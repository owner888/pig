<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\ThemeJson;
use Pig\CodingAgent\Theme\Themes;
use Pig\Test\AssertsThrows;

/**
 * pig's own: upstream validates with typebox and has no test of its own for `validateThemeJson()`, so
 * this pins down the hand-written validator's checks and the shape of its message.
 */
final class ThemeJsonTest extends TestCase
{
    use AssertsThrows;

    /** @return array<string, mixed> */
    private static function dark(): array
    {
        return json_decode((string) file_get_contents(Themes::getThemesDir() . '/dark.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAcceptsTheBuiltinThemes(): void
    {
        foreach (['dark', 'light', 'labra'] as $name) {
            $json = json_decode((string) file_get_contents(Themes::getThemesDir() . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($name, ThemeJson::validateThemeJson($name, $json)['name']);
        }
    }

    public function testListsMissingColorTokensSortedAndOtherErrorsByPath(): void
    {
        $json = self::dark();
        unset($json['colors']['text'], $json['colors']['accent'], $json['colors']['scrollbarTrack']);
        $json['colors']['error'] = 300;
        $json['appearance'] = 'dim';
        $json['vars']['blue'] = true;

        $error = $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('mine', $json));
        $this->assertSame(
            "Invalid theme \"mine\":\n"
            . "\nMissing required color tokens:\n  - accent\n  - text"
            . "\n\nPlease add these colors to your theme's \"colors\" object."
            . "\nSee the built-in themes (dark.json, light.json) for reference values."
            . "\n\nOther errors:\n"
            . "  - /appearance: must match a schema in anyOf\n"
            . "  - /vars/blue: must match a schema in anyOf\n"
            . '  - /colors/error: must match a schema in anyOf',
            $error->getMessage(),
        );
    }

    public function testRequiresANameAndAColorsObject(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('x', ['colors' => []]), '  - /: must have required properties name');
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('x', ['name' => 'x', 'colors' => ['a', 'b']]), '  - /colors: must be object');
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('x', 'dark'), '  - /: must be object');
    }

    public function testRejectsASlashInTheName(): void
    {
        $json = [...self::dark(), 'name' => 'light/dark'];
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('x', $json), 'Invalid theme name "light/dark"');
    }

    public function testTreatsWholeNumberFloatsAsPaletteIndices(): void
    {
        $json = self::dark();
        $json['colors']['accent'] = 24.0;
        $this->assertSame(24, ThemeJson::validateThemeJson('x', $json)['colors']['accent']);
    }
}
