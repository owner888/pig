# Session File Format

Sessions are stored as JSONL (JSON Lines) files. Each line is a JSON object with a `type` field. Session entries form a tree structure via `id`/`parentId` fields, enabling in-place branching without creating new files.

For programmatic creation, persistence, and tree navigation, see the [PHP SDK Guide](/docs/latest/sdk) and `Pig\CodingAgent\Session\SessionManager`.


## File Location

```
~/.pig/agent/sessions/--<path>--/<timestamp>_<session-id>.jsonl
```

By default, `<session-id>` is a UUID. Callers can supply a custom ID through the SDK (`sessionId:`) or `--session-id`. For `<path>`, pig removes the leading path separator and replaces `/`, `\\`, and `:` with `-`. `--session-dir`, `PIG_CODING_AGENT_SESSION_DIR`, or the `sessionDir` setting put every session in one directory instead. pig also lists and resumes sessions pi wrote under `~/.pi/agent/sessions/`; the file name format is the same.

## Deleting Sessions

Sessions can be removed by deleting their `.jsonl` files under `~/.pig/agent/sessions/`.

pig also supports deleting sessions interactively from `/resume` (select a session and press `Ctrl+D`, then confirm); the current session cannot be deleted. When available, pig uses the `trash` CLI to avoid permanent deletion, and deletes the file otherwise.

## Session Version

Sessions have a version field in the header:

- **Version 1**: Linear entry sequence (legacy, auto-migrated on load)
- **Version 2**: Tree structure with `id`/`parentId` linking
- **Version 3**: Renamed `hookMessage` role to `custom` (extensions unification)

Existing sessions are automatically migrated to the current version (v3) when loaded.

## Source Files

