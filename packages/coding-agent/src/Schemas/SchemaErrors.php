<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Schemas;

/**
 * What is wrong with a configuration document, worded and ordered as upstream reports it.
 *
 * Upstream checks `models.json`, theme files and keybinding values with typebox: `Compile(schema)`
 * then `.Errors(value)`, and builds its messages from what comes back. pig adds no third-party
 * package, so this is that `Errors()` written out for the JSON Schema keywords the published
 * configuration schemas use, keeping the three things a reader of the message sees:
 *
 * - **The order.** typebox walks a schema in a fixed keyword order — `type`; for an object
 *   `required`, `additionalProperties`, `patternProperties`, `properties`, `minProperties`,
 *   `maxProperties`; for a list `items`, `maxItems`, `minItems`, `uniqueItems`; for a string
 *   `maxLength`, `minLength`, `pattern`; for a number `exclusiveMaximum`, `exclusiveMinimum`,
 *   `maximum`, `minimum`, `multipleOf`; then `$ref`, `const`, `enum`, `not`, `allOf`, `anyOf`,
 *   `oneOf` — and every keyword is checked even after one has failed.
 * - **The words.** typebox's `en_US` locale: `must be string`, `must have required properties
 *   name, colors`, `must not have additional properties` (and before it `schema is false` at each
 *   property it refused), `must be <= 255`, `must match a schema in anyOf` — after the errors of
 *   every branch that failed.
 * - **The limit.** typebox stops at eight errors (its `maxErrors`), each branch of a union counting
 *   its own eight before they are handed up.
 *
 * Paths are typebox's instance paths: `/colors/accent`, keys as they are, not escaped; `''` is the
 * document itself.
 *
 * A document is decoded JSON, either way PHP decodes it: a JSON object is a `\stdClass` or an
 * array with string keys, a JSON list a list. `[]` is both an empty object and an empty list, and
 * passes as either — the one ambiguity the array decoding leaves, and a caller that needs `{}` and
 * `[]` told apart decodes into objects.
 *
 * Keywords not named above are not checked — `format`, `propertyNames`, `dependentRequired`,
 * `if`/`then`/`else`, `prefixItems`, `contains`, the `unevaluated*` pair and the dynamic references.
 * None of pig's schemas uses them; `ConfigSchemasTest` says so if one ever does.
 */
final class SchemaErrors
{
    /** typebox's `Settings.maxErrors`. */
    private const int MAX_ERRORS = 8;

    /** @var list<array{keyword: string, instancePath: string, params: array<string, mixed>, message: string}> */
    private array $errors = [];

    /**
     * @param array<string, mixed>|bool $root the whole schema, which `$ref` resolves against
     */
    private function __construct(private readonly array|bool $root, private readonly bool $assoc)
    {
    }

    /**
     * Every error in $value against $schema, at most eight, in typebox's order.
     *
     * @param array<string, mixed>|bool      $schema
     * @param array<string, mixed>|bool|null $root   the document $schema came from, for its `$ref`s;
     *                                               $schema itself when null
     * @param bool                           $assoc  whether $value was decoded with objects as
     *                                               arrays, which makes `[]` an empty object as well
     *                                               as an empty list; decoded into `\stdClass`es,
     *                                               `[]` is a list and nothing else
     * @return list<array{keyword: string, instancePath: string, params: array<string, mixed>, message: string}>
     */
    public static function of(array|bool $schema, mixed $value, array|bool|null $root = null, string $instancePath = '', bool $assoc = false): array
    {
        $context = new self($root ?? $schema, $assoc);
        $context->schema($schema, $value, $instancePath);

        return $context->errors;
    }

    /** Whether $value satisfies $schema. */
    public static function check(array|bool $schema, mixed $value, array|bool|null $root = null, bool $assoc = false): bool
    {
        return self::of($schema, $value, $root, assoc: $assoc) === [];
    }

