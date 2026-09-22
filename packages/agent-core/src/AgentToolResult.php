<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * What a tool produced.
 *
 * Two audiences, deliberately separate: `content` goes to the model, `details` goes to
 * the UI. A file edit sends the model "ok, 3 lines changed" and hands the UI the diff.
 */
final readonly class AgentToolResult
{
    /**
     * @param list<\Pig\Ai\UserContent> $content what the model is shown
     * @param mixed                     $details what the UI is shown, and never the model
     */
    public function __construct(
        public array $content,
        public mixed $details = null,
    ) {
    }
}
