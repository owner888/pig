<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;

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
    /** What `api` may say. Upstream's four: the protocols a custom endpoint can speak. */
    private const array APIS = [
        'openai-completions' => Api::OpenAiCompletions,
        'openai-responses' => Api::OpenAiResponses,
        'anthropic-messages' => Api::AnthropicMessages,
        'google-generative-ai' => Api::GoogleGenerativeAi,
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

            [$providerModels, $key, $providerProblems] = self::provider($path, $name, $provider);

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
     * @return array{0: list<Model>, 1: string|null, 2: list<string>}
     */
    private static function provider(string $path, string $name, array $config): array
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

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $problems[] = "{$where}: a model that is not an object";

                continue;
            }

            $built = self::model($where, $name, $baseUrl, $headers, $config['api'] ?? null, $entry);

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
     * @param array<mixed>          $entry
     */
    private static function model(
        string $where,
        string $provider,
        string $baseUrl,
        array $headers,
        mixed $providerApi,
        array $entry,
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

        // Refused rather than read as free, which is the rule every other typed field here
        // follows and the one a price needs most: `/session`, the footer and `--list-models` all
        // report money, and a model that silently costs nothing is a model that misreports it
        // every turn. `"input": "0.28"` with the quotes left on is the mistake to expect, and it
        // is a character to fix once somebody is told. Absent is still free — that is what a local
        // endpoint is, and the whole block stays optional.
        $cost = $entry['cost'] ?? null;

        if ($cost !== null && !is_array($cost)) {
            return "{$where}, model \"{$id}\": \"cost\" must be an object, or left out";
        }

        foreach (['input', 'output', 'cacheRead', 'cacheWrite'] as $field) {
            $value = ($cost ?? [])[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                return "{$where}, model \"{$id}\": \"cost.{$field}\" must be dollars per million"
                    . ' tokens as a number, or left out';
            }
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
            self::compat(is_array($entry['compat'] ?? null) ? $entry['compat'] : null),
            self::thinkingLevelMap(is_array($entry['thinkingLevelMap'] ?? null) ? $entry['thinkingLevelMap'] : null),
        );
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

        return new Pricing($number('input'), $number('output'), $number('cacheRead'), $number('cacheWrite'));
    }

    /**
     * The ways an OpenAI-compatible endpoint is not, as a file can say them.
     *
     * **Every key is upstream's spelling**, so a `models.json` written for pi works here
     * unchanged. This used to read four of them under pig's own shorter names — the four upstream
     * prefixes with `requires` — on the stated grounds that upstream only had four. It had more,
     * in `types.ts`, and the note claiming otherwise is what stopped anybody checking:
     * `requiresMistralToolIds: true` in a pi file did nothing here, and that flag is the one that
     * cuts a tool id to the nine characters such an endpoint will accept.
     *
     * The short spellings are gone rather than kept beside them, for the reason the session
     * format's old shape is: nobody has a pig `models.json` from a release, and a file that holds
     * both names for one flag is a file that can disagree with itself.
     *
     * Absent means null, and null means `OpenAiCompat::detect()` works it out from the URL.
     * That is a better default than any of these flags: a local llama.cpp gets the loose
     * settings it needs without anybody writing a `compat` block at all.
     *
     * @param array<mixed>|null $compat
     */
    private static function compat(?array $compat): ?OpenAiCompat
    {
        if ($compat === null || $compat === []) {
            return null;
        }

        $flag = static fn (string $key, bool $fallback): bool => is_bool($compat[$key] ?? null)
            ? $compat[$key]
            : $fallback;

        $maxTokensField = $compat['maxTokensField'] ?? null;

        return new OpenAiCompat(
            store: $flag('supportsStore', true),
            developerRole: $flag('supportsDeveloperRole', true),
            reasoningEffort: $flag('supportsReasoningEffort', true),
            maxTokensField: $maxTokensField === 'max_tokens' ? 'max_tokens' : 'max_completion_tokens',
            toolResultName: $flag('requiresToolResultName', false),
            assistantAfterToolResult: $flag('requiresAssistantAfterToolResult', false),
            thinkingAsText: $flag('requiresThinkingAsText', false),
            mistralToolIds: $flag('requiresMistralToolIds', false),
            reasoningContentOnAssistantMessages: $flag('requiresReasoningContentOnAssistantMessages', false),
        );
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
