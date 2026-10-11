<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Model;
use Pig\Ai\ReasoningEffort;

/**
 * How hard the agent should think, as a UI setting.
 *
 * One level apart from `ReasoningEffort`: this is what a person toggles, that is what a
 * provider is told. Every level but `off` goes through under its own name — upstream's agent
 * passes `thinkingLevel === "off" ? undefined : thinkingLevel` — so `minimal` reaches the
 * provider as `minimal`: Anthropic's budget for it is 1,024 tokens, a Copilot model's map can
 * say `minimal: "low"`, and OpenAI takes the word itself.
 */
enum ThinkingLevel: string
{
    case Off = 'off';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
    /**
     * Upstream's `max`: "only supported by selected model families" — offered only where a model's
     * `thinkingLevelMap` names it (Anthropic's adaptive models from Opus 4.7 on, GLM-5.3, …), sent as
     * the map's word for it, and a token-budget provider spends `high`'s budget on it.
     */
    case Max = 'max';

    public function toReasoning(): ?ReasoningEffort
    {
        return match ($this) {
            self::Off => null,
            self::Minimal => ReasoningEffort::Minimal,
            self::Low => ReasoningEffort::Low,
            self::Medium => ReasoningEffort::Medium,
            self::High => ReasoningEffort::High,
            self::Xhigh => ReasoningEffort::Xhigh,
            self::Max => ReasoningEffort::Max,
        };
    }

    /** The level a request's reasoning effort stands for; none is `off`. */
    public static function fromReasoning(?ReasoningEffort $reasoning): self
    {
        return $reasoning === null ? self::Off : self::from($reasoning->value);
    }

    /**
     * The levels $model actually has, in order.
     *
     * Upstream's `getSupportedThinkingLevels`. A model that cannot reason has one level and it is
     * `off` — **not the empty list**, which is what pig used to answer and is a different claim:
     * "no levels to choose from" reads as a broken model rather than as one that does not think.
     *
     * Here rather than on `Model` because `Model` lives in the `ai` package, which knows nothing
     * about `ThinkingLevel` — that is the layering, and `Model` carries only the string-keyed
     * half of this.
     *
     * `Model::supportedThinkingLevels()` is the rule, over upstream's seven levels: `xhigh` and `max`
     * only where the map names them, every other level unless the map sets it to null.
     *
     * @return list<self>
     */
    public static function supportedBy(Model $model): array
    {
        return array_map(self::from(...), $model->supportedThinkingLevels());
    }

    /**
     * $level if $model has it, otherwise the nearest level it does have.
     *
     * Upstream's `clampThinkingLevel`, and the search order is the argument: **up from what was
     * asked for first, then down**. The cases are ordered by effort, so a model that refuses
     * `low` is far likelier to be one that always thinks than one that cannot — dropping to `off`
     * there would turn thinking off for a model whose only objection was the amount.
     */
    public static function clampedFor(Model $model, self $level): self
    {
        $available = self::supportedBy($model);

        if (in_array($level, $available, true)) {
            return $level;
        }

        $ordered = self::cases();
        $at = (int) array_search($level, $ordered, true);

        for ($index = $at, $end = count($ordered); $index < $end; $index++) {
            if (in_array($ordered[$index], $available, true)) {
                return $ordered[$index];
            }
        }

        for ($index = $at - 1; $index >= 0; $index--) {
            if (in_array($ordered[$index], $available, true)) {
                return $ordered[$index];
            }
        }

        return $available[0] ?? self::Off;
    }
}
