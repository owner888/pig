<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The wire protocol a model speaks.
 *
 * Distinct from the provider: Groq, Cerebras and OpenRouter are different providers
 * that all speak `openai-completions`.
 */
enum Api: string
{
    case OpenAiCompletions = 'openai-completions';
    case OpenAiResponses = 'openai-responses';
    case AnthropicMessages = 'anthropic-messages';
    case GoogleGenerativeAi = 'google-generative-ai';
    /** Mistral's own chat API (`Providers\Mistral`), upstream's `mistral-conversations`. */
    case MistralConversations = 'mistral-conversations';

    /**
     * A protocol an extension brought: `Stream::start()` finds the implementation through
     * `Extension\ProviderRegistry::apiFor()` by the model's provider name. One case for every
     * such protocol rather than one each, because an enum is closed and an extension is not —
     * the provider name is the discriminator the registry needs, and it is on the model already.
     */
    case Extension = 'extension';
}
