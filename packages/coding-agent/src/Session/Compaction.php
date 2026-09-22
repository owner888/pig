<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;

/**
 * Deciding what to throw away, and writing down what it said.
 *
 * Every function here is pure. Running the model and replacing the conversation happen
 * in `AgentSession`; what is in this file is arithmetic and string handling, which is
 * the part worth testing without a provider.
 *
 * Ported from upstream's `core/compaction/`.
 */
final class Compaction
{
    /** Room left for the answer, so compaction happens before the wall rather than at it. */
    public const int RESERVE_TOKENS = 16_384;

    /** Roughly how much of the recent conversation survives untouched. */
    public const int KEEP_RECENT_TOKENS = 20_000;

    /** Four characters a token: conservative, which is the safe direction to be wrong in. */
    private const int CHARS_PER_TOKEN = 4;

    /**
     * What the next request would carry.
     *
     * The provider's own count when it gave one, because an estimate is only ever a
     * guess at what the tokeniser did.
     */
    public static function contextTokens(Usage $usage): int
    {
        return $usage->totalTokens > 0
            ? $usage->totalTokens
            : $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite;
    }

    /** Whether there is no longer room for an answer. */
    public static function shouldCompact(int $contextTokens, int $contextWindow, int $reserve = self::RESERVE_TOKENS): bool
    {
        return $contextWindow > 0 && $contextTokens > $contextWindow - $reserve;
    }

    /**
     * The usage of the last turn that actually completed.
     *
     * An aborted or failed turn reports whatever it had got to, which is not what the
     * next request will carry.
     */
    public static function lastUsage(array $messages): ?Usage
    {
        foreach (array_reverse($messages) as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }

            if (in_array($message->stopReason, [StopReason::Aborted, StopReason::Error], true)) {
                continue;
            }

            return $message->usage;
        }

