<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\ProviderError;
use Pig\Ai\Tool;

/**
 * JSON-schema constrained sampling ("strict" tools): whether a tool is sent strict, and the
 * strict form of its schema.
 *
 * Ported from upstream's `api/constrained-sampling.ts` — the `json_schema` half only. The grammar
 * half (`resolveGrammarConstrainedSampling()` and the custom-tool input buffers) serves OpenAI's
 * grammar tools, which pig does not send.
 *
 * A tool opts in through `Tool::$constrainedSampling` (`['type' => 'json_schema', 'strict' =>
 * 'prefer'|'require']`); a tool that says nothing is never strict. Every provider asks it, each
 * with its own idea of whether strict mode is there: Anthropic (the `anthropic` provider's models,
 * with its own refused keywords), OpenAI Completions and Responses (`OpenAiCompat::$strictMode`),
 * and Gemini 3+ (`GoogleShared::resolveGoogleFunctionCallingMode()`). The built-in bash, edit,
 * read and write ask for it; the nulls a strict call sends for what it leaves out are dropped by
 * `Agent\ToolArguments` before validation.
 */
final class ConstrainedSampling
{
    /** Upstream's `UNSUPPORTED_STRICT_SCHEMA_KEYS`. */
    private const array UNSUPPORTED_STRICT_SCHEMA_KEYS = [
        '$ref',
        '$defs',
        'definitions',
        'allOf',
        'oneOf',
        'patternProperties',
        'dependentSchemas',
        'dependencies',
        'unevaluatedProperties',
        'propertyNames',
        'contains',
        'prefixItems',
        'not',
        'if',
        'then',
        'else',
    ];

    /**
     * Upstream's `resolveJsonSchemaStrictSampling()`: true when the tool goes strict, null when it
     * does not. A `prefer` tool whose schema has no strict form, or a provider without strict
     * mode, quietly falls back; a `require` tool throws instead.
     *
     * @param (\Closure(string, mixed): bool)|null $isUnsupportedKeyword a provider's extra refusals
     */
    public static function resolveJsonSchemaStrictSampling(
        Tool $tool,
        bool $supportsStrictMode,
        ?\Closure $isUnsupportedKeyword = null,
    ): ?bool {
        $config = $tool->constrainedSampling;

        if (!is_array($config) || ($config['type'] ?? null) !== 'json_schema') {
            return null;
        }

        if ($supportsStrictMode) {
            try {
                self::makeStrictJsonSchema($tool->parameters, $isUnsupportedKeyword);

                return true;
            } catch (UnsupportedStrictJsonSchema $error) {
                if (($config['strict'] ?? null) !== 'require') {
                    return null;
                }

                throw new ProviderError("Tool \"{$tool->name}\" requires JSON-schema constrained sampling, but {$error->getMessage()}.");
            }
        }

        if (($config['strict'] ?? null) === 'require') {
            throw new ProviderError("Tool \"{$tool->name}\" requires JSON-schema constrained sampling, but strict tools are unsupported.");
        }

        return null;
    }

    /**
     * Upstream's `getJsonSchemaToolParameters()`: the strict form when the tool goes strict, the
     * schema as written otherwise.
     *
     * @return array<string, mixed>
     */
    public static function getJsonSchemaToolParameters(Tool $tool, ?bool $strict): array
    {
        return $strict === true ? self::makeStrictJsonSchema($tool->parameters) : $tool->parameters;
    }

    /**
     * Upstream's `makeStrictJsonSchema()`: every property required, an optional one widened to
     * also accept null, and `additionalProperties: false` on every object.
     *
     * @param array<string, mixed> $schema
     * @param (\Closure(string, mixed): bool)|null $isUnsupportedKeyword
     * @return array<string, mixed>
     */
    public static function makeStrictJsonSchema(array $schema, ?\Closure $isUnsupportedKeyword = null): array
    {
        // A PHP array is already a copy — upstream's `structuredClone()`.
        if (!self::isJsonSchemaObject($schema)) {
            throw new UnsupportedStrictJsonSchema('root schema must have type object');
        }

        self::makeJsonSchemaNodeStrict($schema, $isUnsupportedKeyword);

        if (($schema['type'] ?? null) !== 'object') {
            throw new UnsupportedStrictJsonSchema('root schema must have type object');
        }

        return $schema;
    }

    /**
     * JSON's object, as a decoded schema has it. `[]` counts, because `{}` decodes to it.
     */
    private static function isJsonSchemaObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** JS `Array.isArray()`, for a decoded schema. */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @return list<mixed> the schema's `type`, as a list */
    private static function types(array $schema): array
    {
        $type = $schema['type'] ?? null;

        return is_string($type) ? [$type] : (self::isList($type) ? $type : []);
    }

