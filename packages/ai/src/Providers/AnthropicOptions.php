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
    /**
     * @param bool|null   $thinkingEnabled upstream's `thinkingEnabled?: boolean`, and **all three
     *        states matter**: true asks for thinking, false asks for none in so many words
     *        (`thinking: {type: "disabled"}`, unless the model's `thinkingLevelMap` says `off` is
     *        not a level it has), and null — upstream's undefined — says nothing at all.
     *        `Stream::simple()` always says true or false, as upstream's `streamSimple()` does
     * @param string|null $thinkingDisplay upstream's `thinkingDisplay`: `summarized` or `omitted`,
     *        sent as `thinking.display` on a thinking turn. Null is upstream's default, `summarized`
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?bool $thinkingEnabled = null,
        public int $thinkingBudgetTokens = 1024,
        public bool $interleavedThinking = true,
        public ?string $effort = null,
        public ?string $thinkingDisplay = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey);
    }
}
