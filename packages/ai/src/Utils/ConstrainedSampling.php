<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\ProviderError;
use Pig\Ai\Tool;

/**
 * JSON-schema constrained sampling ("strict" tools): whether a tool is sent strict, and the
 * strict form of its schema.
 *
 * Ported from upstream's `api/constrained-sampling.ts`, both halves. The `json_schema` half decides
 * strict tools; the grammar half (`resolveGrammarConstrainedSampling()` and the custom-tool input
 * buffer) serves OpenAI's custom grammar tools, which `OpenAiResponses` and `OpenAiCompletions` send
 * where the model's compat says `supportsOpenAIGrammarTools` (`OpenAiCompat::$grammarTools`).
 *
 * A tool opts in through `Tool::$constrainedSampling` (`['type' => 'json_schema', 'strict' =>
 * 'prefer'|'require']`, or `['type' => 'grammar', 'variants' => ['openai_lark' => …,
 * 'openai_regex' => …]]`); a tool that says nothing is never strict. Every provider asks it, each
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

    // ---- the grammar half --------------------------------------------------------------------

    /**
     * Upstream's `getGrammarToolInput()`: the one string a grammar tool call carries, out of the
     * arguments object pig keeps it in.
     *
     * @param array<string, mixed> $arguments
     */
    public static function getGrammarToolInput(string $toolName, array $arguments, string $inputProperty): string
    {
        $input = $arguments[$inputProperty] ?? null;

        if (!is_string($input)) {
            throw new ProviderError("Grammar tool call \"{$toolName}\" requires argument \"{$inputProperty}\" to be a string.");
        }

        return $input;
    }

    /**
     * A fresh upstream `GrammarToolInputJsonBuffer`: `{input: "", started: false, closed: false}`.
     *
     * @return array{input: string, started: bool, closed: bool}
     */
    public static function newGrammarToolInputJsonBuffer(): array
    {
        return ['input' => '', 'started' => false, 'closed' => false];
    }

    /**
     * Upstream's `appendGrammarToolInputJsonDelta()`: a custom tool call streams raw text, and the
     * stream pig hands on carries JSON argument deltas — so the text is re-said as the growing JSON
     * object `{"<property>":"<text so far>`, closed by `"}` once the input is done. Null when there
     * is nothing to say. The buffer is upstream's mutable object, here passed by reference.
     *
     * @param array{input: string, started: bool, closed: bool} $buffer
     */
    public static function appendGrammarToolInputJsonDelta(array &$buffer, string $inputProperty, string $nextInput, bool $close): ?string
    {
        if ($buffer['closed']) {
            if ($close && $nextInput === $buffer['input']) {
                return null;
            }

            throw new ProviderError("grammar tool input for property \"{$inputProperty}\" changed after it was closed");
        }

        if (!str_starts_with($nextInput, $buffer['input'])) {
            throw new ProviderError("grammar tool input for property \"{$inputProperty}\" changed non-monotonically");
        }

        $inputDelta = substr($nextInput, strlen($buffer['input']));

        if (!$close && $inputDelta === '') {
            return null;
        }

        $delta = '';

        if (!$buffer['started']) {
            $delta .= '{' . self::jsonString($inputProperty) . ':"';
            $buffer['started'] = true;
        }

        // `JSON.stringify(inputDelta).slice(1, -1)`: the text escaped as a JSON string's inside.
        $delta .= substr(self::jsonString($inputDelta), 1, -1);
        $buffer['input'] = $nextInput;

        if ($close) {
            $delta .= '"}';
            $buffer['closed'] = true;
        }

        return $delta;
    }

    /**
     * Upstream's `resolveGrammarConstrainedSampling()`: the grammar a tool is sent with, or null
     * when it is not a grammar tool or the endpoint takes none (it then goes as a function tool).
     * A grammar tool with no usable variant, or whose schema is not one required string property,
     * is an error — the caller asked for something that cannot be sent.
     *
     * @return array{format: 'lark'|'regex', definition: string, inputProperty: string}|null
     */
    public static function resolveGrammarConstrainedSampling(Tool $tool, bool $supportsOpenAIGrammarTools): ?array
    {
        $config = $tool->constrainedSampling;

        if (!is_array($config) || ($config['type'] ?? null) !== 'grammar') {
            return null;
        }

        if (!$supportsOpenAIGrammarTools) {
            return null;
        }

        $variants = is_array($config['variants'] ?? null) ? $config['variants'] : [];
        $larkDefinition = $variants['openai_lark'] ?? null;
        $regexDefinition = $variants['openai_regex'] ?? null;
        $hasLarkDefinition = is_string($larkDefinition) && trim($larkDefinition) !== '';
        $hasRegexDefinition = is_string($regexDefinition) && trim($regexDefinition) !== '';

        if (!$hasLarkDefinition && !$hasRegexDefinition) {
            throw new ProviderError("Tool \"{$tool->name}\" cannot use grammar constrained sampling: no supported grammar variant was provided.");
        }

        try {
            return [
                'format' => $hasLarkDefinition ? 'lark' : 'regex',
                'definition' => $hasLarkDefinition ? $larkDefinition : $regexDefinition,
                'inputProperty' => self::inferGrammarInputProperty($tool),
            ];
        } catch (ProviderError $error) {
            throw new ProviderError("Tool \"{$tool->name}\" cannot use grammar constrained sampling: {$error->getMessage()}.");
        }
    }

    /**
     * Upstream's `createGrammarToolInputProperties()`: tool name => the property its grammar input
     * lives in, for every tool that goes out as a grammar tool. Asked by name when a call arrives
     * (`custom_tool_call`) and when one is replayed.
     *
     * @param list<Tool> $tools
     * @return array<string, string>
     */
    public static function createGrammarToolInputProperties(array $tools, bool $supportsOpenAIGrammarTools): array
    {
        $properties = [];

        foreach ($tools as $tool) {
            $grammar = self::resolveGrammarConstrainedSampling($tool, $supportsOpenAIGrammarTools);

            if ($grammar !== null) {
                $properties[$tool->name] = $grammar['inputProperty'];
            }
        }

        return $properties;
    }

    /** Upstream's `inferGrammarInputProperty()`: the schema's one required property, a string. */
    private static function inferGrammarInputProperty(Tool $tool): string
    {
        $schema = $tool->parameters;

        if (($schema['type'] ?? null) !== 'object') {
            throw new ProviderError('grammar constrained sampling requires an object parameter schema');
        }

        $required = $schema['required'] ?? null;

        if (!self::isList($required) || count($required) !== 1 || !is_string($required[0])) {
            throw new ProviderError('grammar constrained sampling requires exactly one required string property');
        }

        $inputProperty = $required[0];
        $property = $schema['properties'][$inputProperty] ?? null;

        // `!schema.properties?.[inputProperty]`: absent, or a falsy value. A schema is an array
        // here, and an empty one is still a schema (`{}` in JS is truthy).
        if (!is_array($property)) {
            throw new ProviderError("grammar constrained sampling requires a properties entry for {$inputProperty}");
        }

        if (($property['type'] ?? null) !== 'string') {
            throw new ProviderError("grammar constrained sampling property {$inputProperty} must have type string");
        }

        return $inputProperty;
    }

    /** JS `JSON.stringify()` of a string: no `\/`, no `\uXXXX` for printable text or U+2028/2029. */
    private static function jsonString(string $text): string
    {
        return (string) json_encode(
            $text,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
