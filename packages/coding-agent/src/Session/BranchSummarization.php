<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * Turning an abandoned branch into a page of notes.
 *
 * Pure logic, the way `Compaction` is: what to send, in what order, under what budget.
 * `AgentSession::goTo()` makes the model call.
 *
 * Almost everything is shared with compaction and is reached for rather than repeated —
 * `Compaction::estimateTokens()`, `serialize()`, `files()` and `SYSTEM_PROMPT` are all the
 * same questions about a different span of messages. What is different is the prompt (this
 * one asks for a handover, not a recap) and the direction of the walk.
 *
 * Ported from upstream's `core/compaction/branch-summarization.ts`.
 */
final class BranchSummarization
{
    /** What to leave for the prompt and the answer. Upstream's default. */
    public const int RESERVE_TOKENS = 16_384;

    /** How much of the answer to allow. Upstream's `maxTokens: 2048`. */
    public const int MAX_TOKENS = 2_048;

    /**
     * What the model is told before the summary, when it reads one back.
     *
     * Upstream's wording. It matters that this says *the user* went somewhere else: without
     * it a model reads the summary as its own last turn and carries on from work that is
     * not on this branch.
     */
    public const string PREAMBLE = <<<'TEXT'
        The user explored a different conversation branch before returning here.
        Summary of that exploration:


        TEXT;

    /**
     * Upstream's prompt, format and all.
     *
     * A handover rather than a recap: what was being attempted, what got done, what is
     * blocked, what to do next. Someone coming back to a branch a day later wants the same
     * thing, which is why the sections are the ones they are.
     */
    public const string PROMPT = <<<'TEXT'
        Create a structured summary of this conversation branch for context when returning later.

        Use this EXACT format:

        ## Goal
        [What was the user trying to accomplish in this branch?]

        ## Constraints & Preferences
        - [Any constraints, preferences, or requirements mentioned]
        - [Or "(none)" if none were mentioned]

        ## Progress
        ### Done
        - [x] [Completed tasks/changes]

        ### In Progress
        - [ ] [Work that was started but not finished]

        ### Blocked
        - [Issues preventing progress, if any]

        ## Key Decisions
        - **[Decision]**: [Brief rationale]

        ## Next Steps
        1. [What should happen next to continue this work]

        Keep each section concise. Preserve exact file paths, function names, and error messages.
        TEXT;

    /**
     * As much of the branch as fits, newest first, plus what it did to which files.
     *
     * **Two passes, and the first one is the point.** File operations are collected from
     * *every* message, including the ones the budget leaves out, because "which files did
     * this branch touch" has to be complete — a summary that forgets a file it edited sends
     * whoever reads it to a file on disk that no longer matches. The prose can be partial;
     * the file lists cannot.
     *
     * The second pass walks newest to oldest so a branch too long to fit keeps its recent
     * end, which is the part that says where the work got to.
     *
     * @param list<mixed> $messages in the order they were said
     * @param int         $budget   0 for no limit
     * @return array{0: list<mixed>, 1: list<string>, 2: list<string>} what to send, read, modified
     */
    public static function prepare(array $messages, int $budget = 0): array
    {
        [$read, $modified] = Compaction::files($messages);

        if ($budget <= 0) {
            return [$messages, $read, $modified];
        }

        $kept = [];
        $tokens = 0;

        foreach (array_reverse($messages) as $message) {
            // A tool result's context is in the assistant message that asked for it, and
            // results are the bulk of a long branch. Upstream drops them here too.
            if ($message instanceof \Pig\Ai\ToolResultMessage) {
                continue;
            }

            $cost = Compaction::estimateTokens($message);

            if ($tokens + $cost > $budget) {
                // An earlier summary is worth squeezing in past the budget: it stands for
                // everything before it, so dropping it loses the most per token.
                if (self::isSummary($message) && $tokens < (int) ($budget * 0.9)) {
                    array_unshift($kept, $message);
                }

                break;
            }

            array_unshift($kept, $message);
            $tokens += $cost;
        }

        return [$kept, $read, $modified];
    }

    /**
     * What the model is asked, with the branch flattened into it.
     *
     * @param list<mixed> $messages
     */
    public static function request(array $messages, ?string $instructions = null): string
    {
        $conversation = Compaction::serialize($messages);
        $asked = $instructions === null || trim($instructions) === '' ? self::PROMPT : $instructions;

        return "<conversation>\n{$conversation}\n</conversation>\n\n{$asked}";
    }

    /** The budget for a model, or 0 when it does not say how big its window is. */
    public static function budget(int $contextWindow, int $reserve = self::RESERVE_TOKENS): int
    {
        return $contextWindow <= 0 ? 0 : max(0, $contextWindow - $reserve);
    }

    private static function isSummary(mixed $message): bool
    {
        return $message instanceof CompactionSummary || $message instanceof BranchSummary;
    }
}
