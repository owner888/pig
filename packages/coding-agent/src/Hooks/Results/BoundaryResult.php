<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\CodingAgent\Hooks\Boundary\SessionBoundaryDraft;

/**
 * A boundary handler's answer — upstream's `BoundaryResult`. Either field left null leaves what
 * the handlers before it settled on; `entries` **replaces** the list, so a handler that wants to
 * add to it copies the event's `entries` first.
 */
final readonly class BoundaryResult
{
    /** @param list<SessionBoundaryDraft>|null $entries */
    public function __construct(
        public ?array $entries = null,
        public ?bool $continue = null,
    ) {
    }
}
