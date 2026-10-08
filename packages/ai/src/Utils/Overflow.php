<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;

/**
 * Did the conversation outgrow the model's context window?
 *
 * There is no status code for this and no field in any response that says so. Every provider
 * says it in prose, in its own words, and two of them do not say it at all. So this is a table
 * of what each one actually writes, gathered by sending oversized requests and reading the
 * replies — which is why each pattern carries the message it was written for. **A pattern with
 * no example beside it is a guess, and a guess here compacts a conversation that was fine.**
 *
 * Ported from upstream's `ai/src/utils/overflow.ts`, table and all. Kept in `pig/ai` rather
 * than next to the session logic because it is knowledge about providers, and `pig/ai` is
 * where the rest of that lives.
 */
final class Overflow
{
    /**
     * What each provider says when the prompt is too long — upstream's `OVERFLOW_PATTERNS`, in its
     * order, each with the provider its comment names. The examples are upstream's:
     *
     * - Anthropic: "prompt is too long: 213462 tokens > 200000 maximum", and for the byte-size limit
     *   `413 {"error":{"type":"request_too_large","message":"Request exceeds the maximum size"}}`
     * - OpenAI: "Your input exceeds the context window of this model"
     * - OpenAI/LiteLLM: "Requested token count exceeds the model's maximum context length of 131072 tokens"
     * - OpenAI-compatible: "Input length (265330) exceeds model's maximum context length (262144)."
     * - Google: "The input token count (1196265) exceeds the maximum number of tokens allowed (1048575)"
     * - xAI: "This model's maximum prompt length is 131072 but the request contains 537812 tokens"
     * - Groq: "Please reduce the length of the messages or completion"
     * - OpenRouter: "This endpoint's maximum context length is X tokens. However, you requested about Y tokens"
     * - OpenRouter/Poolside: "Input length X exceeds the maximum allowed input length of Y tokens."
     * - Together AI: "The input (X tokens) is longer than the model's context length (Y tokens)."
     * - llama.cpp: "the request exceeds the available context size, try increasing it"
     * - LM Studio: "tokens to keep from the initial prompt is greater than the context length"
     * - GitHub Copilot: "prompt token count of X exceeds the limit of Y"
     * - MiniMax: "invalid params, context window exceeds limit"
     * - Kimi For Coding: "Your request exceeded model token limit: X (requested: Y)"
     * - DS4: "Prompt has X tokens, but the configured context size is Y tokens"
     * - Mistral: "Prompt contains X tokens ... too large for model with Y maximum context length"
     * - z.ai: `{"code":"1261","message":"Prompt too long"}`, `{"code":"1261","message":"Prompt exceeds max length"}` (CN)
     * - DashScope/Qwen: "Range of input length should be [1, X]"
     * - Ollama: "prompt too long; exceeded max context length by X tokens"
     *
     * @var list<array{0: string, 1: string}> the pattern, and who says it
     */
    private const array PATTERNS = [
        ['/prompt (?:is )?too long/i', 'Anthropic and z.ai token overflow'],
        ['/prompt exceeds max length/i', 'z.ai CN endpoint token overflow'],
        ['/request_too_large/i', 'Anthropic request byte-size overflow (HTTP 413)'],
        ['/input is too long for requested model/i', 'Amazon Bedrock'],
        ['/exceeds the context window/i', 'OpenAI (Completions & Responses API)'],
        ["/exceeds (?:the )?(?:model'?s )?maximum context length(?: of [\\d,]+ tokens?|\\s*\\([\\d,]+\\))/i", 'OpenAI-compatible proxies (LiteLLM)'],
        ['/input token count.*exceeds the maximum/i', 'Google (Gemini)'],
        ['/maximum prompt length is \d+/i', 'xAI (Grok)'],
        ['/reduce the length of the messages/i', 'Groq'],
        ['/maximum context length is \d+ tokens/i', 'OpenRouter (most backends)'],
        ['/exceeds (?:the )?maximum allowed input length of [\d,]+ tokens?/i', 'OpenRouter/Poolside'],
        ["/input \\(\\d+ tokens\\) is longer than the model'?s context length \\(\\d+ tokens\\)/i", 'Together AI'],
        ['/exceeds the limit of \d+/i', 'GitHub Copilot'],
        ['/exceeds the available context size/i', 'llama.cpp server'],
        ['/greater than the context length/i', 'LM Studio'],
        ['/context window exceeds limit/i', 'MiniMax'],
        ['/exceeded model token limit/i', 'Kimi For Coding'],
        ['/too large for model with \d+ maximum context length/i', 'Mistral'],
        ['/prompt has [\d,]+ tokens?, but the configured context size is [\d,]+ tokens?/i', 'DS4 server'],
        ['/model_context_window_exceeded/i', 'z.ai non-standard finish_reason surfaced as error text'],
        ['/prompt too long; exceeded (?:max )?context length/i', 'Ollama explicit overflow error'],
        ['/range of input length should be/i', 'DashScope / Qwen Token Plan'],
        ['/context[_ ]length[_ ]exceeded/i', 'Generic fallback'],
        ['/too many tokens/i', 'Generic fallback'],
        ['/token limit exceeded/i', 'Generic fallback'],
    ];

