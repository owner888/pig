<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
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
            default => throw new ProviderError("No provider for {$model->api->value} has been ported yet"),
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

    /** Upstream: mapOptionsForApi(). */
    private static function translate(Model $model, ?SimpleStreamOptions $options): StreamOptions
    {
        $maxTokens = $options?->maxTokens ?? min($model->maxTokens, self::DEFAULT_MAX_TOKENS);
        $apiKey = $options?->apiKey ?? self::envApiKey($model->provider);

        return match ($model->api) {
            Api::OpenAiCompletions => new OpenAiOptions(
                $options?->temperature,
                $maxTokens,
                $options?->signal,
                $apiKey,
                // Xhigh is OpenAI's alone; everyone else speaking this protocol clamps.
                reasoning: $options?->reasoning?->clampToHigh(),
            ),
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
            default => new StreamOptions($options?->temperature, $maxTokens, $options?->signal, $apiKey),
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
