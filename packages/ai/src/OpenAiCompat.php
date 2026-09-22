<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The ways an "OpenAI-compatible" endpoint is not.
 *
 * Seven providers speak `openai-completions`, and every one of them differs somewhere:
 * Mistral wants tool ids exactly nine alphanumeric characters, Grok rejects
 * `reasoning_effort`, Cerebras rejects `store`, Copilot wants assistant text as a string
 * rather than an array. None of that is in anyone's documentation as a difference; it is
 * what a 400 looks like after you have sent it.
 *
 * A table per endpoint rather than one rule, for the reason the tool archive names are a
 * table: a merged rule is right for the providers you tested and silently wrong for the
 * one you did not, and the failure lands on someone else's machine.
 *
 * Ported from upstream's `OpenAICompat` and `detectCompatFromUrl()`.
 */
final readonly class OpenAiCompat
{
    /**
     * @param bool   $store            send `store: false`; the strict endpoints reject the field
     * @param bool   $developerRole    a reasoning model's system prompt goes in a `developer` turn
     * @param bool   $reasoningEffort  send `reasoning_effort` at all
     * @param string $maxTokensField   `max_completion_tokens`, or the older `max_tokens`
     * @param bool   $toolResultName   a tool result carries the tool's name as well as its id
     * @param bool   $assistantAfterToolResult insert a filler turn between a result and a user message
     * @param bool   $thinkingAsText   thinking goes back as `<thinking>` text rather than a field
     * @param bool   $mistralToolIds   tool ids are cut and padded to exactly nine characters
     */
    public function __construct(
        public bool $store = true,
        public bool $developerRole = true,
        public bool $reasoningEffort = true,
        public string $maxTokensField = 'max_completion_tokens',
        public bool $toolResultName = false,
        public bool $assistantAfterToolResult = false,
        public bool $thinkingAsText = false,
        public bool $mistralToolIds = false,
    ) {
    }

    /**
     * What an endpoint needs, worked out from where it is.
     *
     * The URL rather than the provider name, because the same provider name can point at
     * a proxy and a local server, and what matters is what is answering.
     */
    public static function detect(string $baseUrl): self
    {
        $strict = self::hostContains($baseUrl, ['cerebras.ai', 'api.x.ai', 'mistral.ai', 'chutes.ai']);
        $mistral = self::hostContains($baseUrl, ['mistral.ai']);

        return new self(
            store: !$strict,
            developerRole: !$strict,
            reasoningEffort: !self::hostContains($baseUrl, ['api.x.ai']),
            maxTokensField: self::hostContains($baseUrl, ['mistral.ai', 'chutes.ai'])
                ? 'max_tokens'
                : 'max_completion_tokens',
            toolResultName: $mistral,
            // Mistral wanted this until December 2024 and no longer does. Kept as a flag
            // rather than deleted, because the next endpoint to want it will not be the
            // one that documents it.
            assistantAfterToolResult: false,
            thinkingAsText: $mistral,
            mistralToolIds: $mistral,
        );
    }

    /** @param list<string> $needles */
    private static function hostContains(string $baseUrl, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($baseUrl, $needle)) {
                return true;
            }
        }

        return false;
    }
}
