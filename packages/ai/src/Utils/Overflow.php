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
     * What each provider says when the prompt is too long.
     *
     * The comment is the real message, not a description of it.
     *
     * @var list<array{0: string, 1: string}> the pattern, and who says it
     */
    private const array PATTERNS = [
        // "prompt is too long: 213462 tokens > 200000 maximum"
        ['/prompt is too long/i', 'Anthropic'],
        // "Your input exceeds the context window of this model"
        ['/exceeds the context window/i', 'OpenAI, both APIs'],
        // "The input token count (1196265) exceeds the maximum number of tokens allowed (1048575)"
        ['/input token count.*exceeds the maximum/i', 'Google'],
        // "This model's maximum prompt length is 131072 but the request contains 537812 tokens"
        ['/maximum prompt length is \d+/i', 'xAI'],
        // "Please reduce the length of the messages or completion"
        ['/reduce the length of the messages/i', 'Groq'],
        // "This endpoint's maximum context length is X tokens. However, you requested about Y"
        ['/maximum context length is \d+ tokens/i', 'OpenRouter'],
        // "prompt token count of X exceeds the limit of Y"
        ['/exceeds the limit of \d+/i', 'GitHub Copilot'],
        // "the request exceeds the available context size, try increasing it"
        ['/exceeds the available context size/i', 'llama.cpp'],
        // "tokens to keep from the initial prompt is greater than the context length"
        ['/greater than the context length/i', 'LM Studio'],
        // No example: these three are the generic net, for a provider nobody has measured yet.
        ['/context length exceeded/i', 'anyone'],
        ['/too many tokens/i', 'anyone'],
        ['/token limit exceeded/i', 'anyone'],
    ];

    /**
     * Cerebras and Mistral send a 4xx with an empty body and no explanation at all.
     *
     * 429 is in there because those two use it for token-based rate limiting, which is what
     * an oversized prompt looks like to them. It is a guess in a way the patterns above are
     * not, and it is upstream's guess, kept because the alternative — retrying an oversized
     * request three times with backoff — is worse than compacting one that did not need it.
     */
    private const string NO_BODY = '/\b4(00|13|29)\b.*\(no body\)/i';

    /**
     * @param int|null $contextWindow needed only for the silent case below
     */
    public static function happened(AssistantMessage $message, ?int $contextWindow = null): bool
    {
        if ($message->stopReason === StopReason::Error && $message->errorMessage !== null) {
            foreach (self::PATTERNS as [$pattern]) {
                if (preg_match($pattern, $message->errorMessage) === 1) {
                    return true;
                }
            }

            if (preg_match(self::NO_BODY, $message->errorMessage) === 1) {
                return true;
            }
        }

        // The silent kind: z.ai takes the oversized request, answers something, and bills for
        // more input tokens than the window holds. Nothing failed, so nothing said anything —
        // the only evidence is the usage report.
        if ($contextWindow !== null && $contextWindow > 0 && $message->stopReason === StopReason::Stop) {
            return $message->usage->input + $message->usage->cacheRead > $contextWindow;
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
