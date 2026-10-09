<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/**
 * What the next request would be made of, with the drafts so far applied — upstream's
 * `BoundaryContextPreview`. Built again after every handler, so each one sees the last one's work.
 *
 * `canContinue` is whether a request could be sent at all: there is something besides the system
 * prompt, and it does not end on the assistant's own turn — or something is queued to go after it.
 */
final readonly class BoundaryContextPreview
{
    /**
     * @param list<array{id: string, message: mixed, branches: int, label: string|null}> $contextEntries
     * @param list<mixed>            $contextMessages the conversation as the session would hold it
     * @param list<\Pig\Ai\Message>  $llmMessages     the same, as the model would be sent it
     * @param list<mixed>            $pendingMessages what is queued for after the run
     */
    public function __construct(
        public array $contextEntries,
        public array $contextMessages,
        public array $llmMessages,
        public array $pendingMessages,
        public bool $canContinue,
    ) {
    }
}
