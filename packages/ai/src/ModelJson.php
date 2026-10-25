<?php

declare(strict_types=1);

namespace Pig\Ai;

use InvalidArgumentException;

/**
 * A model as pi writes it: one JSON object per model, every field spelled out — the entries of
 * pi's `providers/data/<provider>.json` and of pi.dev's `/api/models/providers/<id>`, `models.json`'s
 * model definitions, and pig's own catalogue (`packages/ai/data/models/<provider>.json`).
 *
 * `decode()` reads one (`type` says chat, classifier or image; chat when absent) and `encode()`
 * writes one, so the catalogue the generator writes is the catalogue every reader reads.
 * `compat()` is upstream's per-API compat schema — `OpenAICompletionsCompat` and
 * `OpenAIResponsesCompat` (one `OpenAiCompat` here), `AnthropicMessagesCompat`, `BedrockCompat` —
 * under its own key names.
 */
final class ModelJson
{
    /** pi's `OpenAICompletionsCompat` / `OpenAIResponsesCompat` keys, by `OpenAiCompat` property. */
    private const array OPENAI_KEYS = [
        'store' => 'supportsStore',
        'developerRole' => 'supportsDeveloperRole',
        'reasoningEffort' => 'supportsReasoningEffort',
        'maxTokensField' => 'maxTokensField',
        'toolResultName' => 'requiresToolResultName',
        'assistantAfterToolResult' => 'requiresAssistantAfterToolResult',
        'thinkingAsText' => 'requiresThinkingAsText',
        'reasoningContentOnAssistantMessages' => 'requiresReasoningContentOnAssistantMessages',
        'strictMode' => 'supportsStrictMode',
        'thinkingFormat' => 'thinkingFormat',
        'chatTemplateKwargs' => 'chatTemplateKwargs',
        'chatTemplateArgs' => 'chatTemplateArgs',
        'openRouterRouting' => 'openRouterRouting',
        'vercelGatewayRouting' => 'vercelGatewayRouting',
        'grammarTools' => 'supportsOpenAIGrammarTools',
        'sessionAffinityFormat' => 'sessionAffinityFormat',
        'supportsLongCacheRetention' => 'supportsLongCacheRetention',
        'supportsExplicitPromptCacheMode' => 'supportsExplicitPromptCacheMode',
        'supportsMaxOutputTokens' => 'supportsMaxOutputTokens',
        'sendSessionAffinityHeaders' => 'sendSessionAffinityHeaders',
        'cacheControlFormat' => 'cacheControlFormat',
        'supportsUsageInStreaming' => 'supportsUsageInStreaming',
        'supportsFinishReason' => 'supportsFinishReason',
        'zaiToolStream' => 'zaiToolStream',
        'thinkingTokenBudgetField' => 'thinkingTokenBudgetField',
        'supportsThinkingTokenBudget' => 'supportsThinkingTokenBudget',
        'vllmPriority' => 'vllmPriority',
        'supportsMidConvoSystemMessages' => 'supportsMidConvoSystemMessages',
        'supportsMidConvoToolAdditions' => 'supportsMidConvoToolAdditions',
        'supportsToolSearch' => 'supportsToolSearch',
        'supportsAdditionalTools' => 'supportsAdditionalTools',
        'supportsMidConvoEffort' => 'supportsMidConvoEffort',
    ];

    /** pi's `AnthropicMessagesCompat` keys, by `AnthropicCompat` property. */
    private const array ANTHROPIC_KEYS = [
        'forceAdaptiveThinking' => 'forceAdaptiveThinking',
        'strictTools' => 'supportsStrictTools',
        'supportsTemperature' => 'supportsTemperature',
        'supportsEagerToolInputStreaming' => 'supportsEagerToolInputStreaming',
        'supportsMidConvoEffort' => 'supportsMidConvoEffort',
        'supportsLongCacheRetention' => 'supportsLongCacheRetention',
        'sendSessionAffinityHeaders' => 'sendSessionAffinityHeaders',
        'sessionAffinityFormat' => 'sessionAffinityFormat',
        'supportsCacheControlOnTools' => 'supportsCacheControlOnTools',
        'allowEmptySignature' => 'allowEmptySignature',
        'allowedFallbackModels' => 'allowedFallbackModels',
        'supportsMidConvoSystemMessages' => 'supportsMidConvoSystemMessages',
        'supportsMidConvoToolChanges' => 'supportsMidConvoToolChanges',
    ];

    /** The object-valued compat keys, written as JSON objects even when empty. */
    private const array OBJECT_KEYS = ['chatTemplateKwargs', 'chatTemplateArgs', 'openRouterRouting', 'vercelGatewayRouting'];

    private function __construct()
    {
    }

