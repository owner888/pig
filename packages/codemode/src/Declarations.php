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
 *
 * A property's comment also carries the constraints its type cannot express, as upstream's
 * `constraintTags()` writes them (`// @minimum 1 @maximum 100 @default 20`). Upstream's first tag,
 * `@integer`, is left out: TypeScript has only `number`, where the array shape here already says
 * `int`.
 */
final class Declarations
{
    /** Largest rendered input type, in characters, before it becomes `mixed`. */
    public const int DEFAULT_INPUT_SCHEMA_MAX_CHARS = 16_000;

    /** Local `$ref` expansions per rendered schema, so shared definitions cannot blow up the output. */
    private const int MAX_REF_EXPANSIONS = 32;

    private const string INDENT = '  ';

    /** Upstream's `TAG_KEYWORDS`, in its order. */
    private const array TAG_KEYWORDS = [
        'minimum',
        'exclusiveMinimum',
        'maximum',
        'exclusiveMaximum',
        'multipleOf',
        'minLength',
        'maxLength',
        'pattern',
        'format',
        'minItems',
        'maxItems',
    ];

    private const array LOWER_BOUNDS = ['minimum', 'exclusiveMinimum', 'minLength', 'minItems'];

    private const array UPPER_BOUNDS = ['maximum', 'exclusiveMaximum', 'maxLength', 'maxItems'];

    private const int MAX_TAG_CHARS = 80;

    /** Upstream's `LINE_TERMINATORS`: what splits a description into comment lines. */
    private const string LINE_TERMINATORS = '/[\r\n\x{2028}\x{2029}]/u';

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

    /**
     * "A result longer than `maxChars` is rendered again without the constraint tags, and renders
     * as `unknown` if it is still too long" — `mixed` here.
     */
    public static function schemaToType(mixed $schema, ?int $maxChars = null): string
    {
        $render = static function (bool $tags) use ($schema): string {
            $context = ['root' => $schema, 'resolving' => [], 'expansions' => 0, 'tags' => $tags];

            return self::toType($schema, $context);
        };
        $type = $render(true);

        if ($maxChars === null || strlen($type) <= $maxChars) {
            return $type;
        }

        $untagged = $render(false);

        return strlen($untagged) <= $maxChars ? $untagged : 'mixed';
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

        $comments = [];

        foreach ($names as $name) {
            $lines = array_values(array_filter(
                array_map(trim(...), preg_split(self::LINE_TERMINATORS, self::descriptionOf($properties[$name])) ?: []),
                static fn (string $line): bool => $line !== '',
            ));
            $tags = $context['tags'] ? self::constraintTags($properties[$name]) : '';
            $comments[] = $tags !== '' ? [...$lines, $tags] : $lines;
        }

        if (array_filter($comments) === []) {
            return 'array{' . implode(', ', $members) . '}';
        }

        $lines = ['array{'];

        foreach ($comments as $index => $comment) {
            foreach ($comment as $line) {
                $lines[] = self::INDENT . '// ' . $line;
            }

            $lines[] = self::INDENT . str_replace("\n", "\n" . self::INDENT, $members[$index]) . ',';
        }

        foreach (array_slice($members, count($names)) as $member) {
            $lines[] = self::INDENT . $member . ',';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * Upstream's `constraintTags()`: "Constraints of a property that its TypeScript type cannot
     * express, as JSDoc-style tags: `@integer @minimum 1 @maximum 100 @default 20`. Empty when there
     * are none." Without `@integer` — see the class comment.
     */
    private static function constraintTags(mixed $schema): string
    {
        if (!self::isObject($schema)) {
            return '';
        }

        $property = $schema;
        // "Optional fields such as Pydantic's `int | None` keep their constraints in the non-null
        // branch. The branch's constraints apply together with the property's, so both are read."
        $variants = is_array($property['anyOf'] ?? null) ? $property['anyOf'] : ($property['oneOf'] ?? null);
        $nonNull = is_array($variants)
            ? array_values(array_filter($variants, static fn (mixed $variant): bool => !(is_array($variant) && ($variant['type'] ?? null) === 'null')))
            : [];
        $branch = count($nonNull) === 1 ? $nonNull[0] : null;
        $branches = self::isObject($branch) ? [$property, $branch] : [$property];
        // "Draft-04 and OpenAPI 3.0 make `minimum`/`maximum` exclusive with a boolean
        // `exclusiveMinimum`/`exclusiveMaximum`."
        $schemas = array_map(static function (array $one): array {
            if (($one['exclusiveMinimum'] ?? null) === true) {
                $one['exclusiveMinimum'] = $one['minimum'] ?? null;
                unset($one['minimum']);
            }

            if (($one['exclusiveMaximum'] ?? null) === true) {
                $one['exclusiveMaximum'] = $one['maximum'] ?? null;
                unset($one['maximum']);
            }

            return $one;
        }, $branches);

        $tags = [];

        foreach (self::TAG_KEYWORDS as $keyword) {
            $values = array_map(static fn (array $one): mixed => $one[$keyword] ?? null, $schemas);
            $lower = in_array($keyword, self::LOWER_BOUNDS, true);

            if ($lower || in_array($keyword, self::UPPER_BOUNDS, true)) {
                $numbers = array_values(array_filter($values, static fn (mixed $v): bool => is_int($v) || is_float($v)));

                if ($numbers !== []) {
                    $tags[] = "@{$keyword} " . self::numberText($lower ? max($numbers) : min($numbers));
                }

                continue;
            }

            $seen = [];

            foreach ($values as $value) {
                if (!is_int($value) && !is_float($value) && !is_string($value)) {
                    continue;
                }

                $text = is_string($value) ? $value : self::numberText($value);

                if (!isset($seen[$text])) {
                    $seen[$text] = true;
                    $tags[] = "@{$keyword} {$text}";
                }
            }
        }

        if (array_filter($schemas, static fn (array $one): bool => ($one['uniqueItems'] ?? null) === true) !== []) {
            $tags[] = '@uniqueItems';
        }

        // "Annotations, unlike constraints, come from the property itself."
        if (array_key_exists('default', $property) && $property['default'] !== null) {
            $tags[] = '@default ' . json_encode($property['default'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (($property['deprecated'] ?? null) === true) {
            $tags[] = '@deprecated';
        }

        $tags = array_filter($tags, static fn (string $tag): bool => mb_strlen($tag) <= self::MAX_TAG_CHARS);

        return implode(' ', array_map(
            static fn (string $tag): string => (string) preg_replace_callback(
                self::LINE_TERMINATORS,
                static fn (array $m): string => sprintf('\\u%04x', mb_ord($m[0])),
                $tag,
            ),
            $tags,
        ));
    }

    /** A number as JavaScript's `${value}` writes it: `1` for `1.0`. */
    private static function numberText(int|float $value): string
    {
        return is_float($value) && floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }

    private static function propertyKey(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : "'" . str_replace("'", "\\'", $name) . "'";
    }
}
