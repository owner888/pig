<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Utils\Text;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CodingAgent;

/**
 * Turning an abandoned branch into a page of notes.
 *
 * Ported from upstream's `core/compaction/branch-summarization.ts`, with its names:
 * `collectEntriesForBranchSummary()` finds what a move leaves behind, `prepareBranchEntries()`
 * fits it to a budget, and `generateBranchSummary()` asks the model. `AgentSession::goTo()` runs
 * the three, and an extension can run them itself.
 *
 * What is shared with compaction is reached for rather than repeated —
 * `Compaction::estimateTokens()`, `serializeConversation()`, the file operations,
 * `completeSummarization()` and `SUMMARIZATION_SYSTEM_PROMPT`. What is different is the prompt
 * (this one asks for a handover, not a recap) and the direction of the walk.
 */
final class BranchSummarization
{
    /** What to leave for the prompt and the answer. Upstream's default. */
    public const int RESERVE_TOKENS = 16_384;

    /** How much of the answer to allow, before the model's own cap. Upstream's `Math.min(4096, model.maxTokens)`. */
    public const int MAX_TOKENS = 4_096;

    /** The window assumed for a model that states none — upstream's `model.contextWindow || 128000`. */
    private const int DEFAULT_CONTEXT_WINDOW = 128_000;

    /** Upstream's `BRANCH_SUMMARY_PREAMBLE`, put in front of the model's summary. */
    public const string PREAMBLE = "The user explored a different conversation branch before returning here.\nSummary of that exploration:\n\n";

    /**
     * Upstream's `BRANCH_SUMMARY_PROMPT`, format and all.
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
     * What moving from `$oldLeafId` to `$targetId` leaves behind — upstream's
     * `collectEntriesForBranchSummary()`.
     *
     * The deepest entry on both paths is the common ancestor; everything on the old path below it
     * is what is being left, oldest first. Compactions are not a boundary: a summary on the old
     * branch is part of what it was, and its prose becomes context like any other message. A null
     * old leaf leaves nothing; a null target is the empty path, which shares nothing.
     */
    public static function collectEntriesForBranchSummary(SessionManager $session, ?string $oldLeafId, ?string $targetId): CollectEntriesResult
    {
        if ($oldLeafId === null) {
            return new CollectEntriesResult([], null);
        }

        $oldPath = $session->pathEntries($oldLeafId);
        $onOldPath = [];

        foreach ($oldPath as $entry) {
            $onOldPath[$entry->id] = true;
        }

        $commonAncestorId = null;
        $targetPath = $session->pathEntries($targetId);

        for ($index = count($targetPath) - 1; $index >= 0; $index--) {
            if (isset($onOldPath[$targetPath[$index]->id])) {
                $commonAncestorId = $targetPath[$index]->id;

                break;
            }
        }

        $entries = [];

        for ($index = count($oldPath) - 1; $index >= 0 && $oldPath[$index]->id !== $commonAncestorId; $index--) {
            $entries[] = $oldPath[$index];
        }

        return new CollectEntriesResult(array_reverse($entries), $commonAncestorId);
    }

    /**
     * Upstream's `getMessageFromEntry()`: the message an entry contributes to a handover, or null.
     * A tool result contributes nothing — its context is in the assistant message that asked for
     * it, and results are the bulk of a long branch — and neither does anything that is not part
     * of the conversation.
     */
    private static function messageFromEntry(SessionEntry $entry): mixed
    {
        $message = $entry->message;

        if ($message instanceof ToolResultMessage || !SessionManager::isSaid($message)) {
            return null;
        }

        return $message;
    }

