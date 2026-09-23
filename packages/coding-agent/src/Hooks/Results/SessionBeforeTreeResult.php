<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * Whether to go through with the jump, and what to say about the branch being left.
 *
 * A handler that supplies `summary` has written the handover itself, and the model is not
 * asked for one. The file lists that go with it are still pig's own reading of the branch:
 * the prose is the hook's, and which files were touched is not its to get wrong.
 */
final readonly class SessionBeforeTreeResult
{
    public function __construct(
        public bool $cancel = false,
        public ?string $summary = null,
    ) {
    }
}
