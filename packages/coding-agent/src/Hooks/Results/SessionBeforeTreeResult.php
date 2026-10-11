<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\Ai\Usage;

/**
 * Whether to go through with the jump, and what to say about the branch being left —
 * upstream's `SessionBeforeTreeResult`.
 *
 * A handler that supplies `summary` has written the handover itself, and the model is not
 * asked — when a summary was asked for at all. `customInstructions`, `replaceInstructions` and
 * `label` override what the default summariser is given and what the entry is labelled; null
 * leaves each as it was.
 */
final readonly class SessionBeforeTreeResult
{
    /**
     * @param array{summary: string, details?: array{readFiles?: list<string>, modifiedFiles?: list<string>}|null, usage?: Usage|null}|null $summary
     *        upstream's `{summary, details?, usage?}`. The entry's file lists are read from
     *        `details['readFiles']` and `details['modifiedFiles']`; other keys of `details` are not
     *        stored, as pig's branch summary entry has nowhere for them yet
     */
    public function __construct(
        public bool $cancel = false,
        public ?array $summary = null,
        public ?string $customInstructions = null,
        public ?bool $replaceInstructions = null,
        public ?string $label = null,
    ) {
    }
}