    /**
     * The value at a JSON pointer into a schema — `#/$defs/ColorValue` — or null when there is none.
     *
     * @param array<string, mixed>|bool $root
     * @return array<string, mixed>|bool|null
     */
    public static function resolve(array|bool $root, string $pointer): array|bool|null
    {
        if (!str_starts_with($pointer, '#')) {
            return null;
        }

        $node = $root;

        foreach (array_slice(explode('/', substr($pointer, 1)), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_array($node) || is_bool($node) ? $node : null;
    }

    // ---- typebox's ErrorSchema ----------------------------------------------------------------

    /** @param array<string, mixed>|bool $schema */
    private function schema(array|bool $schema, mixed $value, string $path): bool
    {
        if ($this->atCapacity()) {
            return false;
        }

        if (is_bool($schema)) {
            return $schema || $this->add('boolean', $path, []);
        }

        $ok = true;

        if (array_key_exists('type', $schema)) {
            $ok = $this->type($schema['type'], $value, $path) && $ok;
        }

        if ($this->isObject($value)) {
            $ok = $this->objectKeywords($schema, $value, $path) && $ok;
        }

        if (self::isList($value)) {
            $ok = $this->arrayKeywords($schema, $value, $path) && $ok;
        }

        if (is_string($value)) {
            $ok = $this->stringKeywords($schema, $value, $path) && $ok;
        }

        if (self::isNumber($value)) {
            $ok = $this->numberKeywords($schema, $value, $path) && $ok;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ok = $this->ref($schema['$ref'], $value, $path) && $ok;
        }

        if (array_key_exists('const', $schema) && !$this->equal($value, $schema['const'])) {
            $ok = $this->add('const', $path, ['allowedValue' => $schema['const']]) && $ok;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $match = false;
            foreach ($schema['enum'] as $allowed) {
                $match = $match || $this->equal($value, $allowed);
            }
            if (!$match) {
                $ok = $this->add('enum', $path, ['allowedValues' => $schema['enum']]) && $ok;
            }
        }

        if (array_key_exists('not', $schema) && (is_array($schema['not']) || is_bool($schema['not']))
            && self::of($schema['not'], $value, $this->root, $path, $this->assoc) === []) {
            $ok = $this->add('not', $path, []) && $ok;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $ok = $this->allOf($schema['allOf'], $value, $path) && $ok;
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $ok = $this->anyOf($schema['anyOf'], $value, $path) && $ok;
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $ok = $this->oneOf($schema['oneOf'], $value, $path) && $ok;
        }

        return $ok;
    }

    private function type(mixed $type, mixed $value, string $path): bool
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $one) {
            if (is_string($one) && $this->isType($one, $value)) {
                return true;
            }
        }

        return $this->add('type', $path, ['type' => $type]);
    }

    /** @param array<string, mixed> $schema */
    private function objectKeywords(array $schema, mixed $value, string $path): bool
    {
        $entries = self::entries($value);
        $ok = true;

        if (isset($schema['required']) && is_array($schema['required'])) {
            $missing = array_values(array_filter($schema['required'], static fn (mixed $key): bool => is_string($key) && !array_key_exists($key, $entries)));
            if ($missing !== []) {
                $ok = $this->add('required', $path, ['requiredProperties' => $missing]) && $ok;
            }
        }

        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $patterns = isset($schema['patternProperties']) && is_array($schema['patternProperties']) ? $schema['patternProperties'] : [];

        if (array_key_exists('additionalProperties', $schema) && (is_array($schema['additionalProperties']) || is_bool($schema['additionalProperties']))) {
            $additional = [];

            foreach ($entries as $key => $each) {
                $key = (string) $key;
                // typebox's `GetPropertiesPattern()`: a key named by `properties` or matched by a
                // `patternProperties` pattern is not additional.
                if (array_key_exists($key, $properties) || self::matchesAnyPattern(array_keys($patterns), $key)) {
                    continue;
                }

                if (!$this->schema($schema['additionalProperties'], $each, "{$path}/{$key}")) {
                    $additional[] = $key;
                }
            }

            if ($additional !== []) {
                $ok = $this->add('additionalProperties', $path, ['additionalProperties' => $additional]) && $ok;
            }
        }

        foreach ($patterns as $pattern => $each) {
            foreach ($entries as $key => $item) {
                if (self::matches((string) $pattern, (string) $key) && (is_array($each) || is_bool($each))) {
                    $ok = $this->schema($each, $item, "{$path}/{$key}") && $ok;
                }
            }
        }

        foreach ($properties as $key => $each) {
            if (array_key_exists((string) $key, $entries) && (is_array($each) || is_bool($each))) {
                $ok = $this->schema($each, $entries[(string) $key], "{$path}/{$key}") && $ok;
            }
        }

        if (isset($schema['minProperties']) && count($entries) < $schema['minProperties']) {
            $ok = $this->add('minProperties', $path, ['limit' => $schema['minProperties']]) && $ok;
        }

        if (isset($schema['maxProperties']) && count($entries) > $schema['maxProperties']) {
            $ok = $this->add('maxProperties', $path, ['limit' => $schema['maxProperties']]) && $ok;
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed>          $value
     */
    private function arrayKeywords(array $schema, array $value, string $path): bool
    {
        $ok = true;

        if (array_key_exists('items', $schema)) {
            $items = $schema['items'];

            if (is_array($items) && array_is_list($items) && $items !== []) {
                // The draft-07 tuple form: one schema per position.
                foreach ($items as $index => $each) {
                    if ($index < count($value) && (is_array($each) || is_bool($each))) {
                        $ok = $this->schema($each, $value[$index], "{$path}/{$index}") && $ok;
                    }
                }
            } elseif (is_array($items) || is_bool($items)) {
                foreach ($value as $index => $each) {
                    $ok = $this->schema($items, $each, "{$path}/{$index}") && $ok;
                }
            }
        }

        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            $ok = $this->add('maxItems', $path, ['limit' => $schema['maxItems']]) && $ok;
        }

        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $ok = $this->add('minItems', $path, ['limit' => $schema['minItems']]) && $ok;
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $each) {
                foreach ($seen as $earlier) {
                    if ($this->equal($each, $earlier)) {
                        return $this->add('uniqueItems', $path, []) && false;
                    }
                }
                $seen[] = $each;
            }
        }

