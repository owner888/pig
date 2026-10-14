# The upstream anchor

The anchor commit, and where it does not build or disagrees with itself. Moved out of `CLAUDE.md` as written — "this file" in the entries below means `CLAUDE.md`, where they were first written. The index is generated from the headings below.

- [The anchor commit does not compile](#the-anchor-commit-does-not-compile)
- [Things taken from upstream HEAD on purpose](#things-taken-from-upstream-head-on-purpose)

## The anchor commit does not compile

`d0a4c37` is the commit that split the agent's single queue into `steer()` and
`followUp()`, and it changed `packages/agent` **only**. `coding-agent/src/core/agent-session.ts`
at that same commit still calls `agent.queueMessage()`, `clearMessageQueue()`,
`getQueueMode()` and `setQueueMode()` — none of which exist there any more. The anchor is a
snapshot taken mid-break.

**And `packages/agent`'s own test suite is on the old side of it too**, which matters when the tests
are read as a specification: `agent/test/agent.test.ts` calls `agent.queueMessage(message)` and
asserts the old refusal wording, so that file cannot run at the anchor either. Where a test there
describes behaviour the split did not touch, it is still the spec and pig pins it; where it names the
queue, it describes an API that no longer exists.

So "port it literally" has no meaning for the parts of `coding-agent` that touch the queue:
there is no working original. Those are ported against the **split** API, which is what
`Pig\Agent\Agent` has, and the mapping is written down where it happens. Anywhere else the
anchor turns out not to build, do the same and say so here rather than porting a call that
cannot have worked.

## Things taken from upstream HEAD on purpose

The anchor is a floor, not a ban. Where HEAD has solved something the snapshot had not, and
the developer asked for it, it is ported and listed here:

| From HEAD | Where it lives here | Upstream |
|---|---|---|
| Ctrl+V pastes a clipboard image as a temp file, and its path into the prompt | `Pig\Tui\Clipboard`, `Editor::pasteFromClipboard()` | `coding-agent/src/utils/clipboard-image.ts` + `interactive-mode.ts` |
| A three-line banner with the keys on one line, the rest behind ctrl+o, and a `[Context]` section for what was loaded | `InteractiveMode::banner()` | the startup screen at 0.87 |
| A 4-cell half-block startup mascot logo icon (custom pink piglet) | `Pig\CodingAgent\Interactive\PigLogo`, `InteractiveMode::banner()` | `pi-logo.ts` at 0.99.1 |
| `!!command` runs without joining the conversation | `AgentSession::executeBash(excludeFromContext: true)`: recorded in the session, left out of every model request (`CodingAgent::toLlm()`) | `!!` at 0.87; the anchor has `!` only |
| Ctrl+G opens the prompt in `$VISUAL`, without stopping the event loop | `InteractiveMode::editPromptExternally()`, `Process::interactive()` | `openExternalEditor()` in `interactive-mode.ts`, which blocks |
| Fullscreen ChatViewport with fixed bottom dock & scroll-to-end indicator | `Pig\CodingAgent\Interactive\ChatViewport`, `Pig\Tui\Components\ScrollView` | `chat-viewport.ts` + `tui-alt-screen.ts` at 0.85+ |
| Skill invocation collapsible message component | `Pig\CodingAgent\Interactive\SkillInvocationMessageComponent`, `Pig\CodingAgent\Prompt\SkillBlock` | `skill-invocation-message.ts` |
| Program status over OSC 7501 — `working` / `blocked` / `done` / `error` / `idle` for terminals and agent dashboards, `PIG_PROGRAM_STATUS=1\|0` to force or silence it | `Pig\Tui\ProgramStatus`, `ProcessTerminal::setProgramStatus()`, `Pig\CodingAgent\Interactive\ProgramStatusReporter` | `tui/src/program-status.ts` + `program-status-reporter.ts` at 503c605 (2026-10-07) |

The program status port differs from upstream in two places, both platform. Upstream sends the
support query inside its kitty keyboard query and reads the answers out of `StdinBuffer`'s split
sequences; pig makes no keyboard query, so `ProcessTerminal::start()` sends the OSC 7501 query with
its own DA1 sentinel and sifts the two answers out of the raw read, holding back a tail that could be
a reply cut by the read — never a lone escape, which is a key. And upstream hears every compaction as
a `compaction_start`/`compaction_end` session event, while here only the overflow one is an event:
`/compact` and the threshold check run in the mode, which tells `ProgramStatusReporter` itself
through `compactionStart()`/`compactionEnd()`. A cancelled overflow compaction reads as `error` for a
moment and becomes `idle` at the `agent_settled` that follows it, since `abort()` set the flag.

The anchor's banner is a column of thirteen keys, which is taller than most of the
conversations it sits above; HEAD moved the list behind `ctrl+o` and put a one-line
summary in its place. `[Skills]` and `[Extensions]` are sections there too; both are
here now.

Upstream reads the clipboard through a native Node addon on macOS and Windows and falls back
to `wl-paste` / `xclip` / PowerShell on Linux. PHP has no addon, so every platform goes
through a command here — including macOS, which uses `pngpaste` when it is installed and
AppleScript, which always is, when it is not. Under Termux it is Termux:API's
`termux-clipboard-get` / `termux-clipboard-set` and nothing else, text only, as upstream's.
