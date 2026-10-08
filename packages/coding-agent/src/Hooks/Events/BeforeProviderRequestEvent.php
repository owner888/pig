<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Upstream's `before_provider_request`: "Fired before a provider request is sent. Can replace the
 * payload."
 *
 * `$payload` is the request body as the provider built it — upstream's params object, which for
 * Anthropic and the OpenAI APIs is the JSON body (Anthropic's with its `betas`), for Gemini the
 * SDK's `{model, contents, config}` and for Mistral the camelCase payload. A handler answers
 * `BeforeProviderRequestResult` with a replacement, or null to leave it; handlers are chained,
 * each given what the last one returned. Wired through the request's `onPayload`.
 */
final readonly class BeforeProviderRequestEvent implements HookEvent
{
    public function __construct(public mixed $payload)
    {
    }

    public function type(): string
    {
        return 'before_provider_request';
    }
}
