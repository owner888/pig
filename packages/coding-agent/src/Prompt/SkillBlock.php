<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * Parsed skill invocation block from a user message.
 *
 * Ported from upstream's `core/agent-session.ts` (ParsedSkillBlock / parseSkillBlock).
 * Format: `<skill name="..." location="...">\n...\n</skill>[\n\nuserMessage]`
 */
final readonly class SkillBlock
{
    public function __construct(
        public string $name,
        public string $location,
        public string $content,
        public ?string $userMessage = null,
    ) {
    }

    /**
     * Parse a skill block from message text.
     * Returns null if the text does not match the skill block format.
     */
    public static function parse(string $text): ?self
    {
        $pattern = '/^<skill name="([^"]+)" location="([^"]+)">\n([\s\S]*?)\n<\/skill>(?:\n\n([\s\S]+))?$/';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return new self(
            name: $matches[1],
            location: $matches[2],
            content: $matches[3],
            userMessage: isset($matches[4]) && $matches[4] !== '' ? $matches[4] : null,
        );
    }
}
