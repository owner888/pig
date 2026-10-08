<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Upstream's `before_provider_headers`: "Fired after request headers are assembled, before the
 * provider HTTP call. Handlers mutate `headers` in place (e.g. to inject tracing/session headers);
 * the return value is ignored. A `null` value deletes that header."
 *
 * Not readonly for that reason: every handler is given the same event, and what is left in
 * `$headers` after the last one is what the request carries as its `headers` option.
 */
final class BeforeProviderHeadersEvent implements HookEvent
{
    /** @param array<string, string|null> $headers */
    public function __construct(public array $headers)
    {
    }

    public function type(): string
    {
        return 'before_provider_headers';
    }
}