    /**
     * Upstream's `CEREBRAS_BODYLESS_OVERFLOW_PATTERN`: Cerebras answers an oversized prompt with a
     * 400 or 413 and no body — `400 status code (no body)` in the `openai` SDK's words, which the
     * completions provider writes too (`ErrorBody`). Only for the `cerebras` provider: from anyone
     * else a bodiless 400 says nothing about size.
     */
    private const string CEREBRAS_BODYLESS = '/^4(?:00|13)\s*(?:status code)?\s*\(no body\)/i';

    /**
     * Upstream's `NON_OVERFLOW_PATTERNS`: an error matching one of these is not an overflow even when
     * it matches a pattern above — Bedrock's throttling reads "Too many tokens, please wait before
     * trying again", which the generic `too many tokens` would otherwise take for one.
     */
    private const array NON_OVERFLOW_PATTERNS = [
        '/^(Throttling error|Service unavailable):/i',
        '/rate limit/i',
        '/too many requests/i',
    ];

    /**
     * Upstream's `isContextOverflow()`.
     *
     * @param int|null $contextWindow needed only for the silent and the length-stop cases below
     */
    public static function happened(AssistantMessage $message, ?int $contextWindow = null): bool
    {
        if ($message->stopReason === StopReason::Error && $message->errorMessage !== null && $message->errorMessage !== '') {
            $isNonOverflow = false;

            foreach (self::NON_OVERFLOW_PATTERNS as $pattern) {
                if (preg_match($pattern, $message->errorMessage) === 1) {
                    $isNonOverflow = true;

                    break;
                }
            }

            if (!$isNonOverflow) {
                foreach (self::PATTERNS as [$pattern]) {
                    if (preg_match($pattern, $message->errorMessage) === 1) {
                        return true;
                    }
                }

                if ($message->provider === 'cerebras' && preg_match(self::CEREBRAS_BODYLESS, $message->errorMessage) === 1) {
                    return true;
                }
            }
        }

        // The silent kind: z.ai takes the oversized request, answers something, and bills for
        // more input tokens than the window holds. Nothing failed, so nothing said anything —
        // the only evidence is the usage report.
        //
        // **And it is not one provider's quirk.** DeepSeek does the same, measured through
        // `test/live.php`: a prompt sized past a declared 65,536-token window came back answered
        // and billed at **98,315** input tokens. Two examples is what makes it a shape rather than
        // a special case, so a new endpoint is worth asking the question of rather than assuming
        // it refuses — and upstream's own comment, which names z.ai and nothing else, is worth
        // reading as the list of what somebody had measured rather than as the list of what the
        // branch covers. *Its code is provider-agnostic exactly as this is*; only its prose is
        // narrow. This comment used to say upstream carried the branch for z.ai alone, which is
        // the fourth shape from CLAUDE.md's index — a claim about a repository that a grep
        // refutes — pointed at upstream for once.
        if ($contextWindow !== null && $contextWindow > 0 && $message->stopReason === StopReason::Stop) {
            if ($message->usage->input + $message->usage->cacheRead > $contextWindow) {
                return true;
            }
        }

        // Upstream's third case, the length stop: Xiaomi MiMo cuts an oversized prompt down to fill
        // the window and answers `length` with nothing written, because there was no room left.
        if ($contextWindow !== null && $contextWindow > 0 && $message->stopReason === StopReason::Length && $message->usage->output === 0) {
            if ($message->usage->input + $message->usage->cacheRead >= $contextWindow * 0.99) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who each pattern was written for.
     *
     * For a test that wants to prove the table is annotated, and for anyone adding a provider:
     * send an oversized request, read what comes back, and put that message in the comment
     * beside the pattern you add.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function patterns(): array
    {
        return self::PATTERNS;
    }
}
