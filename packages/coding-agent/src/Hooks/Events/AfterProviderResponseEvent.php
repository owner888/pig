<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Upstream's `after_provider_response`: "Fired after a provider response is received and before
 * the response stream is consumed." Wired through the request's `onResponse`, so it is heard for
 * what each provider reports there — the successful response for Anthropic and the OpenAI APIs,
 * every response (a refusal included) for Mistral, none for Gemini, whose SDK does not expose it.
 */
final readonly class AfterProviderResponseEvent implements HookEvent
{
    /** @param array<string, string> $headers lowercased names */
    public function __construct(
        public int $status,
        public array $headers,
    ) {
    }

    public function type(): string
    {
        return 'after_provider_response';
    }
}
