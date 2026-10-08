<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
use Pig\Ai\Utils\AssistantMessageEventStream;

/**
 * Picks the provider and translates the options it wants.
 *
 * Two doors, as upstream has: `simple()` takes options that mean the same thing
 * everywhere — "think hard" — and turns them into whatever the chosen provider calls
 * that; `start()` takes options already in the provider's own dialect. The agent loop
 * only ever uses the first.
 *
 * Upstream names these `streamSimple()` and `stream()`; `Stream::stream()` would stutter,
 * so the low-level one is `start()`.
 */
final class Stream
{
    /** Upstream's `CONTEXT_SAFETY_TOKENS`: what `clampMaxTokensToContext()` keeps clear of the window. */
    private const int CONTEXT_SAFETY_TOKENS = 4096;

    /** Upstream's `MIN_MAX_TOKENS`. */
    private const int MIN_MAX_TOKENS = 1;

    /**
     * Upstream's `MIN_ANSWER_TOKENS`: "Tokens always left for the answer when a thinking budget
     * shares the response ceiling."
     */
    private const int MIN_ANSWER_TOKENS = 1024;

    /** Upstream's `DEFAULT_THINKING_BUDGETS`, for the token-budget thinking models. */
    private const array DEFAULT_THINKING_BUDGETS = [
        'minimal' => 1024,
        'low' => 2048,
        'medium' => 8192,
        'high' => 16384,
    ];

