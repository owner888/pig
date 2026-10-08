<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What an `anthropic-messages` model says about its endpoint — upstream's `AnthropicMessagesCompat`,
 * the keys of it pig reads.
 *
 * Every key is **metadata, not detection**: upstream's runtime reads `model.compat?.x` with its
 * default and never looks at the model id, and its model generator writes the flags onto the
 * built-in models that have them. `Models` does that here, with the id rules below copied from the
 * generator; a `models.json` model says them in its `compat` block under upstream's names. Null
 * means not said, which is the runtime default (written beside each key).
 *
 * @see OpenAiCompat the same idea for `openai-completions`, which also detects
 */
final readonly class AnthropicCompat
{
    /**
     * Upstream's generator `EAGER_TOOL_INPUT_STREAMING_UNSUPPORTED_ANTHROPIC_MODELS`, keyed
     * `provider:id`: the Copilot Claudes that refuse per-tool `eager_input_streaming`.
     */
    private const array EAGER_TOOL_INPUT_STREAMING_UNSUPPORTED = [
        'github-copilot:claude-haiku-4.5',
        'github-copilot:claude-sonnet-4',
        'github-copilot:claude-sonnet-4.5',
    ];

    /** Upstream's generator `VERIFIED_ANTHROPIC_MID_CONVO_EFFORT_PROVIDERS`. */
    private const array MID_CONVO_EFFORT_PROVIDERS = ['anthropic', 'openrouter'];

    /** Upstream's generator `MID_CONVO_EFFORT_UNSUPPORTED_ANTHROPIC_MODELS`, keyed `provider:id`. */
    private const array MID_CONVO_EFFORT_UNSUPPORTED = ['openrouter:anthropic/claude-opus-5'];

    /**
     * @param bool|null $forceAdaptiveThinking `thinking: {type: "adaptive"}` plus `output_config.effort`
     *        instead of a token budget — upstream's `forceAdaptiveThinking` (default false), which its
     *        generator sets from the model id (`isAnthropicAdaptiveThinkingModel()`) on every built-in
     *        model of this API
     * @param bool|null $strictTools a tool asking for strict sampling is sent strict — upstream's
     *        `supportsStrictTools` (default false), which its generator sets on every `anthropic`
     *        provider model
     * @param bool|null $supportsTemperature the request may carry `temperature` — upstream's
     *        `supportsTemperature` (default true). Claude Opus 4.7+ rejects a non-default one, and
     *        the generator writes false by `isTemperatureUnsupportedModel()`
     * @param bool|null $supportsEagerToolInputStreaming each tool is sent `eager_input_streaming:
     *        true` — upstream's `supportsEagerToolInputStreaming` (default true). False sends the
     *        older `fine-grained-tool-streaming-2025-05-14` beta instead, on a request with tools
     * @param bool|null $supportsMidConvoEffort the managed-effort models — upstream's
     *        `supportsMidConvoEffort` (default false): always adaptive thinking with
     *        `block_binding`, the effort said by effort-only system messages around the turns, and
     *        no `temperature`. See `Anthropic::body()`
     * @param bool|null $supportsLongCacheRetention `cacheRetention: long` sends `cache_control.ttl:
     *        "1h"` — upstream's `supportsLongCacheRetention` (default true)
     * @param bool|null $sendSessionAffinityHeaders the session id goes out as a header, for a
     *        provider that routes the cache by it — upstream's `sendSessionAffinityHeaders` (default:
     *        true for OpenRouter, by provider or URL, false otherwise)
     * @param string|null $sessionAffinityFormat `openrouter` names that header `x-session-id`;
     *        unset it is `x-session-affinity` — upstream's `sessionAffinityFormat` (default
     *        `openrouter` for OpenRouter)
     * @param bool|null $supportsCacheControlOnTools the last tool carries the `cache_control`
     *        breakpoint — upstream's `supportsCacheControlOnTools` (default true)
     * @param bool|null $allowEmptySignature thinking with no signature is replayed as `thinking` with
     *        `signature: ""` rather than turned into text — upstream's `allowEmptySignature`
     *        (default false), for the compatible providers that emit and accept that
     * @param list<array{provider: string, model: string, cost: Pricing}>|null $allowedFallbackModels
     *        upstream's `allowedFallbackModels`: the models Anthropic may answer with instead
     *        (server-side refusal fallback), sent as `fallbacks`, with the price a turn they answered
     *        is billed at. Absent or empty sends no `fallbacks`, which Anthropic requires then
     */
    public function __construct(
        public ?bool $forceAdaptiveThinking = null,
        public ?bool $strictTools = null,
        public ?bool $supportsTemperature = null,
        public ?bool $supportsEagerToolInputStreaming = null,
        public ?bool $supportsMidConvoEffort = null,
        public ?bool $supportsLongCacheRetention = null,
        public ?bool $sendSessionAffinityHeaders = null,
        public ?string $sessionAffinityFormat = null,
        public ?bool $supportsCacheControlOnTools = null,
        public ?bool $allowEmptySignature = null,
        public ?array $allowedFallbackModels = null,
    ) {
    }

    /** The same compat with `allowedFallbackModels` set — upstream's `mergeAnthropicMessagesCompat()` for that key. */
    public function withAllowedFallbackModels(array $allowedFallbackModels): self
    {
        return new self(
            $this->forceAdaptiveThinking,
            $this->strictTools,
            $this->supportsTemperature,
            $this->supportsEagerToolInputStreaming,
            $this->supportsMidConvoEffort,
            $this->supportsLongCacheRetention,
            $this->sendSessionAffinityHeaders,
            $this->sessionAffinityFormat,
            $this->supportsCacheControlOnTools,
            $this->allowEmptySignature,
            $allowedFallbackModels,
        );
    }

    /**
     * Upstream's generator `isAnthropicAdaptiveThinkingModel()`, copied: the ids whose model wants
     * adaptive thinking. Used when a built-in model is made, never at request time.
     */
    public static function isAdaptiveThinkingModel(string $modelId): bool
    {
        foreach ([
            'opus-4-6', 'opus-4.6', 'opus-4-7', 'opus-4.7', 'opus-4-8', 'opus-4.8', 'opus-5', 'opus.5',
            'sonnet-4-6', 'sonnet-4.6', 'sonnet-5', 'sonnet.5', 'haiku-5', 'haiku.5', 'fable-5', 'mythos-5',
        ] as $needle) {
            if (str_contains($modelId, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upstream's generator `isAnthropicTemperatureUnsupportedModel()`, copied: lower-cased, then
     * these substrings. Used when a built-in model is made, never at request time.
     */
    public static function isTemperatureUnsupportedModel(string $modelId): bool
    {
        $id = strtolower($modelId);

        foreach ([
            'opus-4-7', 'opus-4.7', 'opus-4-8', 'opus-4.8', 'opus-5', 'opus.5',
            'sonnet-5-5', 'sonnet-5.5', 'haiku-5-5', 'haiku-5.5',
        ] as $needle) {
            if (str_contains($id, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upstream's generator `supportsAnthropicMidConvoEffort()`, copied: the id lower-cased and
     * stripped of an `anthropic/` (or `~anthropic/`) prefix, then three patterns.
     */
    public static function supportsMidConvoEffortModel(string $modelId): bool
    {
        $id = (string) preg_replace('#^~?anthropic/#', '', strtolower($modelId));

        return preg_match('/^claude-opus-(?:5|5[.-]5)(?:-\d{8})?$/', $id) === 1
            || preg_match('/^claude-(?:sonnet|haiku)-5[.-]5(?:-\d{8})?$/', $id) === 1
            || preg_match('/^claude-(?:fable|mythos)-5(?:[.-]1)(?:-\d{8})?$/', $id) === 1;
    }

    /**
     * Upstream's generator `getAnthropicMessagesCompat()`, the keys of it pig has, plus the two
     * `applyThinkingLevelMetadata()` writes (`forceAdaptiveThinking`, `supportsTemperature: false`)
     * and `applyStrictToolCompatMetadata()`'s `supportsStrictTools` — everything upstream's generator
     * writes into a built-in `anthropic-messages` model's `compat`. Null when it writes nothing, as
     * upstream leaves `compat` off such a model.
     *
     * Not ported: `supportsMidConvoSystemMessages`/`supportsMidConvoToolChanges` (pig's transcript has
     * no system messages after the first). `allowEmptySignature` is written by upstream only for
     * xiaomi and opencode, which pig has no built-in models of, and `allowedFallbackModels` needs the
     * other rows' prices, so `Models::table()` adds it after every Anthropic row exists.
     */
    public static function forBuiltIn(string $provider, string $modelId): ?self
    {
        $key = "{$provider}:{$modelId}";
        $midConvoEffort = in_array($provider, self::MID_CONVO_EFFORT_PROVIDERS, true)
            && self::supportsMidConvoEffortModel($modelId)
            && !in_array($key, self::MID_CONVO_EFFORT_UNSUPPORTED, true);

        $compat = new self(
            forceAdaptiveThinking: self::isAdaptiveThinkingModel($modelId) ? true : null,
            strictTools: $provider === 'anthropic' ? true : null,
            supportsTemperature: self::isTemperatureUnsupportedModel($modelId) ? false : null,
            supportsEagerToolInputStreaming: in_array($key, self::EAGER_TOOL_INPUT_STREAMING_UNSUPPORTED, true) ? false : null,
            supportsMidConvoEffort: $midConvoEffort ? true : null,
        );

        // `!==` per key and not `==` on the objects: loose comparison counts a `false` as equal to
        // the null of "not said", which would drop exactly the flags that switch something off.
        return array_filter(get_object_vars($compat), static fn (mixed $flag): bool => $flag !== null) === [] ? null : $compat;
    }
}
