<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ReasoningEffort;
use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * The knobs `openai-completions` has that the shared options do not.
 *
 * `reasoning` is the same idea as Anthropic's thinking budget, said the way this API
 * says it: an effort level rather than a number of tokens.
 */
final readonly class OpenAiOptions extends StreamOptions
{
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
        public ?string $toolChoice = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey);
    }
}
