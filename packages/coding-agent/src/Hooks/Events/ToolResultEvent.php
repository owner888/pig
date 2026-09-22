<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A tool has run, and this is what it produced, before the model is shown it.
 *
 * A handler may replace the content, the details, or both. Fired for failures too, with
 * `isError` set and the message as the content, so a hook can watch what goes wrong.
 *
 * Upstream splits this into one interface per tool so that TypeScript can narrow
 * `details` — `isBashToolResult(e)` and its six siblings. Nothing to narrow here:
 * `details` is `mixed` and a handler checks it with `instanceof` when it cares.
 */
final readonly class ToolResultEvent implements HookEvent
{
    /**
     * @param array<string, mixed>      $input
     * @param list<\Pig\Ai\UserContent> $content what the model would be shown
     */
    public function __construct(
        public string $toolName,
        public string $toolCallId,
        public array $input,
        public array $content,
        public mixed $details = null,
        public bool $isError = false,
    ) {
    }

    public function type(): string
    {
        return 'tool_result';
    }
}
