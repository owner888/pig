<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\Http\Request;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A provider answered — status and headers, before the body is read.
 *
 * Upstream's `after_provider_response`. Nothing can be answered: the body is a socket mid-read.
 * What a handler does here is *notice* — a 429's `retry-after`, a quota header, a request id to
 * log — and act on it elsewhere, for instance in `before_retry`.
 *
 * @see BeforeRetryEvent
 */
final readonly class AfterProviderResponseEvent implements HookEvent
{
    /** @param array<string, string> $headers lowercased names */
    public function __construct(
        public int $status,
        public array $headers,
        public Request $request,
    ) {
    }

    public function type(): string
    {
        return 'after_provider_response';
    }
}