        return null;
    }

    /** A rough size for one message, in tokens. */
    public static function estimateTokens(mixed $message): int
    {
        $characters = match (true) {
            $message instanceof UserMessage => self::textLength($message->content),
            $message instanceof AssistantMessage => self::assistantLength($message),
            $message instanceof ToolResultMessage => self::textLength($message->content),
            $message instanceof BashExecution => strlen($message->command) + strlen($message->output),
            $message instanceof CompactionSummary => strlen($message->toText()),
            default => 0,
        };

        return (int) ceil($characters / self::CHARS_PER_TOKEN);
    }

    /**
     * The index of the first message to keep.
     *
     * Walks back from the newest, adding up sizes, and stops once `$keepRecent` tokens
     * are accounted for — then cuts at the nearest place it is *allowed* to cut.
     *
     * A tool result is never one of those places. Splitting a tool call from its result
     * produces a request every provider rejects outright, so the cut lands on the
     * message that made the call, and the results come with it.
     *
     * @param list<mixed> $messages
     */
    public static function cutPoint(array $messages, int $keepRecent = self::KEEP_RECENT_TOKENS): int
    {
        $allowed = [];

        foreach ($messages as $index => $message) {
            if (!$message instanceof ToolResultMessage) {
                $allowed[] = $index;
            }
        }

        if ($allowed === []) {
            return 0;
        }

        $accumulated = 0;

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $accumulated += self::estimateTokens($messages[$index]);

            if ($accumulated < $keepRecent) {
                continue;
            }

            foreach ($allowed as $cut) {
                if ($cut >= $index) {
                    return $cut;
                }
            }

            break;
        }

        // Everything fits inside the budget, so there is nothing old enough to drop.
        return 0;
    }

    /**
     * The conversation as text for the summariser to read.
     *
     * Flattened into labelled lines rather than handed over as messages, because a model
     * given a conversation answers it. This is a document about a conversation.
     *
     * @param list<mixed> $messages
     */
    public static function serialize(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $text = self::text($message->content);

                if ($text !== '') {
                    $parts[] = "[User]: {$text}";
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $text = self::text($message->content);

                if ($text !== '') {
                    $parts[] = "[Tool result]: {$text}";
                }

                continue;
            }

            if ($message instanceof BashExecution) {
                $parts[] = "[Ran]: {$message->command}";

                continue;
            }

            if ($message instanceof CompactionSummary) {
                $parts[] = "[Earlier summary]: {$message->summary}";

                continue;
            }

            if ($message instanceof AssistantMessage) {
                foreach (self::assistantParts($message) as $part) {
                    $parts[] = $part;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Which files were touched, and how.
     *
     * A file that was edited is not also "read": what matters to whatever reads this
     * next is whether the copy on disk still matches what it saw.
     *
     * @param list<mixed> $messages
     * @return array{0: list<string>, 1: list<string>} read, then modified
     */
    public static function files(array $messages): array
    {
        $read = $modified = [];

        foreach ($messages as $message) {
            if ($message instanceof CompactionSummary) {
                // An earlier summary's lists carry forward, or a file read before the
                // last compaction disappears from the record entirely.
                $read = [...$read, ...$message->readFiles];
                $modified = [...$modified, ...$message->modifiedFiles];

                continue;
            }

            if (!$message instanceof AssistantMessage) {
                continue;
            }

            foreach ($message->content as $block) {
                if (!$block instanceof ToolCall) {
                    continue;
                }

                $path = $block->arguments['path'] ?? null;

                if (!is_string($path) || $path === '') {
                    continue;
                }

                match ($block->name) {
                    'read' => $read[] = $path,
                    'write', 'edit' => $modified[] = $path,
                    default => null,
                };
            }
        }

        $modified = array_values(array_unique($modified));
        $read = array_values(array_diff(array_unique($read), $modified));

        sort($read);
        sort($modified);

        return [$read, $modified];
    }

    // ---- the prompts ---------------------------------------------------------------------

    public const string SYSTEM_PROMPT = <<<'TEXT'
        You are a context summarization assistant. Your task is to read a conversation between a
        user and an AI coding assistant, then produce a structured summary following the exact
        format specified.

        Do NOT continue the conversation. Do NOT respond to any questions in the conversation.
        ONLY output the structured summary.
        TEXT;

    public const string PROMPT = <<<'TEXT'
        The messages above are a conversation to summarize. Create a structured context
        checkpoint summary that another LLM will use to continue the work.

        Use this EXACT format:

        ## Goal
        [What is the user trying to accomplish? Can be multiple items if the session covers different tasks.]

        ## Constraints & Preferences
        - [Any constraints, preferences, or requirements mentioned by user]
        - [Or "(none)" if none were mentioned]

        ## Progress
        ### Done
        - [x] [Completed tasks/changes]

        ### In Progress
        - [ ] [Current work]

        ### Blocked
        - [Issues preventing progress, if any]

        ## Key Decisions
        - **[Decision]**: [Brief rationale]

        ## Next Steps
        1. [Ordered list of what should happen next]

        ## Critical Context
        - [Any data, examples, or references needed to continue]
        - [Or "(none)" if not applicable]

        Keep each section concise. Preserve exact file paths, function names, and error messages.
        TEXT;

    public const string UPDATE_PROMPT = <<<'TEXT'
        The messages above are NEW conversation messages to incorporate into the existing summary
        provided in <previous-summary> tags.

        Update the existing structured summary with new information. RULES:
        - PRESERVE all existing information from the previous summary
        - ADD new progress, decisions, and context from the new messages
        - UPDATE the Progress section: move items from "In Progress" to "Done" when completed
        - UPDATE "Next Steps" based on what was accomplished
        - PRESERVE exact file paths, function names, and error messages
        - If something is no longer relevant, you may remove it

        Keep the same section headings as the previous summary, and keep each section concise.
        TEXT;

    /**
     * What to ask the model, given what is being summarised.
     *
     * @param list<mixed> $messages
     */
    public static function request(array $messages, ?string $previousSummary = null, ?string $instructions = null): string
    {
        $prompt = $previousSummary !== null && trim($previousSummary) !== '' ? self::UPDATE_PROMPT : self::PROMPT;

        if ($instructions !== null && trim($instructions) !== '') {
            $prompt .= "\n\nAdditional focus: " . trim($instructions);
        }

        $request = "<conversation>\n" . self::serialize($messages) . "\n</conversation>\n\n";

        if ($previousSummary !== null && trim($previousSummary) !== '') {
            $request .= "<previous-summary>\n{$previousSummary}\n</previous-summary>\n\n";
        }

        return $request . $prompt;
    }

    /**
     * The newest summary in the conversation, which the next one updates rather than replaces.
     *
     * @param list<mixed> $messages
     */
    public static function previousSummary(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof CompactionSummary) {
                return $message->summary;
            }
        }

        return null;
    }

    // ---- measuring -------------------------------------------------------------------------

    /** @param list<mixed> $content */
    private static function text(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    /** @param list<mixed> $content */
    private static function textLength(array $content): int
    {
        $length = 0;

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $length += strlen($block->text);
            }
        }

        return $length;
    }

    private static function assistantLength(AssistantMessage $message): int
    {
        $length = 0;

        foreach ($message->content as $block) {
            $length += match (true) {
                $block instanceof TextContent => strlen($block->text),
                $block instanceof ThinkingContent => strlen($block->thinking),
                $block instanceof ToolCall => strlen($block->name) + strlen((string) json_encode($block->arguments)),
                default => 0,
            };
        }

        return $length;
    }

    /** @return list<string> */
    private static function assistantParts(AssistantMessage $message): array
    {
        $thinking = $text = $calls = [];

        foreach ($message->content as $block) {
            match (true) {
                $block instanceof ThinkingContent => $thinking[] = $block->thinking,
                $block instanceof TextContent => $text[] = $block->text,
                $block instanceof ToolCall => $calls[] = $block->name . '(' . self::arguments($block->arguments) . ')',
                default => null,
            };
        }

        $parts = [];

        if ($thinking !== []) {
            $parts[] = '[Assistant thinking]: ' . implode("\n", $thinking);
        }

        if ($text !== []) {
            $parts[] = '[Assistant]: ' . implode("\n", $text);
        }

        if ($calls !== []) {
            $parts[] = '[Assistant tool calls]: ' . implode('; ', $calls);
        }

        return $parts;
    }

    /** @param array<string, mixed> $arguments */
    private static function arguments(array $arguments): string
    {
        $pairs = [];

        foreach ($arguments as $name => $value) {
            $pairs[] = $name . '=' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode(', ', $pairs);
    }
}
