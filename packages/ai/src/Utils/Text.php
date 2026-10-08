<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;

/** Upstream's `utils/text.ts`. */
final class Text
{
    /**
     * Extract and join text from message content — upstream's `contentText()`.
     *
     * @param string|list<mixed> $content
     */
    public static function contentText(string|array $content, string $separator = "\n"): string
    {
        if (is_string($content)) {
            return $content;
        }

        $texts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $texts[] = $block->text;
            }
        }

        return implode($separator, $texts);
    }

    /** Render a system message as a complete prompt: its content followed by its sections. Upstream's `getSystemMessageText()`. */
    public static function getSystemMessageText(SystemMessage $message): string
    {
        $parts = [self::contentText($message->content)];

        foreach ($message->sections ?? [] as $text) {
            if ($text !== null) {
                $parts[] = $text;
            }
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Render a later system message for APIs that accept system messages mid-conversation —
     * upstream's `renderSystemMessageUpdate()`. Section changes are framed by name so the model can
     * relate them to the leading prompt. This framing is request-time only and may change between
     * versions.
     */
    public static function renderSystemMessageUpdate(SystemMessage $message): string
    {
        $parts = [];
        $text = self::contentText($message->content);

        if ($text !== '') {
            $parts[] = $text;
        }

        foreach ($message->sections ?? [] as $name => $value) {
            $parts[] = $value === null
                ? "Removed system prompt section \"{$name}\"."
                : "Updated system prompt section \"{$name}\":\n\n{$value}";
        }

        return implode("\n\n", $parts);
    }
}
