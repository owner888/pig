<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Asked before a project's own `.pig/` is loaded, of the extensions that are already loaded —
 * the person's and the command line's. The first handler that answers yes or no decides, and
 * wins over a saved decision and the prompt; `undecided` falls through to the next handler.
 *
 * Upstream's `ProjectTrustEvent`. An extension in the project itself cannot hear this, because
 * whether to load it is the question.
 */
final readonly class ProjectTrustEvent implements HookEvent
{
    public function __construct(
        public string $cwd,
    ) {
    }

    public function type(): string
    {
        return 'project_trust';
    }
}
