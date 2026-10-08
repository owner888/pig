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
    /**
     * @param string|null $serviceTier upstream's `OpenAIResponsesOptions.serviceTier`, read by the
     *        Responses provider only: sent as `service_tier`, and the turn's cost scaled by the tier
     *        the response reports (else this one) — `flex` halves it, `priority` doubles it
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
        public ?string $toolChoice = null,
        public ?string $serviceTier = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata);
    }
}
