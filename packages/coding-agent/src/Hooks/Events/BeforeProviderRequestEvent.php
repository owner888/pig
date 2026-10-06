<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\Http\Request;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A request is about to go to a provider.
 *
 * Upstream's `before_provider_request` and `before_provider_headers` as one event, because pig
 * has one `Request` object where upstream has a payload and a headers map built by two SDK
 * layers. A handler answers `BeforeProviderRequestResult` with a replacement, or null to let it
 * go as it is. Fired from `HttpClient::send()`, so it sees every provider's bytes — including a
 * sign-in's token exchange, which is why `request->url` is there to filter on.
 */
final readonly class BeforeProviderRequestEvent implements HookEvent
{
    public function __construct(public Request $request)
    {
    }

    public function type(): string
    {
        return 'before_provider_request';
    }
}
