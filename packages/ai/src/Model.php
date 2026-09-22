<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * One model: which API it speaks, where it lives, what it costs, what it accepts.
 *
 * `Models` holds the ones there are, by id. A caller can still build one by hand — a proxy,
 * a local server, something the registry has not heard of — which is why this is a plain
 * constructor and not something only the registry may call.
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
     * @param OpenAiCompat|null     $compat   overrides for `openai-completions` endpoints;
     *        worked out from the base URL when not given, and meaningless for other APIs
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
        public ?OpenAiCompat $compat = null,
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
