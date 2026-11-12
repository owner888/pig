# Compaction Reference

This reference describes automatic compaction, branch summarization, persisted entries, and extension hooks. For the user workflow, see [Sessions and Context](/docs/latest/sessions#manage-conversation-context).

**Source files** ([pig](https://github.com/owner888/pig)):
- [`packages/coding-agent/src/Session/Compaction.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/Compaction.php) - Compaction logic and shared utilities (cut points, file tracking, serialization)
- [`packages/coding-agent/src/Session/BranchSummarization.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummarization.php) - Branch summarization
- [`packages/coding-agent/src/Session/CompactionEntry.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/CompactionEntry.php) and [`BranchSummaryEntry.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummaryEntry.php) - The persisted entries; [`SessionEntries.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/SessionEntries.php) reads and writes them
- [`packages/coding-agent/src/Session/CompactionPreparation.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/CompactionPreparation.php) and [`CompactionResult.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/CompactionResult.php) - What extensions receive and return
- [`packages/coding-agent/src/Extensions/Events/`](https://github.com/owner888/pig/tree/main/packages/coding-agent/src/Extensions/Events) and [`Extensions/Results/`](https://github.com/owner888/pig/tree/main/packages/coding-agent/src/Extensions/Results) - Extension event and result types

## Overview

pig has two summarization mechanisms:

| Mechanism | Trigger | Purpose |
|-----------|---------|---------|
| Compaction | Context exceeds threshold, or `/compact` | Summarize old messages to free up context |
| Branch summarization | `/tree` navigation | Preserve context when switching branches |

Both use closely related structured formats and track file operations cumulatively. Summarization requests disable prompt-cache writes because these one-off prompts are unlikely to be reused.

## Compaction

### When It Triggers

Auto-compaction triggers when:

```
contextTokens > contextWindow - reserveTokens
```

By default, `reserveTokens` is 16384 tokens (configurable in `~/.pig/agent/settings.json` or `<project-dir>/.pig/settings.json`). This leaves room for the LLM's response.

During a multi-turn agent run, pig checks the canonical projected context after tools finish and their results are appended, before starting the next assistant response. If the threshold is crossed, pig compacts during `prepareNextTurn`, then performs the existing catch-up steering poll before `turn_start`. It skips this between-turn check when the completed tool batch terminates the run and no queued message requires another response. pig also checks before a new user prompt and performs final-attempt overflow recovery after the low-level run ends.

A provider context-overflow error or an early final `stopReason: "length"` can select one compact-and-retry recovery attempt. Length responses with tool calls retain their synthetic failed tool results and follow the ordinary tool/queue scheduler rather than forcing the run to end.

You can also trigger manually with `/compact [instructions]`, where optional instructions focus the summary.

### How It Works

1. **Find cut point**: Walk backwards through the finalized session projection, accumulating token estimates until `keepRecentTokens` (default 20k, configurable in `~/.pig/agent/settings.json` or `<project-dir>/.pig/settings.json`) is reached
2. **Extract messages**: Collect projected messages from the previous kept boundary (or session start) up to the cut point
3. **Generate summary**: Call LLM to summarize with structured format, passing the previous summary as iterative context when present
4. **Append entry**: Save `CompactionEntry` with summary and `firstKeptEntryId`
5. **Rebuilds context**: Session rebuilds the context for the next request, using summary + messages from `firstKeptEntryId` onwards

```
Before compaction:

  entry:  0     1     2     3      4     5     6      7      8     9
        ┌─────┬─────┬─────┬──────┬─────┬─────┬──────┬──────┬─────┬─────┐
        │ hdr │ usr │ ass │ tool │ usr │ ass │ tool │ tool │ ass │ tool│
        └─────┴─────┴─────┴──────┴─────┴─────┴──────┴──────┴─────┴─────┘
                └────────┬───────┘ └──────────────┬──────────────┘
               messagesToSummarize            kept messages
                                   ↑
                          firstKeptEntryId (entry 4)

After compaction (new entry appended):

  entry:  0     1     2     3      4     5     6      7      8     9     10
        ┌─────┬─────┬─────┬──────┬─────┬─────┬──────┬──────┬─────┬─────┬─────┐
        │ hdr │ usr │ ass │ tool │ usr │ ass │ tool │ tool │ ass │ tool│ cmp │
        └─────┴─────┴─────┴──────┴─────┴─────┴──────┴──────┴─────┴─────┴─────┘
               └──────────┬──────┘ └──────────────────────┬───────────────────┘
                 not sent to LLM                    sent to LLM
                                                         ↑
                                              starts from firstKeptEntryId

What the LLM sees:

  ┌────────┬─────────┬─────┬─────┬──────┬──────┬─────┬──────┐
  │ system │ summary │ usr │ ass │ tool │ tool │ ass │ tool │
  └────────┴─────────┴─────┴─────┴──────┴──────┴─────┴──────┘
       ↑         ↑      └─────────────────┬────────────────┘
    prompt   from cmp          messages from firstKeptEntryId
```

On repeated compactions, the summarized span starts at the previous compaction's kept boundary (`firstKeptEntryId`), not at the compaction entry itself, falling back to the entry after the previous compaction if that kept entry cannot be found in the path. A retain-none compaction records its own ID as `firstKeptEntryId`; repeated compaction starts after that entry. This preserves messages that survived the earlier compaction by including them in the next summarization pass as well. pig also recalculates `tokensBefore` from the rebuilt, context-edited session projection before writing the new `CompactionEntry`, so the token count reflects the actual pre-compaction context being replaced. Omitted raw entries remain stored but do not affect cut selection, summaries, checkpoints, or token estimates.

### Overflow and Length Recovery Ordering

Recovery preserves the existing lifecycle and queue order. The completed attempt remains visible to `turn_end` and `agent_end`; post-run recovery then repairs persisted model context before a fresh retry:

```text
persist final assistant response
→ extension/public turn_end
→ extension/public agent_end
→ append context_edit omissions for the selected attempt
→ for overflow/length: run session_before_compact and append compaction on success
→ start the retry as a fresh run
```

If recovery compaction fails or is cancelled, pig keeps the omission edits, appends no compaction, and schedules no internal retry. Existing queued work remains governed by ordinary steering and follow-up rules. `agent_before_settle` sees the repaired projection after recovery processing. Raw transcript history, exports, billing totals, and history-search extensions can still inspect the omitted attempt.

### Split user-message spans

A user-message span starts with a user message and includes all turns until the next user message. Normally, compaction cuts at user-message boundaries.

When one user-message span exceeds `keepRecentTokens`, the cut point lands within that span at an assistant message. This is a split user-message span:

```
Split user-message span (one span exceeds budget):

  entry:  0     1     2      3     4      5      6     7      8
        ┌─────┬─────┬─────┬──────┬─────┬──────┬──────┬─────┬──────┐
        │ hdr │ usr │ ass │ tool │ ass │ tool │ tool │ ass │ tool │
        └─────┴─────┴─────┴──────┴─────┴──────┴──────┴─────┴──────┘
                ↑                                     ↑
         turnStartIndex = 1                  firstKeptEntryId = 7
                │                                     │
                └──── turnPrefixMessages (1-6) ───────┘
                                                      └── kept (7-8)

  isSplitTurn = true
  messagesToSummarize = []  (no earlier user-message spans)
  turnPrefixMessages = [usr, ass, tool, ass, tool, tool]
```

For split user-message spans, pig generates two summaries and merges them:
1. **History summary**: Previous context (if any)
2. **User-message-span prefix summary**: The early part of the split user-message span

### Cut Point Rules

Valid cut points are:
- User messages
- Assistant messages
- BashExecution messages
- Custom messages (custom_message, branch_summary)

Never cut at tool results (they must stay with their tool call).

Preparation advances the kept boundary into a context-invisible suffix only when that suffix contains an omitted assistant attempt and no unomitted context-producing entries. Recovery `context_edit` omissions satisfy this rule; intrinsically context-invisible metadata may coexist with them. Metadata alone and newly appended custom messages do not move the cut. A replacement edit affecting the candidate input or summarized prefix also blocks advancement because the omitted assistant answered the pre-edit input; replacements of suffix entries that are ultimately omitted remain safe. This allows an over-budget recovered input to be summarized while retaining the edits that keep the abandoned attempt omitted, without making bookkeeping change whether new model input is preserved verbatim.

### CompactionEntry Structure

A compaction is a `compaction` line in the session file, read into a [`CompactionEntry`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/CompactionEntry.php):

```json
{
  "type": "compaction",
  "id": "…",
  "parentId": "…",
  "timestamp": "…",
  "summary": "## Goal\n…",
  "firstKeptEntryId": "…",
  "tokensBefore": 123456,
  "usage": { "…": "LLM usage that generated the summary" },
  "fromHook": false,
  "details": { "readFiles": ["src/A.php"], "modifiedFiles": ["src/B.php"] }
}
```

`fromHook` is `true` when an extension provided the summary (the legacy field name). Unlike upstream pi, pig keeps the file lists only in `details` and leaves them out of `summary`; the `<read-files>` and `<modified-files>` tags are added when the summary is shown to the model (`CompactionEntry::toText()`). `details` holds only `readFiles` and `modifiedFiles`: other keys an extension returns are not stored. Generated and extension-provided summaries store their LLM `usage` when available so session totals include summarization work.

See [`Compaction::prepareCompaction()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/Compaction.php) and [`Compaction::compact()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/Compaction.php) for the implementation. For direct programmatic summarization, `Compaction::generateSummary()` returns the summary text and `generateSummaryWithUsage()` the text with its usage.

## Branch Summarization

### When It Triggers

When you use `/tree` to navigate to a different branch, pig offers to summarize the work you're leaving. This injects context from the left branch into the new branch.

### How It Works

1. **Find common ancestor**: Deepest node shared by old and new positions
2. **Collect entries**: Walk from old leaf back to common ancestor
3. **Prepare with budget**: Include messages up to token budget (newest first)
4. **Generate summary**: Call LLM with structured format
5. **Append entry**: Save `BranchSummaryEntryEntry` at navigation point

```
Tree before navigation:

         ┌─ B ─ C ─ D (old leaf, being abandoned)
    A ───┤
         └─ E ─ F (target)

Common ancestor: A
Entries to summarize: B, C, D

After navigation with summary:

         ┌─ B ─ C ─ D
    A ───┤
         └─ E ─ F ─ [summary of B,C,D] (new leaf)
```

### Cumulative File Tracking

Default compaction and branch summarization track files cumulatively. Both extract file operations from tool calls in the messages being summarized. Compaction also carries file lists from the previous pig-generated compaction. Branch summarization carries file lists from pig-generated branch summaries in the entries it summarizes.

File tracking therefore accumulates across default compactions and nested default branch summaries. pig does not automatically carry file lists from extension-generated summaries whose `fromHook` field is `true`; extensions manage their own `details` format.

### BranchSummaryEntryEntry Structure

A branch summary is a `branch_summary` line, read into a [`BranchSummaryEntry`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummaryEntry.php):

```json
{
  "type": "branch_summary",
  "id": "…",
  "parentId": "…",
  "timestamp": "…",
  "summary": "…",
  "fromId": "… (the entry navigated from)",
  "usage": { "…": "LLM usage that generated the summary" },
  "fromHook": false,
  "details": { "readFiles": [], "modifiedFiles": [] }
}
```

As with compaction, `details` stores only `readFiles` and `modifiedFiles`.

See [`collectEntriesForBranchSummaryEntry()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummarization.php), [`prepareBranchEntries()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummarization.php), and [`generateBranchSummaryEntry()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/BranchSummarization.php) for the implementation.

## Summary Format

Both formats include Goal, Constraints & Preferences, Progress, Key Decisions, and Next Steps. Compaction summaries also include Critical Context. Branch summaries stop after Next Steps. When the summary is shown to the model, pig appends the file lists as tags; the stored `summary` text has no tags.

Compaction summaries, as the model sees them, use this format:

```markdown
## Goal
[What the user is trying to accomplish]

## Constraints & Preferences
- [Requirements mentioned by user]

## Progress
### Done
- [x] [Completed tasks]

### In Progress
- [ ] [Current work]

### Blocked
- [Issues, if any]

## Key Decisions
- **[Decision]**: [Rationale]

## Next Steps
1. [What should happen next]

## Critical Context
- [Data needed to continue]

<read-files>
path/to/file1.php
path/to/file2.php
</read-files>

<modified-files>
path/to/changed.php
</modified-files>
```

### Message Serialization

Before summarization, messages are converted to LLM messages with `CodingAgent::toLlm()` and serialized to text via [`Compaction::serializeConversation()`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/Compaction.php). Hook messages, `!` command output, and earlier summaries reach the summarizer as `[User]:` lines:

```
[User]: What they said
[Assistant thinking]: Internal reasoning
[Assistant]: Response text
[Assistant tool calls]: read(path="foo.php"); edit(path="bar.php", ...)
[Tool result]: Output from tool
```

This prevents the model from treating it as a conversation to continue.

Tool results are truncated to 2000 characters (`Compaction::TOOL_RESULT_MAX_CHARS`) during serialization. Content beyond that limit is replaced with a marker indicating how many characters were truncated. This keeps summarization requests within reasonable token budgets, since tool results (especially from `read` and `bash`) are typically the largest contributors to context size.

## Custom Summarization via Extensions

Extensions can intercept and customize both compaction and branch summarization. The event classes live in [`Extensions/Events/`](https://github.com/owner888/pig/tree/main/packages/coding-agent/src/Extensions/Events) and the result classes in [`Extensions/Results/`](https://github.com/owner888/pig/tree/main/packages/coding-agent/src/Extensions/Results). For `session_before_*` events, the first handler that cancels wins; otherwise the last handler's answer is used.

### session_before_compact

Fired before manual, threshold, and overflow compaction. Can cancel or provide a custom summary. See `SessionBeforeCompactEvent` and `CompactionPreparation`.

```php
use Pig\CodingAgent\Extensions\Events\SessionBeforeCompactEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\Results\SessionBeforeCompactResult;
use Pig\CodingAgent\Session\CompactionResult;

$pig->on('session_before_compact', function (SessionBeforeCompactEvent $event, ExtensionContext $ctx) {
    $preparation = $event->preparation;

    // $preparation->messagesToSummarize - messages to summarize
    // $preparation->turnPrefixMessages  - user-message-span prefix (if $preparation->isSplitTurn)
    // $preparation->previousSummary     - previous compaction summary
    // $preparation->fileOps             - extracted file operations (FileOperations)
    // $preparation->tokensBefore        - context tokens before compaction
    // $preparation->firstKeptEntryId    - where kept messages start
    // $preparation->settings            - effective CompactionSettings after model overrides

    // $event->branchEntries      - all entries on the current branch (for custom state)
    // $event->customInstructions - the text after /compact, or null
    // $event->reason             - 'manual' (/compact), 'threshold', or 'overflow'
    // $event->willRetry          - whether the aborted turn is retried after compaction (overflow recovery)
    // $event->signal             - AbortSignal (pass to LLM calls)

    // Cancel (the compaction fails with "Compaction cancelled"):
    return new SessionBeforeCompactResult(cancel: true);

    // Custom summary:
    return new SessionBeforeCompactResult(compaction: new CompactionResult(
        summary: 'Your summary...',
        firstKeptEntryId: $preparation->firstKeptEntryId,
        tokensBefore: $preparation->tokensBefore,
        // usage: $usage, // optional; included in session totals
        details: ['readFiles' => [], 'modifiedFiles' => []],
    ));
});
```

pig writes `firstKeptEntryId` and `tokensBefore` exactly as the handler returns them.

#### Converting Messages to Text

To generate a summary with your own model, convert messages to text with `Compaction::serializeConversation()`:

```php
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Session\Compaction;

$pig->on('session_before_compact', function (SessionBeforeCompactEvent $event, ExtensionContext $ctx) {
    $preparation = $event->preparation;

    // Convert session messages to LLM messages, then serialize to text
    $conversationText = Compaction::serializeConversation(
        CodingAgent::toLlm($preparation->messagesToSummarize),
    );
    // [User]: message text
    // [Assistant thinking]: thinking content
    // [Assistant]: response text
    // [Assistant tool calls]: read(path="..."); bash(command="...")
    // [Tool result]: output text

    // Ask the session's model (or pass another Model) for the summary
    $summary = $ctx->complete("Summarize this coding session:\n\n{$conversationText}", maxTokens: 4000);

    if ($summary === null) {
        return null; // fall back to pig's own summary
    }

    return new SessionBeforeCompactResult(compaction: new CompactionResult(
        summary: $summary,
        firstKeptEntryId: $preparation->firstKeptEntryId,
        tokensBefore: $preparation->tokensBefore,
    ));
});
```

To keep pig's prompts but change the model, call `Compaction::compact($preparation, $model, $apiKey, signal: $event->signal)` and return its `CompactionResult`.

### session_compact

Fired after a compaction entry is written. `SessionCompactEvent` carries `compactionEntry` (the `CompactionEntry`), `fromExtension`, `reason`, and `willRetry`.

### session_compact_failed

Fired when manual or automatic compaction fails or is aborted. This is useful for telemetry extensions that need to pair `session_before_compact` attempts with terminal outcomes.

```php
use Pig\CodingAgent\Extensions\Events\SessionCompactFailedEvent;

$pig->on('session_compact_failed', function (SessionCompactFailedEvent $event, ExtensionContext $ctx): void {
    // $event->reason        - 'manual' (/compact), 'threshold', or 'overflow'
    // $event->errorMessage  - present for non-abort failures, prefixed "Compaction failed: ",
    //                         "Auto-compaction failed: ", or "Context overflow recovery failed: "
    // $event->aborted       - true for cancelled/aborted compactions
    // $event->willRetry     - always false: a turn whose recovery failed is not sent again
    // $event->fromExtension - whether extension-provided compaction content was being used
});
```

### session_before_tree

Fired before `/tree` navigation. Always fires regardless of whether user chose to summarize. Can cancel navigation or provide a custom summary.

```php
use Pig\CodingAgent\Extensions\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Extensions\Results\SessionBeforeTreeResult;

$pig->on('session_before_tree', function (SessionBeforeTreeEvent $event, ExtensionContext $ctx) {
    $preparation = $event->preparation;

    // $preparation->targetId            - where we're navigating to
    // $preparation->oldLeafId           - current position (being abandoned)
    // $preparation->commonAncestorId    - shared ancestor
    // $preparation->entriesToSummarize  - entries that would be summarized
    // $preparation->userWantsSummary    - whether user chose to summarize
    // $preparation->customInstructions, ->replaceInstructions, ->label

    // Cancel navigation entirely:
    return new SessionBeforeTreeResult(cancel: true);

    // Provide a custom summary (only used if userWantsSummary is true):
    if ($preparation->userWantsSummary) {
        return new SessionBeforeTreeResult(summary: [
            'summary' => 'Your summary...',
            // 'usage' => $usage, // optional; included in session totals
            'details' => ['readFiles' => [], 'modifiedFiles' => []],
        ]);
    }

    return null;
});
```

A result can also override `customInstructions`, `replaceInstructions`, and `label` for pig's own summarizer. See `SessionBeforeTreeEvent`, `TreePreparation`, and `SessionBeforeTreeResult`. After navigation, `session_tree` (`SessionTreeEvent`) carries `newLeafId`, `oldLeafId`, `summaryEntry`, and `fromExtension`.

## Settings

Configure compaction in `~/.pig/agent/settings.json` or `<project-dir>/.pig/settings.json`:

```json
{
  "compaction": {
    "enabled": true,
    "reserveTokens": 16384,
    "keepRecentTokens": 20000
  }
}
```

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `true` | Enable auto-compaction |
| `reserveTokens` | `16384` | Tokens to reserve for LLM response |
| `keepRecentTokens` | `20000` | Recent tokens to keep (not summarized) |

Disable auto-compaction with `"enabled": false`. You can still compact manually with `/compact`.

### Per-model overrides

Use `compaction.modelOverrides` to tune token budgets for different models:

```json
{
  "compaction": {
    "reserveTokens": 16384,
    "keepRecentTokens": 20000,
    "modelOverrides": {
      "some-provider/big-model": {
        "reserveTokens": 400000
      }
    }
  }
}
```

For a model with a 1M context window, this override triggers compaction above 600K tokens and keeps the ordinary 20000 recent tokens. Other models retain the ordinary 16384-token reserve. `reserveTokens` also influences summarization output limits, capped by the model's maximum output tokens; it is not solely a trigger threshold.

Keys are exact, case-sensitive `provider/modelId` values, including any slashes within the model ID. Each `reserveTokens` and `keepRecentTokens` value falls back independently from the model override to the ordinary setting to the built-in default. Values must be non-negative safe integers. Invalid values in the matching model override produce an error when read; only omitted fields fall back to the ordinary setting. Model override entries must be objects. Invalid ordinary token settings produce an error when read, even if the active model has a valid override. Only omitted ordinary values use built-in defaults. `enabled` remains global, not model-specific.

These resolved values are used for manual compaction, all automatic threshold checks, overflow recovery, and extension-visible `preparation.settings`. Model switches affect subsequent checks and compactions without changing ordinary settings. Compaction already in progress uses the model and settings captured for that operation. Branch summarization settings are unaffected.

Overrides work in both global and project settings. Settings merge only one level deep: keys directly under `compaction` merge, but a project `compaction.modelOverrides` object replaces the global one entirely, so a project that sets any model override must repeat the global entries it wants to keep. See [Settings](/docs/latest/settings#per-model-compaction-overrides) for details.
