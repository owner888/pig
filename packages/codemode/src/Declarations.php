<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * What a script sees of a tool — upstream's `declarations.ts`, with the type notation changed.
 *
 * Upstream renders TypeScript (`declare const tools: { read(input: { path: string }): Promise<string> }`)
 * because the script is JavaScript. The script is PHP here, so a tool is rendered as a method on
 * `$tools` with its input as a PHPStan-style array shape — the notation PHP code already carries
 * in its docblocks and the one a model writing PHP reads without translating:
 *
 * ```
 * $tools->read(array{path: string, offset?: int} $input): string
 * ```
 *
 * The schema walk is upstream's: `$ref` resolved against the root with a cap on expansions,
 * `const` and `enum` as literal unions, `anyOf`/`oneOf` as unions, `allOf` as an intersection
 * (written `A&B`), tuples, additional properties, and property descriptions as comments.
 */
final class Declarations
{
    /** Largest rendered input type, in characters, before it becomes `mixed`. */
    public const int DEFAULT_INPUT_SCHEMA_MAX_CHARS = 16_000;

    /** Local `$ref` expansions per rendered schema, so shared definitions cannot blow up the output. */
    private const int MAX_REF_EXPANSIONS = 32;

    private const string INDENT = '  ';

    /**
     * @param array{name: string, description?: ?string, inputSchema?: mixed, outputSchema?: mixed} $tool
     */
    public static function signature(array $tool, int $maxChars = self::DEFAULT_INPUT_SCHEMA_MAX_CHARS): string
    {
        $input = self::schemaToType($tool['inputSchema'] ?? null, $maxChars);
        $output = isset($tool['outputSchema']) ? self::schemaToType($tool['outputSchema']) : 'string';

        return '$tools->' . Identifier::of($tool['name']) . "({$input} \$input): {$output}";
    }

    /**
     * The description and the signature together — what `ALL_TOOLS` entries carry and what one
     * section of the codemode description is.
     *
     * @param array{name: string, description?: ?string, inputSchema?: mixed, outputSchema?: mixed} $tool
     */
    public static function sample(array $tool): string
    {
        return trim((string) ($tool['description'] ?? '')) . "\n\ncodemode tool declaration:\n```php\n" . self::signature($tool) . "\n```";
    }

    public static function schemaToType(mixed $schema, ?int $maxChars = null): string
    {
        $context = ['root' => $schema, 'resolving' => [], 'expansions' => 0];
        $type = self::toType($schema, $context);

        return $maxChars !== null && strlen($type) > $maxChars ? 'mixed' : $type;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** @param array<string, mixed> $context */
    private static function toType(mixed $schema, array &$context): string
    {
        if ($schema === true) {
            return 'mixed';
        }

        if ($schema === false) {
            return 'never';
        }

        if (!self::isObject($schema)) {
            return 'mixed';
        }

        if (is_string($schema['$ref'] ?? null)) {
            $ref = $schema['$ref'];

            if (isset($context['resolving'][$ref]) || $context['expansions'] >= self::MAX_REF_EXPANSIONS) {
                return 'mixed';
            }

            $target = self::resolveRef($ref, $context['root']);

            if ($target === null) {
                return 'mixed';
            }

            $context['expansions']++;
            $context['resolving'][$ref] = true;

            try {
                return self::toType($target, $context);
            } finally {
                unset($context['resolving'][$ref]);
            }
        }

        if (array_key_exists('const', $schema)) {
            return self::literal($schema['const']);
        }

        if (is_array($schema['enum'] ?? null)) {
            return self::union(array_map(self::literal(...), $schema['enum']));
        }

        $variants = is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : (is_array($schema['oneOf'] ?? null) ? $schema['oneOf'] : null);

        if ($variants !== null) {
            return self::union(array_map(static fn (mixed $v): string => self::toType($v, $context), $variants));
        }

        if (is_array($schema['allOf'] ?? null)) {
            $parts = array_values(array_filter(
                array_map(static fn (mixed $v): string => self::toType($v, $context), $schema['allOf']),
                static fn (string $part): bool => $part !== 'mixed',
            ));

            return $parts === [] ? 'mixed' : implode('&', array_map(static fn (string $p): string => str_contains($p, '|') ? "({$p})" : $p, $parts));
        }

        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            return self::union(array_map(static fn (mixed $entry): string => self::toType([...$schema, 'type' => $entry], $context), $type));
        }

        return match ($type) {
            'string' => 'string',
            'number' => 'float',
            'integer' => 'int',
            'boolean' => 'bool',
            'null' => 'null',
            'array' => self::arrayType($schema, $context),
            'object' => self::objectType($schema, $context),
            null => isset($schema['properties']) || isset($schema['additionalProperties']) || isset($schema['required'])
                ? self::objectType($schema, $context)
                : (isset($schema['items']) || isset($schema['prefixItems']) ? self::arrayType($schema, $context) : 'mixed'),
            default => 'mixed',
        };
    }

