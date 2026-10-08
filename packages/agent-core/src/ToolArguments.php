<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\Utils\JsonSchema;

/**
 * Checks a tool call's arguments against the schema the model was given.
 *
 * Upstream's `validateToolArguments`: run the schema, and throw a message the **model** reads. The
 * throw is the point — `AgentLoop` turns it into a tool result, so a model that got an argument
 * wrong is told what and calls again, where a crash would end the turn.
 *
 * The schema work is `Ai\Utils\JsonSchema`, which is upstream's AJV. **This used to be the
 * validator itself**, and covered two mistakes — a missing required property and a wrong primitive
 * type — with everything else passed through. That was defensible while there was nothing to check
 * a schema with and stopped being so once there was: two notions of what a tool's schema means is
 * the shape that goes wrong quietly, and the narrower one was the one nobody would have thought to
 * look at.
 *
 * What this file still decides is the **message**, which is upstream's format down to the blank
 * line before the arguments.
 */
final class ToolArguments
{
    /**
     * @return array<string, mixed> the arguments, with a null for an optional parameter dropped
     *         (see `normalizeOptionalNulls()`), when they check out
     * @throws InvalidToolArguments
     */
    public static function validate(Tool $tool, ToolCall $call): array
    {
        $arguments = self::normalizeOptionalNulls($call->arguments, $tool->parameters);

        if (!is_array($arguments)) {
            $arguments = $call->arguments;
        }

        // Upstream's next two steps: `Value.Convert(tool.parameters, args)`, then — for a schema that
        // is not a TypeBox one — `coerceWithJsonSchema(args, tool.parameters)`. Every pig schema is a
        // plain JSON-schema array, so the second is the one that applies, and it is ported below
        // line for line. `Value.Convert` is TypeBox's own and is not: pig has no TypeBox, and on a
        // plain schema upstream runs both, the second being pi's re-statement of the first for
        // schemas TypeBox did not build. Both convert the same primitives (a numeric string to a
        // number, "true"/"false" to a boolean, a number or boolean to a string); where TypeBox's goes
        // further, pig does not follow.
        //
        // Upstream's non-object branch (`return validator.Check(coerced) ? coerced : args`, no
        // throw) cannot be reached here: the arguments are always an array, and no coercion turns an
        // array into anything else.
        $coerced = self::coerceWithJsonSchema($arguments, $tool->parameters);

        if (is_array($coerced)) {
            $arguments = $coerced;
        }

        $problems = JsonSchema::errors($tool->parameters, $arguments);

        if ($problems === []) {
            return $arguments;
        }

        // The arguments the model sent, not the normalized or coerced ones — upstream prints
        // `toolCall.arguments`.
        throw new InvalidToolArguments(sprintf(
            "Validation failed for tool \"%s\":\n%s\n\nReceived arguments:\n%s",
            $call->name,
            implode("\n", array_map(static fn (string $problem): string => '  - ' . $problem, $problems)),
            json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * Upstream's `normalizeOptionalNulls()`, run before the schema: a property that is **present
     * and null**, **not required**, not a `$ref`, and **whose own schema rejects null** is removed,
     * as if the model had left it out. Recursively, through nested objects and arrays.
     *
     * Why it exists: a tool sent with strict JSON-schema sampling (`constrainedSampling`) has its
     * optional parameters rewritten by `ConstrainedSampling::makeStrictJsonSchema()` into required
     * ones that also accept null — strict mode allows no optional keys — so a model that does not
     * want `limit` sends `"limit": null`. The tool's own schema, which is what is checked here,
     * still says `integer`, and without this step `read` answered such a call with "limit: must be
     * integer". Checked against the tool's own schema rather than the strict one, as upstream does:
     * a parameter that already allows null keeps its null.
     *
     * `$value` and the result are PHP values; an object is an array with string keys, a list is a
     * list. An empty array is either, and has nothing to normalize.
     *
     * @param array<string, mixed> $schema
     */
    private static function normalizeOptionalNulls(mixed $value, array $schema): mixed
    {
        if (!is_array($value) || $value === []) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = $schema['items'] ?? null;

            if (is_array($items) && array_is_list($items) && $items !== []) {
                // Tuple form: each item against the schema at its own position.
                foreach ($value as $index => $item) {
                    if (is_array($items[$index] ?? null)) {
                        $value[$index] = self::normalizeOptionalNulls($item, $items[$index]);
                    }
                }
            } elseif (is_array($items)) {
                foreach ($value as $index => $item) {
                    $value[$index] = self::normalizeOptionalNulls($item, $items);
                }
            }

            return $value;
        }

        $properties = $schema['properties'] ?? null;

        if (!is_array($properties)) {
            return $value;
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($properties as $key => $propertySchema) {
            $key = (string) $key;

            if (!array_key_exists($key, $value)) {
                continue;
            }

            // Upstream's `getSubSchemaValidator(propertySchema)?.Check(null) === false`: a schema
            // that cannot be checked (here, one that is not an object) is not known to reject
            // null, so the null stays.
            if (
                $value[$key] === null
                && !in_array($key, $required, true)
                && is_array($propertySchema)
                && !is_string($propertySchema['$ref'] ?? null)
                && JsonSchema::errors($propertySchema, null) !== []
            ) {
                unset($value[$key]);
            } elseif (is_array($propertySchema)) {
                $value[$key] = self::normalizeOptionalNulls($value[$key], $propertySchema);
            }
        }

        return $value;
    }

    // ---- coercion: upstream's `coerceWithJsonSchema()` and its helpers ---------------------------

    /**
     * Upstream's `coerceWithJsonSchema()`: what the model sent, bent to what the schema says where
     * that is unambiguous — `"10"` for an integer becomes 10, `"true"` for a boolean becomes true,
     * `5` for a string becomes `"5"`. A model writing every argument as a string is common enough
     * that refusing `"10"` with "must be integer" made it call again for nothing.
     *
     * In upstream's order: `allOf` one after another, then `anyOf`, then `oneOf`; then the value's
     * own `type` — skipped when the type is a list and the value already is one of them, otherwise
     * the first type whose coercion changes the value wins; then into an object's properties (and
     * `additionalProperties` when that is a schema) and an array's items (tuple or single).
     *
     * JS mutates the object in place and returns it; PHP arrays are values, so each level returns
     * its result and the caller stores it. An object is an array with string keys, a list a list,
     * and `[]` is either — as `JsonSchema::isType()` reads them.
     */
    private static function coerceWithJsonSchema(mixed $value, mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $value;
        }

        $next = $value;

        if (is_array($schema['allOf'] ?? null) && array_is_list($schema['allOf'])) {
            foreach ($schema['allOf'] as $nested) {
                $next = self::coerceWithJsonSchema($next, $nested);
            }
        }

        if (is_array($schema['anyOf'] ?? null) && array_is_list($schema['anyOf'])) {
            $next = self::coerceWithUnionSchema($next, $schema['anyOf']);
        }

        if (is_array($schema['oneOf'] ?? null) && array_is_list($schema['oneOf'])) {
            $next = self::coerceWithUnionSchema($next, $schema['oneOf']);
        }

        $types = self::schemaTypes($schema);
        $matchesUnionMember = false;

        if (count($types) > 1) {
            foreach ($types as $type) {
                if (self::matchesJsonType($next, $type)) {
                    $matchesUnionMember = true;

                    break;
                }
            }
        }

        if ($types !== [] && !$matchesUnionMember) {
            foreach ($types as $type) {
                $candidate = self::coercePrimitiveByType($next, $type);

                if ($candidate !== $next) {
                    $next = $candidate;

                    break;
                }
            }
        }

        if (in_array('object', $types, true) && self::matchesJsonType($next, 'object')) {
            $next = self::coerceObject($next, $schema);
        }

        if (in_array('array', $types, true) && self::matchesJsonType($next, 'array')) {
            $next = self::coerceArray($next, $schema);
        }

        return $next;
    }

    /**
     * Upstream's `coerceWithUnionSchema()`: a value some branch already accepts is left alone;
     * otherwise the first branch that accepts the value once coerced for it wins; otherwise the
     * value is left as it was. A branch that cannot be checked (not a schema object) is skipped,
     * as upstream's `getSubSchemaValidator()` answering undefined is.
     *
     * @param list<mixed> $schemas
     */
    private static function coerceWithUnionSchema(mixed $value, array $schemas): mixed
    {
        foreach ($schemas as $schema) {
            if (is_array($schema) && JsonSchema::errors($schema, $value) === []) {
                return $value;
            }
        }

        foreach ($schemas as $schema) {
            // `structuredClone(value)` upstream; a PHP array is already a copy.
            $coerced = self::coerceWithJsonSchema($value, $schema);

            if (is_array($schema) && JsonSchema::errors($schema, $coerced) === []) {
                return $coerced;
            }
        }

        return $value;
    }

    /**
     * Upstream's `applySchemaObjectCoercion()`: each declared property the value has, against its
     * own schema; then, when `additionalProperties` is a schema, every undeclared key against it.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function coerceObject(array $value, array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($properties as $key => $propertySchema) {
            $key = (string) $key;

            if (!array_key_exists($key, $value)) {
                continue;
            }

            $value[$key] = self::coerceWithJsonSchema($value[$key], $propertySchema);
        }

        $additional = $schema['additionalProperties'] ?? null;

        // A schema object, not `true`/`false` — upstream's `typeof === "object"`.
        if (is_array($additional)) {
            foreach ($value as $key => $propertyValue) {
                if (array_key_exists((string) $key, $properties)) {
                    continue;
                }

                $value[$key] = self::coerceWithJsonSchema($propertyValue, $additional);
            }
        }

        return $value;
    }

    /**
     * Upstream's `applySchemaArrayCoercion()`: tuple `items` position by position (an item past the
     * tuple is left alone), a single `items` schema for every item.
     *
     * @param list<mixed>          $value
     * @param array<string, mixed> $schema
     * @return list<mixed>
     */
    private static function coerceArray(array $value, array $schema): array
    {
        $items = $schema['items'] ?? null;

        if (!is_array($items)) {
            return $value;
        }

        if (array_is_list($items) && $items !== []) {
            foreach ($value as $index => $item) {
                if (!array_key_exists($index, $items)) {
                    continue;
                }

                $value[$index] = self::coerceWithJsonSchema($item, $items[$index]);
            }

            return $value;
        }

        foreach ($value as $index => $item) {
            $value[$index] = self::coerceWithJsonSchema($item, $items);
        }

        return $value;
    }

    /**
     * Upstream's `coercePrimitiveByType()`, case for case. What it does not handle comes back as
     * it went in, which the caller reads as "this type had nothing to say".
     */
    private static function coercePrimitiveByType(mixed $value, string $type): mixed
    {
        switch ($type) {
            case 'number':
                if ($value === null) {
                    return 0;
                }

                if (is_string($value) && trim($value) !== '') {
                    $parsed = self::jsNumber($value);

                    if ($parsed !== null && is_finite((float) $parsed)) {
                        return $parsed;
                    }
                }

                if (is_bool($value)) {
                    return $value ? 1 : 0;
                }

                return $value;

            case 'integer':
                if ($value === null) {
                    return 0;
                }

                if (is_string($value) && trim($value) !== '') {
                    $parsed = self::jsNumber($value);

                    // `Number.isInteger(parsed)`: finite and whole. Returned as a PHP int where it
                    // fits, so `is_int()` holds for what JS would call an integer.
                    if ($parsed !== null && is_finite((float) $parsed) && floor((float) $parsed) === (float) $parsed) {
                        return is_float($parsed) && abs($parsed) < PHP_INT_MAX ? (int) $parsed : $parsed;
                    }
                }

                if (is_bool($value)) {
                    return $value ? 1 : 0;
                }

                return $value;

            case 'boolean':
                if ($value === null) {
                    return false;
                }

                if ($value === 'true') {
                    return true;
                }

                if ($value === 'false') {
                    return false;
                }

                // JS `value === 1` / `value === 0` on a number, which 1.0 and 0.0 also are.
                if ((is_int($value) || is_float($value)) && $value == 1) {
                    return true;
                }

                if ((is_int($value) || is_float($value)) && $value == 0) {
                    return false;
                }

                return $value;

            case 'string':
                if ($value === null) {
                    return '';
                }

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                if (is_int($value) || is_float($value)) {
                    return self::jsString($value);
                }

                return $value;

            case 'null':
                if ($value === '' || $value === 0 || $value === 0.0 || $value === false) {
                    return null;
                }

                return $value;

            default:
                return $value;
        }
    }

    /**
     * JS `Number(text)` for a string, or null where JS gives NaN: surrounding whitespace ignored,
     * decimal with an optional sign, exponent and leading or trailing dot, `Infinity`, and the
     * `0x`/`0o`/`0b` prefixes (unsigned, as in JS). An int when the text is a plain whole number
     * that fits, a float otherwise.
     */
    private static function jsNumber(string $text): int|float|null
    {
        $text = (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $text);

        if (preg_match('/^([+-]?)Infinity$/', $text, $match) === 1) {
            return $match[1] === '-' ? -INF : INF;
        }

        if (preg_match('/^0(?:x([0-9a-f]+)|o([0-7]+)|b([01]+))$/i', $text, $match) === 1) {
            [$digits, $base] = match (true) {
                ($match[1] ?? '') !== '' => [$match[1], 16],
                ($match[2] ?? '') !== '' => [$match[2], 8],
                default => [$match[3], 2],
            };
            $number = 0.0;

            foreach (str_split(strtolower($digits)) as $digit) {
                $number = $number * $base + (float) hexdec($digit);
            }

            return $number < PHP_INT_MAX ? (int) $number : $number;
        }

        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text) !== 1) {
            return null;
        }

        $number = (float) $text;

        return preg_match('/^[+-]?\d+$/', $text) === 1 && abs($number) < PHP_INT_MAX ? (int) $text : $number;
    }

    /** JS `String(number)` for the values a model sends: a whole number has no `.0`. */
    private static function jsString(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        if (is_nan($number)) {
            return 'NaN';
        }

        if (is_infinite($number)) {
            return $number > 0 ? 'Infinity' : '-Infinity';
        }

        if (floor($number) === $number && abs($number) < 1e21) {
            return sprintf('%.0f', $number);
        }

        // Shortest round-trip form, which is what JS prints, with PHP's `E+` spelt JS's way.
        return str_replace(['.0E', 'E+', 'E-'], ['E', 'e+', 'e-'], var_export($number, true));
    }

    /**
     * Upstream's `getSchemaTypes()`: `type` as a list, strings only.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function schemaTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, is_string(...)));
        }

        return [];
    }

    /**
     * Upstream's `matchesJsonType()`. `integer` is a whole number of either PHP type, as it is for
     * JSON; `object` and `array` are told apart as `JsonSchema` tells them, `[]` being both.
     */
    private static function matchesJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            default => false,
        };
    }
}
