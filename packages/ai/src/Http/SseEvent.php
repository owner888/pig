<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

/** One dispatched server-sent event. */
final readonly class SseEvent
{
    /**
     * @param string      $type  the `event:` field, or "message" when none was given
     * @param string      $data  the `data:` lines joined with newlines, trailing one removed
     * @param string|null $id    last `id:` seen — persists across events, per the spec
     * @param int|null    $retry last `retry:` seen, in milliseconds
     * @param list<string> $raw  every line that went into this event, comments included, as
     *        received — upstream's Anthropic decoder keeps them (`ServerSentEvent.raw`) to quote in
     *        the error for an event it cannot parse
     */
    public function __construct(
        public string $type,
        public string $data,
        public ?string $id = null,
        public ?int $retry = null,
        public array $raw = [],
    ) {
    }
}
