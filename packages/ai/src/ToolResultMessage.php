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
     * `$isError` comes before `$details` and has a default, where upstream declares `details?`
     * first and requires `isError`. Every construction in this tree passes it, and the order is
     * the useful one — a caller almost always knows whether the tool failed and often has no
     * details — but the default is worth knowing about: forgetting it reads as success.
     *
     * @param list<UserContent> $content what the model is shown
     * @param mixed             $details what the UI is shown — a diff, a file listing,
     *        command output; never sent to the model
     * @param ?Usage            $usage what the tool itself spent on models while it ran;
     *        "not part of main LLM context accounting", but part of the session's bill
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public bool $isError = false,
        public mixed $details = null,
        ?int $timestamp = null,
        public ?Usage $usage = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
