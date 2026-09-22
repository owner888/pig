<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * One model: which API it speaks, where it lives, what it costs, what it accepts.
 *
 * Upstream ships a generated registry of several hundred of these and looks them up by
 * provider and id. There is no registry here yet — callers build the model they want —
 * and one arrives when something needs to choose between models rather than be handed one.
 */
final readonly class Model
{
    /** Models that accept the xhigh reasoning level. Everything else clamps to high. */
    private const array XHIGH = ['gpt-5.1-codex-max', 'gpt-5.2', 'gpt-5.2-codex'];

    /**
     * @param string                $provider anthropic, openai, groq, … — free-form, since
     *        several providers speak the same api
     * @param list<'text'|'image'>  $input    what the model accepts
     * @param array<string, string> $headers  extra headers every request to it carries
     */
    public function __construct(
        public string $id,
        public string $name,
        public Api $api,
        public string $provider,
        public string $baseUrl,
        public int $contextWindow,
        public int $maxTokens,
        public bool $reasoning = false,
        public array $input = ['text'],
        public Pricing $pricing = new Pricing(),
        public array $headers = [],
    ) {
    }

    public function acceptsImages(): bool
    {
        return in_array('image', $this->input, true);
    }

    public function supportsXhigh(): bool
    {
        return in_array($this->id, self::XHIGH, true);
    }

    public function is(self $other): bool
    {
        return $this->id === $other->id && $this->provider === $other->provider;
    }
}
