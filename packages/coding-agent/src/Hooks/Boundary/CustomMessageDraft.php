<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/** A message for the model, as `$pi->sendMessage()` sends one, appended at the boundary. */
final readonly class CustomMessageDraft implements SessionBoundaryDraft
{
    /** @param string|list<\Pig\Ai\Content> $content */
    public function __construct(
        public string $customType,
        public string|array $content,
        public bool $display = true,
        public mixed $details = null,
    ) {
    }
}
