<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;

/**
 * Something a hook put in the conversation.
 *
 * An app message like `BashExecution`: it sits in the transcript in order, and
 * `CodingAgent::toLlm()` turns it into a user message on the way to the model. What a hook
 * gets out of it is a way to tell the model something the model had no way to find out —
 * a build that just failed, a file that changed under it, a rule about this repository.
 *
 * Three fields are the interesting ones, and they are three different audiences:
 *
 * - **`customType`** is the hook's own name for this kind of message. Nothing here reads it;
 *   it is what a hook filters on when it reads its own messages back out of a resumed
 *   session, and what `registerMessageRenderer()` matches to draw one its own way.
 * - **`display`** is whether a person sees it. False for a message meant only for the model —
 *   a hook that injects a reminder before every turn should not fill the screen with it.
 * - **`details`** is the hook's metadata and is never sent to the model. It survives into the
 *   session file, so a renderer or a later run of the same hook can read it.
 *
 * Ported from upstream's `HookMessage` in `core/messages.ts`.
 */
final readonly class HookMessage
{
    public int $timestamp;

    /** @param list<TextContent|ImageContent> $content */
    public function __construct(
        public string $customType,
        public array $content,
        public bool $display = true,
        public mixed $details = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** The text of it, for a transcript or an export. Images are not text and are skipped. */
    public function toText(): string
    {
        $text = '';

        foreach ($this->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** Whether there is anything here for the model at all. */
    public function isEmpty(): bool
    {
        return $this->content === [];
    }
}