    /**
     * As much of the branch as fits, newest first — upstream's `prepareBranchEntries()`.
     *
     * Walks from the newest entry back, adding messages until the next one would pass the budget
     * (0 for none). A summary that does not fit is squeezed in anyway while less than 90% of the
     * budget is used: it stands for everything before it, so dropping it loses the most per token.
     *
     * The file operations are upstream's two passes: the carried-forward lists of every nested
     * branch summary pig wrote (a hook's are its own), then the tool calls of the messages that
     * made it into the budget. So a branch too long to fit reports the files its kept, recent end
     * touched — upstream's behaviour, which its docs describe as "the messages being summarized".
     *
     * @param list<SessionEntry> $entries oldest first
     */
    public static function prepareBranchEntries(array $entries, int $tokenBudget = 0): BranchPreparation
    {
        $messages = [];
        $fileOps = Compaction::createFileOps();
        $totalTokens = 0;

        foreach ($entries as $entry) {
            if ($entry->message instanceof BranchSummary && !$entry->message->fromHook) {
                foreach ($entry->message->readFiles as $path) {
                    $fileOps->addRead($path);
                }

                foreach ($entry->message->modifiedFiles as $path) {
                    $fileOps->addEdited($path);
                }
            }
        }

        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $entry = $entries[$index];
            $message = self::messageFromEntry($entry);

            if ($message === null) {
                continue;
            }

            Compaction::extractFileOpsFromMessage($message, $fileOps);

            $tokens = Compaction::estimateTokens($message);

            if ($tokenBudget > 0 && $totalTokens + $tokens > $tokenBudget) {
                if (($entry->message instanceof CompactionSummary || $entry->message instanceof BranchSummary)
                    && $totalTokens < $tokenBudget * 0.9
                ) {
                    array_unshift($messages, $message);
                    $totalTokens += $tokens;
                }

                break;
            }

            array_unshift($messages, $message);
            $totalTokens += $tokens;
        }

        return new BranchPreparation($messages, $fileOps, $totalTokens);
    }

    /**
     * Summarise abandoned branch entries — upstream's `generateBranchSummary()`.
     *
     * The budget is the model's window (128k when it states none) less `$reserveTokens`.
     * `$customInstructions` are added as "Additional focus", or replace the prompt outright with
     * `$replaceInstructions`. No reasoning is asked for, and the output is capped at 4096 tokens or
     * the model's own cap. The summary comes back with `PREAMBLE` in front; an abort and an error
     * come back as such rather than thrown.
     *
     * @param list<SessionEntry> $entries oldest first
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @param array<string, Closure> $callbacks `Retry::retryAssistantCall()`'s
     */
    public static function generateBranchSummary(
        array $entries,
        Model $model,
        ?string $apiKey = null,
        ?array $headers = null,
        ?array $env = null,
        ?AbortSignal $signal = null,
        ?string $customInstructions = null,
        ?bool $replaceInstructions = null,
        int $reserveTokens = self::RESERVE_TOKENS,
        ?Closure $streamFn = null,
        ?array $retry = null,
        array $callbacks = [],
    ): BranchSummaryResult {
        $contextWindow = $model->contextWindow > 0 ? $model->contextWindow : self::DEFAULT_CONTEXT_WINDOW;
        $preparation = self::prepareBranchEntries($entries, $contextWindow - $reserveTokens);

        if ($preparation->messages === []) {
            return new BranchSummaryResult(summary: 'No content to summarize');
        }

        $conversationText = Compaction::serializeConversation(CodingAgent::toLlm($preparation->messages));

        if ($replaceInstructions === true && $customInstructions !== null && $customInstructions !== '') {
            $instructions = $customInstructions;
        } elseif ($customInstructions !== null && $customInstructions !== '') {
            $instructions = self::PROMPT . "\n\nAdditional focus: {$customInstructions}";
        } else {
            $instructions = self::PROMPT;
        }

        $response = Compaction::completeSummarization(
            $model,
            Compaction::buildSummarizationContext("<conversation>\n{$conversationText}\n</conversation>\n\n{$instructions}"),
            new SimpleStreamOptions(
                maxTokens: $model->maxTokens > 0 ? min(self::MAX_TOKENS, $model->maxTokens) : self::MAX_TOKENS,
                signal: $signal,
                apiKey: $apiKey,
                headers: $headers,
                env: $env,
            ),
            $streamFn,
            $retry,
            $callbacks,
        );

        if ($response->stopReason === StopReason::Aborted) {
            return new BranchSummaryResult(aborted: true);
        }

        $failure = Compaction::getSummarizationFailure($response, 'Branch summarization');

        if ($failure !== null) {
            return new BranchSummaryResult(error: $failure);
        }

        foreach ($response->content as $block) {
            if ($block instanceof ToolCall) {
                return new BranchSummaryResult(error: 'Branch summarization attempted to call a tool');
            }
        }

        $lists = Compaction::computeFileLists($preparation->fileOps);

        return new BranchSummaryResult(
            summary: self::PREAMBLE . Text::contentText($response->content),
            usage: $response->usage,
            readFiles: $lists['readFiles'],
            modifiedFiles: $lists['modifiedFiles'],
        );
    }
}
