<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleGeminiCli;
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
            // The same options: Code Assist is a different envelope around the same request, so
            // temperature, thinking and tool choice mean exactly what they mean for Gemini.
            Api::GoogleGeminiCli => (new GoogleGeminiCli())->stream($model, $context, self::google($options, $apiKey)),
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
     * it — which is exactly what happened to `google-gemini-cli` for twelve models. Upstream's own
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
            Api::GoogleGeminiCli => self::geminiCli($model, $options, $maxTokens, $apiKey),
            Api::AnthropicMessages => new AnthropicOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                // No reasoning asked for means thinking off, stated rather than left to
                // the provider's default — Anthropic's is off, but Gemini's is not.
                thinkingEnabled: $options?->reasoning !== null,
                thinkingBudgetTokens: self::anthropicBudget($options?->reasoning),
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

        // Upstream's two checks rather than one on `gemini-3`: a Gemini 3 model that is neither
        // pro nor flash takes the budget path there, and two arms of one file disagreeing about
        // which models are Gemini 3 is the shape this audit keeps finding.
        if (str_contains($model->id, '3-pro') || str_contains($model->id, '3-flash')) {
            return new GoogleOptions(...$base, thinkingEnabled: true, thinkingLevel: self::geminiLevel($model, $effort));
        }

        return new GoogleOptions(...$base, thinkingEnabled: true, thinkingBudget: self::geminiBudget($model, $effort));
    }

    /**
     * The same question for Code Assist, and not quite the same answer.
     *
     * **This arm was missing**, so every `google-gemini-cli` model — the five Gemini CLI ones and
     * the seven Antigravity ones, which speak the same API — fell through to the `default` above
     * and was handed plain `StreamOptions`. `--thinking high` on any of them asked for no thinking
     * and got none, without a word. The `off` case came out right by accident: Code Assist reads a
     * missing `thinkingConfig` as none.
     *
     * The shape is the public endpoint's; the numbers are not. Upstream's gemini-cli arm has one
     * flat budget table for every 2.x model where the public arm has per-model ceilings —
     * `2.5-pro` starts at 128 there and 1024 here. The Gemini 3 level path *is* the same, so it
     * shares `geminiLevel()`.
     */
    private static function geminiCli(Model $model, ?SimpleStreamOptions $options, int $maxTokens, ?string $apiKey): GoogleOptions
    {
        $base = [$options?->temperature, $maxTokens, $options?->signal, $apiKey];
        $effort = $options?->reasoning?->clampToHigh();

        if ($effort === null) {
            return new GoogleOptions(...$base, thinkingEnabled: false);
        }

        // Upstream's two checks rather than one for `gemini-3`: Antigravity's ids are
        // `gemini-3-pro-high` and `gemini-3-flash`, and its Claude models take the budget path.
        if (str_contains($model->id, '3-pro') || str_contains($model->id, '3-flash')) {
            return new GoogleOptions(...$base, thinkingEnabled: true, thinkingLevel: self::geminiLevel($model, $effort));
        }

        return new GoogleOptions(...$base, thinkingEnabled: true, thinkingBudget: match ($effort) {
            ReasoningEffort::Minimal => 1024,
            ReasoningEffort::Low => 2048,
            ReasoningEffort::Medium => 8192,
            default => 16_384,
        });
    }

    /** Gemini 3 Pro offers two levels; Flash offers four. */
    private static function geminiLevel(Model $model, ReasoningEffort $effort): string
    {
        if (str_contains($model->id, 'gemini-3-pro')) {
            return match ($effort) {
                ReasoningEffort::Minimal, ReasoningEffort::Low => 'LOW',
                default => 'HIGH',
            };
        }

        return strtoupper($effort->value);
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
