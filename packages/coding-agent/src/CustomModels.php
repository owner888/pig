<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\BedrockCompat;
use Pig\Ai\Model;
use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\CodingAgent\Schemas\ConfigSchemas;
use Pig\CodingAgent\Schemas\SchemaErrors;

/**
 * Providers and models declared in a file, so somebody's own endpoint does not need a release.
 *
 * Upstream's `core/model-config.ts` (the file) and the `models.json` half of
 * `core/provider-composer.ts` (`applyModelsJson()`, `applyModelOverride()`, `modelFromJson()`). A
 * file looks like this, and every key in it is upstream's:
 *
 * ```json
 * {
 *   "providers": {
 *     "my-box": {
 *       "baseUrl": "http://192.168.1.9:8080/v1",
 *       "apiKey": "$MY_BOX_KEY",
 *       "api": "openai-completions",
 *       "authHeader": true,
 *       "headers": { "X-Tenant": "acme" },
 *       "models": [{ "id": "qwen3-coder", "contextWindow": 262144 }]
 *     },
 *     "openai": {
 *       "modelOverrides": { "gpt-5.5": { "contextWindow": 1050000 } }
 *     }
 *   }
 * }
 * ```
 *
 * **A provider may be a new one or one that exists** — built in, or registered by an extension. For
 * one that exists its `baseUrl`, `headers` and `compat` are laid over every model it has, a `models`
 * entry adds a model or replaces the one with the same id (taking the provider's `api` and `baseUrl`
 * from its models when the entry says neither — upstream's `findModelDefaults()`), and
 * `modelOverrides` changes fields of models by id without replacing the list ("Unknown override IDs
 * are ignored"). That is `Models::useOverlay()`, over everything else in the registry, so an extension
 * provider registered after the file was read is covered too. The defaults a `models` entry takes are
 * the ones known when the file is read; the file is read again whenever `/model` opens.
 *
 * **`apiKey` and every header value is a `ConfigValue`**: `$NAME` or `${NAME}` for an environment
 * variable, `!command` for a command's output, anything else literally — a bare `MY_BOX_KEY` is a
 * key that is those ten letters. The key is resolved when a request needs it, so a command runs
 * every time, as upstream's `resolveConfigValueOrThrow()` runs it. Headers are resolved when the file
 * is read — pig's headers are part of the model — so a header that cannot be resolved refuses what it
 * belongs to and says why, in the words upstream's request would have failed with.
 *
 * **Nothing here throws.** A file that is wrong loses only itself: the built-in models are
 * still there, pig still starts, and every problem is collected with the provider and model it
 * came from so the line names what to fix. Upstream refuses the whole file over one schema error;
 * pig refuses the provider, model or override the error is in.
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
        'google-vertex' => Api::GoogleVertex,
        'bedrock-converse-stream' => Api::BedrockConverseStream,
        'azure-openai-responses' => Api::AzureOpenAiResponses,
        'openai-codex-responses' => Api::OpenAiCodexResponses,
        'pi-messages' => Api::PiMessages,
    ];

    /** The `Api` an upstream api name is, or null for one pig has no provider for. */
    public static function api(string $name): ?Api
    {
        return self::APIS[$name] ?? null;
    }

    /** Upstream's `modelFromJson()` defaults for what a definition leaves out. */
    private const int DEFAULT_CONTEXT_WINDOW = 128_000;

    private const int DEFAULT_MAX_TOKENS = 16_384;

    /**
     * @param list<Model>           $models   what the file's `models` entries declare, every provider
     * @param array<string, string> $keys     provider name => what its `apiKey` said, for `Auth`
     * @param list<string>          $problems everything wrong with the file, each naming where
     * @param array<string, array{baseUrl: ?string, headers: array<string, string>, compat: array<mixed>, declared: list<Model>, declaredHeaders: array<string, array<string, string>>, overrides: array<string, array<mixed>>}> $layers
     *        per provider, what `overlay()` lays over the registry
     */
    private function __construct(
        public array $models,
        public array $keys,
        public array $problems,
        private array $layers = [],
    ) {
    }

    /**
     * Read pi's `~/.pi/agent/models.json` and then `~/.pig/agent/models.json`, the second laid over
     * the first a provider at a time.
     *
     * Both, and not "pig's, or pi's when pig has none": somebody who told pi about their endpoint
     * should not have to say it twice, and the fallback stopped meaning that the day they also
     * wrote a pig file for something else — every provider in pi's file vanished from pig without
     * a word, while pi went on using it. A provider named in both is pig's entry **whole**, never a
     * mix of two files' fields, so what pig does with it is what pig's file says. Neither file is
     * ever written.
     */
    public static function discover(): self
    {
        $merged = new self([], [], []);

        foreach ([Config::piHome(), Config::home()] as $directory) {
            $merged = $merged->layeredWith(self::load($directory . '/models.json'));
        }

        return $merged;
    }

    /**
     * This file with `$over` laid on top: a provider `$over` names replaces this one's entry for it
     * — its models, its overrides and its key — and the problems of both are kept.
     */
    private function layeredWith(self $over): self
    {
        $layers = [...$this->layers, ...$over->layers];
        $keys = $this->keys;

        // A provider the later file names takes its key from there, or has none; a key from the
        // earlier file must not ride along on an entry that no longer says it.
        foreach (array_keys($over->layers) as $provider) {
            unset($keys[$provider]);
        }

        $models = [];
        foreach ($layers as $layer) {
            $models = [...$models, ...$layer['declared']];
        }

        return new self($models, [...$keys, ...$over->keys], [...$this->problems, ...$over->problems], $layers);
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
            // object` there. `schemaProblems()` reads this one.
            $objects = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
            $decoded = self::keepCompatObjects($decoded, $objects);
        } catch (\JsonException $error) {
            return new self([], [], ["{$path} is not valid JSON: {$error->getMessage()}"]);
        }

        if (!is_array($decoded) || !isset($decoded['providers']) || !is_array($decoded['providers'])) {
            return new self([], [], ["{$path} has no \"providers\" object in it"]);
        }

        // Upstream's `ModelsConfigSchema` at the top: `$schema` may name the published schema
        // (`https://pigagent.dev/schemas/models.schema.json`) and is never a provider, but it is a
        // string. The file is refused over it, as upstream refuses it — it is about the whole file.
        $topErrors = self::schemaProblems($objects, self::topSchema(), '');

        if ($topErrors !== []) {
            return new self([], [], ["{$path}: invalid models.json schema: " . implode('; ', $topErrors)]);
        }

        $models = [];
        $keys = [];
        $problems = [];
        $layers = [];

        foreach ($decoded['providers'] as $name => $provider) {
            if (!is_string($name) || $name === '' || !is_array($provider)) {
                $problems[] = "{$path}: a provider with no name, or whose value is not an object";

                continue;
            }

            $providerObject = $objects instanceof \stdClass && $objects->providers instanceof \stdClass
                ? ($objects->providers->{$name} ?? null)
                : null;
            [$layer, $key, $providerProblems] = self::provider(
                $path,
                $name,
                $provider,
                $providerObject instanceof \stdClass ? $providerObject : new \stdClass(),
            );

            $problems = [...$problems, ...$providerProblems];

            if ($layer !== null) {
                $layers[$name] = $layer;
                $models = [...$models, ...$layer['declared']];
            }

            if ($key !== null) {
                $keys[$name] = $key;
            }
        }

        return new self($models, $keys, $problems, $layers);
    }

    /**
     * Lay the file over the registry and hand the keys to `Auth` — **replacing** what an earlier read
     * installed, so reading the file again (`/model`) is the whole of a reload.
     */
    public function install(?Auth $auth = null): void
    {
        Models::useOverlay($this->layers === [] ? null : $this->overlay(...));
        Models::useClassifierOverlay($this->layers === [] ? null : $this->classifierOverlay(...));

        // The keys go in whether or not there are models: a provider whose models were all
        // rejected still has a key, and the next run with a fixed file should not need a
        // second place to put it.
        $auth?->setCustomProviderKeys($this->keys);
    }

    /**
     * Upstream's `applyModelsJson()` then `modelOverrides`, over the registry's chat table: each
     * provider's existing models given its `baseUrl`, `headers` and `compat`, its declared models put
     * in by id, and its overrides applied to whichever model answers to each id.
     *
     * @param array<string, Model> $table
     * @return array<string, Model>
     */
    private function overlay(array $table): array
    {
        foreach ($this->layers as $provider => $layer) {
            if ($layer['baseUrl'] !== null || $layer['headers'] !== [] || $layer['compat'] !== []) {
                foreach ($table as $key => $model) {
                    if ($model->provider !== $provider) {
                        continue;
                    }

                    $table[$key] = self::with($model, [
                        'baseUrl' => $layer['baseUrl'] ?? $model->baseUrl,
                        'headers' => [...$model->headers, ...$layer['headers']],
                        'compat' => self::mergeTypedCompat($model->compat, self::compat($layer['compat'], $model->api)),
                    ]);
                }
            }

            foreach ($layer['declared'] as $model) {
                $table[$provider . '/' . $model->id] = $model;
            }

            foreach ($layer['overrides'] as $id => $override) {
                $model = $table[$provider . '/' . $id] ?? null;

                if ($model !== null) {
                    $table[$provider . '/' . $id] = self::applyOverride($model, $override, $layer['declaredHeaders'][$id] ?? []);
                }
            }
        }

        return $table;
    }

    /**
     * Upstream's `applyModelsJson()` over a provider's classifier models: its `baseUrl`, and its
     * `compat` over theirs (7f225f25d) — a custom provider that proxies another service's
     * classifiers says there how that service takes images. Declared models and `modelOverrides`
     * apply to chat models only.
     *
     * @param array<string, \Pig\Ai\ClassifierModel> $table
     * @return array<string, \Pig\Ai\ClassifierModel>
     */
    private function classifierOverlay(array $table): array
    {
        foreach ($this->layers as $provider => $layer) {
            $compat = \Pig\Ai\SystemOneCompat::fromArray($layer['compat']);

            if ($layer['baseUrl'] === null && $compat === null) {
                continue;
            }

            foreach ($table as $key => $model) {
                if ($model->provider !== $provider) {
                    continue;
                }

                $model = $layer['baseUrl'] !== null ? $model->withBaseUrl($layer['baseUrl']) : $model;
                $table[$key] = $compat !== null ? $model->withCompat($compat->over($model->compat)) : $model;
            }
        }

        return $table;
    }

    /**
     * @param array<mixed> $config
     * @param \stdClass $object the same provider, decoded with its objects kept — for `schemaProblems()`
     * @return array{0: array{baseUrl: ?string, headers: array<string, string>, compat: array<mixed>, declared: list<Model>, declaredHeaders: array<string, array<string, string>>, overrides: array<string, array<mixed>>}|null, 1: string|null, 2: list<string>}
     */
    private static function provider(string $path, string $name, array $config, \stdClass $object): array
    {
        $where = "{$path}, provider \"{$name}\"";
        $baseUrl = $config['baseUrl'] ?? null;
        $key = $config['apiKey'] ?? null;

        // Upstream's `ProviderConfigSchema`, all of it but the entries of `models` and
        // `modelOverrides`, which are checked one at a time below so that one bad model refuses that
        // model rather than its provider.
        $providerErrors = self::schemaProblems($object, self::providerSchema(), "/providers/{$name}");

        if ($providerErrors !== []) {
            return [null, null, ["{$where}: invalid models.json schema: " . implode('; ', $providerErrors)]];
        }

        $entries = $config['models'] ?? [];
        $overrides = $config['modelOverrides'] ?? [];

        // Upstream's `applyModelsJson()` refusal: a provider entry that changes nothing is a mistake.
        if ($entries === [] && $baseUrl === null && !isset($config['headers']) && !isset($config['compat'])
            && $overrides === [] && $key === null && !isset($config['oauth']) && !isset($config['authHeader'])) {
            return [null, null, ["{$where}: must specify \"baseUrl\", \"headers\", \"compat\", \"modelOverrides\", or \"models\""]];
        }

        $problems = [];

        try {
            $headers = ConfigValue::resolveAll(self::strings($config['headers'] ?? null), "provider \"{$name}\" header");
        } catch (\RuntimeException $error) {
            return [null, $key, ["{$where}: {$error->getMessage()}"]];
        }

        // `authHeader` is for an endpoint that wants the key in `Authorization` rather than wherever
        // its protocol puts it — a proxy in front of something, usually. Upstream's
        // `withConfiguredAuth()`; with no key that resolves there is no header rather than `Bearer `
        // with nothing after it.
        $missing = $key === null ? [] : ConfigValue::missingVariables($key);

        if ($missing !== []) {
            // Said where every other thing wrong with this file is said, because it is the same kind
            // of thing: a value that cannot do its job, named so the line says what to fix. Without
            // it the only symptom is a model missing from `/model`.
            $named = implode(', ', array_map(static fn (string $variable): string => '$' . $variable, $missing));
            $problems[] = "{$where}: \"apiKey\" references {$named}, which " . (count($missing) === 1 ? 'is' : 'are') . ' not set — no key for this provider';
        }

        // Only here is the key resolved when the file is read; a `!command` key otherwise runs when a
        // request needs it.
        if (($config['authHeader'] ?? false) === true && $key !== null && ($resolved = ConfigValue::resolveUncached($key)) !== null) {
            $headers['Authorization'] = 'Bearer ' . $resolved;
        }

        $providerCompat = is_array($config['compat'] ?? null) ? $config['compat'] : [];
        $existing = Models::baseModelsOf($name);
        $declared = [];
        $declaredHeaders = [];
        $entryObjects = is_array($object->models ?? null) ? $object->models : [];

        foreach ($entries as $position => $entry) {
            $entryObject = $entryObjects[$position] ?? null;

            if (!is_array($entry) || !$entryObject instanceof \stdClass) {
                $problems[] = "{$where}: invalid models.json schema: " . implode('; ', self::schemaProblems($entryObject, self::modelSchema(), "/providers/{$name}/models/{$position}"));

                continue;
            }

            $built = self::model(
                $where,
                $name,
                is_string($baseUrl) ? $baseUrl : null,
                $headers,
                $config['api'] ?? null,
                $providerCompat,
                $entry,
                $entryObject,
                "/providers/{$name}/models/{$position}",
                $existing,
            );

            if (is_string($built)) {
                $problems[] = $built;

                continue;
            }

            [$model, $ownHeaders] = $built;
            $declared[] = $model;
            $declaredHeaders[$model->id] = $ownHeaders;
        }

        $kept = [];
        $overrideObjects = $object->modelOverrides ?? null;

        foreach ($overrides as $id => $override) {
            $overrideObject = $overrideObjects instanceof \stdClass ? ($overrideObjects->{$id} ?? null) : null;
            $checked = self::override($where, $name, (string) $id, $override, $overrideObject);

            if (is_string($checked)) {
                $problems[] = $checked;

                continue;
            }

            $kept[(string) $id] = $checked;
        }

        return [[
            'baseUrl' => is_string($baseUrl) ? rtrim($baseUrl, '/') : null,
            'headers' => $headers,
            'compat' => $providerCompat,
            'declared' => $declared,
            'declaredHeaders' => $declaredHeaders,
            'overrides' => $kept,
        ], $key, $problems];
    }

    /**
     * One model, or the line saying why not — upstream's `modelFromJson()`.
     *
     * @param array<string, string> $headers the provider's, which a model's own override
     * @param array<mixed>          $providerCompat the provider's `compat`, which a model's own
     *        overrides key by key — upstream's `mergeCompat(providerConfig.compat, definition.compat)`
     * @param array<mixed>          $entry
     * @param \stdClass             $object the same model, decoded with its objects kept as objects
     * @param string                $schemaPath the JSON pointer to this model,
     *        `/providers/<name>/models/<index>`, which its schema errors are reported under
     * @param list<Model>           $existing the provider's models before the file, for the defaults
     * @return array{0: Model, 1: array<string, string>}|string the model and its own headers, resolved
     */
    private static function model(
        string $where,
        string $provider,
        ?string $baseUrl,
        array $headers,
        mixed $providerApi,
        array $providerCompat,
        array $entry,
        \stdClass $object,
        string $schemaPath = '',
        array $existing = [],
    ): array|string {
        $id = $entry['id'] ?? null;
        $label = is_string($id) && $id !== '' ? "{$where}, model \"{$id}\"" : $where;

        // Upstream's `ModelDefinitionSchema`, the whole entry, in its words — `<path>: <message>`, the
        // lines of its "Invalid models.json schema" — and in typebox's order, at most eight. Upstream
        // refuses the whole file over one; pig refuses this model, as it does for everything else
        // here. **`cost` is upstream's `ModelCostSchema`**: absent is free — that is what a local
        // endpoint is — but a block that is there says all four rates, or the model is refused.
        $schemaErrors = self::schemaProblems($object, self::modelSchema(), $schemaPath);

        if ($schemaErrors !== [] || !is_string($id)) {
            return "{$label}: invalid models.json schema: " . implode('; ', $schemaErrors);
        }

        $name = $entry['name'] ?? $id;

        // Upstream's rule: the model's own `api` wins, the provider's is next, and then the api of
        // the provider's existing models (`findModelDefaults()`: the same id, else one with the api
        // asked for, else an `openai-completions` one, else the first). None of them is an error
        // rather than a guess — there is no sensible protocol to assume.
        $api = $entry['api'] ?? $providerApi;

        if ($api !== null && (!is_string($api) || !isset(self::APIS[$api]))) {
            return "{$where}, model \"{$id}\": \"api\" must be one of " . implode(', ', array_keys(self::APIS));
        }

        $defaults = self::findModelDefaults($existing, $id, $api === null ? null : self::APIS[$api]);
        $resolvedApi = $api !== null ? self::APIS[$api] : $defaults?->api;

        if ($resolvedApi === null) {
            return "{$where}, model \"{$id}\": no \"api\" specified — set it on the model or on the provider";
        }

        $modelBaseUrl = $entry['baseUrl'] ?? $baseUrl ?? $defaults?->baseUrl;

        if (!is_string($modelBaseUrl) || $modelBaseUrl === '') {
            return "{$where}, model \"{$id}\": \"baseUrl\" is required when defining custom models";
        }

        $window = $entry['contextWindow'] ?? self::DEFAULT_CONTEXT_WINDOW;
        $maxTokens = $entry['maxTokens'] ?? self::DEFAULT_MAX_TOKENS;

        if (!is_int($window) || $window <= 0) {
            return "{$where}, model \"{$id}\": \"contextWindow\" must be a positive whole number";
        }

        if (!is_int($maxTokens) || $maxTokens <= 0) {
            return "{$where}, model \"{$id}\": \"maxTokens\" must be a positive whole number";
        }

        try {
            $ownHeaders = ConfigValue::resolveAll(self::strings($entry['headers'] ?? null), "model \"{$provider}/{$id}\" header");
        } catch (\RuntimeException $error) {
            return "{$where}, model \"{$id}\": {$error->getMessage()}";
        }

        return [new Model(
            $id,
            $name,
            $resolvedApi,
            $provider,
            rtrim($modelBaseUrl, '/'),
            $window,
            $maxTokens,
            ($entry['reasoning'] ?? false) === true,
            self::input($entry['input'] ?? null) ?? ['text'],
            self::pricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : []),
            [...$headers, ...$ownHeaders],
            // Upstream's `mergeCompat()`: `{ ...base, ...override }`, the model's keys over the
            // provider's. Detection then fills whatever neither says, in `OpenAiCompat::resolve()`.
            self::compat(
                self::mergeCompat($providerCompat, is_array($entry['compat'] ?? null) ? $entry['compat'] : []),
                $resolvedApi,
            ),
            self::thinkingLevelMap(is_array($entry['thinkingLevelMap'] ?? null) ? $entry['thinkingLevelMap'] : null),
            // Upstream's `inputLimits: definition.inputLimits` and `promptCache: definition.promptCache`,
            // as written — checked above, and given no defaults here, as upstream gives none.
            is_array($entry['inputLimits'] ?? null) ? $entry['inputLimits'] : null,
            is_array($entry['promptCache'] ?? null) ? $entry['promptCache'] : null,
        ), $ownHeaders];
    }

    /**
     * Upstream's `findModelDefaults()`: the existing model with the same id, else one speaking the api
     * asked for, else an `openai-completions` one, else the first.
     *
     * @param list<Model> $models
     */
    private static function findModelDefaults(array $models, string $id, ?Api $api): ?Model
    {
        foreach ($models as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        foreach ([$api, Api::OpenAiCompletions] as $wanted) {
            foreach ($wanted === null ? [] : $models as $model) {
                if ($model->api === $wanted) {
                    return $model;
                }
            }
        }

        return $models[0] ?? null;
    }

    /** `input` as upstream's `ModelInputModalitySchema` list: `text` and `image`, null when not said. @return list<'text'|'image'>|null */
    private static function input(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }

        $accepted = array_values(array_filter($input, static fn (mixed $one): bool => $one === 'text' || $one === 'image'));

        return $accepted === [] ? null : $accepted;
    }

    /**
     * One `modelOverrides` entry, checked — upstream's `ModelOverrideSchema` — or the line saying why
     * not. Its header values are resolved here, as a model's are.
     *
     * @return array<mixed>|string
     */
    private static function override(string $where, string $provider, string $id, mixed $override, mixed $object): array|string
    {
        $at = "{$where}, modelOverrides \"{$id}\"";

        // Upstream's `ModelOverrideSchema`: every field optional, `cost` a partial `ModelCostSchema`.
        $errors = self::schemaProblems($object, self::overrideSchema(), "/providers/{$provider}/modelOverrides/{$id}");

        if ($errors !== []) {
            return "{$at}: invalid models.json schema: " . implode('; ', $errors);
        }

        if (!is_array($override)) {
            return "{$at}: must be an object";
        }

        try {
            $override['headers'] = ConfigValue::resolveAll(self::strings($override['headers'] ?? null), "model \"{$provider}/{$id}\" header");
        } catch (\RuntimeException $error) {
            return "{$at}: {$error->getMessage()}";
        }

        return $override;
    }

    /**
     * Upstream's `applyModelOverride()`: each field the override says replaces the model's — maps,
     * `promptCache`, `samplingParams` merged a key deep, `inputLimits` two, `cost` rate by rate, `compat`
     * through `mergeCompat()`. Headers: the override's over the model's, a `models` entry's own over both
     * (upstream's `rawModelHeaders()` order).
     *
     * @param array<mixed> $override
     * @param array<string, string> $declaredHeaders the headers a `models` entry with this id said itself
     */
    private static function applyOverride(Model $model, array $override, array $declaredHeaders): Model
    {
        $cost = is_array($override['cost'] ?? null) ? $override['cost'] : null;
        $rate = static fn (string $key, float $base): float => is_int($cost[$key] ?? null) || is_float($cost[$key] ?? null) ? (float) $cost[$key] : $base;
        $number = static fn (string $key, int $base): int => is_int($override[$key] ?? null) || is_float($override[$key] ?? null) ? (int) $override[$key] : $base;

        return self::with($model, [
            'name' => is_string($override['name'] ?? null) ? $override['name'] : $model->name,
            'reasoning' => is_bool($override['reasoning'] ?? null) ? $override['reasoning'] : $model->reasoning,
            'thinkingLevelMap' => is_array($override['thinkingLevelMap'] ?? null)
                ? [...$model->thinkingLevelMap, ...self::thinkingLevelMap($override['thinkingLevelMap'])]
                : $model->thinkingLevelMap,
            'input' => self::input($override['input'] ?? null) ?? $model->input,
            'inputLimits' => self::mergeInputLimits($model->inputLimits, is_array($override['inputLimits'] ?? null) ? $override['inputLimits'] : null),
            'pricing' => $cost === null ? $model->pricing : new Pricing(
                $rate('input', $model->pricing->input),
                $rate('output', $model->pricing->output),
                $rate('cacheRead', $model->pricing->cacheRead),
                $rate('cacheWrite', $model->pricing->cacheWrite),
                is_array($cost['tiers'] ?? null) ? self::pricing(['tiers' => $cost['tiers']])->tiers : $model->pricing->tiers,
            ),
            'promptCache' => is_array($override['promptCache'] ?? null) ? [...($model->promptCache ?? []), ...$override['promptCache']] : $model->promptCache,
            'contextWindow' => $number('contextWindow', $model->contextWindow),
            'maxTokens' => $number('maxTokens', $model->maxTokens),
            'compat' => is_array($override['compat'] ?? null)
                ? self::mergeTypedCompat($model->compat, self::compat($override['compat'], $model->api))
                : $model->compat,
            'headers' => [...$model->headers, ...$override['headers'], ...$declaredHeaders],
        ]);
    }

    /**
     * Upstream's `mergeInputLimits()`: the override's keys over the model's, `images` a level deeper and
     * its `resize` a level deeper still.
     *
     * @param array<string, mixed>|null $base
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>|null
     */
    private static function mergeInputLimits(?array $base, ?array $override): ?array
    {
        if ($override === null) {
            return $base;
        }

        $merged = [...($base ?? []), ...$override];
        $baseImages = is_array($base['images'] ?? null) ? $base['images'] : null;

        if (is_array($override['images'] ?? null)) {
            $images = [...($baseImages ?? []), ...$override['images']];
            $baseResize = is_array($baseImages['resize'] ?? null) ? $baseImages['resize'] : null;

            if (is_array($override['images']['resize'] ?? null)) {
                $images['resize'] = [...($baseResize ?? []), ...$override['images']['resize']];
            } elseif ($baseResize !== null) {
                $images['resize'] = $baseResize;
            }

            $merged['images'] = $images;
        } elseif ($baseImages !== null) {
            $merged['images'] = $baseImages;
        }

        return $merged;
    }

    /**
     * Upstream's `mergeCompat()` for a model's typed compat: every key the override says over the
     * base's, the object-valued ones (`OBJECT_KEYS`, under the property names pig gives them) merged a
     * level deeper. A base of another API's type, or none, is replaced.
     */
    private static function mergeTypedCompat(
        OpenAiCompat|AnthropicCompat|BedrockCompat|null $base,
        OpenAiCompat|AnthropicCompat|BedrockCompat|null $override,
    ): OpenAiCompat|AnthropicCompat|BedrockCompat|null {
        if ($override === null) {
            return $base;
        }

        if ($base === null || $base::class !== $override::class) {
            return $override;
        }

        $merged = get_object_vars($base);

        foreach (get_object_vars($override) as $key => $value) {
            if ($value === null) {
                continue;
            }

            $merged[$key] = in_array($key, self::OBJECT_KEYS, true) && is_array($value) && is_array($merged[$key] ?? null)
                ? [...$merged[$key], ...$value]
                : $value;
        }

        return new ($base::class)(...$merged);
    }

    /**
     * The same model with some fields changed — upstream's `{ ...model, … }`.
     *
     * @param array<string, mixed> $changes constructor argument names
     */
    private static function with(Model $model, array $changes): Model
    {
        return new Model(...[...get_object_vars($model), ...$changes]);
    }

    // ---- upstream's models.json schema -----------------------------------------------------------

    /**
     * Everything `SchemaErrors` finds in $value against $schema, formatted as upstream's
     * `formatValidationPath()` formats it: the instance path dotted, `root` for the document, and a
     * missing property named after its object's path.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function schemaProblems(mixed $value, array $schema, string $instancePath): array
    {
        return array_map(static function (array $error): string {
            $path = str_replace('/', '.', ltrim($error['instancePath'], '/'));

            if ($error['keyword'] === 'required' && ($missing = $error['params']['requiredProperties'][0] ?? null) !== null) {
                return ($path === '' ? $missing : "{$path}.{$missing}") . ": {$error['message']}";
            }

            return ($path === '' ? 'root' : $path) . ": {$error['message']}";
        }, SchemaErrors::of($schema, $value, ConfigSchemas::load('models'), $instancePath));
    }

    /** @return array<string, mixed> the top of `models.schema.json`, its providers not looked into */
    private static function topSchema(): array
    {
        $schema = ConfigSchemas::load('models');
        $schema['properties']['providers'] = ['type' => 'object'];

        return $schema;
    }

    /** @return array<string, mixed> one provider, its `models` and `modelOverrides` entries not looked into */
    private static function providerSchema(): array
    {
        $schema = ConfigSchemas::load('models')['properties']['providers']['patternProperties']['^.*$'];
        $schema['properties']['models'] = ['type' => 'array'];
        $schema['properties']['modelOverrides'] = ['type' => 'object'];

        return $schema;
    }

    /** @return array<string, mixed> one `models` entry */
    private static function modelSchema(): array
    {
        return ConfigSchemas::load('models')['properties']['providers']['patternProperties']['^.*$']['properties']['models']['items'];
    }

    /** @return array<string, mixed> one `modelOverrides` entry */
    private static function overrideSchema(): array
    {
        return ConfigSchemas::load('models')['properties']['providers']['patternProperties']['^.*$']['properties']['modelOverrides']['patternProperties']['^.*$'];
    }

    /**
     * What this model calls each thinking level — upstream's `thinkingLevelMap`.
     *
     * A key with a string is what to send instead of the level's own name; a key with **null**
     * says the model does not have that level at all, which is why this cannot be written with
     * `??` and why a missing key is left missing rather than filled in with null. The three
     * states are `Ai\Model`'s to read; this only has to preserve them.
     *
     * The seven keys are upstream's `ThinkingLevelMapSchema`, `max` among them.
     *
     * Anything else in the object is dropped in silence — unlike the typed fields around it,
     * which say what is wrong. A wrong key here removes a level from a menu rather than
     * misreporting money, and upstream's schema ignores unknown keys too.
     *
     * @param array<mixed>|null $map
     *
     * @return array<string, string|null>
     */
    public static function thinkingLevelMap(?array $map): array
    {
        return ModelJson::thinkingLevelMap($map);
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
    public static function pricing(array $cost): Pricing
    {
        return ModelJson::pricing($cost);
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
    public static function compat(?array $compat, Api $api): OpenAiCompat|AnthropicCompat|BedrockCompat|null
    {
        return $compat === null ? null : ModelJson::compat($compat, $api);
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
