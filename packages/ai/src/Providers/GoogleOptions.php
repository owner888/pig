<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Gemini's own knobs.
 *
 * Thinking is said two different ways depending on the model, which is why both are here
 * rather than one: Gemini 2.5 takes a token budget, Gemini 3 takes a named level and
 * ignores the budget entirely.
 *
 * `enabled` is not redundant with the other two. Gemini thinks *by default* — "dynamic
 * thinking" — so not asking for it is not the same as asking for none, and a caller that
 * wants none has to say so.
 */
final readonly class GoogleOptions extends StreamOptions
{
    /**
     * @param int|null    $thinkingBudget tokens to spend thinking; -1 lets the model decide
     * @param string|null $thinkingLevel  MINIMAL, LOW, MEDIUM or HIGH, for Gemini 3
     * @param string|null $toolChoice     auto, none or any
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public bool $thinkingEnabled = false,
        public ?int $thinkingBudget = null,
        public ?string $thinkingLevel = null,
        public ?string $toolChoice = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey);
    }
}