    /**
     * One model from pi's JSON, as its `type` says (chat when absent). `$provider` fills in a
     * missing `provider`, as pi's catalogue parser stamps the provider it asked for.
     *
     * @param array<string, mixed> $entry
     * @throws InvalidArgumentException when a field the model cannot do without is missing
     */
    public static function decode(array $entry, ?string $provider = null): Model|ClassifierModel|ImageModel
    {
        $id = self::requiredString($entry, 'id');
        $name = is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : $id;
        $provider = is_string($entry['provider'] ?? null) && $entry['provider'] !== '' ? $entry['provider'] : ($provider ?? self::requiredString($entry, 'provider'));
        // Empty for a provider whose address comes from the user's own setup (Azure's resource).
        $baseUrl = is_string($entry['baseUrl'] ?? null) ? $entry['baseUrl'] : throw new InvalidArgumentException("{$provider}/{$id}: a model with no \"baseUrl\"");
        $api = self::requiredString($entry, 'api');
        $input = self::strings($entry['input'] ?? null) ?: ['text'];
        $pricing = self::sharedPricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : []);
        $headers = self::headers($entry['headers'] ?? null);
        $inputLimits = is_array($entry['inputLimits'] ?? null) ? $entry['inputLimits'] : null;

        return match ($entry['type'] ?? 'chat') {
            'classifier' => new ClassifierModel(
                $id,
                $name,
                ClassifierApi::tryFrom($api) ?? throw new InvalidArgumentException("{$provider}/{$id}: unknown classifier api \"{$api}\""),
                $provider,
                $baseUrl,
                self::positiveInt($entry, 'contextWindow'),
                $input,
                $pricing,
                $headers,
                $inputLimits,
                SystemOneCompat::fromArray($entry['compat'] ?? null),
            ),
            'image' => new ImageModel(
                $id,
                $name,
                ImageApi::tryFrom($api) ?? throw new InvalidArgumentException("{$provider}/{$id}: unknown image api \"{$api}\""),
                $provider,
                $baseUrl,
                $input,
                self::strings($entry['output'] ?? null) ?: ['image'],
                $pricing,
                $headers,
                $inputLimits,
            ),
            'chat' => (static function () use ($entry, $id, $name, $provider, $baseUrl, $api, $input, $pricing, $headers, $inputLimits): Model {
                $chatApi = Api::tryFrom($api) ?? throw new InvalidArgumentException("{$provider}/{$id}: unknown api \"{$api}\"");

                return new Model(
                    $id,
                    $name,
                    $chatApi,
                    $provider,
                    $baseUrl,
                    self::positiveInt($entry, 'contextWindow'),
                    self::positiveInt($entry, 'maxTokens'),
                    ($entry['reasoning'] ?? false) === true,
                    $input,
                    $pricing,
                    $headers,
                    is_array($entry['compat'] ?? null) ? self::sharedCompat($entry['compat'], $chatApi) : null,
                    self::thinkingLevelMap(is_array($entry['thinkingLevelMap'] ?? null) ? $entry['thinkingLevelMap'] : null),
                    $inputLimits,
                    is_array($entry['promptCache'] ?? null) ? $entry['promptCache'] : null,
                );
            })(),
            default => throw new InvalidArgumentException("{$provider}/{$id}: unknown model type \"" . (is_scalar($entry['type']) ? (string) $entry['type'] : get_debug_type($entry['type'])) . '"'),
        };
    }

    /**
     * A model as pi writes it, `type` first; keys a model leaves unsaid are left out.
     *
     * @return array<string, mixed>
     */
    public static function encode(Model|ClassifierModel|ImageModel $model): array
    {
        $type = match (true) {
            $model instanceof ClassifierModel => 'classifier',
            $model instanceof ImageModel => 'image',
            default => 'chat',
        };

        $entry = [
            'type' => $type,
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
        ];

        if ($model instanceof Model) {
            $entry['reasoning'] = $model->reasoning;

            if ($model->thinkingLevelMap !== []) {
                $entry['thinkingLevelMap'] = $model->thinkingLevelMap;
            }
        }

        $entry['input'] = $model->input;

        if ($model instanceof ImageModel) {
            $entry['output'] = $model->output;
        }

        $entry['cost'] = self::encodePricing($model->pricing);

        if (!$model instanceof ImageModel) {
            $entry['contextWindow'] = $model->contextWindow;
        }

        if ($model instanceof ClassifierModel && $model->compat !== null && $model->compat->toArray() !== []) {
            $entry['compat'] = $model->compat->toArray();
        }

        if ($model instanceof Model) {
            $entry['maxTokens'] = $model->maxTokens;
            $compat = $model->compat === null ? [] : self::encodeCompat($model->compat);

            if ($compat !== []) {
                $entry['compat'] = $compat;
            }
        }

        if ($model->headers !== []) {
            $entry['headers'] = $model->headers;
        }

        if ($model->inputLimits !== null) {
            $entry['inputLimits'] = $model->inputLimits;
        }

        if ($model instanceof Model && $model->promptCache !== null) {
            $entry['promptCache'] = $model->promptCache;
        }

        return $entry;
    }

    /**
     * Upstream's compat schema for `$api`, from its key names. Null for nothing said.
     *
     * @param array<string, mixed> $compat
     */
    public static function compat(array $compat, Api $api): OpenAiCompat|AnthropicCompat|BedrockCompat|null
    {
        if ($compat === []) {
            return null;
        }

        $flag = static fn (string $key): ?bool => is_bool($compat[$key] ?? null) ? $compat[$key] : null;

        // Upstream's `BedrockCompatSchema`: one key.
        if ($api === Api::BedrockConverseStream) {
            return new BedrockCompat(supportsStrictMode: $flag('supportsStrictMode'));
        }

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
                supportsMidConvoSystemMessages: $flag('supportsMidConvoSystemMessages'),
                supportsMidConvoToolChanges: $flag('supportsMidConvoToolChanges'),
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
            chatTemplateKwargs: self::object($compat['chatTemplateKwargs'] ?? null),
            chatTemplateArgs: self::object($compat['chatTemplateArgs'] ?? null),
            openRouterRouting: self::object($compat['openRouterRouting'] ?? null),
            vercelGatewayRouting: self::object($compat['vercelGatewayRouting'] ?? null),
            grammarTools: $flag('supportsOpenAIGrammarTools'),
            sessionAffinityFormat: is_string($compat['sessionAffinityFormat'] ?? null) ? $compat['sessionAffinityFormat'] : null,
            supportsLongCacheRetention: $flag('supportsLongCacheRetention'),
            supportsExplicitPromptCacheMode: $flag('supportsExplicitPromptCacheMode'),
            supportsMaxOutputTokens: $flag('supportsMaxOutputTokens'),
            sendSessionAffinityHeaders: $flag('sendSessionAffinityHeaders'),
            cacheControlFormat: is_string($compat['cacheControlFormat'] ?? null) ? $compat['cacheControlFormat'] : null,
            supportsUsageInStreaming: $flag('supportsUsageInStreaming'),
            supportsFinishReason: $flag('supportsFinishReason'),
            zaiToolStream: $flag('zaiToolStream'),
            thinkingTokenBudgetField: is_string($compat['thinkingTokenBudgetField'] ?? null) ? $compat['thinkingTokenBudgetField'] : null,
            supportsThinkingTokenBudget: $flag('supportsThinkingTokenBudget'),
            vllmPriority: is_int($compat['vllmPriority'] ?? null) || is_float($compat['vllmPriority'] ?? null) ? $compat['vllmPriority'] : null,
            supportsMidConvoSystemMessages: $flag('supportsMidConvoSystemMessages'),
            supportsMidConvoToolAdditions: $flag('supportsMidConvoToolAdditions'),
            supportsToolSearch: $flag('supportsToolSearch'),
            supportsAdditionalTools: $flag('supportsAdditionalTools'),
            supportsMidConvoEffort: $flag('supportsMidConvoEffort'),
        );
    }

    /** @var array<string, Pricing> `sharedPricing()`'s, by the rates they were built from */
    private static array $pricings = [];

    /** @var array<string, OpenAiCompat|AnthropicCompat|BedrockCompat|null> `sharedCompat()`'s, by api and settings */
    private static array $compats = [];

    /**
     * `pricing()`, one object per distinct set of rates. The shipped catalogue decodes 1,700 rows at
     * every start and many share their rates; a `Pricing` is immutable, so they can share it too.
     *
     * @param array<string, mixed> $cost
     */
    private static function sharedPricing(array $cost): Pricing
    {
        return self::$pricings[serialize($cost)] ??= self::pricing($cost);
    }

    /**
     * `compat()`, one object per distinct api and settings: 1,400 rows of the shipped catalogue carry
     * a `compat` and there are fewer than a hundred different ones. Each compat class is immutable.
     *
     * @param array<string, mixed> $compat
     */
    private static function sharedCompat(array $compat, Api $api): OpenAiCompat|AnthropicCompat|BedrockCompat|null
    {
        $key = $api->value . "\0" . serialize($compat);

        if (!array_key_exists($key, self::$compats)) {
            self::$compats[$key] = self::compat($compat, $api);
        }

        return self::$compats[$key];
    }

    /**
     * Upstream's `ModelCost`: dollars per million tokens, and its request-wide tiers. Absent is free.
     *
     * @param array<mixed> $cost
     */
    public static function pricing(array $cost): Pricing
    {
        $number = static fn (array $from, string $key): float => is_int($from[$key] ?? null) || is_float($from[$key] ?? null) ? (float) $from[$key] : 0.0;
        $tiers = [];

        foreach (is_array($cost['tiers'] ?? null) ? $cost['tiers'] : [] as $tier) {
            if (!is_array($tier) || (!is_int($tier['inputTokensAbove'] ?? null) && !is_float($tier['inputTokensAbove'] ?? null))) {
                continue;
            }

            // `PricingTier` counts tokens in whole numbers; a fractional threshold is compared as
            // the whole number below it, which no token count can tell apart.
            $tiers[] = new PricingTier((int) $tier['inputTokensAbove'], $number($tier, 'input'), $number($tier, 'output'), $number($tier, 'cacheRead'), $number($tier, 'cacheWrite'));
        }

        return new Pricing($number($cost, 'input'), $number($cost, 'output'), $number($cost, 'cacheRead'), $number($cost, 'cacheWrite'), $tiers);
    }

    /**
     * Upstream's `thinkingLevelMap`: a level's string, or null for a level the model does not have.
     *
     * @param array<mixed>|null $map
     * @return array<string, string|null>
     */
    public static function thinkingLevelMap(?array $map): array
    {
        $kept = [];

        // In the map's own order, which is the order the catalogue wrote.
        foreach ($map ?? [] as $level => $value) {
            if (in_array($level, ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true) && ($value === null || is_string($value))) {
                $kept[$level] = $value;
            }
        }

        return $kept;
    }

    /**
     * The JSON text of a list of models, as the catalogue files are written: one array, pretty-printed,
     * slashes and Unicode as they are, and a price of 3 written `3.0`.
     *
     * @param list<Model|ClassifierModel|ImageModel> $models
     */
    public static function file(array $models): string
    {
        return (string) json_encode(
            array_map(self::encode(...), $models),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string, mixed> */
    private static function encodeCompat(OpenAiCompat|AnthropicCompat|BedrockCompat $compat): array
    {
        if ($compat instanceof BedrockCompat) {
            return $compat->supportsStrictMode === null ? [] : ['supportsStrictMode' => $compat->supportsStrictMode];
        }

        $keys = $compat instanceof AnthropicCompat ? self::ANTHROPIC_KEYS : self::OPENAI_KEYS;
        $encoded = [];

        foreach ($keys as $property => $key) {
            $value = $compat->{$property};

            if ($value === null) {
                continue;
            }

            if ($key === 'allowedFallbackModels') {
                $value = array_map(static fn (array $one): array => [
                    'provider' => $one['provider'],
                    'model' => $one['model'],
                    'cost' => self::encodePricing($one['cost']),
                ], $value);
            } elseif (in_array($key, self::OBJECT_KEYS, true) && $value === []) {
                $value = new \stdClass();
            }

            $encoded[$key] = $value;
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    private static function encodePricing(Pricing $pricing): array
    {
        $cost = [
            'input' => $pricing->input,
            'output' => $pricing->output,
            'cacheRead' => $pricing->cacheRead,
            'cacheWrite' => $pricing->cacheWrite,
        ];

        if ($pricing->tiers !== []) {
            $cost['tiers'] = array_map(static fn (PricingTier $tier): array => [
                'inputTokensAbove' => $tier->inputTokensAbove,
                'input' => $tier->input,
                'output' => $tier->output,
                'cacheRead' => $tier->cacheRead,
                'cacheWrite' => $tier->cacheWrite,
            ], $pricing->tiers);
        }

        return $cost;
    }

    /** @return list<array{provider: string, model: string, cost: Pricing}>|null */
    private static function allowedFallbackModels(mixed $entries): ?array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            return null;
        }

        $allowed = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['provider'] ?? null) && is_string($entry['model'] ?? null)) {
                $allowed[] = ['provider' => $entry['provider'], 'model' => $entry['model'], 'cost' => self::pricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : [])];
            }
        }

        return $allowed;
    }

    /**
     * A JSON object, or not said. A list is not one.
     *
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
    }

    /** @param array<string, mixed> $entry */
    private static function requiredString(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("a model with no \"{$key}\"" . (is_string($entry['id'] ?? null) ? " ({$entry['id']})" : ''));
        }

        return $value;
    }

    /** @param array<string, mixed> $entry */
    private static function positiveInt(array $entry, string $key): int
    {
        $value = $entry[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : (is_float($value) && $value > 0 ? (int) $value : 4096);
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /** @return array<string, string> */
    private static function headers(mixed $value): array
    {
        $headers = [];

        foreach (is_array($value) ? $value : [] as $name => $header) {
            if (is_string($name) && is_string($header)) {
                $headers[$name] = $header;
            }
        }

        return $headers;
    }
}
