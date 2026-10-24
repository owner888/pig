<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Schemas;

/**
 * The JSON Schemas of pig's four configuration files — upstream's published configuration schemas
 * (#9880, #10751), served from pigagent.dev where upstream's are served from pi.dev.
 *
 * `packages/coding-agent/schemas/` holds them: `models.schema.json`, `settings.schema.json`,
 * `keybindings.schema.json` and `theme.schema.json`. A file may name its schema in `$schema`
 * (`"$schema": "https://pigagent.dev/schemas/settings.schema.json"`) so an editor completes and
 * checks it; pig accepts the key in all four files and never treats it as a setting, a binding or
 * a provider.
 *
 * **The JSON is the source here.** Upstream writes its schemas in typebox and generates the files
 * from them (`npm run generate:schemas`); pig has no typebox, so the files are kept by hand and read
 * at runtime — `models.json` and themes are checked against them with `SchemaErrors`, typebox's
 * checker written out. What upstream's generation guarantees, `ConfigSchemasTest` checks instead:
 * the keybinding ids and descriptions are `Keybindings::keybindingDefinitions()`, the theme tokens
 * are `ThemeJson::COLOR_TOKENS`, the settings defaults are what `Settings` answers for an empty
 * file, and every default key binding is one the key grammar accepts.
 */
final class ConfigSchemas
{
    /** Where the schemas are published; each is `<base>/<name>.schema.json`. */
    public const string BASE_URL = 'https://pigagent.dev/schemas';

    /** The four, in upstream's order. */
    public const array NAMES = ['models', 'settings', 'keybindings', 'theme'];

    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    /** The published URL of one schema — what a file's `$schema` names. */
    public static function url(string $name): string
    {
        return self::BASE_URL . "/{$name}.schema.json";
    }

    /** The committed file of one schema. */
    public static function path(string $name): string
    {
        return dirname(__DIR__, 2) . "/schemas/{$name}.schema.json";
    }

    /**
     * One schema, decoded with objects as arrays. Read once per process.
     *
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("No configuration schema named {$name}");
        }

        if (!isset(self::$loaded[$name])) {
            $raw = file_get_contents(self::path($name));
            $schema = $raw === false ? null : json_decode($raw, true);

            if (!is_array($schema)) {
                throw new \RuntimeException('Configuration schema ' . self::path($name) . ' could not be read');
            }

            self::$loaded[$name] = $schema;
        }

        return self::$loaded[$name];
    }
}
