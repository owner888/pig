<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\ThemeJson;
use Pig\CodingAgent\Theme\Themes;
use Pig\Test\AssertsThrows;

/**
 * Upstream's theme cases from `config-schemas.test.ts` and `resource-loader-theme.test.ts`, and the
 * message itself: `fixtures/theme-errors.json` holds what upstream's `validateThemeJson()` (typebox
 * 1.3.27, `theme-schema.ts`) said for each document `cases()` builds, run against pig's `dark.json`.
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

    /**
     * The documents behind `fixtures/theme-errors.json`, each built from `dark.json` as upstream's
     * run built it. `notObjects` has `vars: [1]` where upstream's had `[]`: decoded into arrays, `[]`
     * is an empty object as well as an empty list, and is accepted as `vars`.
     *
     * @return array<string, mixed>
     */
    private static function cases(): array
    {
        $dark = self::dark();
        $noName = $dark;
        unset($noName['name']);
        $mine = $dark;
        unset($mine['colors']['text'], $mine['colors']['accent'], $mine['colors']['scrollbarTrack']);
        $mine['colors']['error'] = 300;
        $mine['appearance'] = 'dim';
        $mine['vars']['blue'] = true;

        return [
            'mine' => $mine,
            'unknownTop' => [...$dark, 'unexpected' => true],
            'unknownTop2' => [...$dark, 'unexpected' => true, 'more' => 1, 'name' => 5],
            'unknownColor' => [...$dark, 'colors' => [...$dark['colors'], 'scrollbarThmb' => '']],
            'unknownExport' => [...$dark, 'export' => ['unexpected' => '']],
            'missingAndUnknown' => [...$dark, 'colors' => ['foo' => 1, 'accent' => 300], 'extra' => []],
            'slashBeforeOtherErrors' => [...$dark, 'name' => 'a/b', 'extra' => 1],
            'missingNameUnknown' => [...$noName, 'zzz' => 1],
            'notObjects' => [...$dark, 'colors' => 'x', 'vars' => [1], 'export' => 3],
            'varsKeyNotEscaped' => [...$dark, 'vars' => ['a/b~c' => true]],
            'badVars' => [...$dark, 'vars' => ['a' => 1.5, 'b' => 'x', 'c' => -1]],
            'schemaNotString' => [...$dark, '$schema' => 4],
        ];
    }

    public function testReportsWhatUpstreamReports(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/fixtures/theme-errors.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = self::cases();
        $this->assertSame(array_keys($expected), array_keys($cases));

        foreach ($cases as $label => $json) {
            $error = $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson($label, $json));
            $this->assertSame($expected[$label], $error->getMessage(), $label);
        }
    }

    /** Upstream's "accepts $schema in themes and rejects malformed themes". */
    public function testAcceptsASchemaReferenceAndRejectsUnknownKeys(): void
    {
        $json = [...self::dark(), '$schema' => 'https://pigagent.dev/schemas/theme.schema.json', 'name' => 'custom'];
        $this->assertSame('custom', ThemeJson::validateThemeJson('custom', $json)['name']);
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('invalid', [...self::dark(), 'name' => 'invalid/name']), 'theme names cannot contain');
        $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('invalid', [...self::dark(), 'colors' => ['x' => '']]), 'Missing required color tokens');

        foreach ([
            [...self::dark(), 'unexpected' => true],
            [...self::dark(), 'colors' => [...self::dark()['colors'], 'scrollbarThmb' => '']],
            [...self::dark(), 'export' => ['unexpected' => '']],
        ] as $invalid) {
            $this->assertThrows(InvalidArgumentException::class, static fn () => ThemeJson::validateThemeJson('invalid', $invalid), 'must not have additional properties');
        }
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
