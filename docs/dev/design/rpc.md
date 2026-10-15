# Design: The other modes

`--mode rpc`, print and JSON mode, `--mode mcp`. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [`--mode mcp`: pig as an MCP server, and why it is one tool over HTTP](#--mode-mcp-pig-as-an-mcp-server-and-why-it-is-one-tool-over-http)
- [RPC mode — the second way in](#rpc-mode--the-second-way-in)

## `--mode mcp`: pig as an MCP server, and why it is one tool over HTTP

**Decided 2026-10-09, with the developer.** The question was "pig has http and websocket; can it do
SSE and VLESS?" and the answer split in two. SSE as a *server* had no consumer — the Web UI already
has one WebSocket carrying everything — until the developer named one: pig as an MCP server, where
MCP's Streamable HTTP transport answers a request as `text/event-stream`. So `Web\Protocols\Sse`
exists for that and nothing else: `open()` writes the head and switches the connection, `encode()`
frames one event, the body ends by closing (`Connection: close`), and `TcpConnection::closeAfterSend()`
is the one new method, because `close()` drops whatever is still in the send buffer and an SSE
response's last event would have gone with it.

Three choices, each asked rather than assumed:

- **One tool, `ask`**, not the 24 RPC commands as tools and not the built-in tools re-exported.
  `ask(prompt)` is a turn in this process's one `AgentSession`, answered with the last assistant
  message's text blocks — `PrintMode::say()`'s reading, as a tool result. Calls are serial **and
  queued**: the first draft answered a second `ask` during a turn with an `isError` "busy", and the
  developer asked for a queue instead — a caller with two questions means both, and "try later" is
  a retry loop every caller would have had to write. `McpDispatcher::enqueue()`/`drain()`, one
  fiber at a time, arrival order; the HTTP stream's keep-alive runs while the call waits.
- **Streamable HTTP first, stdio second.** `POST /mcp`: `initialize` / `ping` / `tools/list` as
  one JSON document, `tools/call` as an SSE stream — `notifications/progress` per text delta when
  the caller sent a `progressToken`, a `: keep-alive` comment every 15 s otherwise, the result last.
  `GET` is 405 (nothing server-initiated to stream), `DELETE` forgets the `Mcp-Session-Id`; a stale
  id is 404, which the spec defines as "re-initialize", so a client outliving a restart recovers on
  its own. **JSON or stream is decided by *when* the answer comes**, not by the method: a `$send`
  called while `dispatch()` is still on the stack (including a `tools/call` with no `prompt`) is a
  JSON document, and a `dispatch()` that returns without answering opens the stream. `--mcp-stdio`
  is the same `McpDispatcher` over lines on stdin/stdout (`McpStdio`), with no session id and no
  keep-alive because a pipe needs neither; stdin closing stops the loop, as in `RpcMode`.
- **`--session` / `-c` needed nothing**: `bin/pig` resolves them before the mode is picked and
  hands every mode a session over that store, so `pig --mode mcp --session <id>` was already a
  server over that conversation. Pinned by `McpStdioTest::testAnAskIsATurnInTheSessionFileItWasStartedWith`
  rather than built twice.
- **`--mode mcp`**, not `pig mcp serve`: `pig mcp` is the MCP *client* extension's CLI (`login`,
  `status`), and a server command under it would be the one word meaning both sides.

What the mode does not do, on purpose: no in-process reuse of `HttpServer` (it carries the page, the
pool and the WebSocket; `McpServer` is the same `Connection` + `Http` framing with those taken out),
no batch requests (removed from the spec in 2025-06-18).

Two things the first draft left for asking, both answered the same day: `$ctx->mode()` answers
**`mcp`** — a fifth value beside upstream's four, so a hook can tell an MCP caller from an RPC host
(the developer asked for it rather than the draft's `rpc`). And the twelve-closure
`$hooks->initialize(...)` block was the same in `PrintMode`, `RpcMode`, `InteractiveMode` (twice)
and would have been the fifth copy in `McpMode`; it is **`HookRunner::wire($session, $ui)`** now,
and what each mode keeps for itself is `onError()`, since where a broken hook is reported is the
one thing that differed.

**VLESS was declined for core and built as `extensions/pig-vless`.** The developer's real goal was
an Xray-like inbound so a phone could proxy through the Mac. It is a public-facing proxy server with
no relation to the agent, and Xray / sing-box already do it with REALITY, UDP and probing resistance
pig would never catch up to — so not `packages/`, and the smallest version that a phone can use:

- **TCP command only**; UDP (2) and MUX (3) close the connection. UDP over a stream needs
  per-packet framing on both ends, and Shadowrocket falls back to direct for UDP when the proxy
  declines it, which is fine for a phone.
- **The relay is two `Web\Connection`s over a `Raw` protocol** — `TcpConnection`'s buffered,
  backpressured socket with the framing taken out, one for the phone and one for the far end,
  each `sendRaw()`ing what the other reads. The outbound connect is `STREAM_CLIENT_ASYNC_CONNECT`
  plus one writable wakeup, with `stream_socket_get_name(…, true) === false` as "refused". The
  name lookup inside `stream_socket_client()` is the one blocking call, and the price of simplest.
- **TLS is optional and done after accept.** The listener is plain `tcp://` even with a cert,
  and `stream_socket_enable_crypto(STREAM_CRYPTO_METHOD_TLS_SERVER)` is stepped on readable
  until it returns true — because `stream_socket_accept()` on a `tls://` listener does the
  whole handshake blocking, and a phone on a slow link would hold the loop for everyone.
- **Configured on load, opened on request.** `<cwd>/extensions/` loads for anyone running pig
  in this repository, so the extension writes its settings on `session_start` — listen address
  and a UUID, made once and kept so the phone's link survives restarts (the developer chose
  generate-and-save over refuse-and-explain) — and listens on nothing until `/vless start`.
  `/vless stop | restart | status` as `/web` has them; the bare `/vless` is `status`, not
  `start` as `/web`'s is, because a proxy port is not something to open by a slip of the
  fingers. (The first cut gated on a `"vless": {}` key instead; the developer asked for this.)
  A wrong UUID or a non-VLESS first byte closes the socket without a word, since a reply would
  tell a scanner what is listening.

Regression tests: `Extensions\VlessServerTest` — an echo server on one loopback port, the inbound
on another, a hand-built header as the phone: the `\x00\x00` response head then the relayed echo,
a domain address, a header split across two writes, the wrong UUID and a UDP command and an HTTP
request all closed silently, the parser over all three address types, and the share link.

Regression tests: `Mcp\McpServerTest` — a real loopback listener and a real `Async\Socket` into it,
asserting the bytes an MCP client reads: the session id header, version negotiation, 202 for a
notification, 404 for a stale id, 405 for GET, the SSE frames of an `ask` with and without a
progress token, a failed turn as an `isError` result, two asks landing in one transcript, and two
asks *at once* both answered in order (`testAnAskDuringAnAskWaitsItsTurnRatherThanFailing`).
`Mcp\McpStdioTest` — the lines both ways, a non-JSON line as a parse error that ends nothing,
progress then result as two lines, the session file holding the turn, and EOF stopping the loop.
`ArgumentsTest::testMcpModeNeedsNoMessageBecauseItsMessagesArriveAsToolCalls`.

## RPC mode — the second way in

`Rpc\RpcMode` is `bin/pig --mode rpc`: JSON lines on standard input, JSON lines on standard
output, no terminal at all. Three kinds of line come out — a `response` to a command, an event as
the agent works, and an `extension_ui_request` when a hook wants to ask something — and `id`, when
a command carries one, is echoed on its response.

**The wire is upstream's**, every command, field, response and event name (`rpc-types.ts`,
`rpc-mode.ts`, `json-event.ts`), so a client written against `pi --mode rpc` drives pig unchanged.
pig used to have its own dialect (`hook_ui_request`, `get_branch`, `go_to`, `export`,
`auto_compaction_*`, full-message updates) that no upstream client could talk to; it was replaced
outright, with no compatibility layer. pig's additions are additive and
named: extra `get_state` fields a web tab draws, `navigate_tree`, five commands the web shell
needs from its child (`doctor`, `diff`, `bug`, `export_markdown`, `translate`), `continue`, and `prompt`'s
`requestId` — pi-durable's: a prompt sent again under an id the conversation already took is answered
`{disposition: "duplicate"}` and not taken twice. It is kept as a `pig.request` custom entry when the
prompt is accepted, so it holds across a restart; a refused one is not kept and may be sent again.

