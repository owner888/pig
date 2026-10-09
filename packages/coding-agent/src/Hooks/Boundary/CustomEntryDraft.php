<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/** A note for the extension's own next run, outside the conversation — `$pi->appendEntry()` as a draft. */
final readonly class CustomEntryDraft implements SessionBoundaryDraft
{
    public function __construct(
        public string $customType,
        public mixed $data = null,
    ) {
    }
}
