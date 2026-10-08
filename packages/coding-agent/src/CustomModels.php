<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;

/**
 * Providers declared in a file, so somebody's own endpoint does not need a release.
 *
 * Upstream's `core/model-registry.ts`, the half of it that reads `models.json` — the other
 * half is `Ai\Models`, `Auth` and `ModelResolver`, which were already here. A file looks like
 * this, and every key in it is upstream's:
 *
 * ```json
 * {
 *   "providers": {
 *     "my-box": {
 *       "baseUrl": "http://192.168.1.9:8080/v1",
 *       "apiKey": "MY_BOX_KEY",
 *       "api": "openai-completions",
 *       "authHeader": true,
 *       "headers": { "X-Tenant": "acme" },
 *       "models": [{
 *         "id": "qwen3-coder", "name": "Qwen3 Coder",
 *         "reasoning": false, "input": ["text"],
 *         "contextWindow": 262144, "maxTokens": 32768,
 *         "cost": { "input": 0, "output": 0, "cacheRead": 0, "cacheWrite": 0 }
 *       }]
 *     }
 *   }
 * }
 * ```
 *
 * **`apiKey` is a variable name before it is a key.** Upstream's `resolveApiKeyConfig`: the
 * value is looked up in the environment first and only used literally if nothing answers to
 * it. So `"apiKey": "MY_BOX_KEY"` keeps the secret out of the file, which matters more here
 * than anywhere else in pig — this is the one config file whose natural contents are a
 * credential, and a file in a repository is a credential in a repository.
 *
 * **Nothing here throws.** A file that is wrong loses only itself: the built-in models are
 * still there, pig still starts, and every problem is collected with the provider and model it
 * came from so the line names what to fix. That is `Settings`' rule — a file that is not JSON
 * is named, not ignored — applied to a file with more ways to be wrong.
 */
