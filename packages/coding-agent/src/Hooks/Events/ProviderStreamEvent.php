<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Upstream's `provider_stream_event`: "Fired for a parsed provider stream event before Pi normalizes
 * it." `$data` is the provider's own event, decoded; it is the provider's and is to be treated as
 * read-only. Wired through the request's `onProviderStreamEvent`.
 */
final readonly class ProviderStreamEvent implements HookEvent
{
    public function __construct(
        public string $provider,
        public string $api,
        public string $model,
        public mixed $data,
    ) {
    }

    public function type(): string
    {
        return 'provider_stream_event';
    }
}
