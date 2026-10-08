<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/** Mistral's own knobs on top of the shared ones — upstream's `MistralOptions`. */
final readonly class MistralOptions extends StreamOptions
{
    /**
     * @param string|null $toolChoice      `auto`, `none`, `any` or `required`, sent as `tool_choice`
     *        (upstream also takes `{type: "function", function: {name}}`, which nothing in pig asks for)
     * @param string|null $promptMode      `reasoning`, sent as `prompt_mode` — how a reasoning model with
     *        no effort levels (Magistral) is asked to think
     * @param string|null $reasoningEffort `none`, `low`, `medium`, `high` or `max`, sent as
     *        `reasoning_effort` — what a model whose `thinkingLevelMap` names its efforts is sent
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?string $toolChoice = null,
        public ?string $promptMode = null,
        public ?string $reasoningEffort = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata);
    }
}