final readonly class CustomModels
{
    /** What `api` may say: the protocols pig has a provider for, under upstream's names. */
    private const array APIS = [
        'openai-completions' => Api::OpenAiCompletions,
        'openai-responses' => Api::OpenAiResponses,
        'anthropic-messages' => Api::AnthropicMessages,
        'google-generative-ai' => Api::GoogleGenerativeAi,
        'mistral-conversations' => Api::MistralConversations,
    ];

    /**
     * @param list<Model>           $models   ready to hand to `Models::register()`
     * @param array<string, string> $keys     provider name => what its `apiKey` said, for `Auth`
     * @param list<string>          $problems everything wrong with the file, each naming where
     */
    private function __construct(
        public array $models,
        public array $keys,
        public array $problems,
    ) {
    }

    /**
     * Read `~/.pig/agent/models.json`, or pi's if pig has none.
     *
     * pi's is a fallback rather than a merge: one file is what upstream has, and somebody who
     * already told pi about their endpoint should not have to say it twice. Neither file is
     * ever written — this one is only ever read.
     */
    public static function discover(): self
    {
        foreach ([Config::home(), Config::piHome()] as $directory) {
            $path = $directory . '/models.json';

            if (is_file($path)) {
                return self::load($path);
            }
        }

        return new self([], [], []);
    }

    /** Nothing at all, for a session that should not read the person's files. */
    public static function none(): self
    {
        return new self([], [], []);
    }

    public static function load(string $path): self
    {
        // Not having one is the normal case — upstream returns no models and no error for a
        // missing file too. A warning on every start is how people learn to skip the warnings
        // that matter. A file that *is* there and cannot be read is different, and says so.
        if (!is_file($path)) {
            return new self([], [], []);
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return new self([], [], ["{$path} could not be read"]);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            // The same document with its objects kept as objects: `{}` and `[]` are one value as
            // arrays, and upstream's schema checks tell them apart — `"cost": []` is `must be
            // object` there. `schemaErrors()` reads this one.
            $objects = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
            $decoded = self::keepCompatObjects($decoded, $objects);
        } catch (\JsonException $error) {
            return new self([], [], ["{$path} is not valid JSON: {$error->getMessage()}"]);
        }

        if (!is_array($decoded) || !isset($decoded['providers']) || !is_array($decoded['providers'])) {
            return new self([], [], ["{$path} has no \"providers\" object in it"]);
        }

        $models = [];
        $keys = [];
        $problems = [];

        foreach ($decoded['providers'] as $name => $provider) {
            if (!is_string($name) || $name === '' || !is_array($provider)) {
                $problems[] = "{$path}: a provider with no name, or whose value is not an object";

                continue;
            }

            $providerObject = $objects instanceof \stdClass && $objects->providers instanceof \stdClass
                ? ($objects->providers->{$name} ?? null)
                : null;
            [$providerModels, $key, $providerProblems] = self::provider(
                $path,
                $name,
                $provider,
                $providerObject instanceof \stdClass ? $providerObject : new \stdClass(),
            );

            $models = [...$models, ...$providerModels];
            $problems = [...$problems, ...$providerProblems];

            if ($key !== null) {
                $keys[$name] = $key;
            }
        }

        return new self($models, $keys, $problems);
    }

    /** Hand the models to the registry and the keys to `Auth`. */
    public function install(?Auth $auth = null): void
    {
        if ($this->models !== []) {
            Models::register($this->models);
        }

        // The keys go in whether or not there are models: a provider whose models were all
        // rejected still has a key, and the next run with a fixed file should not need a
        // second place to put it.
        $auth?->setCustomProviderKeys($this->keys);
    }

    /**
     * @param array<mixed> $config
     * @param \stdClass $object the same provider, decoded with its objects kept — for `schemaErrors()`
     * @return array{0: list<Model>, 1: string|null, 2: list<string>}
     */
    private static function provider(string $path, string $name, array $config, \stdClass $object): array
    {
        $where = "{$path}, provider \"{$name}\"";
        $baseUrl = $config['baseUrl'] ?? null;
        $key = $config['apiKey'] ?? null;

        if (!is_string($baseUrl) || $baseUrl === '') {
            return [[], null, ["{$where}: no \"baseUrl\""]];
        }

        if (!is_string($key) || $key === '') {
            return [[], null, ["{$where}: no \"apiKey\" — an environment variable's name, or the key itself"]];
        }

        $entries = $config['models'] ?? null;

        if (!is_array($entries) || $entries === []) {
            return [[], $key, ["{$where}: no \"models\" to declare"]];
        }

        // Upstream's `ProviderConfigSchema.compat`, the key of it whose shape pig checks: a provider's
        // `allowedFallbackModels`, each with upstream's `ModelCostSchema` cost. A provider-level
        // problem refuses the provider, as a model-level one refuses the model.
        $compatObject = $object->compat ?? null;
        $providerSchemaErrors = $compatObject instanceof \stdClass && property_exists($compatObject, 'allowedFallbackModels')
            ? self::schemaErrors($compatObject->allowedFallbackModels, self::ALLOWED_FALLBACK_MODELS_SCHEMA, "providers.{$name}.compat.allowedFallbackModels")
            : [];

        if ($providerSchemaErrors !== []) {
            return [[], $key, ["{$where}: invalid models.json schema: " . implode('; ', $providerSchemaErrors)]];
        }

        $headers = self::strings($config['headers'] ?? null);

        // `authHeader` is for an endpoint that wants the key in `Authorization` rather than
        // wherever its protocol puts it — a proxy in front of something, usually. Resolved
        // here rather than at request time because that is where upstream does it, and
        // because a header is part of what a model *is* in this design.
        $models = [];
        $problems = [];
        $resolved = self::resolve($key);

        // Said where every other thing wrong with this file is said, because it is the same kind
        // of thing: a value that cannot do its job, named so the line says what to fix. Without
        // it the only symptom is a 401 from an endpoint, which explains nothing about a file.
        if ($resolved === null && preg_match(self::VARIABLE_NAME, $key) === 1) {
            $problems[] = "{$where}: \"apiKey\" names {$key}, which is not set — no key for this provider";
        }

        if (($config['authHeader'] ?? false) === true && $resolved !== null) {
            $headers['Authorization'] = 'Bearer ' . $resolved;
        }

        $entryObjects = is_array($object->models ?? null) ? $object->models : [];

        foreach (array_values($entries) as $position => $entry) {
            $entryObject = $entryObjects[$position] ?? null;

            if (!is_array($entry) || !$entryObject instanceof \stdClass) {
                $problems[] = "{$where}: a model that is not an object";

                continue;
            }

            $built = self::model(
                $where,
                $name,
                $baseUrl,
                $headers,
                $config['api'] ?? null,
                is_array($config['compat'] ?? null) ? $config['compat'] : [],
                $entry,
                $entryObject,
                "providers.{$name}.models.{$position}",
            );

            if (is_string($built)) {
                $problems[] = $built;

                continue;
            }

            $models[] = $built;
        }

        return [$models, $key, $problems];
    }

    /**
     * One model, or the line saying why not.
     *
     * @param array<string, string> $headers the provider's, which a model's own override
     * @param array<mixed>          $providerCompat the provider's `compat`, which a model's own
     *        overrides key by key — upstream's `mergeCompat(providerConfig.compat, definition.compat)`
     * @param array<mixed>          $entry
     * @param \stdClass             $object the same model, decoded with its objects kept as objects
     * @param string                $schemaPath upstream's validation path to this model,
     *        `providers.<name>.models.<index>`, for the errors `schemaErrors()` reports
     */
    private static function model(
        string $where,
        string $provider,
        string $baseUrl,
        array $headers,
        mixed $providerApi,
        array $providerCompat,
        array $entry,
        \stdClass $object,
        string $schemaPath = '',
    ): Model|string {
        $id = $entry['id'] ?? null;
        $name = $entry['name'] ?? null;

        if (!is_string($id) || $id === '') {
            return "{$where}: a model with no \"id\"";
        }

        if (!is_string($name) || $name === '') {
            return "{$where}, model \"{$id}\": no \"name\"";
        }

        // Upstream's rule: the model's own `api` wins, the provider's is the default, and
        // neither one present is an error rather than a guess — there is no sensible protocol
        // to assume, and assuming one produces a request the endpoint rejects for reasons
        // nothing on screen explains.
        $api = $entry['api'] ?? $providerApi;

        if (!is_string($api) || !isset(self::APIS[$api])) {
            return "{$where}, model \"{$id}\": \"api\" must be one of "
                . implode(', ', array_keys(self::APIS))
                . ' — set it on the model or on the provider';
        }

        $window = $entry['contextWindow'] ?? null;
        $maxTokens = $entry['maxTokens'] ?? null;

        if (!is_int($window) || $window <= 0) {
            return "{$where}, model \"{$id}\": \"contextWindow\" must be a positive whole number";
        }

        if (!is_int($maxTokens) || $maxTokens <= 0) {
            return "{$where}, model \"{$id}\": \"maxTokens\" must be a positive whole number";
        }

        // Upstream's `ModelDefinitionSchema` for the blocks below, checked the way its TypeBox schema
        // checks them and reported in its words — `<path>: <message>`, the lines of its "Invalid
        // models.json schema" — in the schema's order: `inputLimits`, `cost`, `promptCache`,
        // `compat.allowedFallbackModels`. Upstream refuses the whole file over one; pig refuses this
        // model, as it does for everything else here, and names every error the schema found in it.
        //
        // **`cost` is upstream's `ModelCostSchema`**: an object, all four rates required and each a
        // number, and its tiers. Absent is still free — that is what a local endpoint is, and the
        // block stays optional — but a block that is there says all four, or the model is refused:
        // `{"input": 3}` used to price output, cache reads and writes at nothing, every turn, where
        // upstream refuses the file. pig also used to refuse a negative price, which upstream's
        // `Type.Number()` accepts; it no longer does.
        $compatObject = $object->compat ?? null;
        $schemaErrors = [
            ...self::schemaErrors($object->inputLimits ?? null, self::INPUT_LIMITS_SCHEMA, "{$schemaPath}.inputLimits", property_exists($object, 'inputLimits')),
            ...self::schemaErrors($object->cost ?? null, self::COST_SCHEMA, "{$schemaPath}.cost", property_exists($object, 'cost')),
            ...self::schemaErrors($object->promptCache ?? null, self::PROMPT_CACHE_SCHEMA, "{$schemaPath}.promptCache", property_exists($object, 'promptCache')),
            ...($compatObject instanceof \stdClass
                ? self::schemaErrors($compatObject->allowedFallbackModels ?? null, self::ALLOWED_FALLBACK_MODELS_SCHEMA, "{$schemaPath}.compat.allowedFallbackModels", property_exists($compatObject, 'allowedFallbackModels'))
                : []),
        ];

        if ($schemaErrors !== []) {
            return "{$where}, model \"{$id}\": invalid models.json schema: " . implode('; ', $schemaErrors);
        }

        $input = [];

        foreach (is_array($entry['input'] ?? null) ? $entry['input'] : ['text'] as $accepted) {
            if ($accepted === 'text' || $accepted === 'image') {
                $input[] = $accepted;
            }
        }

        return new Model(
            $id,
            $name,
            self::APIS[$api],
            $provider,
            rtrim($baseUrl, '/'),
            $window,
            $maxTokens,
            ($entry['reasoning'] ?? false) === true,
            $input === [] ? ['text'] : $input,
            self::pricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : []),
            [...$headers, ...self::strings($entry['headers'] ?? null)],
            // Upstream's `mergeCompat()`: `{ ...base, ...override }`, the model's keys over the
            // provider's. Detection then fills whatever neither says, in `OpenAiCompat::resolve()`.
            self::compat(
                self::mergeCompat($providerCompat, is_array($entry['compat'] ?? null) ? $entry['compat'] : []),
                self::APIS[$api],
            ),
            self::thinkingLevelMap(is_array($entry['thinkingLevelMap'] ?? null) ? $entry['thinkingLevelMap'] : null),
            // Upstream's `inputLimits: definition.inputLimits` and `promptCache: definition.promptCache`,
            // as written — checked above, and given no defaults here, as upstream gives none.
            is_array($entry['inputLimits'] ?? null) ? $entry['inputLimits'] : null,
            is_array($entry['promptCache'] ?? null) ? $entry['promptCache'] : null,
        );
    }

    /** Upstream's `ModelCostSchema`: the four rates required, each a number, and optional tiers. */
    private const array COST_SCHEMA = [
        'type' => 'object',
        'required' => ['input', 'output', 'cacheRead', 'cacheWrite'],
        'properties' => [
            'input' => ['type' => 'number'],
            'output' => ['type' => 'number'],
            'cacheRead' => ['type' => 'number'],
            'cacheWrite' => ['type' => 'number'],
            'tiers' => ['type' => 'array', 'items' => self::COST_TIER_SCHEMA],
        ],
    ];

    /**
     * Upstream's `allowedFallbackModels` schema: at most three `AnthropicAllowedFallbackModelSchema`
     * entries — a provider and a model, neither empty, and a `ModelCostSchema` cost, tiers and all.
     */
    private const array ALLOWED_FALLBACK_MODELS_SCHEMA = [
        'type' => 'array',
        'maxItems' => 3,
        'items' => [
            'type' => 'object',
            'required' => ['provider', 'model', 'cost'],
            'properties' => [
                'provider' => ['type' => 'string', 'minLength' => 1],
                'model' => ['type' => 'string', 'minLength' => 1],
                'cost' => self::COST_SCHEMA,
            ],
        ],
    ];

    /** Upstream's `ModelCostTierSchema`: all five fields required, each a number. */
    private const array COST_TIER_SCHEMA = [
        'type' => 'object',
        'required' => ['inputTokensAbove', 'input', 'output', 'cacheRead', 'cacheWrite'],
        'properties' => [
            'inputTokensAbove' => ['type' => 'number'],
            'input' => ['type' => 'number'],
            'output' => ['type' => 'number'],
            'cacheRead' => ['type' => 'number'],
            'cacheWrite' => ['type' => 'number'],
        ],
    ];

    /** Upstream's `ModelPromptCacheSchema`: seconds per tier, each above zero. */
    private const array PROMPT_CACHE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'short' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'long' => ['type' => 'number', 'exclusiveMinimum' => 0],
        ],
    ];

    /** Upstream's `ModelInputLimitsSchema` and its `ImageResizeSchema`. */
    private const array INPUT_LIMITS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'maxRequestBytes' => ['type' => 'integer', 'minimum' => 1],
            'images' => [
                'type' => 'object',
                'properties' => [
                    'resize' => [
                        'type' => 'object',
                        'properties' => [
                            'maxWidth' => ['type' => 'integer', 'minimum' => 1],
                            'maxHeight' => ['type' => 'integer', 'minimum' => 1],
                            'maxBytes' => ['type' => 'integer', 'minimum' => 1],
                            'jpegQuality' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        ],
                    ],
                    'maxPerMessage' => ['type' => 'integer', 'minimum' => 1],
                    'maxPerRequest' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
        ],
    ];

    /**
     * What upstream's TypeBox schema says about one value, as `formatValidationPath()` writes it:
     * `<path>: <message>`, with TypeBox 1.3's English messages (`must be number`, `must be
     * integer`, `must be string`, `must be object`, `must be array`, `must be > 0`, `must be >= 1`,
     * `must be <= 100`, `must not have fewer than 1 characters`, `must not have more than 3 items`,
     * `must have required properties a, b`) — a `required` error's path naming the first property
     * missing. Its order (TypeBox's `ErrorSchema()`): the type, then an object's `required` before
     * its properties in the schema's order, a list's items before its `maxItems`, a string's
     * `minLength`, and a number's bounds — the bounds still checked on a number of the wrong kind
     * (`0.5` is both `must be integer` and `must be >= 1`), the keywords of another type skipped.
     *
     * **The value is from the object-preserving decode**: a JSON object is a `stdClass` and a JSON
     * array a PHP list, so `{}` where a list is wanted is `must be array` and `[]` where an object
     * is wanted is `must be object`, as upstream tells them apart. This used to read the array
     * decode, where the two are one value and either was taken as whichever the schema asked for.
     * An absent optional value (`$present` false) has nothing to say.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function schemaErrors(mixed $value, array $schema, string $path, bool $present = true): array
    {
        if (!$present) {
            return [];
        }

        $isNumber = is_int($value) || is_float($value);
        $isInteger = is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
        $isObject = $value instanceof \stdClass;
        $isArray = is_array($value);

        $typeMatches = match ($schema['type']) {
            'number' => $isNumber,
            'integer' => $isNumber && $isInteger,
            'string' => is_string($value),
            'array' => $isArray,
            default => $isObject,
        };

        $errors = $typeMatches ? [] : ["{$path}: must be {$schema['type']}"];

        if ($isObject && $schema['type'] === 'object') {
            $missing = array_values(array_filter($schema['required'] ?? [], static fn (string $key): bool => !property_exists($value, $key)));

            if ($missing !== []) {
                $errors[] = "{$path}.{$missing[0]}: must have required properties " . implode(', ', $missing);
            }

            foreach ($schema['properties'] as $key => $property) {
                $errors = [...$errors, ...self::schemaErrors($value->{$key} ?? null, $property, "{$path}.{$key}", property_exists($value, $key))];
            }
        }

        if ($isArray && $schema['type'] === 'array') {
            foreach (array_values($value) as $index => $item) {
                $errors = [...$errors, ...self::schemaErrors($item, $schema['items'], "{$path}.{$index}")];
            }

            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: must not have more than {$schema['maxItems']} items";
            }
        }

        if (is_string($value) && isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = "{$path}: must not have fewer than {$schema['minLength']} characters";
        }

        if ($isNumber) {
            if (isset($schema['exclusiveMinimum']) && !($value > $schema['exclusiveMinimum'])) {
                $errors[] = "{$path}: must be > {$schema['exclusiveMinimum']}";
            }

            if (isset($schema['maximum']) && !($value <= $schema['maximum'])) {
                $errors[] = "{$path}: must be <= {$schema['maximum']}";
            }

            if (isset($schema['minimum']) && !($value >= $schema['minimum'])) {
                $errors[] = "{$path}: must be >= {$schema['minimum']}";
            }
        }

        return $errors;
    }

    /**
     * What this model calls each thinking level — upstream's `thinkingLevelMap`.
     *
     * A key with a string is what to send instead of the level's own name; a key with **null**
     * says the model does not have that level at all, which is why this cannot be written with
     * `??` and why a missing key is left missing rather than filled in with null. The three
     * states are `Ai\Model`'s to read; this only has to preserve them.
     *
     * The seven keys are upstream's `ThinkingLevelMapSchema` — including `max`, which has no
     * `ThinkingLevel` here. It is kept rather than refused: a `models.json` written for pi is
     * meant to work here, and a file that is rejected over a level pig has no name for would make
     * that false. `ThinkingLevel::supportedBy()` simply never asks for it.
     *
     * Anything else in the object is dropped in silence — unlike the typed fields around it,
     * which say what is wrong. A wrong key here removes a level from a menu rather than
     * misreporting money, and upstream's schema ignores unknown keys too.
     *
     * @param array<mixed>|null $map
     *
     * @return array<string, string|null>
     */
    private static function thinkingLevelMap(?array $map): array
    {
        if ($map === null) {
            return [];
        }

        $kept = [];

        foreach (['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'] as $level) {
            if (!array_key_exists($level, $map)) {
                continue;
            }

            $value = $map[$level];

            if ($value === null || is_string($value)) {
                $kept[$level] = $value;
            }
        }

        return $kept;
    }

    /**
     * Dollars per million tokens. Absent is free, which is what a local model is.
     *
     * The same four field names and the same unit as `Ai\Models`' own tables and as upstream's
     * `cost` — so Sonnet's three dollars per million input tokens is `3.0` and not `0.000003`,
     * and a `models.json` written for pi carries its prices over unchanged. Every value here has
     * already been checked by `model()`, which refuses one that is not a number rather than
     * letting it read as free.
     *
     * @param array<mixed> $cost
     */
    private static function pricing(array $cost): Pricing
    {
        $number = static fn (string $key): float => is_int($cost[$key] ?? null) || is_float($cost[$key] ?? null)
            ? (float) $cost[$key]
            : 0.0;

        // Upstream's `ModelCost.tiers`: `{inputTokensAbove, input, output, cacheRead, cacheWrite}`
        // each, "the highest matching input threshold applies to the full request". A model's tiers
        // have passed `schemaErrors()` by now — all five fields, all numbers — so the guards here
        // are for `allowedFallbackModels`' costs, which are not checked that way.
        $tiers = [];

        foreach (is_array($cost['tiers'] ?? null) ? $cost['tiers'] : [] as $tier) {
            if (!is_array($tier) || (!is_int($tier['inputTokensAbove'] ?? null) && !is_float($tier['inputTokensAbove'] ?? null))) {
                continue;
            }

            $rate = static fn (string $key): float => is_int($tier[$key] ?? null) || is_float($tier[$key] ?? null)
                ? (float) $tier[$key]
                : 0.0;
            // `PricingTier` counts tokens in whole numbers; a fractional threshold is compared as
            // the whole number below it, which no token count can tell apart.
            $tiers[] = new PricingTier((int) $tier['inputTokensAbove'], $rate('input'), $rate('output'), $rate('cacheRead'), $rate('cacheWrite'));
        }

        return new Pricing($number('input'), $number('output'), $number('cacheRead'), $number('cacheWrite'), $tiers);
    }

    /**
     * The object-valued `compat` keys — upstream's `mergeCompat()` list — whose value reaches the
     * wire as the file wrote it: the two routing objects, sent as the request's `provider` and
     * `providerOptions.gateway`, and the two template-value objects, which `StreamProxy` sends
     * whole and `OpenAiCompletions` reads value by value.
     */
    private const array OBJECT_KEYS = ['openRouterRouting', 'vercelGatewayRouting', 'chatTemplateKwargs', 'chatTemplateArgs'];

    /**
     * The object-valued `compat` keys, re-read from the document with **its objects kept as objects**.
     *
     * Upstream passes these on exactly as the file wrote them. Decoded into PHP arrays, `{}` and
     * `[]` are the same value, and a nested empty object — `"max_price": {}` in a routing object,
     * `"options": {}` in `chatTemplateKwargs` — went out as `[]`, which is not the JSON the file
     * said. So each of these values is taken from a second, object-preserving decode of the same
     * text, where every non-empty object becomes an array again and an empty one stays an object
     * (`stdClass`, which `json_encode` writes as `{}`). The value itself stays an array — `{}` there
     * is `[]`, which every reader already treats as the empty object — so only what is inside it
     * changes.
     *
     * This used to cover the two routing keys only, and the template values went out with every
     * nested `{}` turned into `[]`.
     *
     * @param array<mixed> $decoded the document as arrays
     * @return array<mixed>
     */
    private static function keepCompatObjects(mixed $decoded, mixed $objects): mixed
    {
        if (!is_array($decoded) || !is_array($decoded['providers'] ?? null) || !($objects instanceof \stdClass)) {
            return $decoded;
        }

        foreach ($decoded['providers'] as $name => $provider) {
            $providerObject = $objects->providers->{$name} ?? null;

            if (!is_array($provider) || !$providerObject instanceof \stdClass) {
                continue;
            }

            $decoded['providers'][$name] = self::withCompatObjects($provider, $providerObject);

            foreach (is_array($provider['models'] ?? null) ? $provider['models'] : [] as $index => $model) {
                $modelObject = is_array($providerObject->models ?? null) ? ($providerObject->models[$index] ?? null) : null;

                if (is_array($model) && $modelObject instanceof \stdClass) {
                    $decoded['providers'][$name]['models'][$index] = self::withCompatObjects($model, $modelObject);
                }
            }
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $entry a provider or a model, as arrays
     * @return array<mixed>
     */
    private static function withCompatObjects(array $entry, \stdClass $object): array
    {
        $compat = $object->compat ?? null;

        if (!is_array($entry['compat'] ?? null) || !$compat instanceof \stdClass) {
            return $entry;
        }

        foreach (self::OBJECT_KEYS as $key) {
            $value = $compat->{$key} ?? null;

            if ($value instanceof \stdClass) {
                $entry['compat'][$key] = (array) self::emptyObjectsKept($value, top: true);
            }
        }

        return $entry;
    }

    /** A decoded JSON value as arrays, except that an empty object below the top stays `{}`. */
    private static function emptyObjectsKept(mixed $value, bool $top = false): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);

            if ($properties === [] && !$top) {
                return new \stdClass();
            }

            return array_map(self::emptyObjectsKept(...), $properties);
        }

        if (is_array($value)) {
            return array_map(self::emptyObjectsKept(...), $value);
        }

        return $value;
    }

    /**
     * Upstream's `mergeCompat(base, override)`: the override's keys over the base's, and the
     * object-valued ones (`openRouterRouting`, `vercelGatewayRouting`, `chatTemplateKwargs`,
     * `chatTemplateArgs`, upstream's list in its order) merged a level deeper rather than replaced —
     * so a provider's `"openRouterRouting": {"allow_fallbacks": false}` and a model's
     * `{"order": ["anthropic"]}` both reach the request.
     *
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    private static function mergeCompat(array $base, array $override): array
    {
        if ($override === []) {
            return $base;
        }

        $merged = [...$base, ...$override];

        foreach (self::OBJECT_KEYS as $key) {
            $baseValue = $base[$key] ?? null;
            $overrideValue = $override[$key] ?? null;

            if (is_array($baseValue) || is_array($overrideValue)) {
                $merged[$key] = [
                    ...(is_array($baseValue) ? $baseValue : []),
                    ...(is_array($overrideValue) ? $overrideValue : []),
                ];
            }
        }

        return $merged;
    }

    /**
     * The ways an OpenAI-compatible endpoint is not, as a file can say them.
     *
     * **Every key is upstream's spelling**, so a `models.json` written for pi works here
     * unchanged. This used to read four of them under pig's own shorter names — the four upstream
     * prefixes with `requires` — on the stated grounds that upstream only had four. It had more,
     * in `types.ts`, and the note claiming otherwise is what stopped anybody checking. (Upstream has
     * since dropped one of them, `requiresMistralToolIds`, with Mistral's move to its own API, and so
     * has pig.)
     *
     * The short spellings are gone rather than kept beside them, for the reason the session
     * format's old shape is: nobody has a pig `models.json` from a release, and a file that holds
     * both names for one flag is a file that can disagree with itself.
     *
     * **A key the block leaves out is null, and null is "not said"**: `OpenAiCompat::resolve()`
     * lays the block over what `detect()` works out from the provider and URL, key by key —
     * upstream's `getCompat()`, `model.compat.x ?? detected.x`. This used to fill every missing
     * key with a plain default instead, so a block setting one flag for a DeepSeek endpoint also
     * switched `store`, the `developer` role and `max_completion_tokens` back on, which detection
     * had turned off. A block that sets every key means what it always did.
     *
     * No block at all is null too, and detection then decides alone — a local llama.cpp gets
     * the loose settings it needs without anybody writing a `compat` block.
     *
     * @param array<mixed>|null $compat
     */
    private static function compat(?array $compat, Api $api): OpenAiCompat|AnthropicCompat|null
    {
        if ($compat === null || $compat === []) {
            return null;
        }

        $flag = static fn (string $key): ?bool => is_bool($compat[$key] ?? null) ? $compat[$key] : null;

        // Upstream types `compat` by the model's API, and an `anthropic-messages` model's block is
        // `AnthropicMessagesCompat`: `forceAdaptiveThinking` is how a proxy serving an adaptive-only
        // Claude says so, since nothing at request time looks at the id.
        if ($api === Api::AnthropicMessages) {
            return new AnthropicCompat(
                forceAdaptiveThinking: $flag('forceAdaptiveThinking'),
                strictTools: $flag('supportsStrictTools'),
                supportsTemperature: $flag('supportsTemperature'),
                supportsEagerToolInputStreaming: $flag('supportsEagerToolInputStreaming'),
                supportsMidConvoEffort: $flag('supportsMidConvoEffort'),
                supportsLongCacheRetention: $flag('supportsLongCacheRetention'),
                sendSessionAffinityHeaders: $flag('sendSessionAffinityHeaders'),
                sessionAffinityFormat: is_string($compat['sessionAffinityFormat'] ?? null) ? $compat['sessionAffinityFormat'] : null,
                supportsCacheControlOnTools: $flag('supportsCacheControlOnTools'),
                allowEmptySignature: $flag('allowEmptySignature'),
                allowedFallbackModels: self::allowedFallbackModels($compat['allowedFallbackModels'] ?? null),
            );
        }

        $maxTokensField = $compat['maxTokensField'] ?? null;

        return new OpenAiCompat(
            store: $flag('supportsStore'),
            developerRole: $flag('supportsDeveloperRole'),
            reasoningEffort: $flag('supportsReasoningEffort'),
            // A string that is neither name still means `max_completion_tokens`, as it does
            // upstream, where only `=== "max_tokens"` sends the older field.
            maxTokensField: is_string($maxTokensField)
                ? ($maxTokensField === 'max_tokens' ? 'max_tokens' : 'max_completion_tokens')
                : null,
            toolResultName: $flag('requiresToolResultName'),
            assistantAfterToolResult: $flag('requiresAssistantAfterToolResult'),
            thinkingAsText: $flag('requiresThinkingAsText'),
            reasoningContentOnAssistantMessages: $flag('requiresReasoningContentOnAssistantMessages'),
            strictMode: $flag('supportsStrictMode'),
            thinkingFormat: is_string($compat['thinkingFormat'] ?? null) ? $compat['thinkingFormat'] : null,
            chatTemplateKwargs: self::templateValues($compat['chatTemplateKwargs'] ?? null),
            chatTemplateArgs: self::templateValues($compat['chatTemplateArgs'] ?? null),
            // Upstream's routing objects, passed through as written: OpenRouter's `provider` field
            // and Vercel's `only`/`order`. A JSON object, or not said — the same test as the
            // template values. `{}` is said, and is sent as `{}`, as upstream sends it.
            openRouterRouting: self::templateValues($compat['openRouterRouting'] ?? null),
            vercelGatewayRouting: self::templateValues($compat['vercelGatewayRouting'] ?? null),
            grammarTools: $flag('supportsOpenAIGrammarTools'),
            // `OpenAIResponsesCompat`'s keys, which the Responses provider reads.
            sessionAffinityFormat: is_string($compat['sessionAffinityFormat'] ?? null) ? $compat['sessionAffinityFormat'] : null,
            supportsLongCacheRetention: $flag('supportsLongCacheRetention'),
            supportsExplicitPromptCacheMode: $flag('supportsExplicitPromptCacheMode'),
            supportsMaxOutputTokens: $flag('supportsMaxOutputTokens'),
            // Upstream's `OpenAICompletionsCompatSchema` keys for caching and session affinity.
            sendSessionAffinityHeaders: $flag('sendSessionAffinityHeaders'),
            cacheControlFormat: is_string($compat['cacheControlFormat'] ?? null) ? $compat['cacheControlFormat'] : null,
            // The rest of `OpenAICompletionsCompatSchema`: streaming and finish-reason support, z.ai's
            // `tool_stream`, the reasoning-budget field, vLLM's priority, and the transcript keys.
            supportsUsageInStreaming: $flag('supportsUsageInStreaming'),
            supportsFinishReason: $flag('supportsFinishReason'),
            zaiToolStream: $flag('zaiToolStream'),
            thinkingTokenBudgetField: is_string($compat['thinkingTokenBudgetField'] ?? null) ? $compat['thinkingTokenBudgetField'] : null,
            supportsThinkingTokenBudget: $flag('supportsThinkingTokenBudget'),
            vllmPriority: is_int($compat['vllmPriority'] ?? null) || is_float($compat['vllmPriority'] ?? null) ? $compat['vllmPriority'] : null,
            supportsMidConvoSystemMessages: $flag('supportsMidConvoSystemMessages'),
            supportsMidConvoToolAdditions: $flag('supportsMidConvoToolAdditions'),
            // `OpenAIResponsesCompat`'s tool-search keys.
            supportsToolSearch: $flag('supportsToolSearch'),
            supportsAdditionalTools: $flag('supportsAdditionalTools'),
        );
    }

    /**
     * Upstream's `allowedFallbackModels`: a list of `{provider, model, cost}`, an entry missing the
     * two names left out. Not said is null; a list with nothing usable in it is empty, which sends
     * no `fallbacks`.
     *
     * @return list<array{provider: string, model: string, cost: Pricing}>|null
     */
    private static function allowedFallbackModels(mixed $entries): ?array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            return null;
        }

        $allowed = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['provider'] ?? null) || !is_string($entry['model'] ?? null)) {
                continue;
            }

            $allowed[] = [
                'provider' => $entry['provider'],
                'model' => $entry['model'],
                'cost' => self::pricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : []),
            ];
        }

        return $allowed;
    }

    /**
     * `chatTemplateKwargs` / `chatTemplateArgs` (and the two routing objects): a JSON object, or
     * not said. A list is not one.
     *
     * @return array<string, mixed>|null
     */
    private static function templateValues(mixed $values): ?array
    {
        return is_array($values) && ($values === [] || !array_is_list($values)) ? $values : null;
    }

    /** `MY_BOX_KEY`, `DEEPSEEK_API_KEY` — what an environment variable is called and no key is. */
    private const string VARIABLE_NAME = '/^[A-Z][A-Z0-9_]*$/';

    /**
     * A value that names an environment variable, or is the thing itself, or is nothing.
     *
     * Upstream's `resolveApiKeyConfig`, and the order is the point: the variable is looked at
     * first, so a file can carry a name and the secret can stay out of it.
     *
     * **The third answer is pig's own.** Upstream falls back to the value whenever the environment
     * has nothing, which cannot tell a literal key from a *name whose variable is not set* — and
     * that second case then travels as a key: `hasKeyFor()` says yes, the models are listed and
     * switchable, and the request goes out as `Authorization: Bearer DEEPSEEK_API_KEY`. Which is
     * the failure this class's own docblock names as the reason for resolving at all, arriving
     * through the fallback. Measured with a `models.json` naming a variable that was not set:
     * `hasKeyFor` true, `apiKey` the literal string, and the provider in `availableModels()`.
     *
     * The two are told apart by shape, which is safe because no provider's key looks like a
     * variable name: `sk-ant-…`, `sk-…`, `AIza…`, `gsk_…`, `xai-…` all carry lowercase or a dash,
     * and `^[A-Z][A-Z0-9_]*$` carries neither. So an all-capitals value with no variable behind it
     * is a name that was never filled in, and the honest answer is that there is no key — which
     * `hasKeyFor()` turns into a model that is not offered, and `load()` into a line at startup
     * saying which variable is empty. Anything else is still taken literally, as upstream takes it.
     */
    public static function resolve(string $value): ?string
    {
        $fromEnvironment = getenv($value);

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        return $value === '' || preg_match(self::VARIABLE_NAME, $value) === 1 ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && is_string($entry)) {
                $strings[$key] = $entry;
            }
        }

        return $strings;
    }
}
