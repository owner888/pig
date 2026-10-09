<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/** What the model is shown of an earlier entry: null omits it, a string or content list replaces it. */
final readonly class ContextEditDraft implements SessionBoundaryDraft
{
    /** @param string|list<\Pig\Ai\Content>|null $replacement */
    public function __construct(
        public string $targetId,
        public string|array|null $replacement = null,
    ) {
    }
}