Source on GitHub ([pig](https://github.com/owner888/pig)):
- [`packages/coding-agent/src/Session/SessionManager.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/SessionManager.php) - SessionManager, tree navigation, and context projection
- [`packages/coding-agent/src/Session/SessionEntries.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/SessionEntries.php) - Reading and writing entry lines
- [Message Types](/docs/latest/message-types) - Shared message and content-block reference
- [`packages/coding-agent/src/Session/SessionCodec.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/SessionCodec.php) - Extended message types
- [`packages/ai/src/Context.php`](https://github.com/owner888/pig/blob/main/packages/ai/src/Context.php) - Base message and content-block types
- [`packages/agent-core/src/AgentState.php`](https://github.com/owner888/pig/blob/main/packages/agent-core/src/AgentState.php) - Extensible `AgentMessage` union

For session and message definitions in your project, inspect `packages/coding-agent/src/Session/` and `packages/ai/src/`.

## Messages

A `message` entry stores an [`AgentMessage`](/docs/latest/message-types). Message content blocks, roles, usage, and message timestamps are defined in [Message Types](/docs/latest/message-types).

Session entry timestamps are ISO 8601 strings. The nested message timestamp is a Unix timestamp in milliseconds.

## Entry Base

All entries (except `SessionHeader`) extend `SessionEntryBase`:

```typescript
interface SessionEntryBase {
  type: string;
  id: string;           // Usually an 8-char hex ID; may fall back to a full UUID
  parentId: string | null;  // Parent entry ID (null for a root entry)
  timestamp: string;    // ISO timestamp
}
```

## Entry Types

### SessionHeader

First line of the file. Metadata only, not part of the tree (no `id`/`parentId`).

```json
{"type":"session","version":3,"id":"uuid","timestamp":"2024-12-03T14:00:00.000Z","cwd":"/path/to/project"}
```

For sessions with a parent (created via `/fork`, `/clone`, `--fork`, or RPC `new_session` with `parentSession`):

```json
{"type":"session","version":3,"id":"uuid","timestamp":"2024-12-03T14:00:00.000Z","cwd":"/path/to/project","parentSession":"/path/to/original/session.jsonl"}
```

### SessionMessageEntry

A message in the conversation. The `message` field contains an `AgentMessage`. System messages carry the prompt and tool loadout: the first request of a session persists one with every prompt section and tool declaration, and later changes persist as system messages that patch `sections` by name (`null` removes one) and list `toolsAdded`/`toolsRemoved`. Replaying them in order yields the current prompt and tools; there is no separate prompt state entry.

```json
{"type":"message","id":"a0b1c2d3","parentId":null,"timestamp":"2024-12-03T14:00:00.000Z","message":{"role":"system","content":"","sections":{"preamble":"You are an expert coding assistant...","tools":"<tools>\n- read: ...\n</tools>","cwd":"/project"},"toolsAdded":[{"name":"read","description":"...","parameters":{}}],"timestamp":1733234400000}}
{"type":"message","id":"d4e5f6g7","parentId":"c3d4e5f6","timestamp":"2024-12-03T14:04:00.000Z","message":{"role":"system","content":"","sections":{"skills":"<skills>...</skills>"},"toolsRemoved":[{"name":"write"}],"timestamp":1733234640000}}
```

Sessions created before system messages existed have no leading system message; the first request declares the current prompt as a later system message, which replays the same way.

```json
{"type":"message","id":"a1b2c3d4","parentId":"prev1234","timestamp":"2024-12-03T14:00:01.000Z","message":{"role":"user","content":"Hello","timestamp":1733234401000}}
{"type":"message","id":"b2c3d4e5","parentId":"a1b2c3d4","timestamp":"2024-12-03T14:00:02.000Z","message":{"role":"assistant","content":[{"type":"text","text":"Hi!"}],"api":"anthropic-messages","provider":"anthropic","model":"claude-sonnet-4-5","usage":{...},"stopReason":"stop","timestamp":1733234402000}}
{"type":"message","id":"c3d4e5f6","parentId":"b2c3d4e5","timestamp":"2024-12-03T14:00:03.000Z","message":{"role":"toolResult","toolCallId":"call_123","toolName":"bash","content":[{"type":"text","text":"output"}],"isError":false,"timestamp":1733234403000}}
```

Assistant messages name the model that produced them. Newer messages also record `thinkingLevel`, the pig thinking level requested for that response.

### ModelChangeEntry

Emitted when the user switches models mid-session. The latest entry is the selected model, which may be a [virtual model](/docs/latest/virtual-models); assistant messages then name the physical model that answered.

```json
{"type":"model_change","id":"d4e5f6g7","parentId":"c3d4e5f6","timestamp":"2024-12-03T14:05:00.000Z","provider":"openai","modelId":"gpt-4o"}
```

### ThinkingLevelChangeEntry

Emitted when the user changes the thinking/reasoning level.

```json
{"type":"thinking_level_change","id":"e5f6g7h8","parentId":"d4e5f6g7","timestamp":"2024-12-03T14:06:00.000Z","thinkingLevel":"high"}
```

### UsageEntry

Records model-attributed usage that is not an assistant message and does not participate in LLM context. `kind` is an arbitrary string identifying the operation; for example, cache warming uses `"cache_warm"`.

```json
{"type":"usage","id":"f6g7h8i9","parentId":"e5f6g7h8","timestamp":"2024-12-03T14:08:00.000Z","kind":"cache_warm","provider":"anthropic","model":"claude-sonnet-4-5","usage":{"input":0,"output":0,"cacheRead":50000,"cacheWrite":0,"totalTokens":50000,"cost":{"input":0,"output":0,"cacheRead":0.015,"cacheWrite":0,"total":0.015}}}
```

Usage entries contribute to session token and cost totals. pig hides them from the conversation tree. Consumers should treat unknown `kind` values as normal usage rather than rejecting them.

### CompactionEntry

Created when context is compacted. Stores a summary of earlier messages and a complete system prompt/tool checkpoint.

```json
{"type":"compaction","id":"f6g7h8i9","parentId":"e5f6g7h8","timestamp":"2024-12-03T14:10:00.000Z","summary":"User discussed X, Y, Z...","firstKeptEntryId":"c3d4e5f6","tokensBefore":50000,"systemMessage":{"role":"system","content":"You are a coding assistant.","toolsAdded":[],"timestamp":1733235000000}}
```

`firstKeptEntryId` is required. It identifies the first entry retained from before the compaction entry. When rebuilding context, pig replaces older summarized entries with the compaction summary and keeps the range beginning at this entry. A retain-none compaction stores its own ID in this field, so no preceding entries are retained.

Optional fields:
- `systemMessage`: The replayed prompt sections and tool declarations at the compaction boundary; it becomes the leading system message of the compacted context, and system messages among the kept entries are dropped in its favor. It is absent on older session entries.
- `usage`: LLM usage from generating the summary; included in session token and cost totals
- `details`: The file lists `{ readFiles: string[], modifiedFiles: string[] }`. pig stores only these two keys, also for extension-provided summaries, and keeps the lists out of `summary`; the `<read-files>`/`<modified-files>` tags are added when the summary is shown to the model
- `fromHook`: `true` if generated by an extension, `false` or absent if pig-generated (legacy field name)

### ContextEditEntry

Append-only edit of one earlier context-producing entry. It changes only future model context; the target entry and its metadata remain unchanged in raw history, UI, exports, and session accounting.

```json
{"type":"context_edit","id":"g6h7i8j9","parentId":"f6g7h8i9","timestamp":"2024-12-03T14:11:00.000Z","targetId":"c3d4e5f6","replacement":null}
```

Targets may be user, assistant, tool-result, or custom-message entries. `replacement: null` omits the target from model context. A non-null `replacement` replaces only the target message content. String replacements for assistant and tool-result entries are normalized to one text block because those roles require content arrays. If several edits target the same entry, the latest edit on the active branch wins. Edits are branch-relative: navigating to a point before the edit reveals the target's original contribution again.

### BranchSummaryEntry

Created when switching branches via `/tree` with an LLM generated summary of the left branch up to the common ancestor. Captures context from the abandoned path.

```json
{"type":"branch_summary","id":"g7h8i9j0","parentId":"a1b2c3d4","timestamp":"2024-12-03T14:15:00.000Z","fromId":"f6g7h8i9","summary":"Branch explored approach A..."}
```

`parentId` is the entry from which the new branch continues. `fromId` is the previous leaf whose abandoned path was summarized.

Optional fields:
- `usage`: LLM usage from generating the summary; included in session token and cost totals
- `details`: File tracking data (`{ readFiles: string[], modifiedFiles: string[] }`); other keys are not stored
- `fromHook`: `true` if generated by an extension, `false` or absent if pig-generated (legacy field name)

### CustomEntry

Extension state persistence. Does NOT participate in LLM context.

```json
{"type":"custom","id":"h8i9j0k1","parentId":"g7h8i9j0","timestamp":"2024-12-03T14:20:00.000Z","customType":"my-extension","data":{"count":42}}
```

Use `customType` to identify your extension's entries on reload. Interactive mode can render custom entries via `$pig->registerEntryRenderer($customType, $renderer)`, but they still do not participate in LLM context.

pig stores [virtual model](/docs/latest/virtual-models) router state as custom entries with upstream's `customType` `pi.virtual-model-state` and `data` `{ provider, modelId, state }`. Other pig-written custom entries include `codemode-store` (codemode `store()` values) and `pig.bug-report` (a sent or exported `/bug` report).

### CustomMessageEntry

Extension-injected messages that DO participate in LLM context.

```json
{"type":"custom_message","id":"i9j0k1l2","parentId":"h8i9j0k1","timestamp":"2024-12-03T14:25:00.000Z","customType":"my-extension","content":"Injected context...","display":true}
```

Fields:
- `content`: String or `(TextContent | ImageContent)[]` (same as UserMessage)
- `display`: `true` = show in TUI with distinct styling, `false` = hidden
- `details`: Optional extension-specific metadata (not sent to LLM)

### LabelEntry

User-defined bookmark/marker on an entry.

```json
{"type":"label","id":"j0k1l2m3","parentId":"i9j0k1l2","timestamp":"2024-12-03T14:30:00.000Z","targetId":"a1b2c3d4","label":"checkpoint-1"}
```

A label entry without `label` clears the label.

### SessionInfoEntry

Session metadata (e.g., user-defined display name). Set via `/name`, `--name` / `-n`, RPC `set_session_name`, or `$pig->setSessionName()` in extensions.

```json
{"type":"session_info","id":"k1l2m3n4","parentId":"j0k1l2m3","timestamp":"2024-12-03T14:35:00.000Z","name":"Refactor auth module"}
```

The session name is displayed in the session selector (`/resume`) instead of the first message when set.

## Tree Structure

Entries normally form one tree, but navigation APIs can create multiple roots:
- A root entry has `parentId: null`; the first entry is initially the root
- Each non-root entry points to its parent via `parentId`
- Branching creates new children from an earlier entry
- The "leaf" is the current position in the tree
- Going back to before the first entry (`SessionManager::goTo(null)`) lets a later entry become another root

```
[user msg] ─── [assistant] ─── [user msg] ─── [assistant] ─┬─ [user msg] ← current leaf
                                                            │
                                                            └─ [branch_summary] ─── [user msg] ← alternate branch
```

## Context Building

pig walks from the current leaf to the root (`SessionManager::pathEntries()`), producing the active entry list while honoring compaction (upstream's `buildContextEntries()`):

1. Collects all entries on the path
2. If one or more `CompactionEntry` values are on the path, uses the latest one:
   - Includes the compaction entry first
   - Includes non-system entries from `firstKeptEntryId` up to, but not including, the compaction entry
   - Includes entries after the compaction entry
3. Preserves non-message entries in the selected range so interactive mode can render them

`SessionManager::buildSessionProjection()` then applies the latest `context_edit` for each selected target. It returns the model-visible messages together with their source entries. Omitted targets produce no message; replacements retain the source entry's role and metadata while changing only content. The raw selected entries are not modified.

The session builds on that projection to produce the message list for the LLM (`SessionManager::messages()`, upstream's `buildSessionContext()`):

1. Extracts current model and thinking level settings from the full path
2. Converts selected entries to messages:
   - `message` -> stored `AgentMessage`
   - `compaction` -> complete system checkpoint followed by `compactionSummary`
   - `branch_summary` -> `branchSummary`
   - `custom_message` -> `CustomMessage`
   - `context_edit` -> no context message of its own
   - `usage` and `custom` -> no context message

The compaction summary replaces entries before `firstKeptEntryId`. Pre-compaction system messages are folded into the complete checkpoint rather than replayed from the retained range. Retained non-system entries and all entries after the compaction remain available to the LLM.

## Parsing Example

```php
<?php

foreach (file('session.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $entry = json_decode($line, true);

    echo match ($entry['type']) {
        'session' => sprintf("Session v%d: %s\n", $entry['version'] ?? 1, $entry['id']),
        'message' => sprintf("[%s] %s: %s\n", $entry['id'], $entry['message']['role'], json_encode($entry['message']['content'] ?? null)),
        'compaction' => sprintf("[%s] Compaction: %d tokens summarized\n", $entry['id'], $entry['tokensBefore']),
        'branch_summary' => sprintf("[%s] Branch from %s\n", $entry['id'], $entry['fromId']),
        'usage' => sprintf("[%s] Usage (%s): %d tokens\n", $entry['id'], $entry['kind'], $entry['usage']['totalTokens']),
        'custom' => sprintf("[%s] Custom (%s): %s\n", $entry['id'], $entry['customType'], json_encode($entry['data'] ?? null)),
        'custom_message' => sprintf("[%s] Extension message (%s): %s\n", $entry['id'], $entry['customType'], json_encode($entry['content'])),
        'label' => sprintf("[%s] Label \"%s\" on %s\n", $entry['id'], $entry['label'] ?? '', $entry['targetId']),
        'model_change' => sprintf("[%s] Model: %s/%s\n", $entry['id'], $entry['provider'], $entry['modelId']),
        'thinking_level_change' => sprintf("[%s] Thinking: %s\n", $entry['id'], $entry['thinkingLevel']),
        default => '',
    };
}
```
