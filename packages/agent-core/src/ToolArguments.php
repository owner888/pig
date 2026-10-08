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

        $problems = JsonSchema::errors($tool->parameters, $arguments);

        if ($problems === []) {
            return $arguments;
        }

        // The arguments the model sent, not the normalized ones — upstream prints
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
}