        return $ok;
    }

    /** @param array<string, mixed> $schema */
    private function stringKeywords(array $schema, string $value, string $path): bool
    {
        $ok = true;

        if (isset($schema['maxLength']) && self::length($value) > $schema['maxLength']) {
            $ok = $this->add('maxLength', $path, ['limit' => $schema['maxLength']]) && $ok;
        }

        if (isset($schema['minLength']) && self::length($value) < $schema['minLength']) {
            $ok = $this->add('minLength', $path, ['limit' => $schema['minLength']]) && $ok;
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && !self::matches($schema['pattern'], $value)) {
            $ok = $this->add('pattern', $path, ['pattern' => $schema['pattern']]) && $ok;
        }

        return $ok;
    }

    /** @param array<string, mixed> $schema */
    private function numberKeywords(array $schema, int|float $value, string $path): bool
    {
        $ok = true;
        $limits = [
            'exclusiveMaximum' => ['<', static fn (int|float $limit): bool => $value < $limit],
            'exclusiveMinimum' => ['>', static fn (int|float $limit): bool => $value > $limit],
            'maximum' => ['<=', static fn (int|float $limit): bool => $value <= $limit],
            'minimum' => ['>=', static fn (int|float $limit): bool => $value >= $limit],
        ];

        foreach ($limits as $keyword => [$comparison, $holds]) {
            if (isset($schema[$keyword]) && self::isNumber($schema[$keyword]) && !$holds($schema[$keyword])) {
                $ok = $this->add($keyword, $path, ['comparison' => $comparison, 'limit' => $schema[$keyword]]) && $ok;
            }
        }

        if (isset($schema['multipleOf']) && self::isNumber($schema['multipleOf']) && $schema['multipleOf'] > 0) {
            $quotient = $value / $schema['multipleOf'];
            if (abs($quotient - round($quotient)) > 1e-9) {
                $ok = $this->add('multipleOf', $path, ['multipleOf' => $schema['multipleOf']]) && $ok;
            }
        }

        return $ok;
    }

    private function ref(string $pointer, mixed $value, string $path): bool
    {
        $target = self::resolve($this->root, $pointer);

        if ($target === null) {
            return false;
        }

        $inner = new self($this->root, $this->assoc);
        $result = $inner->schema($target, $value, $path);

        if (!$result) {
            foreach ($inner->errors as $error) {
                $this->push($error);
            }
        }

        return $result;
    }

    /** @param array<mixed> $branches */
    private function allOf(array $branches, mixed $value, string $path): bool
    {
        $failed = [];

        foreach ($branches as $branch) {
            $inner = new self($this->root, $this->assoc);
            if ((is_array($branch) || is_bool($branch)) && !$inner->schema($branch, $value, $path)) {
                $failed[] = $inner;
            }
        }

        foreach ($failed as $inner) {
            foreach ($inner->errors as $error) {
                $this->push($error);
            }
        }

        return $failed === [];
    }

    /** @param array<mixed> $branches */
    private function anyOf(array $branches, mixed $value, string $path): bool
    {
        $failed = [];

        foreach ($branches as $branch) {
            $inner = new self($this->root, $this->assoc);
            if ((is_array($branch) || is_bool($branch)) && $inner->schema($branch, $value, $path)) {
                return true;
            }
            $failed[] = $inner;
        }

        foreach ($failed as $inner) {
            foreach ($inner->errors as $error) {
                $this->push($error);
            }
        }

        return $this->add('anyOf', $path, []);
    }

    /** @param array<mixed> $branches */
    private function oneOf(array $branches, mixed $value, string $path): bool
    {
        $failed = [];
        $passing = [];

        foreach ($branches as $index => $branch) {
            $inner = new self($this->root, $this->assoc);
            if ((is_array($branch) || is_bool($branch)) && $inner->schema($branch, $value, $path)) {
                $passing[] = $index;
            } else {
                $failed[] = $inner;
            }
        }

        if (count($passing) === 1) {
            return true;
        }

        if ($passing === []) {
            foreach ($failed as $inner) {
                foreach ($inner->errors as $error) {
                    $this->push($error);
                }
            }
        }

        return $this->add('oneOf', $path, ['passingSchemas' => $passing]);
    }

    // ---- errors -------------------------------------------------------------------------------

    private function atCapacity(): bool
    {
        return count($this->errors) >= self::MAX_ERRORS;
    }

    /**
     * Record an error and answer false, as typebox's `context.AddError()` does.
     *
     * @param array<string, mixed> $params
     */
    private function add(string $keyword, string $path, array $params): bool
    {
        $this->push(['keyword' => $keyword, 'instancePath' => $path, 'params' => $params, 'message' => self::message($keyword, $params)]);

        return false;
    }

    /** @param array{keyword: string, instancePath: string, params: array<string, mixed>, message: string} $error */
    private function push(array $error): void
    {
        if (!$this->atCapacity()) {
            $this->errors[] = $error;
        }
    }

    /**
     * typebox's `en_US` locale, for the keywords checked here.
     *
     * @param array<string, mixed> $params
     */
    private static function message(string $keyword, array $params): string
    {
        $limit = static fn (): string => self::number($params['limit']);

        return match ($keyword) {
            'additionalProperties' => 'must not have additional properties',
            'anyOf' => 'must match a schema in anyOf',
            'boolean' => 'schema is false',
            'const' => 'must be equal to constant',
            'enum' => 'must be equal to one of the allowed values',
            'exclusiveMaximum', 'exclusiveMinimum', 'maximum', 'minimum' => "must be {$params['comparison']} {$limit()}",
            'maxItems' => "must not have more than {$limit()} items",
            'maxLength' => "must not have more than {$limit()} characters",
            'maxProperties' => "must not have more than {$limit()} properties",
            'minItems' => "must not have fewer than {$limit()} items",
            'minLength' => "must not have fewer than {$limit()} characters",
            'minProperties' => "must not have fewer than {$limit()} properties",
            'multipleOf' => 'must be multiple of ' . self::number($params['multipleOf']),
            'not' => 'must not be valid',
            'oneOf' => 'must match exactly one schema in oneOf',
            'pattern' => "must match pattern \"{$params['pattern']}\"",
            'required' => 'must have required properties ' . implode(', ', $params['requiredProperties']),
            'type' => is_string($params['type']) ? "must be {$params['type']}" : 'must be either ' . implode(' or ', $params['type']),
            'uniqueItems' => 'must not have duplicate items',
            default => 'an unknown validation error occurred',
        };
    }

    /** A number as JavaScript's `String()` writes it: `128000`, `0.5`. */
    private static function number(mixed $number): string
    {
        if (is_float($number) && is_finite($number) && floor($number) === $number && abs($number) < 1e21) {
            return (string) (int) $number;
        }

        return is_float($number) ? (string) json_encode($number) : (string) $number;
    }

    // ---- values -------------------------------------------------------------------------------

    /** typebox's type names, JSON's way: one number type, objects that are not lists. */
    private function isType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => $this->isObject($value),
            'array' => self::isList($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => self::isNumber($value),
            'null' => $value === null,
            'string' => is_string($value),
            default => true,
        };
    }

    private function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && (($this->assoc && $value === []) || !array_is_list($value)));
    }

    /** @phpstan-assert-if-true list<mixed> $value */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @phpstan-assert-if-true int|float $value */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /** @return array<string, mixed> */
    private static function entries(mixed $value): array
    {
        $entries = [];

        foreach ($value instanceof \stdClass ? get_object_vars($value) : $value as $key => $each) {
            $entries[(string) $key] = $each;
        }

        return $entries;
    }

    /** typebox's equality: `===` for a scalar (one number type), structural for the rest. */
    private function equal(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return $a == $b;
        }

        if ($this->isObject($a) && $this->isObject($b) && !(self::isList($a) && self::isList($b))) {
            $a = self::entries($a);
            $b = self::entries($b);

            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $key => $each) {
                if (!array_key_exists($key, $b) || !$this->equal($each, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (self::isList($a) && self::isList($b)) {
            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $index => $each) {
                if (!$this->equal($each, $b[$index])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /** typebox counts graphemes for `minLength` and `maxLength`. */
    private static function length(string $value): int
    {
        $length = function_exists('grapheme_strlen') ? grapheme_strlen($value) : false;

        return is_int($length) ? $length : mb_strlen($value);
    }

    /** @param list<int|string> $patterns */
    private static function matchesAnyPattern(array $patterns, string $key): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches((string) $pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A schema `pattern` tested as JavaScript's `new RegExp(pattern, 'u')` tests it: `$` is the end of
     * the string and nothing before a final newline (PCRE's `D`), and `.` is any character but a
     * line terminator.
     */
    private static function matches(string $pattern, string $value): bool
    {
        static $compiled = [];
        $compiled[$pattern] ??= '#' . str_replace('#', '\\#', $pattern) . '#uD';

        return @preg_match($compiled[$pattern], $value) === 1;
    }
}
