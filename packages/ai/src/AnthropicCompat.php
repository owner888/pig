<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What an `anthropic-messages` model says about its endpoint — upstream's `AnthropicMessagesCompat`,
 * the two keys of it pig reads.
 *
 * Both are **metadata, not detection**: upstream's runtime reads `model.compat?.x` with a default of
 * false and never looks at the model id, and its model generator writes the flags onto the built-in
 * models that have them. `Models` does that here; a `models.json` model says them in its `compat`
 * block under upstream's names. Null means not said, which is false.
 *
 * @see OpenAiCompat the same idea for `openai-completions`, which also detects
 */
final readonly class AnthropicCompat
{
    /**
     * @param bool|null $forceAdaptiveThinking `thinking: {type: "adaptive"}` plus `output_config.effort`
     *        instead of a token budget — upstream's `forceAdaptiveThinking`, which its generator sets
     *        from the model id (`isAnthropicAdaptiveThinkingModel()`) on every built-in model of this API
     * @param bool|null $strictTools a tool asking for strict sampling is sent strict — upstream's
     *        `supportsStrictTools`, which its generator sets on every `anthropic` provider model
     */
    public function __construct(
        public ?bool $forceAdaptiveThinking = null,
        public ?bool $strictTools = null,
    ) {
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
}