    private static function literal(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) json_encode($value);
        }

        return 'mixed';
    }

    private static function resolveRef(string $ref, mixed $root): mixed
    {
        if ($ref !== '#' && !str_starts_with($ref, '#/')) {
            return null;
        }

        $current = $root;

        foreach (array_filter(explode('/', substr($ref, 2)), static fn (string $s): bool => $s !== '') as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!self::isObject($current) || !array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return is_bool($current) || self::isObject($current) ? $current : null;
    }

    /** @param list<string> $types */
    private static function union(array $types): string
    {
        $unique = [];

        foreach ($types as $type) {
            $unique[$type] = true;
        }

        $types = array_keys($unique);

        if ($types === []) {
            return 'never';
        }

        if (in_array('mixed', $types, true)) {
            return 'mixed';
        }

        return implode('|', $types);
    }

    /** @param array<string, mixed> $context */
    private static function arrayType(array $schema, array &$context): string
    {
        if (isset($schema['items']) && !array_is_list($schema['items'])) {
            return 'list<' . self::toType($schema['items'], $context) . '>';
        }

        $tuple = is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : (is_array($schema['items'] ?? null) ? $schema['items'] : []);

        if ($tuple !== []) {
            return 'array{' . implode(', ', array_map(static fn (mixed $item): string => self::toType($item, $context), $tuple)) . '}';
        }

        return 'list<mixed>';
    }

    private static function descriptionOf(mixed $property): string
    {
        return self::isObject($property) && is_string($property['description'] ?? null) ? trim($property['description']) : '';
    }

    /** @param array<string, mixed> $context */
    private static function objectType(array $schema, array &$context): string
    {
        $properties = self::isObject($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? array_flip(array_filter($schema['required'], 'is_string')) : [];
        $names = array_map('strval', array_keys($properties));
        sort($names);
        $members = [];

        foreach ($names as $name) {
            $optional = isset($required[$name]) ? '' : '?';
            $members[] = self::propertyKey($name) . "{$optional}: " . self::toType($properties[$name], $context);
        }

        $additional = $schema['additionalProperties'] ?? null;

        if ($additional !== null && $additional !== false) {
            $members[] = '...<string, ' . ($additional === true ? 'mixed' : self::toType($additional, $context)) . '>';
        } elseif ($additional === null && $names === []) {
            return 'array<string, mixed>';
        }

        if ($members === []) {
            return 'array{}';
        }

        $described = array_filter($names, static fn (string $n): bool => self::descriptionOf($properties[$n]) !== '');

        if ($described === []) {
            return 'array{' . implode(', ', $members) . '}';
        }

        $lines = ['array{'];

        foreach ($names as $index => $name) {
            foreach (preg_split('/\r?\n/', self::descriptionOf($properties[$name])) ?: [] as $line) {
                if (trim($line) !== '') {
                    $lines[] = self::INDENT . '// ' . trim($line);
                }
            }

            $lines[] = self::INDENT . str_replace("\n", "\n" . self::INDENT, $members[$index]) . ',';
        }

        foreach (array_slice($members, count($names)) as $member) {
            $lines[] = self::INDENT . $member . ',';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private static function propertyKey(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : "'" . str_replace("'", "\\'", $name) . "'";
    }
}
