<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Cost;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\Retry;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Uuid;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CodingAgent;

/**
 * Deciding what to throw away, and writing down what it said.
 *
 * Ported from upstream's `core/compaction/compaction.ts` and `core/compaction/utils.ts`, with
 * upstream's names, so an extension can do what upstream's can: read the `CompactionPreparation`
 * a `session_before_compact` handler is given, call `serializeConversation()` on its messages,
 * ask `generateSummary()` or `compact()` for pig's own summary, and answer with the
 * `CompactionResult`.
 *
 * Everything that decides is pure — the cut, the preparation, the serialisation, the file lists.
 * The functions that ask a model (`completeSummarization()` and the three built on it) take the
 * model, its key and the stream function as arguments, as upstream's do; `AgentSession` passes
 * its own, so a compaction's request carries the session's request options and its retries are
 * announced (`SummarizationRetryEvent`).
 */
final class Compaction
{
    /** Room left for the answer, so compaction happens before the wall rather than at it. */
    public const int RESERVE_TOKENS = 16_384;

    /** Roughly how much of the recent conversation survives untouched. */
    public const int KEEP_RECENT_TOKENS = 20_000;

    /**
     * Upstream's `TOOL_RESULT_MAX_CHARS`: how much of one tool result the summariser is shown.
     * "Full content is not needed for summarization", and tool results — a `read`, a `bash` — are
     * what makes a conversation big in the first place.
     */
    public const int TOOL_RESULT_MAX_CHARS = 2_000;

    /** Four characters a token: conservative, which is the safe direction to be wrong in. */
    private const int CHARS_PER_TOKEN = 4;

    /**
     * What one image is worth, as characters, so the same `/ 4` turns it into 1200 tokens.
     *
     * Upstream's `ESTIMATED_IMAGE_CHARS`, which its `estimateTextAndImageContentChars()` now adds
     * for an image in a user message, a tool result or a custom message alike.
     */
    private const int CHARS_PER_IMAGE = 4_800;

    // ---- tokens ----------------------------------------------------------------------------

    /**
     * What the next request would carry — upstream's `calculateContextTokens()`.
     *
     * The provider's own count when it gave one, because an estimate is only ever a
     * guess at what the tokeniser did.
     */
    public static function calculateContextTokens(Usage $usage): int
    {
        return $usage->totalTokens > 0
            ? $usage->totalTokens
            : $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite;
    }

    /**
     * Whether there is no longer room for an answer — upstream's `shouldCompact()`.
     *
     * A model that states no window is never compacted for: there is no wall to stay away from.
     */
    public static function shouldCompact(int $contextTokens, int $contextWindow, CompactionSettings $settings): bool
    {
        if (!$settings->enabled) {
            return false;
        }

        return $contextWindow > 0 && $contextTokens > $contextWindow - $settings->reserveTokens;
    }

    /**
     * The usage of the last turn that actually completed.
     *
     * An aborted or failed turn reports whatever it had got to, which is not what the
     * next request will carry.
     *
     * Nor is a reading taken before a compaction. The messages kept across the cut keep
     * their usage, and that usage measured a request that no longer exists — believing
     * it means the session asks to be compacted again before every turn, for ever.
     * Compare timestamps rather than positions: the summary sits in front of the
     * messages it is newer than.
     *
     * pig's own, for the footer and the threshold check; `estimateProjectedContextTokens()` is
     * upstream's answer to the same staleness for a compaction's `tokensBefore`.
     *
     * @param list<mixed> $messages
     */
    public static function lastUsage(array $messages): ?Usage
    {
        $compactedAt = 0;

        foreach ($messages as $message) {
            if ($message instanceof CompactionSummary && $message->timestamp > $compactedAt) {
                $compactedAt = $message->timestamp;
            }
        }

        foreach (array_reverse($messages) as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }

            if (in_array($message->stopReason, [StopReason::Aborted, StopReason::Error], true)) {
                continue;
            }

            // `<=`, not `<`: the summary is written straight after the turn it replaces,
            // and a millisecond is wide enough to hold both. Discarding a good reading
            // costs one turn without one; keeping a stale one costs every turn after it.
            return $message->timestamp <= $compactedAt ? null : $message->usage;
        }

