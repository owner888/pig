# Message Types

pig uses `AgentMessage` values in SDK state, lifecycle events, RPC responses, and persisted session message entries. This page defines those shared messages and their content blocks.

The definitions below use TypeScript interface notation to describe the JSON shape of each value, as it appears in session files and RPC/JSON output; in PHP each is a class under `Pig\Ai`, `Pig\Agent`, or `Pig\CodingAgent\Session`. Message timestamps are Unix timestamps in milliseconds. They are different from the ISO 8601 timestamps on [session entries](/docs/latest/session-format#entry-base).

Source definitions:

- [`packages/ai/src/Context.php`](https://github.com/owner888/pig/blob/main/packages/ai/src/Context.php) defines provider-facing messages and content blocks.
- [`packages/agent-core/src/AgentState.php`](https://github.com/owner888/pig/blob/main/packages/agent-core/src/AgentState.php) defines the extensible `AgentMessage` union.
- [`packages/coding-agent/src/Session/SessionCodec.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Session/SessionCodec.php) adds coding-agent message roles.

## Content blocks

### TextContent

```typescript
interface TextContent {
  type: "text";
  text: string;
  textSignature?: string;
}
```

`textSignature` contains provider-specific message metadata. Treat it as opaque.

### ImageContent

```typescript
interface ImageContent {
  type: "image";
  data: string;
  mimeType: string;
}
```

`data` is base64-encoded image data. `mimeType` identifies its media type, such as `image/png` or `image/jpeg`.

### ThinkingContent

```typescript
interface ThinkingContent {
  type: "thinking";
  thinking: string;
  thinkingSignature?: string;
  redacted?: boolean;
}
```

Thinking signatures contain provider-specific replay data. Treat them as opaque. A redacted block can have no visible thinking text while retaining an encrypted payload in `thinkingSignature`.

### ToolCall

```typescript
interface ToolCall {
  type: "toolCall";
  id: string;
  name: string;
  arguments: Record<string, any>;
  thoughtSignature?: string;
  namespace?: string;
}
```

`thoughtSignature` is provider-specific. `namespace` identifies an OpenAI Responses namespace for dynamically loaded or namespaced tools.

## Usage

Assistant messages always contain usage. Tool results can contain usage when the tool performed nested model work.

```typescript
interface Usage {
  input: number;
  output: number;
  cacheRead: number;
  cacheWrite: number;
  cacheWrite1h?: number;
  reasoning?: number;
  totalTokens: number;
  cost: {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
    total: number;
  };
}
```

When present, `reasoning` is already included in `output`; do not add it again. `cacheWrite1h` is the subset of `cacheWrite` written with one-hour retention.

## Base messages

### SystemMessage

```typescript
interface SystemMessage {
  role: "system";
  content: string | TextContent[];
  sections?: Record<string, string | null>;
  toolsAdded?: Tool[];
  toolsRemoved?: ToolReference[];
  replace?: boolean;
  timestamp: number;
}
```

The leading system message declares the initial prompt and tools. Later system messages can append instructions, replace or remove named prompt sections, and add or remove tools. Replaying them in order yields the current state. A message with `replace: true` discards the earlier state and establishes a complete new baseline.

### UserMessage

```typescript
interface UserMessage {
  role: "user";
  content: string | (TextContent | ImageContent)[];
  timestamp: number;
}
```

### AssistantMessage

```typescript
interface AssistantMessage {
  role: "assistant";
  content: (TextContent | ThinkingContent | ToolCall)[];
  api: string;
  provider: string;
  model: string;
  responseModel?: string;
  responseId?: string;
  providerThinkingLevel?: string;
  diagnostics?: AssistantMessageDiagnostic[];
  usage: Usage;
  stopReason: "pending" | "stop" | "length" | "toolUse" | "error" | "aborted" | "deferred";
  deferred?: DeferredHandle;
  errorMessage?: string;
  rawStopReason?: string;
  endTurn?: boolean;
  timestamp: number;
}
```

`responseModel` records a concrete provider response model when it differs from the requested model. `responseId`, `providerThinkingLevel`, `diagnostics`, and `rawStopReason` preserve provider or runtime details.

`"pending"` is used for a partial assistant message while it streams. The completed message in `message_end` has a terminal stop reason, and pig does not persist `"pending"` assistant messages in session JSONL.

A `"deferred"` response has a `DeferredHandle` with the provider data needed to retrieve it:

```typescript
interface DeferredHandle {
  provider: string;
  modelId: string;
  api: string;
  id: string;
  expiresAt?: number;
  pollAfterMs?: number;
  data?: JsonValue;
}
```

### ToolResultMessage

```typescript
interface ToolResultMessage<TDetails = any> {
  role: "toolResult";
  toolCallId: string;
  toolName: string;
  content: (TextContent | ImageContent)[];
  details?: TDetails;
  usage?: Usage;
  nestedCalls?: NestedToolCalls;
  isError: boolean;
  timestamp: number;
  durationMs?: number;
}

interface NestedToolCalls {
  calls: NestedToolCallRecord[];
  complete: boolean;
}

interface NestedToolCallRecord {
  id: string;                     // "<parent call id>/<n>"
  name: string;
  arguments?: Record<string, any>; // left out when over the size limits
  argumentsBytes?: number;         // their size, when they were left out
  status: "ok" | "error" | "unfinished";
  durationMs?: number;
  error?: string;                  // cut to 500 characters
}
```

`details` is tool-specific. Optional `usage` reports nested model work performed by the tool and contributes to full-session statistics, but it is not part of the main model-call usage. `durationMs` is how long the tool ran, measured with a monotonic clock; it is absent for a call that never ran and for results written before pig recorded it.

`nestedCalls` is the record of the calls the tool made to other tools while it ran — a [`codemode`](/docs/latest/cli#how-codemode-works) script's `$tools->read(...)`. It is kept for the session record and never sent to the model; their results are not recorded, only their names, arguments, outcome and time. It is bounded: at most 256 calls, 8 KB of arguments per call and 32 KB in all — arguments past that are left out with their size kept, calls past the count are dropped, and `complete` is `false` when either happened or a call had not finished when the tool did. What those calls spent on models is added to the result's `usage`.

## Coding-agent messages

The coding-agent package extends `AgentMessage` with four roles.

### BashExecutionMessage

Created by direct shell commands: `!` and `!!` in the terminal (`!!` sets `excludeFromContext`) and the RPC [`bash`](/docs/latest/rpc-commands#bash) command. It is not an LLM tool result. The PHP class is `Pig\CodingAgent\Session\BashExecutionMessage`.

```typescript
interface BashExecutionMessage {
  role: "bashExecution";
  command: string;
  output: string;
  exitCode: number | undefined;
  cancelled: boolean;
  truncated: boolean;
  fullOutputPath?: string;
  excludeFromContext?: boolean;
  timestamp: number;
}
```

Unless `excludeFromContext` is true, pig converts this message to user-role text before the next model request.

### CustomMessage

Created when an extension sends a context message.

```typescript
interface CustomMessage<T = unknown> {
  role: "custom";
  customType: string;
  content: string | (TextContent | ImageContent)[];
  display: boolean;
  details?: T;
  timestamp: number;
}
```

pig converts its content to a user message for model requests. `display` controls terminal rendering; `details` is not sent to the model.

### BranchSummaryEntryMessage

```typescript
interface BranchSummaryEntryMessage {
  role: "branchSummary";
  summary: string;
  fromId: string | null;
  timestamp: number;
}
```

pig creates this context message from a persisted `branch_summary` entry.

### CompactionEntryMessage

```typescript
interface CompactionEntryMessage {
  role: "compactionSummary";
  summary: string;
  tokensBefore: number;
  timestamp: number;
}
```

pig creates this context message from a persisted `compaction` entry.

## AgentMessage union

In the coding agent, the union is equivalent to:

```typescript
type AgentMessage =
  | SystemMessage
  | UserMessage
  | AssistantMessage
  | ToolResultMessage
  | BashExecutionMessage
  | CustomMessage
  | BranchSummaryEntryMessage
  | CompactionEntryMessage;
```

At the lower-level agent-core package, `AgentMessage` is a marker interface. Applications can add custom roles by implementing this interface, so consumers should tolerate unknown custom roles when accepting messages from an augmented host.
