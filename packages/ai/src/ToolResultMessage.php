<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What a tool returned, addressed back to the call that asked for it.
 *
 * One arm of the Message union — see Context for the alias.
 */
final readonly class ToolResultMessage
{
    public int $timestamp;

    /**
     * @param list<UserContent> $content what the model is shown
     * @param mixed             $details what the UI is shown — a diff, a file listing,
     *        command output; never sent to the model
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public bool $isError = false,
        public mixed $details = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
