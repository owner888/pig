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
    case GoogleGeminiCli = 'google-gemini-cli';
}
