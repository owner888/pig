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
    /**
     * @param string|null $toolChoice upstream's provider-neutral `ToolChoice`, `auto` or `none`:
     *        "When omitted, adapters use provider-specific behavior." Handed to the Anthropic and
     *        Responses providers as their own `toolChoice`, as upstream's `streamSimple()`s do
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
        public ?string $toolChoice = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata);
    }
}
