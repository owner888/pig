<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Usage;

/**
 * What a tool produced.
 *
 * Two audiences, deliberately separate: `content` goes to the model, `details` goes to
 * the UI. A file edit sends the model "ok, 3 lines changed" and hands the UI the diff.
 *
 * `usage` is a third thing: what the tool itself spent on models while it ran (a codemode
 * script's classifier calls). It goes onto the tool result message, where the session's bill
 * counts it, and is "not part of main LLM context accounting" — upstream's
 * `AgentToolResult.usage`.
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
        public ?Usage $usage = null,
    ) {
    }
}
