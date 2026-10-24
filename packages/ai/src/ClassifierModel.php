<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A structured classifier model: usable with `Models::classify()` only — upstream's
 * `ClassifierModel`, `BaseModel` plus `contextWindow`.
 *
 * It has no `maxTokens`, `reasoning` or level map, because it never generates: it answers
 * the questions it is asked with probabilities. A chat model and a classifier model may share a
 * provider and an id (OpenRouter's `cloudflare/clef` is both a chat id elsewhere and a classifier
 * here); they live in separate tables, as upstream keeps "chat and classifier entries with the same
 * provider and id separate".
 */
final readonly class ClassifierModel
{
    /**
     * @param list<'text'|'image'> $input what the model accepts
     * @param array<string, string> $headers extra headers every request to it carries
     * @param array<string, mixed>|null $inputLimits upstream's `inputLimits`; no built-in classifier
     *        carries any (the generator's `applyImageInputMetadata()` never sees them)
     * @param SystemOneCompat|null $compat "Compatibility overrides for System One APIs. If not set,
     *        auto-detected from provider, baseUrl, and id."
     */
    public function __construct(
        public string $id,
        public string $name,
        public ClassifierApi $api,
        public string $provider,
        public string $baseUrl,
        public int $contextWindow,
        public array $input = ['text'],
        public Pricing $pricing = new Pricing(),
        public array $headers = [],
        public ?array $inputLimits = null,
        public ?SystemOneCompat $compat = null,
    ) {
    }

    /** The same model at another base URL — upstream's `{ ...model, baseUrl }`. */
    public function withBaseUrl(string $baseUrl): self
    {
        return new self($this->id, $this->name, $this->api, $this->provider, $baseUrl, $this->contextWindow, $this->input, $this->pricing, $this->headers, $this->inputLimits, $this->compat);
    }

    /** The same model with another compat. */
    public function withCompat(?SystemOneCompat $compat): self
    {
        return new self($this->id, $this->name, $this->api, $this->provider, $this->baseUrl, $this->contextWindow, $this->input, $this->pricing, $this->headers, $this->inputLimits, $compat);
    }
}