    private static function isStructuredSchema(mixed $schema): bool
    {
        if (!self::isJsonSchemaObject($schema)) {
            return false;
        }

        $types = self::types($schema);

        return in_array('object', $types, true)
            || in_array('array', $types, true)
            || array_key_exists('properties', $schema)
            || array_key_exists('items', $schema);
    }

    private static function schemaAllowsNull(mixed $schema): bool
    {
        if (!self::isJsonSchemaObject($schema)) {
            return false;
        }

        if (($schema['type'] ?? null) === 'null' || (self::isList($schema['type'] ?? null) && in_array('null', $schema['type'], true))) {
            return true;
        }

        if ((array_key_exists('const', $schema) && $schema['const'] === null)
            || (self::isList($schema['enum'] ?? null) && in_array(null, $schema['enum'], true))) {
            return true;
        }

        if (!self::isList($schema['anyOf'] ?? null)) {
            return false;
        }

        foreach ($schema['anyOf'] as $variant) {
            if (self::schemaAllowsNull($variant)) {
                return true;
            }
        }

        return false;
    }

    /** @param (\Closure(string, mixed): bool)|null $isUnsupportedKeyword */
    private static function makeJsonSchemaNodeStrict(mixed &$schema, ?\Closure $isUnsupportedKeyword): void
    {
        if (!self::isJsonSchemaObject($schema)) {
            throw new UnsupportedStrictJsonSchema('boolean schemas are unsupported');
        }

        foreach (self::UNSUPPORTED_STRICT_SCHEMA_KEYS as $key) {
            if (array_key_exists($key, $schema)) {
                throw new UnsupportedStrictJsonSchema("{$key} schemas are unsupported");
            }
        }

        if ($isUnsupportedKeyword !== null) {
            foreach ($schema as $key => $value) {
                if ($isUnsupportedKeyword((string) $key, $value)) {
                    throw new UnsupportedStrictJsonSchema("{$key}: " . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' is unsupported');
                }
            }
        }

        if (array_key_exists('anyOf', $schema)) {
            if (!self::isList($schema['anyOf']) || $schema['anyOf'] === []) {
                throw new UnsupportedStrictJsonSchema('anyOf must contain at least one schema');
            }

            foreach ($schema['anyOf'] as $index => $variant) {
                if (self::isStructuredSchema($variant)) {
                    throw new UnsupportedStrictJsonSchema('object and array unions are unsupported');
                }

                self::makeJsonSchemaNodeStrict($schema['anyOf'][$index], $isUnsupportedKeyword);
            }
        }

        if (array_key_exists('items', $schema)) {
            // `[]` is a tuple of nothing in JSON and an empty object here; read it as the latter,
            // the way `isJsonSchemaObject()` does everywhere else.
            if (self::isList($schema['items']) && $schema['items'] !== []) {
                throw new UnsupportedStrictJsonSchema('tuple schemas are unsupported');
            }

            self::makeJsonSchemaNodeStrict($schema['items'], $isUnsupportedKeyword);
        }

        $isObjectSchema = ($schema['type'] ?? null) === 'object';

        if (array_key_exists('properties', $schema) && !$isObjectSchema) {
            throw new UnsupportedStrictJsonSchema('properties require type object');
        }

        if (!$isObjectSchema) {
            return;
        }

        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            throw new UnsupportedStrictJsonSchema('schema-valued or true additionalProperties is unsupported');
        }

        if (array_key_exists('properties', $schema) && !self::isJsonSchemaObject($schema['properties'])) {
            throw new UnsupportedStrictJsonSchema('object properties must be a schema map');
        }

        if (array_key_exists('required', $schema)) {
            $required = $schema['required'];

            if (!self::isList($required) || array_filter($required, static fn (mixed $key): bool => !is_string($key)) !== []) {
                throw new UnsupportedStrictJsonSchema('object required must be a string array');
            }
        }

        $properties = $schema['properties'] ?? [];
        $propertyNames = array_map('strval', array_keys($properties));
        $required = self::isList($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $key) {
            if (!in_array($key, $propertyNames, true)) {
                throw new UnsupportedStrictJsonSchema('required contains an unknown property');
            }
        }

        foreach ($properties as $key => $property) {
            self::makeJsonSchemaNodeStrict($properties[$key], $isUnsupportedKeyword);

            if (!in_array((string) $key, $required, true) && !self::schemaAllowsNull($properties[$key])) {
                $properties[$key] = ['anyOf' => [$properties[$key], ['type' => 'null']]];
            }
        }

        if (array_key_exists('properties', $schema)) {
            $schema['properties'] = $properties;
        }

        $schema['required'] = $propertyNames;
        $schema['additionalProperties'] = false;
    }
}
