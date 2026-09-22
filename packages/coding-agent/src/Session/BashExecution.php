<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * A command someone ran themselves, with `!`.
 *
 * An app message, not an LLM one: it sits in the conversation so the transcript shows
 * what happened in order, and `CodingAgent` turns it into a user message on the way to
 * the model. The point of `!` is that running `git diff` or the tests puts the result in
 * front of the model without anyone copying it there by hand.
 *
 * `!!` runs the same command and does not make one of these, for the times the answer is
 * for the person and not for the model — `ls`, `git log`, checking a path. Context is the
 * scarce thing in a session, and half of what someone types at a shell is not worth any.
 *
 * Ported from upstream's `BashExecutionMessage` in `core/messages.ts`.
 */
final readonly class BashExecution
{
    public int $timestamp;

    public function __construct(
        public string $command,
        public string $output,
        public ?int $exitCode = null,
        public bool $cancelled = false,
        public bool $truncated = false,
        public ?string $spillPath = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /**
     * The same thing, as the model reads it.
     *
     * Fenced, because output is not prose and a model that reads it as prose starts
     * answering the text of an error message rather than the error.
     */
    public function toText(): string
    {
        $text = "Ran `{$this->command}`\n";
        $trimmed = rtrim($this->output, "\n");
        $text .= $trimmed === '' ? '(no output)' : "```\n{$trimmed}\n```";

        if ($this->cancelled) {
            $text .= "\n\n(command cancelled)";
        } elseif ($this->exitCode !== null && $this->exitCode !== 0) {
            $text .= "\n\nCommand exited with code {$this->exitCode}";
        }

        if ($this->truncated && $this->spillPath !== null) {
            $text .= "\n\n[Output truncated. Full output: {$this->spillPath}]";
        }

        return $text;
    }
}
