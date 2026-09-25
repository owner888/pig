<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Checking a value against a JSON Schema, in the subset a tool's parameters use.
 *
 * Upstream's `utils/validation.ts` compiles the schema with AJV and `ajv-formats`. Nothing like
 * that is bundled here, so this is the part of draft-07 that describes an LLM tool's input,
 * written out — and **an unknown keyword is ignored, never a failure**: a schema using something
 * this does not implement has to keep working, or adding a keyword to a tool would break the tool
 * rather than tighten it.
 *
 * What is checked: `type` (one or several), `enum`, `const`, `required`, `properties`,
 * `additionalProperties`, `items`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`,
 * `multipleOf`, `minLength`, `maxLength`, `pattern`, `minItems`, `maxItems`, `uniqueItems`,
 * `minProperties`, `maxProperties`, `anyOf`, `oneOf`, `allOf`, `not`.
 *
 * What is not, each for a reason:
 *
 * - **`format`.** `ajv-formats` makes `format: "email"` an assertion; in draft-07 proper it is an
 *   annotation. A tool that needs an email checked has a better place to check it than a schema
 *   the model is being graded against.
 * - **`$ref`, `$defs`, `definitions`.** A reference needs a resolver and a cycle guard, and a
 *   tool's parameters are a flat description of one call's arguments — every schema in either
 *   tree is self-contained.
 * - **`dependencies`, `if`/`then`/`else`, `patternProperties`, `propertyNames`,
 *   `contains`.** Nothing in either tree uses them; they arrive when something does.
 *
 * The messages are AJV's wording (`must be string`, `must have required property 'x'`) rather than
 * invented ones. They are read by the **model**, which then corrects itself and calls again — and
 * AJV's phrasing is what it has seen everywhere else.
 */
final class JsonSchema
{
    /**
     * Everything wrong with `$value`, as `path: message` — empty when it checks out.
     *
     * The path is a JSON pointer with the leading `/` removed, and `root` for the value itself,
     * which is upstream's formatting. One difference: a missing property is reported at **its own
     * path**, so a nested one reads `edits/0/path` where upstream reports the bare property name
     * and loses where it was missing from.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function errors(array $schema, mixed $value): array
    {
        $problems = [];
        self::check($schema, $value, '', $problems);

        return $problems;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function check(array $schema, mixed $value, string $path, array &$problems): void
    {
        // An empty schema accepts everything, which is what `{}` means and what a tool with no
        // parameters declares.
        if ($schema === []) {
            return;
        }

        self::checkType($schema, $value, $path, $problems);
        self::checkEnum($schema, $value, $path, $problems);
        self::checkNumber($schema, $value, $path, $problems);
        self::checkString($schema, $value, $path, $problems);
        self::checkArray($schema, $value, $path, $problems);
        self::checkObject($schema, $value, $path, $problems);
        self::checkCombinators($schema, $value, $path, $problems);
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkType(array $schema, mixed $value, string $path, array &$problems): void
    {
        $type = $schema['type'] ?? null;

        if ($type === null) {
            return;
        }

        // `type` may be a list — `["string", "null"]` is how an optional string is often written.
        $allowed = is_array($type) ? array_values(array_filter($type, is_string(...))) : [$type];

        if (!is_string($type) && $allowed === []) {
            return;
        }

        foreach ($allowed as $one) {
            if (self::isType($one, $value)) {
                return;
            }
        }

        $problems[] = self::problem($path, 'must be ' . implode(',', $allowed));
    }

    private static function isType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // JSON has one number type, so a whole float is an integer — AJV agrees, and a
            // provider that sends `2.0` for a count is sending 2.
            'integer' => is_int($value) || (is_float($value) && !is_nan($value) && floor($value) === $value),
            'number' => (is_int($value) || is_float($value)) && !is_bool($value),
            'boolean' => is_bool($value),
            // **The one thing PHP makes hard.** `json_decode(assoc: true)` gives an array for both
            // a JSON array and a JSON object, so the two are told apart by whether the keys are
            // `0..n`. `[]` is both an empty list and an empty object, and satisfies either — which
            // is right: there is no third answer, and rejecting one of them would reject a valid
            // document for being indistinguishable from another valid one.
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (array_is_list($value) ? $value === [] : true),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkEnum(array $schema, mixed $value, string $path, array &$problems): void
    {
        if (array_key_exists('const', $schema) && $schema['const'] !== $value) {
            $problems[] = self::problem($path, 'must be equal to constant');
        }

        $enum = $schema['enum'] ?? null;

        if (is_array($enum) && !in_array($value, $enum, true)) {
            $problems[] = self::problem($path, 'must be equal to one of the allowed values');
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkNumber(array $schema, mixed $value, string $path, array &$problems): void
    {
        if (!is_int($value) && !is_float($value) || is_bool($value)) {
            return;
        }

        foreach (
            [
                'minimum' => ['<', 'must be >= '],
                'maximum' => ['>', 'must be <= '],
                'exclusiveMinimum' => ['<=', 'must be > '],
                'exclusiveMaximum' => ['>=', 'must be < '],
            ] as $keyword => [$operator, $message]
        ) {
            $bound = $schema[$keyword] ?? null;

            if (!is_int($bound) && !is_float($bound)) {
                continue;
            }

            $broken = match ($operator) {
                '<' => $value < $bound,
                '>' => $value > $bound,
                '<=' => $value <= $bound,
                default => $value >= $bound,
            };

            if ($broken) {
                $problems[] = self::problem($path, $message . $bound);
            }
        }

        $step = $schema['multipleOf'] ?? null;

        if (!is_int($step) && !is_float($step) || $step <= 0) {
            return;
        }

        // Whether the quotient is a whole number, to a tolerance — **not** `fmod(…) === 0.0`, and
        // not a one-sided tolerance on `fmod` either, which was the first attempt: `fmod(0.3, 0.1)`
        // is `0.0999999999999999778`, which is close to *the divisor* rather than to zero, so a
        // check for "remainder near 0" calls 0.3 not a multiple of 0.1.
        //
        // AJV asks `Number.isInteger(value / multipleOf)` and therefore does reject that, which is
        // the standard's literal reading and is **deliberately not copied**: the reader of this
        // message is the model, and "0.3 must be multiple of 0.1" is a correction it cannot act on.
        $quotient = (float) $value / (float) $step;

        if (abs($quotient - round($quotient)) > 1e-9) {
            $problems[] = self::problem($path, "must be multiple of {$step}");
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkString(array $schema, mixed $value, string $path, array &$problems): void
    {
        if (!is_string($value)) {
            return;
        }

        // Characters, not bytes: a `maxLength` of 10 on a Chinese string is ten characters, and
        // `strlen` would make it three. JSON Schema counts code points.
        $length = mb_strlen($value, 'UTF-8');
        $min = $schema['minLength'] ?? null;
        $max = $schema['maxLength'] ?? null;

        if (is_int($min) && $length < $min) {
            $problems[] = self::problem($path, "must NOT have fewer than {$min} characters");
        }

        if (is_int($max) && $length > $max) {
            $problems[] = self::problem($path, "must NOT have more than {$max} characters");
        }

        $pattern = $schema['pattern'] ?? null;

        if (!is_string($pattern) || $pattern === '') {
            return;
        }

        // A schema's `pattern` is an ECMA-262 regex with no delimiters, so one is added — and `#`
        // rather than `/`, because a path pattern is full of slashes and escaping them here would
        // change the pattern. `u` for the same reason `mb_strlen` is used above.
        $quoted = '#' . str_replace('#', '\\#', $pattern) . '#u';

        // A pattern that is not a valid PCRE is the schema's mistake, not the value's, and
        // reporting it against the value would send the model looking in the wrong place. The
        // warning is swallowed with a handler rather than `@`, which this project forbids and the
        // linter enforces.
        set_error_handler(static fn (): bool => true);

        try {
            $matched = preg_match($quoted, $value);
        } finally {
            restore_error_handler();
        }

        if ($matched === 1 || $matched === false) {
            return;
        }

        $problems[] = self::problem($path, "must match pattern \"{$pattern}\"");
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkArray(array $schema, mixed $value, string $path, array &$problems): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            return;
        }

        $count = count($value);
        $min = $schema['minItems'] ?? null;
        $max = $schema['maxItems'] ?? null;

        if (is_int($min) && $count < $min) {
            $problems[] = self::problem($path, "must NOT have fewer than {$min} items");
        }

        if (is_int($max) && $count > $max) {
            $problems[] = self::problem($path, "must NOT have more than {$max} items");
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            // Compared encoded, because `in_array` with objects would compare them by value in a
            // way that depends on key order — and `{"a":1,"b":2}` and `{"b":2,"a":1}` are the same
            // document.
            $seen = array_map(static fn (mixed $item): string => self::identity($item), $value);

            if (count(array_unique($seen)) !== $count) {
                $problems[] = self::problem($path, 'must NOT have duplicate items');
            }
        }

        $items = $schema['items'] ?? null;

        if (!is_array($items)) {
            return;
        }

        // The tuple form — `items` as a list of schemas, one per position — is draft-07's and is
        // handled by position; a single schema applies to every element.
        $tuple = array_is_list($items);

        foreach ($value as $index => $element) {
            $each = $tuple ? ($items[$index] ?? null) : $items;

            if (is_array($each)) {
                self::check($each, $element, $path . '/' . $index, $problems);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkObject(array $schema, mixed $value, string $path, array &$problems): void
    {
        // A list is not an object here, except for `[]`, which is both — see `isType()`.
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            return;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $value)) {
                // At its own path, unlike upstream, which reports the bare property name and so
                // cannot say which object it was missing from.
                $problems[] = self::problem($path . '/' . $name, "must have required property '{$name}'");
            }
        }

        $count = count($value);
        $min = $schema['minProperties'] ?? null;
        $max = $schema['maxProperties'] ?? null;

        if (is_int($min) && $count < $min) {
            $problems[] = self::problem($path, "must NOT have fewer than {$min} properties");
        }

        if (is_int($max) && $count > $max) {
            $problems[] = self::problem($path, "must NOT have more than {$max} properties");
        }

        $additional = $schema['additionalProperties'] ?? null;

        foreach ($value as $name => $each) {
            $declared = $properties[(string) $name] ?? null;

            if (is_array($declared)) {
                self::check($declared, $each, $path . '/' . $name, $problems);

                continue;
            }

            if ($additional === false) {
                $problems[] = self::problem($path, 'must NOT have additional properties');

                continue;
            }

            // `additionalProperties` as a schema: everything undeclared has to match it.
            if (is_array($additional)) {
                self::check($additional, $each, $path . '/' . $name, $problems);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $problems
     */
    private static function checkCombinators(array $schema, mixed $value, string $path, array &$problems): void
    {
        $anyOf = $schema['anyOf'] ?? null;

        if (is_array($anyOf) && $anyOf !== []) {
            // The branches' own complaints are dropped on purpose: a value that matched none of
            // four alternatives produces four sets of reasons, and the useful sentence is that it
            // matched none of them.
            if (self::matching($anyOf, $value) === 0) {
                $problems[] = self::problem($path, 'must match a schema in anyOf');
            }
        }

        $oneOf = $schema['oneOf'] ?? null;

        if (is_array($oneOf) && $oneOf !== [] && self::matching($oneOf, $value) !== 1) {
            $problems[] = self::problem($path, 'must match exactly one schema in oneOf');
        }

        $allOf = $schema['allOf'] ?? null;

        if (is_array($allOf)) {
            foreach ($allOf as $branch) {
                if (is_array($branch)) {
                    // Reported at this path, because `allOf` is a conjunction about *this* value
                    // rather than a choice between shapes.
                    self::check($branch, $value, $path, $problems);
                }
            }
        }

        $not = $schema['not'] ?? null;

        if (is_array($not) && self::errors($not, $value) === []) {
            $problems[] = self::problem($path, 'must NOT be valid');
        }
    }

    /**
     * How many of these schemas the value satisfies.
     *
     * @param array<mixed> $branches
     */
    private static function matching(array $branches, mixed $value): int
    {
        $matched = 0;

        foreach ($branches as $branch) {
            if (is_array($branch) && self::errors($branch, $value) === []) {
                $matched++;
            }
        }

        return $matched;
    }

    /** A value's identity for `uniqueItems`, with object keys sorted so order does not count. */
    private static function identity(mixed $value): string
    {
        if (is_array($value) && !array_is_list($value)) {
            ksort($value);
            $value = array_map(static fn (mixed $each): mixed => is_array($each) ? self::identity($each) : $each, $value);
        }

        return (string) json_encode($value);
    }

    private static function problem(string $path, string $message): string
    {
        // Upstream's formatting: the pointer without its leading slash, and `root` for the value
        // itself.
        return ($path === '' ? 'root' : ltrim($path, '/')) . ': ' . $message;
    }
}