        return null;
    }

    /**
     * Upstream's `getAssistantUsage()`: an assistant message's usage, unless it was aborted, failed,
     * or reported nothing at all.
     */
    private static function assistantUsage(mixed $message): ?Usage
    {
        if (!$message instanceof AssistantMessage
            || $message->stopReason === StopReason::Aborted
            || $message->stopReason === StopReason::Error
            || self::calculateContextTokens($message->usage) <= 0
        ) {
            return null;
        }

        return $message->usage;
    }

    /**
     * The last usable assistant usage on a branch — upstream's `getLastAssistantUsage()`.
     *
     * @param list<SessionEntry> $entries
     */
    public static function getLastAssistantUsage(array $entries): ?Usage
    {
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $usage = self::assistantUsage($entries[$index]->message);

            if ($usage !== null) {
                return $usage;
            }
        }

        return null;
    }

    /**
     * The context's size from the last usable assistant usage plus an estimate of what came after
     * it — upstream's `estimateContextTokens()`.
     *
     * @param list<mixed> $messages
     */
    public static function estimateContextTokens(array $messages): ContextUsageEstimate
    {
        $usageIndex = null;
        $usage = null;

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $usage = self::assistantUsage($messages[$index]);

            if ($usage !== null) {
                $usageIndex = $index;

                break;
            }
        }

        if ($usage === null || $usageIndex === null) {
            $estimated = 0;

            foreach ($messages as $message) {
                $estimated += self::estimateTokens($message);
            }

            return new ContextUsageEstimate($estimated, 0, $estimated, null);
        }

        $usageTokens = self::calculateContextTokens($usage);
        $trailingTokens = 0;

        for ($index = $usageIndex + 1; $index < count($messages); $index++) {
            $trailingTokens += self::estimateTokens($messages[$index]);
        }

        return new ContextUsageEstimate($usageTokens + $trailingTokens, $usageTokens, $trailingTokens, $usageIndex);
    }

    /**
     * The projected context's size, without trusting a usage that was measured before a later
     * edit or compaction — upstream's `estimateProjectedContextTokens()`.
     *
     * A provider's count is believed only when the entry it came from is newer than the newest
     * `context_edit` or compaction on the branch; otherwise everything is estimated, the current
     * system message counted once.
     *
     * @param list<ProjectedSessionEntry> $projection
     * @param list<SessionEntry>          $branchEntries
     */
    public static function estimateProjectedContextTokens(array $projection, array $branchEntries): ContextUsageEstimate
    {
        $messages = self::projectedMessages($projection);
        $estimate = self::estimateContextTokens($messages);

        if ($estimate->lastUsageIndex !== null) {
            $projectedMessageIndex = 0;
            $usageEntryId = null;

            foreach ($projection as $entry) {
                $nextMessageIndex = $projectedMessageIndex + count($entry->messages);

                if ($estimate->lastUsageIndex < $nextMessageIndex) {
                    $usageEntryId = $entry->sourceEntry->id;

                    break;
                }

                $projectedMessageIndex = $nextMessageIndex;
            }

            $usageEntryIndex = -1;

            foreach ($branchEntries as $index => $entry) {
                if ($usageEntryId !== null && $entry->id === $usageEntryId) {
                    $usageEntryIndex = $index;

                    break;
                }
            }

            $latestInvalidatingEntryIndex = -1;

            for ($index = count($branchEntries) - 1; $index >= 0; $index--) {
                $message = $branchEntries[$index]->message;

                if ($message instanceof ContextEdit || $message instanceof CompactionSummary) {
                    $latestInvalidatingEntryIndex = $index;

                    break;
                }
            }

            if ($usageEntryIndex > $latestInvalidatingEntryIndex) {
                return $estimate;
            }
        }

        $currentSystem = Transcript::getCurrentSystemMessage($messages);
        $tokens = $currentSystem === null ? 0 : self::estimateTokens($currentSystem);

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                $tokens += self::estimateTokens($message);
            }
        }

        return new ContextUsageEstimate($tokens, 0, $tokens, null);
    }

    /** A rough size for one message, in tokens — upstream's `estimateTokens()`. */
    public static function estimateTokens(mixed $message): int
    {
        $characters = match (true) {
            // Upstream's `system` arm: the content, every section that is not a removal, and the
            // JSON of the tools it adds.
            $message instanceof SystemMessage => (is_string($message->content) ? strlen($message->content) : self::textLength($message->content))
                + array_sum(array_map(static fn (?string $section): int => $section === null ? 0 : strlen($section), $message->sections ?? []))
                + ($message->toolsAdded === null ? 0 : strlen((string) json_encode(array_map(MessageJson::encodeTool(...), $message->toolsAdded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))),
            $message instanceof UserMessage => self::textLength($message->content),
            $message instanceof AssistantMessage => self::assistantLength($message),
            $message instanceof ToolResultMessage => self::textLength($message->content),
            $message instanceof BashExecution => strlen($message->command) + strlen($message->output),
            // Both summaries, and by what they *become*: `CodingAgent::toLlm()` turns each into a
            // user message built from `toText()`, so that is the string the request will carry.
            // Upstream measures `.summary` alone, which is a little short of what it sends.
            //
            // `BranchSummary` was missing from this list, and a summary of an abandoned branch is
            // long — a 3,900-character one estimated as **zero tokens**. See docs/dev/traps/session.md.
            $message instanceof CompactionSummary => strlen($message->toText()),
            $message instanceof BranchSummary => strlen($message->toText()),
            $message instanceof HookMessage => strlen($message->toText()),
            default => 0,
        };

        return (int) ceil($characters / self::CHARS_PER_TOKEN);
    }

    // ---- the cut ---------------------------------------------------------------------------

    /**
     * What one session entry contributes to the context, edits aside — upstream's
     * `sessionEntryToContextMessages()`.
     *
     * A compaction is its replayed system message and its summary; a branch summary with no
     * prose is nothing; an entry that is not part of the conversation is nothing.
     *
     * @return list<mixed>
     */
    public static function entryMessages(SessionEntry $entry): array
    {
        $message = $entry->message;

        if ($message instanceof CompactionSummary) {
            return $message->systemMessage === null ? [$message] : [$message->systemMessage, $message];
        }

        if ($message instanceof BranchSummary && $message->summary === '') {
            return [];
        }

        return SessionManager::isSaid($message) ? [$message] : [];
    }

    /**
     * Upstream's `isCutPointMessage()`: somewhere a kept part may start. Never a tool result, which
     * must follow the call it answers, and never a system message, which is prompt state the
     * compaction carries itself.
     */
    private static function isCutPointMessage(mixed $message): bool
    {
        return $message instanceof UserMessage
            || $message instanceof AssistantMessage
            || $message instanceof BashExecution
            || $message instanceof HookMessage
            || $message instanceof BranchSummary
            || $message instanceof CompactionSummary;
    }

    /** Upstream's `isTurnStartMessage()`: the kinds of message a turn begins with. */
    private static function isTurnStartMessage(mixed $message): bool
    {
        return $message instanceof UserMessage
            || $message instanceof BashExecution
            || $message instanceof HookMessage
            || $message instanceof BranchSummary
            || $message instanceof CompactionSummary;
    }

    private static function isTurnStartEntry(SessionEntry $entry): bool
    {
        if ($entry->message instanceof CompactionSummary) {
            return false;
        }

        foreach (self::entryMessages($entry) as $message) {
            if (self::isTurnStartMessage($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry that starts the turn containing $entryIndex, walking back no further than
     * $startIndex; -1 when there is none — upstream's `findTurnStartIndex()`.
     *
     * @param list<SessionEntry> $entries
     */
    public static function findTurnStartIndex(array $entries, int $entryIndex, int $startIndex): int
    {
        for ($index = $entryIndex; $index >= $startIndex; $index--) {
            if (self::isTurnStartEntry($entries[$index])) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Where to cut so that roughly `$keepRecentTokens` of the newest conversation is kept —
     * upstream's `findCutPoint()`, over raw session entries between `$startIndex` and `$endIndex`
     * (exclusive).
     *
     * Walks back from the newest, adding up sizes, and stops once the budget is accounted for —
     * then cuts at the nearest place it is *allowed* to cut at or after that entry. A tool result
     * is never one of those places: splitting a call from its result produces a request every
     * provider rejects, so the cut lands on the message that made the call and the results come
     * with it. Context-invisible entries just before the cut (a model change, a label) are kept
     * with it.
     *
     * A cut that lands on an assistant message is inside a turn — `isSplitTurn`, with the turn's
     * start in `turnStartIndex` — and `compact()` summarises that turn's beginning separately.
     *
     * @param list<SessionEntry> $entries
     */
    public static function findCutPoint(array $entries, int $startIndex, int $endIndex, int $keepRecentTokens): CutPointResult
    {
        $cutPoints = [];

        for ($index = $startIndex; $index < $endIndex; $index++) {
            if ($entries[$index]->message instanceof CompactionSummary) {
                continue;
            }

            foreach (self::entryMessages($entries[$index]) as $message) {
                if (self::isCutPointMessage($message)) {
                    $cutPoints[] = $index;

                    break;
                }
            }
        }

        if ($cutPoints === []) {
            return new CutPointResult($startIndex, -1, false);
        }

        $accumulated = 0;
        $cutIndex = $cutPoints[0];

        for ($index = $endIndex - 1; $index >= $startIndex; $index--) {
            $tokens = 0;

            foreach (self::entryMessages($entries[$index]) as $message) {
                $tokens += self::estimateTokens($message);
            }

            if ($tokens === 0) {
                continue;
            }

            $accumulated += $tokens;

            if ($accumulated >= $keepRecentTokens) {
                $cutIndex = self::firstAtOrAfter($cutPoints, $index);

                break;
            }
        }

        while ($cutIndex > $startIndex) {
            $previous = $entries[$cutIndex - 1];

            if ($previous->message instanceof CompactionSummary || self::entryMessages($previous) !== []) {
                break;
            }

            $cutIndex--;
        }

        $startsTurn = self::isTurnStartEntry($entries[$cutIndex]);
        $turnStartIndex = $startsTurn ? -1 : self::findTurnStartIndex($entries, $cutIndex, $startIndex);

        return new CutPointResult($cutIndex, $turnStartIndex, !$startsTurn && $turnStartIndex !== -1);
    }

    /**
     * Upstream's `cutPoints.find((candidate) => candidate >= i) ?? cutPoints[cutPoints.length - 1]`.
     *
     * @param non-empty-list<int> $cutPoints
     */
    private static function firstAtOrAfter(array $cutPoints, int $index): int
    {
        foreach ($cutPoints as $candidate) {
            if ($candidate >= $index) {
                return $candidate;
            }
        }

        return $cutPoints[count($cutPoints) - 1];
    }

    private static function isProjectedTurnStart(ProjectedSessionEntry $entry): bool
    {
        if ($entry->sourceEntry->message instanceof CompactionSummary) {
            return false;
        }

        foreach ($entry->messages as $message) {
            if (self::isTurnStartMessage($message)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ProjectedSessionEntry> $entries */
    private static function findProjectedTurnStartIndex(array $entries, int $entryIndex, int $startIndex): int
    {
        for ($index = $entryIndex; $index >= $startIndex; $index--) {
            if (self::isProjectedTurnStart($entries[$index])) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * `findCutPoint()` over the projection, which is what `prepareCompaction()` cuts — upstream's
     * `findProjectedCutPoint()`, its recovery rule included.
     *
     * The rule: an overflow recovery leaves the attempt it gave up on in the file, omitted by a
     * `context_edit`, after the last input anybody sent. When the budget is exceeded and
     * everything after the cut is such an omitted attempt (and the edits omitting it), the cut
     * moves one entry on, so the over-budget input is summarised and the omission edits are kept.
     * Anything else after the cut — metadata alone, a replacement of something outside that
     * suffix, a compaction — keeps it where it is.
     *
     * @param list<ProjectedSessionEntry> $entries
     */
    private static function findProjectedCutPoint(array $entries, int $startIndex, int $endIndex, int $keepRecentTokens): CutPointResult
    {
        $cutPoints = [];

        for ($index = $startIndex; $index < $endIndex; $index++) {
            if ($entries[$index]->sourceEntry->message instanceof CompactionSummary) {
                continue;
            }

            foreach ($entries[$index]->messages as $message) {
                if (self::isCutPointMessage($message)) {
                    $cutPoints[] = $index;

                    break;
                }
            }
        }

        if ($cutPoints === []) {
            return new CutPointResult($startIndex, -1, false);
        }

        $accumulated = 0;
        $exceededBudget = false;
        $cutIndex = $cutPoints[0];

        for ($index = $endIndex - 1; $index >= $startIndex; $index--) {
            $tokens = 0;

            foreach ($entries[$index]->messages as $message) {
                $tokens += self::estimateTokens($message);
            }

            if ($tokens === 0) {
                continue;
            }

            $accumulated += $tokens;

            if ($accumulated >= $keepRecentTokens) {
                $exceededBudget = true;
                $cutIndex = self::firstAtOrAfter($cutPoints, $index);

                break;
            }
        }

        $suffix = array_slice($entries, $cutIndex + 1, max(0, $endIndex - $cutIndex - 1));
        $isIntrinsicallyVisible = static fn (ProjectedSessionEntry $entry): bool => !$entry->sourceEntry->message instanceof ContextEdit
            && self::entryMessages($entry->sourceEntry) !== [];
        $isOmitted = static fn (ProjectedSessionEntry $entry): bool => $isIntrinsicallyVisible($entry) && $entry->messages === [];

        $omittedSuffixIds = [];

        foreach ($suffix as $entry) {
            if ($isOmitted($entry)) {
                $omittedSuffixIds[$entry->sourceEntry->id] = true;
            }
        }

        $hasExternalReplacement = false;
        $hasOmittedAssistant = false;
        $allOmittedOrInvisible = true;

        foreach ($suffix as $entry) {
            $source = $entry->sourceEntry->message;

            if ($source instanceof ContextEdit && $source->replacement !== null && !isset($omittedSuffixIds[$source->targetId])) {
                $hasExternalReplacement = true;
            }

            if ($source instanceof AssistantMessage && $isOmitted($entry)) {
                $hasOmittedAssistant = true;
            }

            if ($source instanceof CompactionSummary || ($isIntrinsicallyVisible($entry) && !$isOmitted($entry))) {
                $allOmittedOrInvisible = false;
            }
        }

        if ($exceededBudget && !$hasExternalReplacement && $hasOmittedAssistant && $allOmittedOrInvisible) {
            $cutIndex++;
        }

        while ($cutIndex > $startIndex) {
            $previous = $entries[$cutIndex - 1];

            if ($previous->sourceEntry->message instanceof CompactionSummary || $previous->messages !== []) {
                break;
            }

            $cutIndex--;
        }

        $startsTurn = self::isProjectedTurnStart($entries[$cutIndex]);
        $turnStartIndex = $startsTurn ? -1 : self::findProjectedTurnStartIndex($entries, $cutIndex, $startIndex);

        return new CutPointResult($cutIndex, $turnStartIndex, !$startsTurn && $turnStartIndex !== -1);
    }

    // ---- the preparation -------------------------------------------------------------------

    /**
     * Work out what a compaction of this branch would summarise and keep — upstream's
     * `prepareCompaction()`, and what `session_before_compact` hands an extension.
     *
     * Over the projection (`SessionManager::buildSessionProjection()`), so a context edit's
     * omission or replacement is what is measured and summarised. A previous compaction is the
     * start: its summary becomes `previousSummary`, which the new one updates rather than
     * repeats, and only what came after it is cut. Null when there is nothing to do — the branch
     * already ends in a compaction, or the cut leaves nothing older than itself to summarise.
     *
     * @param list<SessionEntry> $pathEntries the branch, root first (`SessionManager::branchEntries()`)
     */
    public static function prepareCompaction(array $pathEntries, CompactionSettings $settings): ?CompactionPreparation
    {
        if ($pathEntries !== [] && $pathEntries[count($pathEntries) - 1]->message instanceof CompactionSummary) {
            return null;
        }

        $projected = SessionManager::buildSessionProjection($pathEntries);
        $sourceEntries = array_map(static fn (ProjectedSessionEntry $entry): SessionEntry => $entry->sourceEntry, $projected);

        // The newest compaction is projected first; older ones in its retained range contribute nothing.
        $previousCompactionIndex = -1;

        foreach ($projected as $index => $entry) {
            if ($entry->sourceEntry->message instanceof CompactionSummary && $entry->messages !== []) {
                $previousCompactionIndex = $index;

                break;
            }
        }

        $previousSummary = null;
        $boundaryStart = 0;

        if ($previousCompactionIndex >= 0) {
            $previousSummary = $projected[$previousCompactionIndex]->sourceEntry->message->summary;
            $boundaryStart = $previousCompactionIndex + 1;
        }

        $boundaryEnd = count($projected);
        $tokensBefore = self::estimateProjectedContextTokens($projected, $pathEntries)->tokens;
        $cutPoint = self::findProjectedCutPoint($projected, $boundaryStart, $boundaryEnd, $settings->keepRecentTokens);

        $firstKeptEntry = ($projected[$cutPoint->firstKeptEntryIndex] ?? null)?->sourceEntry;

        if ($firstKeptEntry === null) {
            return null;
        }

        $historyEnd = $cutPoint->isSplitTurn ? $cutPoint->turnStartIndex : $cutPoint->firstKeptEntryIndex;

        $messagesToSummarize = self::summarisable(array_slice($projected, $boundaryStart, max(0, $historyEnd - $boundaryStart)));
        $turnPrefixMessages = $cutPoint->isSplitTurn
            ? self::summarisable(array_slice($projected, $cutPoint->turnStartIndex, $cutPoint->firstKeptEntryIndex - $cutPoint->turnStartIndex))
            : [];

        if ($messagesToSummarize === [] && $turnPrefixMessages === []) {
            return null;
        }

        $fileOps = self::extractFileOperations($messagesToSummarize, $sourceEntries, $previousCompactionIndex);

        if ($cutPoint->isSplitTurn) {
            foreach ($turnPrefixMessages as $message) {
                self::extractFileOpsFromMessage($message, $fileOps);
            }
        }

        return new CompactionPreparation(
            $firstKeptEntry->id,
            $messagesToSummarize,
            $turnPrefixMessages,
            $cutPoint->isSplitTurn,
            $tokensBefore,
            $previousSummary,
            $fileOps,
            $settings,
        );
    }

    /**
     * Upstream's `getMessagesFromProjectedEntryForCompaction()`, over a run of entries: a
     * compaction contributes nothing (its summary is `previousSummary`), and system messages are
     * prompt state, not conversation — the compaction entry carries their replay.
     *
     * @param list<ProjectedSessionEntry> $entries
     * @return list<mixed>
     */
    private static function summarisable(array $entries): array
    {
        $messages = [];

        foreach ($entries as $entry) {
            if ($entry->sourceEntry->message instanceof CompactionSummary) {
                continue;
            }

            foreach ($entry->messages as $message) {
                if (!$message instanceof SystemMessage) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }

    /**
     * @param list<ProjectedSessionEntry> $projection
     * @return list<mixed>
     */
    private static function projectedMessages(array $projection): array
    {
        $messages = [];

        foreach ($projection as $entry) {
            array_push($messages, ...$entry->messages);
        }

        return $messages;
    }

    // ---- file operations -------------------------------------------------------------------

    /**
     * Upstream's `extractFileOperations()`: the summarised messages' file operations, plus the
     * previous compaction's lists when pig wrote it — a hook's lists are the hook's, not pig's
     * findings, and are not carried forward as though they were.
     *
     * @param list<mixed>        $messages
     * @param list<SessionEntry> $entries
     */
    private static function extractFileOperations(array $messages, array $entries, int $previousCompactionIndex): FileOperations
    {
        $fileOps = self::createFileOps();

        if ($previousCompactionIndex >= 0) {
            $previous = $entries[$previousCompactionIndex]->message;

            if ($previous instanceof CompactionSummary && !$previous->fromHook) {
                foreach ($previous->readFiles as $path) {
                    $fileOps->addRead($path);
                }

                foreach ($previous->modifiedFiles as $path) {
                    $fileOps->addEdited($path);
                }
            }
        }

        foreach ($messages as $message) {
            self::extractFileOpsFromMessage($message, $fileOps);
        }

        return $fileOps;
    }

    /** Upstream's `createFileOps()`. */
    public static function createFileOps(): FileOperations
    {
        return new FileOperations();
    }

    /**
     * Add the file operations of an assistant message's tool calls — upstream's
     * `extractFileOpsFromMessage()`. `read`, `write` and `edit` with a string `path`; anything else
     * names no file.
     *
     * Upstream also reads the calls a codemode script made from its tool result's `nestedCalls`;
     * pig's tool results do not record nested calls, so there is nothing there to read.
     */
    public static function extractFileOpsFromMessage(mixed $message, FileOperations $fileOps): void
    {
        if (!$message instanceof AssistantMessage) {
            return;
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
                'read' => $fileOps->addRead($path),
                'write' => $fileOps->addWritten($path),
                'edit' => $fileOps->addEdited($path),
                default => null,
            };
        }
    }

    /**
     * The lists a summary records — upstream's `computeFileLists()`: the files only read, and the
     * files written or edited, each sorted. A file that was modified is not also "read": what
     * matters to whatever reads this next is whether the copy on disk still matches what it saw.
     *
     * @return array{readFiles: list<string>, modifiedFiles: list<string>}
     */
    public static function computeFileLists(FileOperations $fileOps): array
    {
        $modified = [];

        foreach ([...array_keys($fileOps->edited), ...array_keys($fileOps->written)] as $path) {
            $modified[(string) $path] = true;
        }

        $readFiles = [];

        foreach (array_keys($fileOps->read) as $path) {
            if (!isset($modified[(string) $path])) {
                $readFiles[] = (string) $path;
            }
        }

        $modifiedFiles = array_map(strval(...), array_keys($modified));

        sort($readFiles);
        sort($modifiedFiles);

        return ['readFiles' => $readFiles, 'modifiedFiles' => $modifiedFiles];
    }

    /**
     * The lists as the tags a summary carries — upstream's `formatFileOperations()`. Empty when
     * there are none, so a summary that touched nothing does not end in empty tags.
     *
     * @param list<string> $readFiles
     * @param list<string> $modifiedFiles
     */
    public static function formatFileOperations(array $readFiles, array $modifiedFiles): string
    {
        $sections = [];

        if ($readFiles !== []) {
            $sections[] = "<read-files>\n" . implode("\n", $readFiles) . "\n</read-files>";
        }

        if ($modifiedFiles !== []) {
            $sections[] = "<modified-files>\n" . implode("\n", $modifiedFiles) . "\n</modified-files>";
        }

        return $sections === [] ? '' : "\n\n" . implode("\n\n", $sections);
    }

    /**
     * Which files were touched in a list of messages, carrying forward the lists of the summaries
     * among them that pig wrote (not a hook's).
     *
     * pig's own, for the Markdown export, which has messages rather than a preparation in hand.
     *
     * @param list<mixed> $messages
     * @return array{0: list<string>, 1: list<string>} read, then modified
     */
    public static function files(array $messages): array
    {
        $fileOps = self::createFileOps();

        foreach ($messages as $message) {
            if (($message instanceof CompactionSummary || $message instanceof BranchSummary) && !$message->fromHook) {
                foreach ($message->readFiles as $path) {
                    $fileOps->addRead($path);
                }

                foreach ($message->modifiedFiles as $path) {
                    $fileOps->addEdited($path);
                }

                continue;
            }

            self::extractFileOpsFromMessage($message, $fileOps);
        }

        $lists = self::computeFileLists($fileOps);

        return [$lists['readFiles'], $lists['modifiedFiles']];
    }

    // ---- serialising -----------------------------------------------------------------------

    /**
     * The conversation as text for the summariser to read — upstream's `serializeConversation()`.
     *
     * Flattened into labelled lines rather than handed over as messages, because a model given a
     * conversation answers it. Takes LLM messages, as upstream's does: call `CodingAgent::toLlm()`
     * first (upstream's `convertToLlm()`), which is what turns a command's output, a hook's
     * message and a summary into the user messages the model would be shown. A tool result is cut
     * to `TOOL_RESULT_MAX_CHARS` characters with a note of how much was left out.
     *
     * @param list<mixed> $messages
     */
    public static function serializeConversation(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $content = Text::contentText($message->content, '');

                if ($content !== '') {
                    $parts[] = "[User]: {$content}";
                }

                continue;
            }

            // Thinking is left out (upstream 1.2): replayed thinking is often not in the live
            // context, so including it could make the summarization request larger than the
            // context it summarizes and fail.
            if ($message instanceof AssistantMessage) {
                $calls = [];
                $hasText = false;

                foreach ($message->content as $block) {
                    if ($block instanceof ToolCall) {
                        $calls[] = $block->name . '(' . self::arguments($block->arguments) . ')';
                    } elseif ($block instanceof TextContent) {
                        $hasText = true;
                    }
                }

                if ($hasText) {
                    $parts[] = '[Assistant]: ' . Text::contentText($message->content);
                }

                if ($calls !== []) {
                    $parts[] = '[Assistant tool calls]: ' . implode('; ', $calls);
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $content = Text::contentText($message->content, '');

                if ($content !== '') {
                    $parts[] = '[Tool result]: ' . self::truncateForSummary($content, self::TOOL_RESULT_MAX_CHARS);
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /** Upstream's `truncateForSummary()`: the beginning, then how many characters were left out. */
    private static function truncateForSummary(string $text, int $maxChars): string
    {
        $length = mb_strlen($text);

        if ($length <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n\n[... " . ($length - $maxChars) . ' more characters truncated]';
    }

    // ---- the prompts -----------------------------------------------------------------------

    /** Upstream's `SUMMARIZATION_SYSTEM_PROMPT`, word for word, shared with branch summaries. */
    public const string SUMMARIZATION_SYSTEM_PROMPT = "You are a context summarization assistant. Your task is to read a conversation between a user and an AI assistant, then produce a structured summary following the exact format specified.\n\n"
        . 'Do NOT continue the conversation. Do NOT respond to any questions in the conversation. ONLY output the structured summary.';

    /** Upstream's `SUMMARIZATION_PROMPT`, word for word. */
    public const string SUMMARIZATION_PROMPT = <<<'TEXT'
        The messages above are a conversation to summarize. Create a structured context checkpoint summary that another LLM will use to continue the work.

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

    /** Upstream's `UPDATE_SUMMARIZATION_PROMPT` (its `UPDATE_SUMMARIZATION_INSTRUCTIONS` included), word for word. */
    public const string UPDATE_SUMMARIZATION_PROMPT = <<<'TEXT'
        The messages above are NEW conversation messages to incorporate into the existing summary provided in <previous-summary> tags.

        Update the existing structured summary with new information. RULES:
        - PRESERVE all existing information from the previous summary
        - ADD new progress, decisions, and context from the new messages
        - UPDATE the Progress section: move items from "In Progress" to "Done" when completed
        - UPDATE "Next Steps" based on what was accomplished
        - PRESERVE exact file paths, function names, and error messages
        - If something is no longer relevant, you may remove it

        Use this EXACT format:

        ## Goal
        [Preserve existing goals, add new ones if the task expanded]

        ## Constraints & Preferences
        - [Preserve existing, add new ones discovered]

        ## Progress
        ### Done
        - [x] [Include previously done items AND newly completed items]

        ### In Progress
        - [ ] [Current work - update based on progress]

        ### Blocked
        - [Current blockers - remove if resolved]

        ## Key Decisions
        - **[Decision]**: [Brief rationale] (preserve all previous, add new)

        ## Next Steps
        1. [Update based on current state]

        ## Critical Context
        - [Preserve important context, add new if needed]

        Keep each section concise. Preserve exact file paths, function names, and error messages.
        TEXT;

    /** Upstream's `TURN_PREFIX_SUMMARIZATION_PROMPT`, word for word: the beginning of a turn the cut split. */
    public const string TURN_PREFIX_SUMMARIZATION_PROMPT = <<<'TEXT'
        The messages above are earlier context from an ongoing conversation. Later messages are stored separately and do not need to be reconstructed.

        Create a concise checkpoint of the user's request and the progress shown above. This checkpoint will be placed before the later messages so the conversation can continue with the necessary context.

        ## Original Request
        [What did the user ask for?]

        ## Progress So Far
        - [Key decisions and work completed in these messages]

        ## Context Needed to Continue
        - [Information from these messages needed to understand the later work]

        Only summarize information explicitly present above. Do not infer or recreate later messages.
        TEXT;

    // ---- asking the model ------------------------------------------------------------------

    /**
     * Why a summarisation answer cannot be kept, or null when it can — upstream's
     * `getSummarizationFailure()`. A length stop is partial text and must not become a checkpoint.
     */
    public static function getSummarizationFailure(AssistantMessage $response, string $label): ?string
    {
        if ($response->stopReason === StopReason::Error) {
            return "{$label} failed: " . ($response->errorMessage ?: 'Unknown error');
        }

        if ($response->stopReason === StopReason::Length) {
            return "{$label} failed: generation hit the token cap and the summary is incomplete";
        }

        return null;
    }

    /**
     * One summarisation request, outside the agent loop — upstream's `completeSummarization()`,
     * the one choke point for compaction and branch summaries.
     *
     * "Avoid cache writes for one-off summaries": `cacheRetention: none`, and the caller's routing
     * id or a fresh one. Through `$streamFn` when there is one (the session passes its agent's, so
     * the request carries the session's request options), and wrapped in
     * `Retry::retryAssistantCall()` "so transient stream drops honor the configured retry policy
     * instead of failing the whole compaction on the first attempt".
     *
     * @param (Closure(Model, TranscriptContext, SimpleStreamOptions): mixed)|null $streamFn
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @param array<string, Closure> $callbacks `Retry::retryAssistantCall()`'s
     */
    public static function completeSummarization(
        Model $model,
        TranscriptContext $context,
        SimpleStreamOptions $options,
        ?Closure $streamFn = null,
        ?array $retry = null,
        array $callbacks = [],
    ): AssistantMessage {
        $request = new SimpleStreamOptions(
            temperature: $options->temperature,
            maxTokens: $options->maxTokens,
            signal: $options->signal,
            apiKey: $options->apiKey,
            reasoning: $options->reasoning,
            toolChoice: $options->toolChoice,
            cacheRetention: 'none',
            sessionId: $options->sessionId ?? Uuid::v7(),
            metadata: $options->metadata,
            headers: $options->headers,
            timeoutMs: $options->timeoutMs,
            maxRetries: $options->maxRetries,
            maxRetryDelayMs: $options->maxRetryDelayMs,
            onPayload: $options->onPayload,
            onResponse: $options->onResponse,
            onProviderStreamEvent: $options->onProviderStreamEvent,
            env: $options->env,
            transport: $options->transport,
            websocketConnectTimeoutMs: $options->websocketConnectTimeoutMs,
            thinkingBudgets: $options->thinkingBudgets,
        );

        $produce = static function () use ($model, $context, $request, $streamFn): AssistantMessage {
            $response = $streamFn !== null ? $streamFn($model, $context, $request) : Stream::simple($model, $context, $request);

            foreach ($response as $ignored) {
                // Nothing streams anywhere: a summary is only useful whole.
            }

            $message = $response->result()->await();

            if (!$message instanceof AssistantMessage) {
                throw new AgentError('The summariser answered with something that was not a message.');
            }

            return $message;
        };

        return Retry::retryAssistantCall($produce, $retry, $request->signal, $callbacks);
    }

    /**
     * Summarise messages, or update `$previousSummary` with them — upstream's `generateSummary()`.
     *
     * @param list<mixed> $currentMessages app messages; converted with `CodingAgent::toLlm()` here
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @param array<string, Closure> $callbacks
     * @throws AgentError when the model failed, stopped at its token cap, or tried to call a tool
     */
    public static function generateSummary(
        array $currentMessages,
        Model $model,
        ?int $reserveTokens,
        ?string $apiKey,
        ?array $headers = null,
        ?AbortSignal $signal = null,
        ?string $customInstructions = null,
        ?string $previousSummary = null,
        ?ThinkingLevel $thinkingLevel = null,
        ?Closure $streamFn = null,
        ?array $env = null,
        ?array $retry = null,
        array $callbacks = [],
        ?string $sessionId = null,
    ): string {
        return self::generateSummaryWithUsage(
            $currentMessages,
            $model,
            $reserveTokens,
            $apiKey,
            $headers,
            $signal,
            $customInstructions,
            $previousSummary,
            $thinkingLevel,
            $streamFn,
            $env,
            $retry,
            $callbacks,
            $sessionId,
        )['text'];
    }

    /**
     * `generateSummary()` with what it cost — upstream's `generateSummaryWithUsage()`.
     *
     * The update prompt when there is a previous summary, "Additional focus" when there are
     * instructions, and the conversation serialised inside `<conversation>` tags so the model
     * reads it rather than answers it. No output cap of its own: the request gets the model's
     * output limit, so long reasoning cannot use up a fixed summary budget (#9512).
     *
     * @param int|null $reserveTokens unused, as upstream's `_reserveTokens`: "Kept for existing
     *        callers; summaries use the model's output limit"
     *
     * @param list<mixed> $currentMessages
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @param array<string, Closure> $callbacks
     * @return array{text: string, usage: Usage}
     * @throws AgentError
     */
    public static function generateSummaryWithUsage(
        array $currentMessages,
        Model $model,
        ?int $reserveTokens,
        ?string $apiKey,
        ?array $headers = null,
        ?AbortSignal $signal = null,
        ?string $customInstructions = null,
        ?string $previousSummary = null,
        ?ThinkingLevel $thinkingLevel = null,
        ?Closure $streamFn = null,
        ?array $env = null,
        ?array $retry = null,
        array $callbacks = [],
        ?string $sessionId = null,
    ): array {
        $basePrompt = $previousSummary !== null && $previousSummary !== '' ? self::UPDATE_SUMMARIZATION_PROMPT : self::SUMMARIZATION_PROMPT;

        if ($customInstructions !== null && $customInstructions !== '') {
            $basePrompt = "{$basePrompt}\n\nAdditional focus: {$customInstructions}";
        }

        $conversationText = self::serializeConversation(CodingAgent::toLlm($currentMessages));

        $promptText = "<conversation>\n{$conversationText}\n</conversation>\n\n";

        if ($previousSummary !== null && $previousSummary !== '') {
            $promptText .= "<previous-summary>\n{$previousSummary}\n</previous-summary>\n\n";
        }

        $promptText .= $basePrompt;

        $response = self::completeSummarization(
            $model,
            self::buildSummarizationContext($promptText),
            self::summarizationOptions($model, $apiKey, $headers, $env, $signal, $thinkingLevel, $sessionId),
            $streamFn,
            $retry,
            $callbacks,
        );

        $failure = self::getSummarizationFailure($response, 'Summarization');

        if ($failure !== null) {
            throw new AgentError($failure);
        }

        if (self::hasToolCall($response)) {
            throw new AgentError('Summarization attempted to call a tool');
        }

        return ['text' => Text::contentText($response->content), 'usage' => $response->usage];
    }

    /**
     * Summarise a prepared compaction — upstream's `compact()`.
     *
     * **A split turn gets two summaries, merged.** When the cut lands inside a turn
     * (`isSplitTurn`), the history before that turn is summarised as usual (or the previous
     * summary stands, if there is no new history) and the turn's own beginning
     * (`turnPrefixMessages`) is summarised separately with a smaller budget and a prompt for
     * exactly that — what was asked and how far it got — then the two are joined under
     * `**Turn Context (split turn):**`. Their usages are added together.
     *
     * The file lists go in `details` and not into the summary text — see `CompactionResult`.
     *
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @param array<string, Closure> $callbacks
     * @throws AgentError
     */
    public static function compact(
        CompactionPreparation $preparation,
        Model $model,
        ?string $apiKey,
        ?array $headers = null,
        ?string $customInstructions = null,
        ?AbortSignal $signal = null,
        ?ThinkingLevel $thinkingLevel = null,
        ?Closure $streamFn = null,
        ?array $env = null,
        ?array $retry = null,
        array $callbacks = [],
        ?string $sessionId = null,
    ): CompactionResult {
        $reserveTokens = $preparation->settings->reserveTokens;

        if ($preparation->isSplitTurn && $preparation->turnPrefixMessages !== []) {
            $historyText = $preparation->previousSummary ?? 'No prior history.';
            $historyUsage = null;

            if ($preparation->messagesToSummarize !== []) {
                $history = self::generateSummaryWithUsage(
                    $preparation->messagesToSummarize,
                    $model,
                    $reserveTokens,
                    $apiKey,
                    $headers,
                    $signal,
                    $customInstructions,
                    $preparation->previousSummary,
                    $thinkingLevel,
                    $streamFn,
                    $env,
                    $retry,
                    $callbacks,
                    $sessionId,
                );
                $historyText = $history['text'];
                $historyUsage = $history['usage'];
            }

            $turnPrefix = self::generateTurnPrefixSummary(
                $preparation->turnPrefixMessages,
                $model,
                $apiKey,
                $headers,
                $env,
                $signal,
                $thinkingLevel,
                $streamFn,
                $retry,
                $callbacks,
                $sessionId,
                $customInstructions,
            );

            $summary = "{$historyText}\n\n---\n\n**Turn Context (split turn):**\n\n{$turnPrefix['text']}";
            $usage = $historyUsage === null ? $turnPrefix['usage'] : self::combineUsage($historyUsage, $turnPrefix['usage']);
        } else {
            $result = self::generateSummaryWithUsage(
                $preparation->messagesToSummarize,
                $model,
                $reserveTokens,
                $apiKey,
                $headers,
                $signal,
                $customInstructions,
                $preparation->previousSummary,
                $thinkingLevel,
                $streamFn,
                $env,
                $retry,
                $callbacks,
                $sessionId,
            );
            $summary = $result['text'];
            $usage = $result['usage'];
        }

        $lists = self::computeFileLists($preparation->fileOps);

        return new CompactionResult(
            $summary,
            $preparation->firstKeptEntryId,
            $preparation->tokensBefore,
            usage: $usage,
            details: $lists,
        );
    }

    /**
     * The beginning of a split turn, summarised — upstream's `generateTurnPrefixSummary()`, with the
     * `/compact` instructions as its "Additional focus" too: when the split turn was all that was new
     * since the last compaction, this was the only summary and the instructions went nowhere (#11058).
     *
     * @param list<mixed> $messages
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     * @param array<string, mixed>|null $retry
     * @param array<string, Closure> $callbacks
     * @return array{text: string, usage: Usage}
     */
    private static function generateTurnPrefixSummary(
        array $messages,
        Model $model,
        ?string $apiKey,
        ?array $headers,
        ?array $env,
        ?AbortSignal $signal,
        ?ThinkingLevel $thinkingLevel,
        ?Closure $streamFn,
        ?array $retry,
        array $callbacks,
        ?string $sessionId,
        ?string $customInstructions = null,
    ): array {
        $conversationText = self::serializeConversation(CodingAgent::toLlm($messages));
        $promptText = "# Conversation\n{$conversationText}\n\n# Instructions\n" . self::TURN_PREFIX_SUMMARIZATION_PROMPT;

        if ($customInstructions !== null && $customInstructions !== '') {
            $promptText = "{$promptText}\n\nAdditional focus: {$customInstructions}";
        }

        $response = self::completeSummarization(
            $model,
            self::buildSummarizationContext($promptText),
            self::summarizationOptions($model, $apiKey, $headers, $env, $signal, $thinkingLevel, $sessionId),
            $streamFn,
            $retry,
            $callbacks,
        );

        $failure = self::getSummarizationFailure($response, 'Turn prefix summarization');

        if ($failure !== null) {
            throw new AgentError($failure);
        }

        if (self::hasToolCall($response)) {
            throw new AgentError('Turn prefix summarization attempted to call a tool');
        }

        return ['text' => Text::contentText($response->content), 'usage' => $response->usage];
    }

    /** Upstream's `buildSummarizationContext()`: the summariser's system prompt and one user message. */
    public static function buildSummarizationContext(string $promptText): TranscriptContext
    {
        return Transcript::normalizeContext(new Context([new UserMessage([new TextContent($promptText)])], self::SUMMARIZATION_SYSTEM_PROMPT));
    }

    /**
     * Upstream's `createSummarizationOptions()`: reasoning at the given level when the model
     * reasons and the level is not `off`.
     *
     * @param array<string, string>|null $headers
     * @param array<string, string>|null $env
     */
    private static function summarizationOptions(
        Model $model,
        ?string $apiKey,
        ?array $headers,
        ?array $env,
        ?AbortSignal $signal,
        ?ThinkingLevel $thinkingLevel,
        ?string $sessionId,
    ): SimpleStreamOptions {
        return new SimpleStreamOptions(
            signal: $signal,
            apiKey: $apiKey,
            reasoning: $model->reasoning && $thinkingLevel !== null ? $thinkingLevel->toReasoning() : null,
            sessionId: $sessionId,
            headers: $headers,
            env: $env,
        );
    }

    private static function hasToolCall(AssistantMessage $response): bool
    {
        foreach ($response->content as $block) {
            if ($block instanceof ToolCall) {
                return true;
            }
        }

        return false;
    }

    /** Upstream's `combineUsage()` (`usage-totals.ts`): two usages added, the optional splits kept when either has them. */
    private static function combineUsage(Usage $first, Usage $second): Usage
    {
        return new Usage(
            $first->input + $second->input,
            $first->output + $second->output,
            $first->cacheRead + $second->cacheRead,
            $first->cacheWrite + $second->cacheWrite,
            $first->totalTokens + $second->totalTokens,
            new Cost(
                $first->cost->input + $second->cost->input,
                $first->cost->output + $second->cost->output,
                $first->cost->cacheRead + $second->cost->cacheRead,
                $first->cost->cacheWrite + $second->cost->cacheWrite,
                $first->cost->total + $second->cost->total,
            ),
            $first->reasoning === null && $second->reasoning === null ? null : ($first->reasoning ?? 0) + ($second->reasoning ?? 0),
            $first->cacheWrite1h === null && $second->cacheWrite1h === null ? null : ($first->cacheWrite1h ?? 0) + ($second->cacheWrite1h ?? 0),
        );
    }

    // ---- measuring -------------------------------------------------------------------------

    /**
     * How much of a request one content list is worth, as characters.
     *
     * An image counts as `CHARS_PER_IMAGE` rather than as nothing. Counting it as nothing is what
     * made a conversation of screenshots impossible to compact: twelve turns of images asked to be
     * cut down to 2,000 tokens dropped **nothing at all**, because the walk back never reached the
     * budget — so compaction ran, paid for a summarisation, and freed no context. See docs/dev/traps/session.md.
     *
     * @param list<mixed> $content
     */
    private static function textLength(array $content): int
    {
        $length = 0;

        foreach ($content as $block) {
            $length += match (true) {
                $block instanceof TextContent => strlen($block->text),
                $block instanceof ImageContent => self::CHARS_PER_IMAGE,
                default => 0,
            };
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