- **Commands** are upstream's set: `prompt` (with `streamingBehavior`; answered **once**, with
  `{disposition}`, when it is known what became of the prompt), `steer` / `follow_up` (images,
  `disposition`; a hook command is refused there), `abort`, `clear_queue`, `new_session`
  (`parentSession`), `get_state`, `set_model`, `cycle_model`, `get_available_models`,
  `set_thinking_level` (clamped to the model, as `AgentSession::setThinkingLevel()` clamps),
  `cycle_thinking_level`, `get_available_thinking_levels`, `set_steering_mode`,
  `set_follow_up_mode`, `compact` (returns the `CompactionResult` with `estimatedTokensAfter`),
  `set_auto_compaction`, `set_auto_retry`, `abort_retry`, `bash` (`excludeFromContext`), `abort_bash`,
  `get_session_stats`, `export_html`, `switch_session`, `fork`, `clone`, `get_fork_messages`,
  `get_entries` (since a cursor), `get_tree`, `get_last_assistant_text`, `set_session_name`,
  `get_messages`, `get_commands`. `navigate_tree {targetId, summarize, customInstructions,
  replaceInstructions, label}` answers `{cancelled, aborted?, editorText?, summaryEntry?}` — pig's, under
  the name and shape upstream's SDK method has.
- **Events**: `message_update` carries `{usage, assistantMessageEvent}` with deltas only
  (`text_delta`, `toolcall_start/delta/end` …), never the whole message so far; tool events carry
  `args` / `partialResult`; `agent_settled`, `queue_update`, `entry_appended` and
  `thinking_level_changed` are emitted; `auto_retry_start/end`; `compaction_start/end` for manual,
  threshold and overflow alike; `bash_execution_update` streams a `bash` command's output.
  `--mode json` writes the session header first.
