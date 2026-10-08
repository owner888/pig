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
     * @param OpenAiCompat|AnthropicCompat|null $compat what this model says about its endpoint, key
     *        by key, typed by API as upstream's `compat` is: `OpenAiCompat` for `openai-completions`
     *        (laid over what `OpenAiCompat::detect()` works out, by `OpenAiCompat::resolve()`) and
     *        `openai-responses` (which reads `strictMode` alone), `AnthropicCompat` for
     *        `anthropic-messages`. A provider ignores a compat of another API's type.
     * @param array<string, string|null> $thinkingLevelMap what this model calls each thinking
     *        level, keyed by `ThinkingLevel`'s value. **Three states, not two**: a key that is
     *        absent means "send the level's own name", a key whose value is null means the model
     *        does not have that level at all, and a string is what to send instead. Upstream's
     *        `ThinkingLevelMap`, and the distinction is the whole of it — `??` flattens the first
     *        two into each other and is the wrong operator for reading this.
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
        public OpenAiCompat|AnthropicCompat|null $compat = null,
        public array $thinkingLevelMap = [],
    ) {
    }

    public function acceptsImages(): bool
    {
        return in_array('image', $this->input, true);
    }

    /**
     * Whether $level is one this model has at all.
     *
     * Only an explicit null says no. A level the map does not mention is one the model is assumed
     * to have — upstream's rule, and the reason a partial map is useful: a model says only what
     * is unusual about it.
     */
    public function hasThinkingLevel(string $level): bool
    {
        // `array_key_exists` and not `??`, which reads an explicit null as a missing key and so
        // answers "yes, it has that level" for the one value that means the opposite. Upstream
        // can write `mapped === null` because JavaScript tells `undefined` and `null` apart;
        // this is the line where that stops being free.
        return !array_key_exists($level, $this->thinkingLevelMap)
            || $this->thinkingLevelMap[$level] !== null;
    }

    /**
     * What to send for $level, or null to send nothing at all.
     *
     * The three states in one place, so that no caller has to remember which operator reads
     * them: absent gives back the level's own name, null gives null, a string gives the string.
     */
    public function thinkingEffort(string $level): ?string
    {
        return array_key_exists($level, $this->thinkingLevelMap)
            ? $this->thinkingLevelMap[$level]
            : $level;
    }

    /**
     * Whether this model takes the xhigh level.
     *
     * The map wins when it mentions xhigh at all, which is upstream's rule. **The id list stays
     * as the answer when it does not**, and that is a deviation about pig's own table rather than
     * about the rule: upstream can insist xhigh be opted into because every model it ships carries
     * a map, and pig's generated tables carry none. Drop the list and `gpt-5.2` silently loses a
     * level it has.
     */
    public function supportsXhigh(): bool
    {
        return array_key_exists('xhigh', $this->thinkingLevelMap)
            ? $this->thinkingLevelMap['xhigh'] !== null
            : in_array($this->id, self::XHIGH, true);
    }

    public function is(self $other): bool
    {
        return $this->id === $other->id && $this->provider === $other->provider;
    }
}
