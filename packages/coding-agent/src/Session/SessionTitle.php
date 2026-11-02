<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Ai\UserMessage;

/**
 * pig's own, not upstream's: the name a session gets by itself, as Claude Code's do — what the
 * first exchange is about, in the language the person writes in, for the footer, the session
 * list, the Web UI's tabs and the terminal's title.
 *
 * Asked once per session, of the session's model, after the first prompt settles and only while
 * the session has no name; a name given with `/name` (or a scheduled task's) is never replaced.
 * The question is the first message and the first answer, each cut to 300 characters, and the
 * answer a few words, so it costs a few hundred tokens once. This was the developer's
 * `smart-session` extension; `autoSessionName.enabled` is the key it read.
 */
final class SessionTitle
{
    private const int EXCERPT = 300;

    /** Longer than this is not a title but the model talking. */
    private const int MAX_LENGTH = 60;

    /**
     * The first thing the person asked and the first thing said back, or null before there is one.
     *
     * @param list<mixed> $messages
     */
    public static function excerpt(array $messages): ?string
    {
        $user = null;

        foreach ($messages as $message) {
            if ($user === null && $message instanceof UserMessage) {
                $text = self::text($message->content);
                $user = $text !== '' ? $text : null;
            } elseif ($user !== null && $message instanceof AssistantMessage) {
                $text = self::text($message->content);
                if ($text !== '') {
                    return 'User: ' . self::cut($user) . "\nAssistant: " . self::cut($text);
                }
            }
        }

        return $user !== null ? 'User: ' . self::cut($user) : null;
    }

    public static function prompt(string $excerpt): string
    {
        return "Name this conversation for a tab and a session list.\n\n"
            . "<conversation>\n{$excerpt}\n</conversation>\n\n"
            . "Write the title in the language the user writes in: 3 to 8 words, or 4 to 12 characters "
            . "in Chinese or Japanese, saying what the task or question is (\"Fix login 500 error\", "
            . "\"修改默认模型为 Gemini\"). Reply with the title alone: no quotes, no closing punctuation, "
            . "no \"Title:\".";
    }

    /** What the model said, made a title — or null when it is not one. */
    public static function clean(string $answer): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $answer));
        $edges = '/^[#*\s`\'"“‘《「]+|[#*\s`\'"”’》」.:;!?。，！？…]+$/u';
        $title = (string) preg_replace($edges, '', $title);
        $title = (string) preg_replace('/^(?:标题|会话标题|主题|Session title|Title)\s*[:：]\s*/iu', '', $title);
        $title = trim((string) preg_replace($edges, '', $title));

        $length = mb_strlen($title);

        return $length >= 2 && $length <= self::MAX_LENGTH ? $title : null;
    }

    /** @param list<mixed>|string $content */
    private static function text(array|string $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        $parts = [];
        foreach ($content as $part) {
            if ($part instanceof TextContent) {
                $parts[] = $part->text;
            }
        }

        return trim(implode(' ', $parts));
    }

    private static function cut(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT) . '…' : $text;
    }
}