- **Extension UI**: `extension_ui_request` / `extension_ui_response`, a UUID `id` on every request,
  `setStatus` / `setWidget` / `setTitle` and `notify` (`notifyType`) told rather than asked, widget
  placement `aboveEditor` / `belowEditor`, `pasteToEditor` as `set_editor_text`. The TUI-only calls
  (`setWorkingMessage()` and the rest) send nothing, as upstream's do. A dialog `timeout` is honoured
  by every `HookUi` (the terminal counts it down).
- **Errors**: every failure is a `success: false` response rather than a disconnection; a line that
  is not JSON is `Failed to parse command: …`; a trailing CR is stripped. A broken extension is
  `extension_error {extensionPath, event, error}`; a custom tool that failed to start is pig's
  `tool_error`.

**Standard input goes on the same loop as the model's socket**, through `Loop::onReadable()`.
`RpcUi` is `HookUi` over the wire: a hook calling `confirm()` parks its fiber on a `Deferred` exactly
as it does in the terminal, and the line that resumes it is an `extension_ui_response` instead of a
keystroke. `readline()` would have blocked the loop that has to deliver it. A host may answer several
questions in any order, so there is no "one thing in the overlay at a time"; `custom()` returns null
because there is nothing to draw a `Pig\Tui\Component` on.

Messages go out through `SessionCodec`, so a host reading `get_messages` and a `--continue` reading
the file see the same shape. `RpcEvents` is the one place the wire shape of an agent event is
written down: upstream calls `JSON.stringify(event)`, which also means a field rename there changes
the protocol silently — so a rename upstream is a rename here (`RpcModeTest` pins the shapes).

