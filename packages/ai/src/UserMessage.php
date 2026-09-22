<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A turn from the person: text, optionally with images.
 *
 * One arm of the Message union — see Context for the alias.
 */
final readonly class UserMessage
{
    /** @var list<UserContent> */
    public array $content;

    public int $timestamp;

    /**
     * Upstream types this as `string | (TextContent | ImageContent)[]` and leaves the
     * union for every consumer to unpack. A bare string is wrapped here instead, so
     * downstream only ever sees a list.
     *
     * @param string|list<UserContent> $content
     */
    public function __construct(string|array $content, ?int $timestamp = null)
    {
        $this->content = is_string($content) ? [new TextContent($content)] : $content;
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
