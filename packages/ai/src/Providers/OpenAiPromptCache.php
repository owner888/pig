<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

/**
 * Upstream's `api/openai-prompt-cache.ts`: the one rule the three Responses-shaped APIs share about
 * `prompt_cache_key`.
 */
final class OpenAiPromptCache
{
    /** Upstream's `OPENAI_PROMPT_CACHE_KEY_MAX_LENGTH`, in code points. */
    public const int OPENAI_PROMPT_CACHE_KEY_MAX_LENGTH = 64;

    /**
     * Upstream's `clampOpenAIPromptCacheKey()`: the key, cut to 64 code points (`Array.from(key)`
     * counts code points, as `mb_substr()` does). Null stays null.
     */
    public static function clampOpenAIPromptCacheKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        if (mb_strlen($key) <= self::OPENAI_PROMPT_CACHE_KEY_MAX_LENGTH) {
            return $key;
        }

        return mb_substr($key, 0, self::OPENAI_PROMPT_CACHE_KEY_MAX_LENGTH);
    }
}