`rpc-client.ts` **is** ported, as `Rpc\RpcClient`, with upstream's method names (`getState()`,
`getAvailableModels()`, `exportHtml()`, `getTree()`, `getEntries()`, `getCommands()`, `clone()`, …);
`waitForIdle()`, `collectEvents()` and `promptAndWait()` wait for `agent_settled`, and the web
`SessionPool` takes a tab as idle there too. `rpc-types.ts` is not ported as types: its unions
describe JSON that arrived from outside the process, which a static type cannot check, and the host
at the other end is deliberately not PHP. The runtime checks in `RpcMode` are the contract, and a
class per command would need the same checks to construct itself. Typing `RpcClient`'s
`array<string, mixed>` returns would help a PHP host, and is not done because no defect has been
traced to their absence.

Not done yet: `get_commands`' `sourceInfo` keeps less provenance than upstream's `SourceInfo`.

`SessionCodec::encodeContent()` and `plain()` went from private to public for `RpcEvents`.
Widening it was the cheaper of two bad options: a second content encoder living in `Rpc\` is
two things that can disagree about what a message looks like, and they would disagree the
first time a content type gained a field.

What is left unported, across every package, each for a reason:

| Upstream | Why not |
|---|---|
| eleven of the selector components, as files | every one of them is here as something else, and the audit that checked it is below: `hook-selector`, `hook-editor` and `hook-input` are `TerminalUi::select()`, `editor()` and `input()`; `queue-mode`, `show-images`, `thinking` and `settings-selector` are `/settings`' rows plus `thinkingSubmenu()`; `theme-selector` is that list's theme row; `oauth-selector` is `showSignIns()`; `session-selector` is `Cli\SessionPicker`; `model-selector` is `showModels()`. **`tree-selector.ts` is no longer among them** — it is `Interactive\TreeList` |
| `coding-agent/modes/interactive/components/user-message-selector.ts` | the **twelfth** selector, here as `InteractiveMode::showForkPoints()` — `/fork`'s list of every message somebody typed, a `SelectList` rather than a component of its own, as `/login` and `/model` are. Upstream also opens it on a **double Escape** with an empty prompt; pig's Escape stops whatever is running and does nothing when nothing is, so that gesture has no meaning here |
| `ai/utils/typebox-helpers.ts` (24) | `StringEnum`, a TypeBox helper that emits `{type:"string", enum:[…]}` because TypeBox's own `Type.Enum` emits `anyOf`/`const` and Google's API rejects that. In PHP a schema **is** an array, so there is nothing to help with — you write the array, and `JsonSchemaTest` says so where the enum is tested |
| `coding-agent/modes/rpc/rpc-types.ts` | 203 lines of types for JSON that arrives from outside the process, where a static type guarantees nothing and the runtime checks are the contract — the long version is in the RPC section. `RpcMode`'s docblock plus `RpcEvents` is where the wire shape is written down. Its `rpc-client.ts` **is** ported, as `Rpc\RpcClient` |
| every `index.ts` | barrel re-exports, which is what an autoloader does here |

**This table has now been wrong four times.** Twice in the same way: the first time it said "the
rest is left out on purpose" while `modes/print-mode.ts` and `cli/file-processor.ts` were simply
never listed, and the second time it covered `coding-agent` only, while `agent/proxy.ts`,
`ai/cli.ts`, `ai/utils/validation.ts` and `tui/components/settings-list.ts` were unported and
unmentioned — and the `pig/tui` paragraph said "done" with a file missing.

The third was the other direction, and is the more interesting one: the table claimed
`visual-truncate.ts` was unported, with a confident paragraph about pig slicing logical lines
where upstream counts wrapped rows. **It was ported, as `Interactive\BashOutputComponent`,
before the row was written.** The mistake was comparing upstream's bash branch against pig's
*generic* branch: upstream truncates visually for bash output only, and its generic tool output
is `lines.slice(0, maxLines)` — logical and head-first, which is exactly what
`ToolExecutionComponent::cut()` does. Both halves already matched; the row compared the wrong
halves.

The fourth was the first kind again — `model-registry.ts`'s `models.json` half simply never
listed, and now ported rather than listed — and it is the one that shows what the sweep is
actually for. The file *has* a counterpart: the built-in registry, key resolution and lookup are all in pig. Reading the row
"`model-registry.ts` → `Ai\Models` + `ModelResolver`" and stopping there is how 180 lines of it
stayed invisible. **A name with a counterpart is not a file that is ported; it is a file worth
reading to the end.**

**And a ported file is worth a difference-by-difference audit, not only a look at the parts that
felt like decisions.** `Migrations` was reviewed that way after the fact and it found two real
gaps — a `glob` that skipped dotfiles where upstream scans, and a message in pig's own words where
upstream has one — plus an entry in this file claiming a deviation from upstream that was only a
deviation from the first draft. Every difference either has a reason written next to it or is a
bug; there is no third category, and the ones without a reason are the ones nobody looked at.

So the sweep below finds names, and **a missing name is a question, not an answer.** A behaviour
can be ported into a file called something else — pig's is a component rather than a function,
because only `render()` knows the terminal's width. Before writing "not ported", read what the
upstream file does and grep pig for the behaviour, which is the same evidence a "done" claim
needs.

**The last run of it went five names deep and the five answers were all different**, which is what
that rule is worth in practice. `tui/components/cancellable-loader.ts` and `truncated-text.ts` are
`Components\CancellableLoader` and `Components\TruncatedText` — ported, never named here.
`utils/mime.ts` is `Tui\Images\ImageType::ofFile()`, and its two upstream callers, `read.ts` and
`file-processor.ts`, are `ReadTool` and `Cli\FileArguments` here calling the same one.
`user-message-selector.ts` is the row above. And `core/bash-executor.ts` had a real find in it —
the entry on cleaning a command's output at the source.

So the rule, which is the same rule and a wider sweep: **a claim that nothing is missing is worth
checking against the file list rather than against memory, in every package and not just the one
being worked on.** One command does it:

```bash
for p in ai agent tui coding-agent; do
  find /tmp/pi/packages/$p/src -name '*.ts' -not -name '*.test.ts' -not -name 'index.ts' | sort
