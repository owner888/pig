<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Tool;
use Pig\Ai\ToolCall;

/**
 * Checks a tool call's arguments against the schema the model was given.
 *
 * Upstream compiles the schema with AJV. Nothing like that is bundled here, and pulling a
 * JSON Schema library in is the developer's call — so this covers the two mistakes a model actually
 * makes, a missing required property and a value of the wrong primitive type, and lets
 * everything else through. Upstream ships a no-validation path of its own for browser
 * extensions, so "trust the model" is a shape it already has.
 *
 * Failure is reported by throwing; the loop turns that into a tool result the model reads
 * and can correct from, which is the whole point of validating rather than crashing.
 */
final class ToolArguments
{
    /**
     * @return array<string, mixed> the arguments, unchanged, when they check out
     * @throws InvalidToolArguments
     */
    public static function validate(Tool $tool, ToolCall $call): array
    {
        $schema = $tool->parameters;
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $problems = [];

        foreach ($required as $name) {
            if (!array_key_exists($name, $call->arguments)) {
                $problems[] = "  - {$name}: is required";
            }
        }

        foreach ($call->arguments as $name => $value) {
            $expected = $properties[$name]['type'] ?? null;

            if (!is_string($expected) || self::matches($expected, $value)) {
                continue;
            }

            $problems[] = "  - {$name}: expected {$expected}, got " . get_debug_type($value);
        }

        if ($problems === []) {
            return $call->arguments;
        }

        throw new InvalidToolArguments(sprintf(
            "Validation failed for tool \"%s\":\n%s\n\nReceived arguments:\n%s",
            $call->name,
            implode("\n", $problems),
            json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));
    }

    private static function matches(string $expected, mixed $value): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            // JSON has one number type; an integer schema still accepts a whole float.
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            // An unknown or composite type is not something this checker can judge.
            default => true,
        };
    }
}
