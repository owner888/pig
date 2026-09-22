<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Async\AbortSignal;

/**
 * Provider-independent options, as the agent loop passes them.
 *
 * `reasoning` says how hard to think in the abstract; each provider turns that into its
 * own dialect — a token budget for Anthropic, an effort level for OpenAI.
 */
final readonly class SimpleStreamOptions extends StreamOptions
{
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey);
    }
}