done
```

Read it against pig's own tree, and anything with no counterpart belongs in this table or in the
code. It is worth running after a run of porting, not during one — during one, every second file
is legitimately absent.

**And the command names four packages where upstream has seven, which makes the command itself a
claim.** Nobody had checked it, so it was checked: `mom` (4,440 lines, a Slack bot that delegates to
the coding agent), `pods` (1,773, a CLI for vLLM deployments on GPU pods) and `web-ui` (14,524, lit
components for a browser chat UI) are outside pig's scope, and the evidence rather than the reading
is that **the dependency direction is one-way** — nothing in `ai`, `agent`, `tui` or `coding-agent`
imports from any of the three, and all three import from them. So the four are the whole of what pig
ports, and ~20,700 lines of upstream are deliberately not in the scoreboard below. The last run of
the sweep also confirmed the scoreboard's own arithmetic exactly: 127 files, 42,644 lines, no file
new and none gone, 114 audited + 13 ruled out with no overlap and no gap.

**`mom` is still worth reading, and not as a port.** It re-implements five of the tools — `read`,
`write`, `edit`, `bash` and `truncate` — against a sandbox executor rather than the filesystem, so it
is upstream's own second answer to questions pig has already answered, which is the *first* shape
from the index pointed at upstream instead of at pig. What that found is the entry below on the diff
renderer. `truncate.ts` there is a strict subset of `coding-agent`'s (no `maxLines`/`maxBytes` on the
result, no `truncateLine` for grep) with the same arithmetic, and the tools differ structurally
because they shell out — mom's `read` even detects an image by *extension* where `coding-agent`'s
reads the file's magic. pig ported the fuller copy in every case, which is worth knowing was a
verified answer rather than the only one available.