    /**
     * Provider-independent options, mapped and sent.
     *
     * Upstream: streamSimple().
     */
    public static function simple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        return self::start($model, $context, self::translate($model, $context, $options));
    }

    /**
     * Options already in the provider's dialect.
     *
     * Throws rather than returning a failed stream when no key can be found: that is a
     * misconfiguration, not a request that went wrong, and upstream throws here too.
     *
     * Upstream: stream().
     */
    public static function start(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $apiKey = $options?->apiKey ?? self::envApiKey($model->provider);

        if ($apiKey === null || $apiKey === '') {
            throw new ProviderError("No API key for provider: {$model->provider}");
        }

        return match ($model->api) {
            Api::AnthropicMessages => (new Anthropic())->stream($model, $context, self::anthropic($options, $apiKey)),
            Api::OpenAiCompletions => (new OpenAiCompletions())->stream($model, $context, self::openAi($options, $apiKey)),
            Api::OpenAiResponses => (new OpenAiResponses())->stream($model, $context, self::openAi($options, $apiKey)),
            Api::GoogleGenerativeAi => (new Google())->stream($model, $context, self::google($options, $apiKey)),
            // Still no `default`: this arm names the one case that is not a built-in, and the
            // registry is what answers for it. The options are already in the extension's own
            // dialect by the time they reach here — `translate()` asked it — or are what the
            // caller built; either way they carry the key.
            Api::Extension => self::extensionApi($model)->stream($model, $context, self::withKey($options, $apiKey)),
        };
    }

    /**
     * The protocol an extension registered for this model's provider, or a refusal that names it.
     *
     * A model saying `Api::Extension` with no provider behind it is one that was in a session
     * file or a settings file when the extension was installed and is not any more — and "no
     * API key" or a null three layers down is the wrong sentence for that.
     */
    private static function extensionApi(Model $model): Extension\StreamApi
    {
        return Extension\ProviderRegistry::apiFor($model)
            ?? throw new ProviderError("No extension provides the protocol for {$model->provider}/{$model->id}. Is its extension loaded?");
    }

    /**
     * The options as they came, with the key on them.
     *
     * `translate()` hands the extension the key and the extension puts it on what it builds, so
     * the common path arrives here with one already. A caller of `start()` with options that
     * carry none — the shape the four built-in `anthropic()`/`openAi()`/`google()` helpers also
     * handle — gets the base options with the key, which is the most this can build without
     * knowing the extension's subclass.
     */
    private static function withKey(?StreamOptions $options, string $apiKey): StreamOptions
    {
        if ($options !== null && $options->apiKey !== null) {
            return $options;
        }

        return new StreamOptions(
            $options?->temperature,
            $options?->maxTokens,
            $options?->signal,
            $apiKey,
            $options?->cacheRetention,
            $options?->sessionId,
            $options?->metadata,
        );
    }

    /**
     * The key for a provider, from the environment.
     *
     * Upstream: getEnvApiKey(). An OAuth token wins over an API key where both exist.
     */
    public static function envApiKey(string $provider): ?string
    {
        $names = match ($provider) {
            'anthropic' => ['ANTHROPIC_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'],
            'github-copilot' => ['COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN'],
            'openai' => ['OPENAI_API_KEY'],
            'google' => ['GEMINI_API_KEY'],
            'groq' => ['GROQ_API_KEY'],
            'cerebras' => ['CEREBRAS_API_KEY'],
            'xai' => ['XAI_API_KEY'],
            'openrouter' => ['OPENROUTER_API_KEY'],
            'zai' => ['ZAI_API_KEY'],
            'mistral' => ['MISTRAL_API_KEY'],
            default => Extension\ProviderRegistry::envKeysFor($provider),
        };

        foreach ($names as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Upstream: mapOptionsForApi().
     *
     * **One arm per API and no `default`**, which is how `start()` below has always been written
     * and how this should have been: the `default` that used to sit here handed back plain
     * `StreamOptions`, so an API with no arm got no thinking configuration and said nothing about
     * it — which is exactly what happened to the Gemini CLI provider for twelve models. Upstream's own
     * default is an exhaustiveness check that throws. Without a `default`, a new `Api` case fails
     * here loudly instead of quietly asking the provider for its defaults.
     */
    private static function translate(Model $model, Context $context, ?SimpleStreamOptions $options): StreamOptions
    {
        // Upstream's `buildBaseOptions()`, which every one of its `streamSimple()`s starts from:
        // `maxTokens: clampMaxTokensToContext(model, context, options?.maxTokens ?? model.maxTokens)`.
        // The model's own ceiling when nobody said, cut to the room the conversation leaves. This
        // used to be `min(model.maxTokens, 32_000)` here and `intdiv(model.maxTokens, 3)` in
        // `Anthropic`, neither of which upstream has any more.
        $maxTokens = self::clampMaxTokensToContext($model, $context, $options?->maxTokens ?? $model->maxTokens);
        $apiKey = $options?->apiKey ?? self::envApiKey($model->provider);
        return match ($model->api) {
            // The same question as for the responses arm below, and upstream asks it the same way
            // in both: xhigh is a property of the *model*, not of the protocol it speaks. This used
            // to clamp unconditionally — "xhigh is OpenAI's alone" — which is true of the models in
            // the registry today and not of a `models.json` proxy that resells `gpt-5.2` over
            // chat-completions, which is a common shape.
            Api::OpenAiCompletions => new OpenAiOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                reasoning: $model->supportsXhigh() ? $options?->reasoning : $options?->reasoning?->clampToHigh(),
                cacheRetention: $options?->cacheRetention,
                sessionId: $options?->sessionId,
                metadata: $options?->metadata,
            ),
            // Upstream's `streamSimple()` in `openai-responses.ts`: the level clamped to the ones
            // the model has (`clampThinkingLevel()`, which reads the `thinkingLevelMap`), `off`
            // meaning none, and the caller's `toolChoice` passed on.
            Api::OpenAiResponses => new OpenAiOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                reasoning: self::clampedReasoning($model, $options?->reasoning),
                toolChoice: $options?->toolChoice,
                cacheRetention: $options?->cacheRetention,
                sessionId: $options?->sessionId,
                metadata: $options?->metadata,
            ),
            Api::GoogleGenerativeAi => self::gemini($model, $options, $maxTokens, $apiKey),
            Api::AnthropicMessages => self::anthropicSimple($model, $context, $options, $maxTokens, $apiKey),
            Api::Extension => self::extensionApi($model)->translate($model, $options, $apiKey
                ?? throw new ProviderError("No API key for provider: {$model->provider}")),
        };
    }

    /**
     * Upstream's `clampMaxTokensToContext()`: the request's ceiling, cut so the conversation plus
     * the answer plus 4,096 tokens of slack fit in the window — never below 1. A model with no
     * window (0) is not cut.
     */
    private static function clampMaxTokensToContext(Model $model, Context $context, int $maxTokens): int
    {
        if ($model->contextWindow <= 0) {
            return max(self::MIN_MAX_TOKENS, $maxTokens);
        }

        $available = $model->contextWindow - Utils\Estimate::contextTokens($context) - self::CONTEXT_SAFETY_TOKENS;

        return min($maxTokens, max(self::MIN_MAX_TOKENS, $available));
    }

    /**
     * Upstream's `streamSimple()` in `anthropic-messages.ts`, arm for arm.
     *
     * - No reasoning asked for: `thinkingEnabled: false`, which `Anthropic` sends as `thinking:
     *   {type: "disabled"}` unless the model cannot switch it off.
     * - A `forceAdaptiveThinking` model: an effort level (`mapThinkingLevelToEffort()`).
     * - Anything else thinks on a token budget: upstream's `adjustMaxTokensForThinking()` raises
     *   the ceiling by the level's budget (minimal 1,024, low 2,048, medium 8,192, high 16,384;
     *   xhigh and max count as high) up to the model's own, keeps 1,024 for the answer when the
     *   budget would take it all, clamps the result to the context again, and the budget to what
     *   is left above 1,024.
     *
     * Upstream's caller-supplied `thinkingBudgets` are not ported: nothing in pig sets them.
     */
    private static function anthropicSimple(Model $model, Context $context, ?SimpleStreamOptions $options, int $maxTokens, ?string $apiKey): AnthropicOptions
    {
        $base = [$options?->temperature, $maxTokens, $options?->signal, $apiKey];
        $reasoning = $options?->reasoning;
        $toolChoice = $options?->toolChoice;

        if ($reasoning === null) {
            return new AnthropicOptions(...$base, thinkingEnabled: false, toolChoice: $toolChoice, cacheRetention: $options?->cacheRetention, sessionId: $options?->sessionId, metadata: $options?->metadata);
        }

        $compat = $model->compat instanceof AnthropicCompat ? $model->compat : null;

        if ($compat?->forceAdaptiveThinking === true) {
            return new AnthropicOptions(
                ...$base,
                thinkingEnabled: true,
                effort: self::anthropicEffort($model, $reasoning),
                toolChoice: $toolChoice,
                cacheRetention: $options?->cacheRetention,
                sessionId: $options?->sessionId,
                metadata: $options?->metadata,
            );
        }

        // `adjustMaxTokensForThinking(base.maxTokens, model.maxTokens, reasoning)`: the base is
        // always set here (`buildBaseOptions()` sets it), so the ceiling is the base plus the budget,
        // capped at the model's.
        $level = in_array($reasoning->value, ['xhigh', 'max'], true) ? 'high' : $reasoning->value;
        $thinkingBudget = self::DEFAULT_THINKING_BUDGETS[$level];
        $adjusted = min($maxTokens + $thinkingBudget, $model->maxTokens);

        if ($adjusted <= $thinkingBudget) {
            $thinkingBudget = min($thinkingBudget, max(0, $adjusted - self::MIN_ANSWER_TOKENS));
        }

        $ceiling = self::clampMaxTokensToContext($model, $context, $adjusted);

        return new AnthropicOptions(
            $options?->temperature,
            $ceiling,
            $options?->signal,
            $apiKey,
            thinkingEnabled: true,
            thinkingBudgetTokens: min($thinkingBudget, max(0, $ceiling - self::MIN_ANSWER_TOKENS)),
            toolChoice: $toolChoice,
            cacheRetention: $options?->cacheRetention,
            sessionId: $options?->sessionId,
            metadata: $options?->metadata,
        );
    }

    /**
     * Upstream's `clampThinkingLevel(model, reasoning)` for the Responses API, with `off` meaning no
     * reasoning. pig has no `max` effort, so a clamp that would land there — a model whose map
     * offers `max` and not `xhigh`, asked for `xhigh` — is refused out loud rather than sent as
     * something else; the agent's own clamp (`ThinkingLevel::clampedFor()`) never asks that.
     */
    private static function clampedReasoning(Model $model, ?ReasoningEffort $reasoning): ?ReasoningEffort
    {
        if ($reasoning === null) {
            return null;
        }

        $clamped = $model->clampThinkingLevel($reasoning->value);

        if ($clamped === 'off') {
            return null;
        }

        return ReasoningEffort::tryFrom($clamped)
            ?? throw new ProviderError("{$model->provider}/{$model->id} has no '{$reasoning->value}' thinking level, and its nearest, '{$clamped}', is not one pig can send");
    }

    private static function anthropic(?StreamOptions $options, string $apiKey): AnthropicOptions
    {
        if ($options instanceof AnthropicOptions) {
            return new AnthropicOptions(
                $options->temperature,
                $options->maxTokens,
                $options->signal,
                $apiKey,
                $options->thinkingEnabled,
                $options->thinkingBudgetTokens,
                $options->interleavedThinking,
                // These two were dropped here, so a caller's own effort never reached an adaptive
                // model through `start()`; everything else is kept whole, as the comment on
                // `google()` says this helper does.
                $options->effort,
                $options->thinkingDisplay,
                $options->toolChoice,
                $options->cacheRetention,
                $options->sessionId,
                $options->metadata,
            );
        }

        return new AnthropicOptions(
            $options?->temperature,
            $options?->maxTokens,
            $options?->signal,
            $apiKey,
            cacheRetention: $options?->cacheRetention,
            sessionId: $options?->sessionId,
            metadata: $options?->metadata,
        );
    }

    /**
     * Upstream's `mapThinkingLevelToEffort()`: the model's `thinkingLevelMap` entry when it is a
     * string, else minimal and low are `low`, medium `medium`, anything else `high`.
     */
    private static function anthropicEffort(Model $model, ?ReasoningEffort $reasoning): string
    {
        $mapped = $reasoning !== null ? ($model->thinkingLevelMap[$reasoning->value] ?? null) : null;
        if (is_string($mapped)) {
            return $mapped;
        }

        return match ($reasoning?->value) {
            'minimal', 'low' => 'low',
            'medium' => 'medium',
            'high', 'xhigh' => 'high',
            default => 'high',
        };
    }

    /**
     * How hard Gemini should think, said the way the model in question understands it.
     *
     * Upstream's `streamSimple()` in `google-generative-ai.ts`. A level model takes a named level
     * and ignores a budget; 2.5 takes a budget in tokens and has different ceilings for pro and
     * flash. And saying nothing means *dynamic* thinking, not none — so a turn that did not ask
     * for thinking has to ask for none, which `Google` words per model.
     *
     * Upstream clamps the level to the model here (`clampThinkingLevel`); pig's agent has already
     * done that with `ThinkingLevel::clampedFor()` before a request is built, and this package
     * cannot see that one, so what is left here is upstream's `xhigh` → `high` for Google.
     */
    private static function gemini(Model $model, ?SimpleStreamOptions $options, int $maxTokens, ?string $apiKey): GoogleOptions
    {
        $base = [$options?->temperature, $maxTokens, $options?->signal, $apiKey];
        $effort = $options?->reasoning?->clampToHigh();

        if ($effort === null) {
            return new GoogleOptions(...$base, thinkingEnabled: false, cacheRetention: $options?->cacheRetention, sessionId: $options?->sessionId, metadata: $options?->metadata);
        }

        $resolvedLevel = GoogleShared::resolveGoogleThinkingLevel($model, $effort->value);

        // Upstream's `usesGoogleThinkingLevel()`, a regex over the id. It replaces
        // `str_contains($id, 'gemini-3')`, which matched every 3.x id but missed
        // `gemini-flash-latest`, `gemini-flash-lite-latest` and Gemma 4, which all take a level too.
        if (GoogleShared::usesGoogleThinkingLevel($model)) {
            return new GoogleOptions(...$base, thinkingEnabled: true, thinkingLevel: GoogleShared::toGoogleThinkingLevel($resolvedLevel), cacheRetention: $options?->cacheRetention, sessionId: $options?->sessionId, metadata: $options?->metadata);
        }

        return new GoogleOptions(...$base, thinkingEnabled: true, thinkingBudget: self::geminiBudget($model, $resolvedLevel), cacheRetention: $options?->cacheRetention, sessionId: $options?->sessionId, metadata: $options?->metadata);
    }

    /**
     * Upstream's `getGoogleBudget()`, from the level the model's map resolved to.
     *
     * Upstream also takes the caller's own `thinkingBudgets` first; pig's `SimpleStreamOptions` has
     * no such field, so the table is the whole answer. https://ai.google.dev/gemini-api/docs/thinking#set-budget
     */
    private static function geminiBudget(Model $model, string $level): int
    {
        if (str_contains($model->id, '2.5-pro')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 32768][$level];
        }

        // Before `2.5-flash`, which its id also contains: Flash-Lite thinks at least 512.
        if (str_contains($model->id, '2.5-flash-lite')) {
            return ['minimal' => 512, 'low' => 2048, 'medium' => 8192, 'high' => 24576][$level];
        }

        if (str_contains($model->id, '2.5-flash')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 24576][$level];
        }

        // A model with no published ceiling: -1 lets it decide, which beats a guess.
        return -1;
    }

    /** The key is resolved late; a caller's own Google options are otherwise kept whole. */
    private static function google(?StreamOptions $options, string $apiKey): GoogleOptions
    {
        if ($options instanceof GoogleOptions) {
            return new GoogleOptions(
                $options->temperature,
                $options->maxTokens,
                $options->signal,
                $apiKey,
                $options->thinkingEnabled,
                $options->thinkingBudget,
                $options->thinkingLevel,
                $options->toolChoice,
                $options->cacheRetention,
                $options->sessionId,
                $options->metadata,
            );
        }

        return new GoogleOptions(
            $options?->temperature,
            $options?->maxTokens,
            $options?->signal,
            $apiKey,
            cacheRetention: $options?->cacheRetention,
            sessionId: $options?->sessionId,
            metadata: $options?->metadata,
        );
    }

    /** The same shape as `anthropic()`: the key is resolved late, everything else is kept. */
    private static function openAi(?StreamOptions $options, string $apiKey): OpenAiOptions
    {
        if ($options instanceof OpenAiOptions) {
            return new OpenAiOptions(
                $options->temperature,
                $options->maxTokens,
                $options->signal,
                $apiKey,
                $options->reasoning,
                $options->toolChoice,
                $options->serviceTier,
                $options->cacheRetention,
                $options->sessionId,
                $options->metadata,
            );
        }

        return new OpenAiOptions(
            $options?->temperature,
            $options?->maxTokens,
            $options?->signal,
            $apiKey,
            cacheRetention: $options?->cacheRetention,
            sessionId: $options?->sessionId,
            metadata: $options?->metadata,
        );
    }
}
