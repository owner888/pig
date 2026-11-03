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
     * Upstream's `prepareToolCallArguments()`: the call with the tool's `prepareArguments()` run
     * over its arguments, for a tool that has one (`PreparesArguments`); the call itself otherwise,
     * and for arguments that were not a JSON object, which `validate()` refuses as they are.
     */
    public static function prepare(AgentTool $tool, ToolCall $call): ToolCall
    {
        if (!$tool instanceof PreparesArguments || $call->argumentsError !== null) {
            return $call;
        }

        $prepared = $tool->prepareArguments($call->arguments);

        return $prepared === $call->arguments
            ? $call
            : new ToolCall($call->id, $call->name, $prepared, $call->thoughtSignature, $call->namespace);
    }

    /**
     * @return array<string, mixed> the arguments, with a null for an optional parameter dropped
     *         (see `normalizeOptionalNulls()`), when they check out
     * @throws InvalidToolArguments
     */
    public static function validate(Tool $tool, ToolCall $call): array
    {
        // Upstream's `validateToolCall()`: arguments that were not a JSON object fail with what was sent (#10897).
        if ($call->argumentsError !== null) {
            throw new InvalidToolArguments("Invalid arguments for tool \"{$call->name}\": {$call->argumentsError}");
        }

        $arguments = self::normalizeOptionalNulls($call->arguments, $tool->parameters);

        if (!is_array($arguments)) {
            $arguments = $call->arguments;
        }

        // Upstream's next two passes, pi-schema's `Coercer` (6536f80, which replaced TypeBox's
        // `Value.Convert` and the old `coerceWithJsonSchema()`): TypeBox 1.3.27's conversion rules
        // keyed on JSON Schema structure, then primitive coercion by `type`. **For every schema** —
        // an extension's or an MCP server's as well as a built-in's; upstream used to convert only
        // the nodes TypeBox built. PHP arrays are
        // values, so each pass returns its result where upstream copies on write.
        $converted = self::valueConvert($tool->parameters, $arguments);

        if (is_array($converted)) {
            $arguments = $converted;
        }

        // An object stays an object through both passes, so upstream's non-object branch cannot be
        // reached here: the arguments are always an array.
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

    // ---- pass 2: TypeBox's conversion rules, keyed on JSON Schema structure --------------------

    /**
     * pi-schema's `Coercer::convert()`/`convertNode()`: TypeBox 1.3.27's `Value.Convert` rules, read
     * off the schema's own keywords rather than TypeBox's kind marks — the node's first keyword
     * among `const`, `enum`, `anyOf`/`oneOf`, `allOf` and `type` (a typeless node with properties
     * is an object, one with items an array). Each kind is TypeBox's `From*`/`Try*` pair; a `Try*`
     * that fails leaves the value unchanged.
     *
     * One difference: `$ref` is left as it is, because `Ai\Utils\JsonSchema` resolves no
     * references (see why there); upstream converts through the referenced node.
     */
    private static function valueConvert(mixed $schema, mixed $value): mixed
    {
        if (!is_array($schema) || is_string($schema['$ref'] ?? null)) {
            return $value;
        }

        if (array_key_exists('const', $schema)) {
            return self::convertLiteral($schema['const'], $value);
        }

        if (is_array($schema['enum'] ?? null)) {
            return self::convertEnum($schema, $schema['enum'], $value);
        }

        foreach (['anyOf', 'oneOf'] as $union) {
            if (is_array($schema[$union] ?? null) && array_is_list($schema[$union])) {
                return self::convertUnion($schema, $schema[$union], $value);
            }
        }

        if (is_array($schema['allOf'] ?? null) && array_is_list($schema['allOf'])) {
            foreach ($schema['allOf'] as $member) {
                $value = self::valueConvert($member, $value);
            }

            return self::convertMembers($schema, $value);
        }

        return match ($schema['type'] ?? null) {
            'object' => self::convertObject($schema, $value),
            'array' => self::convertArray($schema, $value),
            'string' => self::tryString($value) ?? $value,
            'number' => self::tryNumber($value) ?? $value,
            // `FromInteger`: `Math.trunc()` of what `TryNumber` gives.
            'integer' => ($number = self::tryNumber($value)) === null ? $value : self::truncate($number),
            'boolean' => ($boolean = self::tryBoolean($value)) === null ? $value : $boolean['value'],
            'null' => self::tryNull($value) ? null : $value,
            null => match (true) {
                isset($schema['properties']) || isset($schema['patternProperties']) => self::convertObject($schema, $value),
                isset($schema['items']) || isset($schema['prefixItems']) => self::convertArray($schema, $value),
                default => $value,
            },
            default => $value,
        };
    }

    /** `convertLiteral()`: a value of another type that converts to exactly the literal becomes it. */
    private static function convertLiteral(mixed $literal, mixed $value): mixed
    {
        if ($literal === $value) {
            return $value;
        }

        $result = match (true) {
            is_string($literal) => self::tryString($value),
            is_int($literal), is_float($literal) => self::tryNumber($value),
            is_bool($literal) => self::tryBoolean($value)['value'] ?? null,
            default => null,
        };

        // JS `===` on numbers: 1 and 1.0 are one value.
        $same = (is_int($literal) || is_float($literal)) && (is_int($result) || is_float($result))
            ? $result == $literal
            : $result === $literal;

        return $result !== null && $same ? $literal : $value;
    }

    /**
     * `convertEnum()`: a member stays; else the first member the value converts to that the node
     * accepts.
     *
     * @param array<string, mixed> $schema
     * @param array<mixed>         $members
     */
    private static function convertEnum(array $schema, array $members, mixed $value): mixed
    {
        if (in_array($value, $members, true)) {
            return $value;
        }

        foreach ($members as $member) {
            $candidate = self::convertLiteral($member, $value);

            if ($candidate !== $value && JsonSchema::errors($schema, $candidate) === []) {
                return $candidate;
            }
        }

        return $value;
    }

    /**
     * `convertUnion()`: a value one branch accepts stays; else the first branch's conversion the
     * whole union accepts; else the value. A node that also declares members converts them into
     * each candidate before it is checked.
     *
     * @param array<string, mixed> $schema
     * @param list<mixed>          $branches
     */
    private static function convertUnion(array $schema, array $branches, mixed $value): mixed
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && JsonSchema::errors($branch, $value) === []) {
                return self::convertMembers($schema, $value);
            }
        }

        foreach ($branches as $branch) {
            $candidate = self::convertMembers($schema, self::valueConvert($branch, $value));

            if (JsonSchema::errors($schema, $candidate) === []) {
                return $candidate;
            }
        }

        return self::convertMembers($schema, $value);
    }

    /**
     * `convertMembers()`: the object members of a union or intersection node, when it declares any.
     *
     * @param array<string, mixed> $schema
     */
    private static function convertMembers(array $schema, mixed $value): mixed
    {
        return isset($schema['properties']) || isset($schema['patternProperties']) || isset($schema['additionalProperties'])
            ? self::convertObject($schema, $value)
            : $value;
    }

    /**
     * `convertObject()`: each key the object has, against its property, each `patternProperties`
     * schema whose pattern matches it, and `additionalProperties` (when a schema) for a key neither
     * names. Anything that is not an object comes back unchanged.
     *
     * @param array<string, mixed> $schema
     */
    private static function convertObject(array $schema, mixed $value): mixed
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            return $value;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $patterns = is_array($schema['patternProperties'] ?? null) ? $schema['patternProperties'] : [];
        $additional = is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null;

        foreach ($value as $key => $next) {
            $matched = array_key_exists((string) $key, $properties);

            if ($matched) {
                $next = self::valueConvert($properties[(string) $key], $next);
            }

            foreach ($patterns as $pattern => $patternSchema) {
                if (self::matches((string) $pattern, (string) $key)) {
                    $matched = true;
                    $next = self::valueConvert($patternSchema, $next);
                }
            }

            if (!$matched && $additional !== null) {
                $next = self::valueConvert($additional, $next);
            }

            $value[$key] = $next;
        }

        return $value;
    }

    /**
     * `convertArray()`: a tuple (`prefixItems`, or an `items` list) converts the items of an array
     * and never wraps; otherwise an array's items are converted against `items`, and **anything
     * else is wrapped in one** and converted as its single item.
     *
     * @param array<string, mixed> $schema
     */
    private static function convertArray(array $schema, mixed $value): mixed
    {
        $items = $schema['items'] ?? null;
        // A draft-7 `items` list is the tuple and has no rest; after `prefixItems` the rest is `items`.
        $draft7Tuple = is_array($items) && array_is_list($items) && $items !== [];
        $prefix = $draft7Tuple ? $items : (is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : null);
        $isList = is_array($value) && array_is_list($value);

        if ($prefix !== null) {
            if (!$isList) {
                return $value;
            }

            $rest = $draft7Tuple ? null : $items;

            foreach ($value as $index => $item) {
                $itemSchema = $prefix[$index] ?? $rest;
                $value[$index] = $itemSchema === null ? $item : self::valueConvert($itemSchema, $item);
            }

            return $value;
        }

        if ($isList) {
            return $items === null ? $value : array_map(static fn (mixed $item): mixed => self::valueConvert($items, $item), $value);
        }

        return [self::valueConvert($items, $value)];
    }

    /** A `patternProperties` key against a property name, as `JsonSchema` matches `pattern`. */
    private static function matches(string $pattern, string $key): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('#' . str_replace('#', '\\#', $pattern) . '#u', $key) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /** `TryString`: a number or boolean as JS prints it, null as "null", a string as itself. */
    private static function tryString(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => self::jsString($value),
            $value === null => 'null',
            is_string($value) => $value,
            default => null,
        };
    }

    /**
     * `TryNumber`: a boolean as 1 or 0, a finite number as itself (`Guard.IsNumber` is
     * `Number.isFinite`), null as 0, and a string by JS's unary `+` (so "" and "  " are 0), else
     * "false"/"true" in any case, else a `123n` BigInt literal in the safe-integer range.
     */
    private static function tryNumber(mixed $value): int|float|null
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }

        if ($value === null) {
            return 0;
        }

        if (!is_string($value)) {
            return null;
        }

        // `+value`: JS's `Number()`, whose empty (or all-whitespace) string is 0.
        $coerced = preg_match('/^[\s\x{00A0}\x{FEFF}]*$/u', $value) === 1 ? 0 : self::jsNumber($value);

        if ($coerced !== null && is_finite((float) $coerced)) {
            return $coerced;
        }

        $lowercase = strtolower($value);

        if ($lowercase === 'false') {
            return 0;
        }

        if ($lowercase === 'true') {
            return 1;
        }

        // `TryBigInt`'s string arm; its integer and decimal patterns are numbers `+value` already
        // took, so only the `n` suffix is left to match.
        if (preg_match('/^-?(0|[1-9]\d*)n$/', $value) === 1) {
            $digits = substr($value, 0, -1);

            return abs((float) $digits) <= 9007199254740991 ? (int) $digits : null;
        }

        return null;
    }

    /**
     * `TryBoolean`: 0 and 1 (numbers or the strings "0"/"1"), "false"/"true" in any case, null as
     * false. Wrapped so that "failed" and `false` are different answers.
     *
     * @return array{value: bool}|null
     */
    private static function tryBoolean(mixed $value): ?array
    {
        return match (true) {
            is_bool($value) => ['value' => $value],
            (is_int($value) || is_float($value)) && $value == 0 => ['value' => false],
            (is_int($value) || is_float($value)) && $value == 1 => ['value' => true],
            $value === null => ['value' => false],
            is_string($value) && strtolower($value) === 'false', $value === '0' => ['value' => false],
            is_string($value) && strtolower($value) === 'true', $value === '1' => ['value' => true],
            default => null,
        };
    }

    /** `TryNull`: false, 0, null, and the strings "undefined", "null" (any case), "" and "0". */
    private static function tryNull(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value === false,
            is_int($value), is_float($value) => $value == 0,
            $value === null => true,
            is_string($value) => in_array(strtolower($value), ['undefined', 'null'], true) || $value === '' || $value === '0',
            default => false,
        };
    }

    /** `Math.trunc()`, as an int where the result fits in one. */
    private static function truncate(int|float $number): int|float
    {
        if (is_int($number)) {
            return $number;
        }

        $truncated = $number < 0 ? ceil($number) : floor($number);

        return abs($truncated) < PHP_INT_MAX ? (int) $truncated : $truncated;
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
