<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Antigravity;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
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
    /** Upstream caps the default here rather than at the model's own ceiling. */
    private const int DEFAULT_MAX_TOKENS = 32_000;

    /**
     * Provider-independent options, mapped and sent.
     *
     * Upstream: streamSimple().
     */
    public static function simple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        return self::start($model, $context, self::translate($model, $options));
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
            Api::Antigravity => (new Antigravity())->stream($model, $context, self::google($options, $apiKey)),
        };
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
            default => [],
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
    private static function translate(Model $model, ?SimpleStreamOptions $options): StreamOptions
    {
        $maxTokens = $options?->maxTokens ?? min($model->maxTokens, self::DEFAULT_MAX_TOKENS);
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
            ),
            Api::OpenAiResponses => new OpenAiOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                reasoning: $model->supportsXhigh() ? $options?->reasoning : $options?->reasoning?->clampToHigh(),
            ),
            Api::GoogleGenerativeAi => self::gemini($model, $options, $maxTokens, $apiKey),
            Api::Antigravity => self::antigravity($options, $maxTokens, $apiKey),
            Api::AnthropicMessages => new AnthropicOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                // No reasoning asked for means thinking off, stated rather than left to
                // the provider's default — Anthropic's is off, but Gemini's is not.
                thinkingEnabled: $options?->reasoning !== null,
                thinkingBudgetTokens: self::anthropicBudget($options?->reasoning),
                effort: self::anthropicEffort($model, $options?->reasoning),
            ),
        };
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
            );
        }

        return new AnthropicOptions($options?->temperature, $options?->maxTokens, $options?->signal, $apiKey);
    }

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
     * Gemini 3 takes a named level and ignores a budget; 2.5 takes a budget in tokens and
     * has different ceilings for pro and flash. And saying nothing means *dynamic*
     * thinking, not none — so a turn that did not ask for thinking has to ask for none.
     */
    private static function gemini(Model $model, ?SimpleStreamOptions $options, int $maxTokens, ?string $apiKey): GoogleOptions
    {
        $base = [$options?->temperature, $maxTokens, $options?->signal, $apiKey];
        $effort = $options?->reasoning?->clampToHigh();

        if ($effort === null) {
            return new GoogleOptions(...$base, thinkingEnabled: false);
        }

        // Every Gemini 3.x model takes a *level*, which was measured rather than read — one
        // request per model and level against the public endpoint, 2026-10-01 (CLAUDE.md, "Gemini
        // 3.x on the public endpoint"). Upstream's two checks, `3-pro` and `3-flash`, matched the
        // anchor-era ids and stopped matching once the catalogue moved to `gemini-3.1-pro-preview`
        // and `gemini-3.5-flash`: every one of those fell through to `thinkingBudget: -1`, which
        // thinks as much as it likes and ignores the level entirely.
        if (str_contains($model->id, 'gemini-3')) {
            return new GoogleOptions(...$base, thinkingEnabled: true, thinkingLevel: self::geminiLevel($model, $effort));
        }

        return new GoogleOptions(...$base, thinkingEnabled: true, thinkingBudget: self::geminiBudget($model, $effort));
    }

    /**
     * Antigravity's, which needs the *level* and nothing else about thinking.
     *
     * No budget and no clamping here, unlike every other arm: on this deployment a level chooses
     * a different upstream model, and `Antigravity\Routing` owns both that choice and the budget
     * that goes with it. Passing a second opinion down would be two things deciding one.
     *
     * `ReasoningEffort`'s values are `ThinkingLevel`'s minus `off`, and `off` arrives here as no
     * reasoning at all — so the level the provider wants is exactly `$options?->reasoning?->value`
     * with null meaning off. That the two enums line up is luck worth stating: if a case is ever
     * added to one of them, this is a line to check.
     */
    private static function antigravity(?SimpleStreamOptions $options, int $maxTokens, ?string $apiKey): GoogleOptions
    {
        return new GoogleOptions(
            $options?->temperature,
            $maxTokens,
            $options?->signal,
            $apiKey,
            thinkingEnabled: $options?->reasoning !== null,
            thinkingLevel: $options?->reasoning?->value,
        );
    }

    /**
     * The level's own name, upper-cased, unless the model's map says otherwise.
     *
     * Upstream folded Pro down to two levels (`gemini-3-pro-preview` took LOW and HIGH). Measured
     * on `gemini-3.1-pro-preview`, MEDIUM is a real level there — 111 thinking tokens against
     * 87 and 142 — so there is nothing to fold any more. Which levels a model *refuses* (MINIMAL
     * on five of the nine, `off` on five) is per model with no pattern in the name, so it lives
     * in the row's `thinkingLevelMap` and is clamped away before a request is built; a level that
     * still reaches here is sent as it is, and a provider that refuses it says so by name.
     */
    private static function geminiLevel(Model $model, ReasoningEffort $effort): string
    {
        return strtoupper($model->thinkingEffort($effort->value) ?? $effort->value);
    }

    /** https://ai.google.dev/gemini-api/docs/thinking#set-budget */
    private static function geminiBudget(Model $model, ReasoningEffort $effort): int
    {
        if (str_contains($model->id, '2.5-pro')) {
            return match ($effort) {
                ReasoningEffort::Minimal => 128,
                ReasoningEffort::Low => 2048,
                ReasoningEffort::Medium => 8192,
                default => 32768,
            };
        }

        if (str_contains($model->id, '2.5-flash')) {
            return match ($effort) {
                ReasoningEffort::Minimal => 128,
                ReasoningEffort::Low => 2048,
                ReasoningEffort::Medium => 8192,
                default => 24576,
            };
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
            );
        }

        return new GoogleOptions($options?->temperature, $options?->maxTokens, $options?->signal, $apiKey);
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
            );
        }

        return new OpenAiOptions($options?->temperature, $options?->maxTokens, $options?->signal, $apiKey);
    }

    /** Anthropic spends reasoning as a token budget rather than an effort level. */
    private static function anthropicBudget(?ReasoningEffort $reasoning): int
    {
        return match ($reasoning?->clampToHigh()) {
            ReasoningEffort::Low => 2048,
            ReasoningEffort::Medium => 8192,
            ReasoningEffort::High => 16384,
            default => 1024,
        };
    }
}
