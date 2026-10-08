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
     * @param bool   $thinkingAsText   thinking goes back as untagged text, one part ahead of the answer, rather than a field
     * @param bool   $mistralToolIds   tool ids are cut and padded to exactly nine characters
     * @param bool   $reasoningContentOnAssistantMessages every replayed assistant turn of a reasoning
     *        model carries `reasoning_content`, empty when there is none — upstream's
     *        `requiresReasoningContentOnAssistantMessages`, which DeepSeek needs
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
        public bool $reasoningContentOnAssistantMessages = false,
    ) {
    }

    /**
     * What an endpoint needs, worked out from where it is.
     *
     * The URL rather than the provider name, because the same provider name can point at
     * a proxy and a local server, and what matters is what is answering.
     *
     * `$provider` is for the one flag upstream also decides by name: its `isDeepSeek` is
     * `provider === "deepseek" || baseUrl.toLowerCase().includes("deepseek.com")`.
     */
    public static function detect(string $baseUrl, string $provider = ''): self
    {
        $isDeepSeek = $provider === 'deepseek' || str_contains(strtolower($baseUrl), 'deepseek.com');

        $strict = self::hostContains($baseUrl, ['cerebras.ai', 'api.x.ai', 'mistral.ai', 'chutes.ai']);
        $mistral = self::hostContains($baseUrl, ['mistral.ai']);

        return new self(
            store: !$strict,
            developerRole: !$strict,
            reasoningEffort: !self::hostContains($baseUrl, ['api.x.ai']),
            // `deepseek.com` is the third, and the only one here found by measurement rather than
            // by a 400: DeepSeek **accepts** `max_completion_tokens` and ignores it, so nothing
            // fails and every request is simply unbounded. `test/live.php` asked for 16 tokens and
            // got 145, which is what turned "the stop reason is not mapped" into "the field name
            // is wrong". *An endpoint that quietly drops a field is worse than one that refuses
            // it, and only a number tells them apart.*
            maxTokensField: self::hostContains($baseUrl, ['mistral.ai', 'chutes.ai', 'deepseek.com'])
                ? 'max_tokens'
                : 'max_completion_tokens',
            toolResultName: $mistral,
            // Mistral wanted this until December 2024 and no longer does. Kept as a flag
            // rather than deleted, because the next endpoint to want it will not be the
            // one that documents it.
            assistantAfterToolResult: false,
            thinkingAsText: $mistral,
            mistralToolIds: $mistral,
            // Upstream's `requiresReasoningContentOnAssistantMessages: isDeepSeek`. DeepSeek's
            // thinking mode answers a replayed assistant turn without `reasoning_content` with a
            // 400 — and pig only ever writes that field when the turn had thinking to put in it.
            reasoningContentOnAssistantMessages: $isDeepSeek,
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
