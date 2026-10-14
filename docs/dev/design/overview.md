# Design overview


```
packages/async/      → Pig\Async\        (pig/async)       Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           (pig/ai)          unified LLM API, HTTP/SSE, Anthropic; Extension\ for a provider an extension brings
packages/agent-core/ → Pig\Agent\        (pig/agent-core)  AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          (pig/tui)         renderer, widths, keys, editor, markdown, images, env
packages/coding-agent/ → Pig\CodingAgent\ (pig/coding-agent) tools, theme, session, interactive CLI
bin/pig                                     the entry point
```

**There are two `Paths` classes and that is deliberate.** `Pig\Tui\Paths` is a path as a *person*
typed or pasted it — a leading `~`, and the spaces macOS substitutes on the way to the clipboard —
and `Pig\CodingAgent\Tools\Paths` is the rest: resolving against a working directory, collapsing
`..`, the macOS screenshot retry. The split is which package can see which: the `@` file picker lives
in `pig/tui` and had its own worse copy of the first part until this, which is the third time that
has happened to the same fourteen lines. A grep for `Paths::expand` finding both is the point of the
shared name, and the second one delegates.

`Pig\Tui\Env` has no upstream counterpart either, and exists for one reason: `getenv()` answers `''`
for a variable exported without a value where JavaScript's truthiness reads that as absent, so the
same correction was being written out in six places. Both traps are at the bottom of this file.

Ported so far: all of `ai` (`types.ts`, `utils/event-stream.ts`, `stream.ts`, the Anthropic provider),
all of `agent-core` (`types.ts` 217 → `agent-loop.ts` 417 → `agent.ts` 439), and `tui`'s foundation
(`utils.ts` 712 → `terminal.ts` 138 → `tui.ts` 351 → `keys.ts` 547 → `autocomplete.ts` 576 →
`components/`, `terminal-image.ts` 340, `image.ts` 87). **`pig/tui` is now done**: `components/settings-list.ts` was the last file, and it arrived with the `/settings` screen that is its only consumer — a component with no consumer is dead code, which is why it waited. This paragraph said "done" for months while that file was missing; the file-list check below is what caught it, and the claim is only worth as much as the last time that check was run.

`coding-agent` is upstream's biggest package — 23k lines at the anchor, and most of it is not
the agent: OAuth, twenty-five selector components. What is being ported is the part
that makes it a coding agent: `core/tools/` (done), `core/system-prompt.ts` (done), enough of
`core/agent-session.ts` to hold a session (done — `Session\AgentSession`), an interactive mode
built on `pig/tui` (done — `Interactive\`), `core/hooks/` (done — `Hooks\`),
`core/custom-tools/` (done — `CustomTools\`), `core/messages.ts` (done — the four app message
types under `Session\`), `modes/rpc/` (done — `Rpc\`) and `modes/print-mode.ts` (done —
`PrintMode`). The rest is left out until something needs it.
