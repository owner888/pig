<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Anthropic's own knobs on top of the shared ones.
 *
 * Upstream also carries `toolChoice`; nothing sets it yet, so it is left out until
 * something does.
 */
final readonly class AnthropicOptions extends StreamOptions
{
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public bool $thinkingEnabled = false,
        public int $thinkingBudgetTokens = 1024,
        public bool $interleavedThinking = true,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey);
    }
}
