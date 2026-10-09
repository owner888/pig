<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/** What `HookRunner::emitBoundary()` settled on — upstream's `BoundaryDispatchResult`. */
final readonly class BoundaryDispatch
{
    /** @param list<SessionBoundaryDraft> $entries */
    public function __construct(
        public array $entries,
        public bool $continue,
        public BoundaryContextPreview $context,
        /** False when the last preview could not be built from the entries: nothing is appended then. */
        public bool $valid,
    ) {
    }
}
