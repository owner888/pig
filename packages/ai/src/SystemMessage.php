<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * System instructions and tool declarations at one point in the transcript — upstream's
 * `SystemMessage`.
 *
 * The leading system message is the system prompt. Later system messages change it: `content`
 * adds instructions from that point on, `sections` replace or remove named prompt sections, and
 * `toolsAdded`/`toolsRemoved` change the tool set. Replaying every system message in order yields
 * the current prompt and tools (`Utils\Transcript::getCurrentSystemMessage()`). Providers that
 * accept system messages mid-conversation send each one in place; other providers rebuild the
 * leading system message from the replayed state.
 *
 * One arm of the Message union — see Context for the alias.
 */
final readonly class SystemMessage
{
    public int $timestamp;

    /**
     * Unlike `UserMessage`, a string `content` is kept a string: upstream's type is
     * `string | TextContent[]` and the session file writes whichever it was, so the leading
     * message's `"content": ""` reads back as it was written.
     *
     * @param string|list<TextContent> $content instruction text. On the leading message this is the
     *        base prompt; later, additional instructions.
     * @param array<string, string|null>|null $sections named, ordered prompt sections rendered
     *        verbatim after `content`. The leading message declares them; later messages replace
     *        sections by name, and null removes one. Keep each section self-delimiting (a tag, a
     *        heading) so the model can relate an update to the original. Avoid integer-like names:
     *        a PHP array turns `"1"` into the key `1`, as a JSON object reorders them upstream.
     * @param list<Tool>|null $toolsAdded complete definitions of tools that become available here
     * @param list<ToolReference>|null $toolsRemoved tools that stop being available here
     * @param int|null $timestamp Unix milliseconds; upstream's `createInitialSystemMessage()`
     *        stamps the leading message 0, so 0 is kept rather than replaced with now
     */
    public function __construct(
        public string|array $content = '',
        public ?array $sections = null,
        public ?array $toolsAdded = null,
        public ?array $toolsRemoved = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
