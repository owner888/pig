# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## Upstream anchor

**Port against commit `d0a4c37` (2026-01-02)** — the snapshot behind the "418 lines" claim
(`packages/agent/src/agent-loop.ts` was 417 lines + trailing newline there). Do not port
against upstream HEAD: by 2026-09 that file is 898 lines and `packages/agent/src` is 33k.

Upstream at that commit was still published as `@mariozechner/pi-*`; it is `@earendil-works/pi-*` now.

Reference checkout for diffing lives outside this repo — clone pi and `git checkout d0a4c37`.

### The anchor commit does not compile

`d0a4c37` is the commit that split the agent's single queue into `steer()` and
`followUp()`, and it changed `packages/agent` **only**. `coding-agent/src/core/agent-session.ts`
at that same commit still calls `agent.queueMessage()`, `clearMessageQueue()`,
`getQueueMode()` and `setQueueMode()` — none of which exist there any more. The anchor is a
snapshot taken mid-break.

So "port it literally" has no meaning for the parts of `coding-agent` that touch the queue:
there is no working original. Those are ported against the **split** API, which is what
`Pig\Agent\Agent` has, and the mapping is written down where it happens. Anywhere else the
anchor turns out not to build, do the same and say so here rather than porting a call that
cannot have worked.

### Things taken from upstream HEAD on purpose

The anchor is a floor, not a ban. Where HEAD has solved something the snapshot had not, and
the developer asked for it, it is ported and listed here:

| From HEAD | Where it lives here | Upstream |
|---|---|---|
| Ctrl+V pastes a clipboard image as a temp file, and its path into the prompt | `Pig\Tui\Clipboard`, `Editor::pasteFromClipboard()` | `coding-agent/src/utils/clipboard-image.ts` + `interactive-mode.ts` |
| A three-line banner with the keys on one line, the rest behind ctrl+o, and a `[Context]` section for what was loaded | `InteractiveMode::banner()` | the startup screen at 0.87 |
| `!!command` runs without joining the conversation | `AgentSession::executeBash(remember: false)` | `!!` at 0.87; the anchor has `!` only |
| Ctrl+G opens the prompt in `$VISUAL`, without stopping the event loop | `InteractiveMode::editPromptExternally()`, `Process::interactive()` | `openExternalEditor()` in `interactive-mode.ts`, which blocks |

The anchor's banner is a column of thirteen keys, which is taller than most of the
conversations it sits above; HEAD moved the list behind `ctrl+o` and put a one-line
summary in its place. `[Skills]` and `[Extensions]` are sections there too; `[Skills]` is
here now, and `[Extensions]` is not ported, so it has no heading to be empty under.

Upstream reads the clipboard through a native Node addon on macOS and Windows and falls back
to `wl-paste` / `xclip` / PowerShell on Linux. PHP has no addon, so every platform goes
through a command here — including macOS, which uses `pngpaste` when it is installed and
AppleScript, which always is, when it is not.

## Decisions on record

| Decision | Choice | Why |
|---|---|---|
| Port fidelity | File-by-file literal | Class/file/method names track upstream so `git diff` against a newer upstream commit stays mechanical |
| Concurrency | Hand-written `Fiber` + `stream_select` loop | One `select()` must wait on the LLM socket and STDIN together — that is what makes Esc-to-interrupt and typing mid-stream possible |
| HTTP transport | Raw `tls://` streams, hand-written HTTP/1.1 | Sockets and STDIN are then the same kind of thing; one loop, no `ext-curl` |
| Unions | real union type where the arms are closed and few, marker interface otherwise | PHP has union types; what it lacks is naming one and typing an array's elements — see [Porting the unions](#porting-the-unions) |
| Layout | composer monorepo, `Pig\*` | Mirrors upstream's package split one-to-one |
| Session file | pi's format exactly, in pi's directory layout | A conversation started in either tool opens in the other; `--resume` lists both — see [The session file is pi's file](#the-session-file-is-pis-file) |
| Back-compatibility | none before a release | pig has never shipped, so there is nobody with an old file; a reader for one would be compatibility with nothing, exercised only by the test written to exercise it |

### Deliberate exceptions to "prefer the platform library over your own"

Both were chosen explicitly, not by default:

- **TUI is hand-written ANSI + `stty`, not `php-tui/php-tui`** (the Ratatui port, which already
  has differential rendering and widgets). Chosen to port `pi-tui` literally.
- **Event loop is hand-written, not `amphp/amp` v3** (also Fiber-based, with SSE-capable HTTP).
  Chosen to keep the core dependency-free.

`Pig\Async` has no upstream counterpart at all — JS ships an event loop, PHP does not.

### Extensions

`ext-mbstring`, `ext-json`, `ext-openssl` and `ext-pcre` are required and are in every build worth
running. `pig/tui` additionally requires **`ext-pcntl`**, because SIGWINCH is the only way to learn
that the window was resized; without it the UI would draw at the startup width forever, so
`ProcessTerminal` refuses to start rather than doing that quietly. `ext-intl` is deliberately *not*
required — see [Four upstream dependencies that are not needed here](#four-upstream-dependencies-that-are-not-needed-here).

**One required external binary: `stty`.** PHP core has no termios binding and ext-pcntl does not
add one, so raw mode and the window size go through `proc_open('stty …')` against `/dev/tty`.

Everything else is optional and probed, never assumed. `Pig\Tui\Process::capture()` answers
`null` for a missing program exactly as it does for an empty result, because the caller does the
same thing either way — "this machine has no `wl-paste`" is a capability, not an error:

| Binary | For | Where |
|---|---|---|
| `pbpaste`, `pngpaste`, `osascript` | clipboard text and images | macOS |
| `wl-paste`, `xclip` | clipboard text and images | Wayland, X11 |
| `powershell.exe`, `wslpath` | clipboard images | WSL |
| `fd` | the `@` file picker in `pig/tui` | anywhere; `@` offers nothing without it |

**`fd` and `rg` are required by the `find` and `grep` tools**, as upstream requires them. A PHP
walk with `.gitignore` support was written and measured first — about 220ms over a 100k-file
tree against fd's ~40 — and then deleted: matching upstream's search semantics exactly is worth
more than the saved dependency, and two implementations that can disagree about which files
exist is a bug the model would have to debug.

`ensureTool()` is ported too, so the same thing happens as in pi: `ExternalTool` looks in
`~/.pig/tools/`, then the PATH (knowing that Debian calls `fd` `fdfind`), and only then has
`ToolInstaller` fetch the release from GitHub. Two differences, both of which upstream has at
HEAD or would be better for: `PIG_OFFLINE=1` turns fetching off, and the download says what it
is pulling and from where **before** it starts, because an executable arriving from the
internet should not arrive quietly. Nothing checks a signature — neither project publishes
one — so this trusts GitHub and those two repositories exactly as far as upstream does.

The archive names are upstream's table, copied per tool rather than tidied into one rule.
The two projects do not publish the same Linux builds — ripgrep has an x86_64 musl build but
only a gnu one for aarch64, fd publishes gnu for both — so a shared rule names an archive that
does not exist, which is a 404 at download time on a machine nobody is watching. `archive()`
and `url()` are public so the naming can be tested without a download; that was the only way
the first version's wrong names could have been caught.

The download goes through `pig/ai`'s own HTTP client, which grew `HttpClient::follow()` for it:
a release URL is answered with a 302 to GitHub's object store, and a streaming API never
redirects, so `send()` keeps its single-request behaviour and only downloads pay for the loop.

## Layout

```
packages/async/      → Pig\Async\        (pig/async)       Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           (pig/ai)          unified LLM API, HTTP/SSE, Anthropic
packages/agent-core/ → Pig\Agent\        (pig/agent-core)  AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          (pig/tui)         renderer, widths, keys, editor, markdown, images
packages/coding-agent/ → Pig\CodingAgent\ (pig/coding-agent) tools, theme, session, interactive CLI
bin/pig                                     the entry point
```

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

`AgentSession` is 1901 lines upstream and ~1000 here, because the one thing it coordinates that
is not ported is not there to coordinate: branching to a second session file.
What is left is the conversation, the event fan-out,
the queue of messages someone typed while the agent was working, the thinking level, what the
session has cost, persistence, compaction, tree navigation and the hook events. Each of the
rest can arrive on its own when something needs it.

`Theme\Palette` is upstream's `theme.ts` down to what it is for: the two built-in themes as
tables, and the bridges that turn them into `pig/tui`'s `MarkdownTheme`, `EditorTheme` and
`SelectListTheme`. A component asks for `accent` or `toolOutput` and never for cyan, so an
unknown name throws where the component is wired rather than rendering colourless later.
Reading a theme from JSON, the custom-themes directory and the watcher that reloads one as
it is edited are not ported; they arrive with a `/theme` command if that is ever wanted.

`!command` runs a shell command and puts the result in the conversation, as a
`Session\BashExecution` — an app message, not an LLM one. `CodingAgent` supplies the
`convertToLlm` that turns it into a user message on the way to the model; the agent's own
default drops anything that is not one of the three LLM types, which is exactly what
`!!command` wants, since it never makes a `BashExecution` at all. So the difference
between the two is one boolean and no special case downstream.

A command run while the agent is working is **held back until the run ends**. A message
added between a tool call and its result is a request the provider rejects outright, so
`AgentSession` queues it and flushes on `AgentEndEvent` — before the listeners see that
event, so a UI redrawing on it already has the message.

`Session\SessionManager` is the conversation on disk: one JSON object per line, appended
to, under `~/.pig/sessions/<the project's path, flattened>/`. Appending rather than
rewriting is what makes a session survive whatever ends the process. **Nothing is written
until the first assistant message** — someone who starts pig, reads the banner and quits
leaves no file behind, which is what makes the directory worth opening at all; the
messages before that one are written in front of it when it arrives.

The log is a **tree**, as upstream's is: every entry carries an `entryId` and a `parent`,
and the session holds a *leaf* — the end of the branch being talked on. `messages()` walks
from the leaf back to the root rather than reading the file in order, because the file holds
every branch and only one of them is this conversation.

Going back (`/tree`) moves the leaf to an earlier entry; the next thing said hangs off it and
forks. **Nothing is deleted and nothing is rewritten** — the entries on the abandoned road
keep their parents, so it can be gone back to in exactly the same way. That is the whole
value of the shape: a wrong turn costs nothing.

Three things this changed that are worth knowing:

- **A compaction is resolved on the way out, every time.** It used to be applied once as the
  file was read, which was correct while the log was a line and wrong the moment a branch is
  taken from *before* the compaction — that branch never had it.
- **Format version 2, and it is pi's version 2.** See below — it was not, for a while, and
  that was worse than a different number. There is no reader for what pig wrote in between.
- **The end of the file is the leaf.** A branch is only ever made by appending, so the newest
  entry is always on the branch that was being talked on when the session was last open.

### The session file is pi's file

**It was not, and it said it was.** pig wrote the message flattened onto the line with
`entryId` and `parent` and an integer millisecond timestamp, under a header saying
`version: 2` — which is the number pi uses for a different shape. So pi opening a pig file
would agree about the version and then find no `type` on any line. A different version number
would have been honest; the same number for a different format is the one thing that cannot be.

It is pi's now, field for field:

| | pi, and now pig | pig, before |
|---|---|---|
| project directory | `--Users-kaka-Dev-pig--` | `Users-kaka-Dev-pig` |
| file | `2026-01-02T21-29-30-123Z_<uuid>.jsonl` | `2026-01-02-212930-<12 hex>.jsonl` |
| header timestamp | `"2026-01-02T21:29:30.123Z"` | `1735849770123` |
| a message | `{"type":"message","id","parentId","timestamp","message":{…}}` | the message, flat, plus `entryId` and `parent` |
| entry id | eight characters off a UUID | twelve hex characters |
| compaction | `type:"compaction"` with `firstKeptEntryId` | `role:"compactionSummary"` with `replaced` |
| a hook's message | `type:"custom_message"` | `role:"hookMessage"` |
| a hook's note | `type:"custom"`, in the tree | `type:"custom"`, outside it |

`Session\SessionEntries` encodes the **line**; `Session\SessionCodec` encodes the **message**
that sits inside one. Upstream keeps them apart for a reason that only shows up here: not
every line is a message. A compaction, a branch summary, a hook's message and a hook's private
note are each a line of their own with its own `type`, and two of them are not part of the
conversation at all. `RpcEvents` keeps using `SessionCodec`, because a host wants a message.

Three things this changed that are worth knowing:

- **`replaced` became `firstKeptEntryId`.** They say the same thing from opposite ends — "this
  stands in for the first N messages" against "the kept part starts here" — and only one of
  them is something pi can read. `replaced` survives as a number on a screen, *derived* when
  the file is read: a file holding both is a file that can disagree with itself.
  `SessionManager::entryAt()` is the join, because the cut is an index into the resolved
  conversation and the file is a tree of entries, and those stop being the same numbering the
  moment one compaction has already happened.
- **A line neither tool understands stays in the tree.** Skipping one **broke the chain**,
  because the entries after it name it as their parent — a pi conversation came back as its
  first message and nothing else. Such a line is kept as a node holding nothing and walked past
  on the way out. The general shape: **in a tree read from a file, an entry you cannot use is
  still an entry other entries point at.**
- **`~/.pi/agent/sessions/` is read too**, beside pig's own, so `--resume` lists both and
  opening one appends to it in its own directory in its own format — the conversation stays one
  conversation. The `agent` in that path is not a typo: upstream's `getAgentDir()` is
  `join(homedir(), ".pi", "agent")`. `PI_AGENT_DIR` and `PI_HOME` override it.

**There is no reader for the old shape, on purpose.** The developer's call, and the reasoning
is worth keeping because it is the kind that gets forgotten: pig has never been released and
nobody has ever had a session file in the old format, so a reader for it would be compatibility
with nothing — code that can only ever be exercised by a test written to exercise it. It was
written first and then deleted, along with the two tests that covered it. Anything that is not
a line pi could have written is a line pig has nothing to do with, and takes the same path as
`thinking_level_change`: a node holding nothing, walked past on the way out.

The general rule, for the next time this comes up: **back-compatibility is a debt to real users,
and there are none until there is a release.** Before that, a format change is a format change.

### What a conversation was being had with

`model_change` and `thinking_level_change` are lines in the file that are not messages: the
model never sees them and `/tree` does not offer them, but they are what makes resuming mean
something. Without them `--continue` after an afternoon on opus came back on whatever the
settings said — which is the wrong answer to "carry on where I left off", and was pig's answer
until the file format became pi's and there was somewhere standard to put them.

`SessionManager::settings()` walks the branch and takes the last of each. The model falls back
to **whatever answered last**, exactly as pi's does: an assistant message carries the provider
and the model that produced it, so a conversation records what it was had with even if nobody
ever changed it on purpose.

Four things about it:

- **The branch, not the file.** Switching model on a branch you later walked away from is not
  what this conversation is being had with.
- **`--model` beats the file**, which is why `restoreSettings()` takes a flag rather than
  deciding for itself: a model named on the command line is someone saying what they want
  *now*, and the file is saying what was true last time. The order is otherwise unchanged —
  typed, then the environment, then this session, then the settings, then the default. This
  session is new and sits above the settings, because the settings hold what to open a *new*
  conversation with.
- **A level the restored model cannot do is clamped**, for the same reason `setModel()` clamps:
  the person resuming did not ask for it, the file did, and a provider would reject it.
- **A model this pig has no entry for is not an error.** pig's registry is 166 of pi's models
  and excludes OpenRouter's 236, so a pi session on one of those restores to whatever pig had.
  The conversation still opens, and the footer says which model is answering.

Recorded only when something actually changed: `setModel()` is also how the thinking level gets
clamped, and a line per call would be a file full of a model changing to itself.

### Naming a point

`/label before the refactor` names where the conversation is, and `/tree` shows the name beside
what was said — because a list of "4 back · 7 back · 12 back" is a list nobody can choose from.
`/label` with nothing clears it. pi's `type: "label"` entry, so a name set in either tool shows
up in the other.

Four things about it:

- **It names the last thing that was *said*, not the leaf.** Writing a label advances the leaf
  — the label entry becomes it — so naming the leaf would mean `/label a name` followed by
  `/label` to clear it named the first label rather than clearing the message's name. The first
  test of this **passed while doing the wrong thing**, because it asked about the leaf too.
- **Clearing writes a line rather than removing one.** Nothing in a session file is ever
  deleted: a name that was taken off is a line saying so, sitting after the line that set it,
  and reading in file order is what makes the last one win.
- **Labels are read in file order, not branch order**, which is upstream's rule and the right
  one: a label names an entry by id and that entry can be on any branch. Read off the current
  branch, a name would vanish and come back as you moved around.
- **A label on an id nothing has is refused.** It would be a line nobody ever reads back, and
  the mistake is in the caller.

The name is shown *beside* the message rather than instead of it: the name is what you were
thinking and the message is what was actually said, and only one of those is a fact.

`Session\SessionCodec` has no upstream counterpart at all: a message there is a plain
object and `JSON.stringify` is the whole persistence layer. PHP objects do not survive
that, so the shapes are written out by hand — which is the one place that has to change
when a message type gains a field. A tool's `details` is `mixed`, so it is flattened to
arrays on the way out; the only thing anything reads back out of it is `edit`'s diff,
which is strings and integers and survives exactly.

A resumed conversation is **redrawn from its messages**, not from anything saved about the
screen — `InteractiveMode::replay()`. A transcript is a view of the conversation, and
keeping a second copy of it on disk is how the two end up disagreeing.

`Session\Compaction` is upstream's `core/compaction/` (1246 lines) as pure functions: how
full the window is, where the conversation can be cut, what the summariser is asked, and
which files were touched. Running the model and swapping the messages over is
`AgentSession::compact()`, so everything with arithmetic or string handling in it is
testable without a provider. The summary itself is `Session\CompactionSummary` — an app
message like `BashExecution`, turned into a user message by `CodingAgent::toLlm()`.

Three things about it are worth knowing before changing any of it:

- **The cut never lands on a tool result.** A result separated from the call that produced
  it is a request every provider rejects outright, so `cutPoint()` collects the legal
  positions first and only then looks for the one nearest the budget. `CompactionTest`
  checks this at every budget from 1 to 6000 rather than at one number, because the
  arithmetic that picks the cut is exactly the kind of thing that is right for the number
  you tested.
- **The log is still append-only.** Compaction does not rewrite the file: the summary is
  appended like any other message and carries `replaced`, the number of messages from the
  start it stands in for. `SessionManager::add()` splices on the way back in, so a resumed
  session comes back compacted. Without that count, replaying the log would hand back the
  whole conversation that had just been compacted away — and the messages themselves stay
  in the file, because a session log is a record of what was said.
- **The file lists are separate from the prose.** A summary that says "read some files"
  sends the model to read them again. `Compaction::files()` also carries an earlier
  summary's lists forward, or a file read before the last compaction disappears from the
  record entirely.
- **A usage reading older than the last compaction is discarded.** See the trap below.

`compact()` throws upstream's own messages — `Nothing to compact (session too small)` and
`Already compacted` — rather than returning quietly. The wording is upstream's on purpose,
because it is the wording someone will search for.

Everything `InteractiveMode` says goes through one of three methods, which is what keeps
the three kinds of line apart on a screen that is already full of colour: `sayError()`
(red, prefixed `Error:`), `sayWarning()` (yellow, `Warning:`) and `say()` (dim, no label)
— upstream's `showError()` / `showWarning()`. A message that is drawn by colour alone is
a message nobody can name, so no new one is drawn that way.

Not ported from upstream's compaction: branch summarisation (`branch-summarization.ts`,
which needs the tree) and the split-turn prefix summary — upstream generates a second,
smaller summary when the cut falls inside a turn, which needs turn boundaries that the
linear log here does not mark.

`Interactive\` is the terminal front end. `InteractiveMode` is the arrangement — which event
becomes which component, which key means what — and `bin/pig` is the entry point. The
components it draws with are `UserMessageComponent`, `AssistantMessageComponent`,
`ToolExecutionComponent`, `FooterComponent`, `DiffView`, `BashOutputComponent`,
`CompactionComponent` and `CustomEditor`. They keep upstream's `…Component` names rather than `pig/tui`'s suffix-free
`Text` / `Box` / `Markdown`, because `UserMessage` and `AssistantMessage` are already taken by
`Pig\Ai`; the developer chose matching upstream over matching the sibling package.

`InteractiveMode` is ~1200 lines against upstream's 2439, and the difference is almost entirely
selectors: upstream has twenty-five of them — models, sessions, settings, hooks, OAuth, branch
trees — and each needs a subsystem that is not ported. What is here is the loop that makes it
an agent you can talk to, fourteen slash commands plus whatever the hooks add, the keys, and
the dialogs a hook or a custom tool can open mid-turn (`Interactive\TerminalUi`).

`/copy` needed a clipboard *writer*, which nothing had: `SystemClipboard` could only read.
`Process::feed()` is the piece under it — a command with text on its standard input, which is
how `pbcopy`, `wl-copy` and `xclip` take theirs. Passing the text as an argument would put a
whole answer in the process list for anyone to read, if the length limit allowed it at all.
Two things in `feed()` are the whole reason it is not three lines: the write is **looped**,
because a long answer is larger than a pipe buffer and one `fwrite` stops short; and stdin is
**closed before waiting**, because that is what tells the program its input ended — a copy that
hangs forever is the other way round.

The writer knows about Wayland, which upstream's does not. Its own *reader* handles `wl-paste`,
so a session that could paste but not copy would be a puzzle with nothing on screen to explain
it.

An image in a tool result is **drawn**, on terminals that can draw one. `pig/tui`'s `Image`
falls back to a label by itself, so `ToolExecutionComponent` does not choose *that* — it adds the
component and lets it decide, and the text half stopped naming the image so it is not named
twice. What it does choose is whether to add the component at all: `terminal.showImages` off
adds `TerminalImage::fallback()`'s label instead, which is the same string from the same
function, so a picture turned off and a picture that cannot be drawn read the same. The images
are rebuilt on every `draw()` rather than appended to, because a running tool reports its result
again on each update — and `setShowImages()` rebuilds them for the same reason `setExpanded()`
does, since the setting is changed while a transcript is already on screen.

**Two preview sizes for command output**, both upstream's, in one component where upstream has
two: `BASH_LINES = 5` for a command the model ran, `TYPED_BASH_LINES = 20` for one typed with
`!`. Upstream's numbers live in `tool-execution.ts` and `bash-execution.ts` respectively, and the
difference has a reason worth keeping — a `!` command is the thing the person just asked for and
is looking at, where a model's is one step inside something else. pig reuses one component for
both, so the number is a constructor argument rather than a second class.

`CustomEditor` wraps `Pig\Tui\Components\Editor` rather than extending it — the editor is
`final`, and wrapping keeps the list of keys an application may steal explicit. `Editor` grew
one method for this: `setTheme()`, so the border can change colour with the thinking level.

The terminal is injected, so `InteractiveModeTest` drives the whole front end by typing into a
`FakeTerminal`: `start()` draws the first frame, keystrokes go in by hand, and `run()` — which
blocks on the loop — is the only thing a test never calls.

`Theme\Colour` carries the one piece of real arithmetic in there: fitting a hex colour onto
a 256-colour terminal. The guard worth knowing about is that the grey ramp only wins when
the colour was nearly neutral to begin with — without it every muted tone in the theme
snaps to a pure grey and the theme loses its tint.

`Ai\Models` is the registry. Upstream generates `models.generated.ts` from models.dev — 7105
lines, 414 models, twelve providers — and what is here is the models whose *protocol* is
ported: Anthropic's 21, OpenAI's own 33 on the Responses API, Google's 21, the 72 across
Cerebras, Groq, Mistral, xAI and Zai that speak `openai-completions`, and GitHub Copilot's 19.
Offering a model and then failing to send the request is a worse answer than "no such model",
so the rest arrive with their protocols. OpenRouter's 236 speak a ported protocol and are still
left out: that list is a directory of everyone else's models and goes stale fastest. The figures are upstream's *at the anchor commit*, not whatever models.dev says
today: a port should agree with the thing it was ported from.

The table is keyed `provider/id`, and `get()` used to take an id alone because no two
providers claimed the same one. **Copilot's table is the one that broke that**, exactly where
this said it would: it serves OpenAI's, Anthropic's and Google's models under their own ids, so
`gpt-5` now names two things.

The rule is `Models::RESOLD`, and it is one line of data rather than an ordering: **a bare id
means the direct provider**, and Copilot's is `github-copilot/gpt-5`. Writing it down mattered
more than it looks — building Copilot's table last already gave the right answer, and would
have stopped doing so the first time somebody moved a `foreach`. `Models::isResold()` is public
so `ModelResolver` asks the same question rather than keeping its own copy, in both the
exact-id pass and the sort over substring matches.

Two things about it that are easy to get backwards:

- **A resold id is still an answer**, just never the first one. `oswe-vscode-prime` is VS Code's
  own preview model and no direct provider carries it, so `get()` falls back to the reseller
  rather than answering null.
- **`grok-code-fast-1` looked Copilot-only and is not** — xAI's table carries it too, so a bare
  id means xAI's. That was a wrong guess in a test, not in the code, and the test now asserts
  both halves.

`ModelsTest::testEveryDuplicatedIdIsOneOfTheResoldOnes` replaced the uniqueness check: two
providers may share an id only when one of them is reselling, and any other collision is a typo
in a table that would make `get()` answer with whichever was written first.

It replaced a `CodingAgent::model()` that invented the figures around an id — 200k of context,
64k of output, reasoning on. Right for one model and wrong for most: `claude-3-haiku` caps
output at 4096 and cannot reason, so `--model claude-3-haiku-20240307` built a request the
provider rejects, from a flag that looked like it had worked.

`Providers\OpenAiCompletions` is upstream's `openai-completions.ts`, and it is worth more than
the one name on it: Groq, Cerebras, xAI, Zai, Mistral, OpenRouter and GitHub Copilot all answer
this shape. Structurally it differs from `Anthropic` in one way that matters — **Anthropic
numbers its content blocks and says when each opens and closes; this does not.** A block runs
until something of a different kind arrives, so the boundaries are worked out in the provider,
and that is the only real complexity in the file. `AssistantMessageBuilder` grew `nextWire()`
and `setToolCall()` for it: OpenAI numbers nothing, and a tool call's id and name arrive in
whichever delta they arrive in.

`Ai\OpenAiCompat` is the table of ways an "OpenAI-compatible" endpoint is not one. Mistral
wants tool ids of exactly nine alphanumeric characters, Grok rejects `reasoning_effort`,
Cerebras rejects `store`, Copilot re-answers every earlier prompt if assistant text arrives as
an array. None of that is documented anywhere as a difference; it is what a 400 looks like
after you have sent it. A table per endpoint rather than one rule, for the same reason the tool
archive names are a table — see that note above.

`Providers\TransformMessages` (upstream's `transorm-messages.ts`, misspelling and all) is what
makes `/model` safe across providers. Two things get cleaned up before any provider sees the
history: a **thinking block from another provider becomes `<thinking>` text**, because it is
signed and the signature means nothing anywhere else; and a **tool call with no result gets one
invented** saying so, because an interrupted turn leaves a dangling call and every provider
rejects the whole conversation rather than ignoring it. A stated "No result provided" is worse
than the truth and far better than a request that cannot be sent at all.

`Providers\OpenAiResponses` is upstream's `openai-responses.ts` — what gpt-5 and codex speak,
and the third shape in three providers. A response is a list of *items* and the stream says
when each opens and closes, which after `openai-completions` is a relief. Two things in it have
no counterpart anywhere else:

- **A reasoning item goes back whole.** What arrives as text is a *summary*; the reasoning
  itself is encrypted and opaque, and the model wants its own item back verbatim or it reasons
  from nothing again. So the entire item is kept as the thinking block's signature and replayed
  as it came — which is what `TextContent::$textSignature` and the builder's `setSignature()`
  are for. Asking for it at all needs `include: ["reasoning.encrypted_content"]`.
- **A tool call has two ids.** `call_id` addresses the result, `id` is the item's own, and both
  have to go back, so they travel joined as `call_id|id` and are split on the way out. A call
  from another provider has one id, which is then used for both.

`# Juice: 0 !important` is not a joke: gpt-5 has no documented way to turn reasoning off, and
that developer message is what upstream found works.

`Providers\Google` is upstream's `google.ts` — Gemini, and the shape furthest from the other
three. A chunk carries a list of *parts*, and a part is text, or thinking (text with
`thought: true` on it), or a whole function call. Which means:

- **A tool call arrives complete**, arguments and all, in one part — so it is opened,
  delivered and closed in the same breath. There is nothing to stream.
- **Thinking and text are the same field**, told apart by a flag, so a block ends where the
  flag changes: the boundary problem from `openai-completions`, one field over.
- **Gemini often sends no id for a call**, and a result has to be addressed to something, so
  one is invented — and a repeat within a message is replaced for the same reason.
- **Saying nothing about thinking means dynamic thinking, not none.** A turn that did not ask
  for it has to ask for `thinkingBudget: 0`, or the model thinks anyway and bills for it.
- **Thinking is said two ways.** Gemini 3 takes a named level and ignores a budget; 2.5 takes a
  budget in tokens, with a different ceiling for pro and flash. `Stream::gemini()` picks; the
  provider sends whichever arrived.
- **Eighteen of its twenty finish reasons mean "no"** — safety, recitation, a malformed call, a
  language it will not answer in. Only `STOP` and `MAX_TOKENS` are not errors.

Upstream hands this to `@google/genai`; the REST endpoint is called directly here —
`:streamGenerateContent?alt=sse`, which is what that package does underneath. Without `alt=sse`
the response is one enormous JSON array rather than a stream.

`Providers\GoogleShared` is upstream's `google-shared.ts`, and it exists now for the reason it
exists there: **two providers speak this shape.** Google Cloud Code Assist wraps the same request
in a project envelope and answers with the same chunk one key deeper, so what a Gemini request
and a Gemini chunk look like is not `Google`'s private business any more. All of it used to sit
in `Google` as private methods — 609 lines — because there was one caller; the extraction moved
about 400 of them out and `Google` is 207. The developer's call, with the alternative being a
second copy of the conversion and CLAUDE.md's own rule against two implementations that can
disagree about what a request looks like.

Three things about the split:

- **The caller unwraps.** Code Assist's chunks arrive under a `response` key, and
  `GoogleShared::onChunk()` is handed the candidate rather than being told which provider it is
  speaking for.
- **pig shares more than upstream does.** Upstream keeps the chunk walk in each provider and
  shares only the request conversion; the chunk is the same shape one key deeper, and two walks
  over it are two things that can come to disagree about what a Gemini answer is.
- **Each provider keeps its own business**: where it sends, how it authenticates, how it words a
  failure, and the body it assembles around the shared pieces. `explain()` stayed behind in both
  for that last reason — the message names the provider.

It was an extraction and nothing else, which is what `GoogleTest`'s thirty-one cases passing
unchanged is the evidence for. A move that needed a test changed would have been a rewrite.

`ModelResolver` is upstream's `model-resolver.ts`: `sonnet` finds the model, `sonnet:high`
finds it and sets the thinking level. When several match, the alias beats the dated build
behind it — someone typing `sonnet` wants the current one, not the June 2024 build that sorts
first. The pattern is tried whole before it is split on a colon, because an id can contain one
(OpenRouter's `:exacto`). Not ported: the glob scopes (`--model 'anthropic/*:high'`) for
running several models against one task — `fnmatch()` is the whole of what `minimatch` was
doing there, so that is rows of work rather than a dependency when something wants it.

`Export\HtmlExport` and `Export\MarkdownHtml` are upstream's `core/export-html/`, with the
rendering moved from the browser into PHP. Upstream's export is 211 lines of logic, 65KB of
templates, and **160KB of vendored `marked.min.js` and `highlight.min.js`** — and `marked` is
the dependency `Pig\Tui\Markdown` was written to replace, so shipping it in the export would
be the same dependency through a side door. The lexer and the highlighter are already here;
`MarkdownHtml` is the second thing that walks their output, and the file it writes has no
script in it at all. The same conversation comes out at 5KB rather than 230KB, and it reads
with JavaScript off, prints, and greps.

`Highlight` is reused through the seam that already existed for theming: a `HighlightTheme`
whose closures write `<span class="hl-keyword">` instead of an escape sequence. The one trap
in that is below.

`<details>` does the folding, which is the only interaction upstream's JavaScript provided
that was worth keeping. Open or closed is decided on the *text* length, not the number of
newlines — the renderer turns a paragraph of prose into one long line, so counting newlines
folds every code block and never folds any thinking, which is exactly backwards.

`Settings` is upstream's `core/settings-manager.ts`. Two JSON files, both optional:
`~/.pig/settings.json` is the person's and is written back to; `<cwd>/.pig/settings.json` is
the project's and is only ever read. The project wins, which is the point of it being
separate — a repository can say "compaction keeps more here" without touching anyone's
preferences, and a `/theme` typed in that repository still saves to the person's file and
still loses to the project's answer while they are in it.

Upstream is 374 lines, most of it forty getter/setter pairs. The pairs here are only for the
settings something actually reads — theme, model, thinking level, hidden thinking, pictures, the
three compaction numbers, the skill filters — and everything else is reachable through `get()` under
upstream's own key names, so a settings file written by either project is read by both. A key
gains a typed accessor when something needs one, not before.

Three decisions in it:

- **Merged one level deep, not recursively.** Every nested thing in this format is a flat
  group of scalars; a deeper merge would be answering a question the format never asks.
- **A list replaces rather than appends.** A list here is an answer, not a contribution:
  merging two would leave a project no way to *stop* ignoring a skill the person ignores.
- **A file that is not JSON is named, not ignored.** A typo in a settings file is otherwise a
  setting that quietly does nothing for the rest of its life. `bin/pig` prints the complaints
  before the UI starts, the same as it does for a malformed skill.

Order for anything with more than one source: what was typed, then the environment, then what
was chosen last time, then the built-in default. `--no-save` gets `Settings::inMemory()`, so a
session that is not written down does not write anything else down either.

### `/settings`, and why its list is shorter than upstream's

`Components\SettingsList` is upstream's `tui/components/settings-list.ts` and `/settings` —
`InteractiveMode::showSettings()` — is upstream's `settings-selector.ts`. It was the last
unported file in `pig/tui`, and it waited this long for a good reason: a component whose only
consumer is unported is dead code, so the two only make sense together.

It is not a `SelectList` with two columns, and the difference is worth naming. A select list is
*answered* — one question, Enter means "this one", and it closes. A settings list is **lived
in**: several things are changed in one visit, so Enter changes the row under the cursor and
leaves the list open, Escape means "done" rather than "cancelled", and the screen has to keep
saying what each row is set to now. Space does what Enter does, as upstream allows, because a
two-value row reads like a checkbox.

Two decisions of pig's own:

- **`SettingItem` is readonly and the values live in the list.** Upstream writes the new value
  back into the item it was given. Here `SettingsList` holds an `id => value` map, so a
  declaration built in one place stays a declaration, and `setValue()` exists for the setting
  that moves on its own — the thinking level changes when a model that cannot reason is chosen,
  and the screen should agree with the truth rather than with what was last pressed on it.
- **The label column is measured in columns, not characters.** Upstream's `.length` puts 主题 at
  2 where the terminal gives it 4, which pushes every value column after it out of line. Same
  deviation, same reason, as `SelectList`'s description column.

**The list only offers things that take effect**, which is what decided its six rows:

| Row | Reaches |
|---|---|
| Theme | `useTheme()`, split out of `switchTheme()` so a row can name a theme rather than toggle |
| Thinking | a submenu of the levels *this model* offers, so a model that cannot reason gets no row at all rather than six ways to change nothing |
| Thinking blocks | `useHideThinking()`, split out of the ctrl+t handler |
| Pictures | `useShowImages()`, and its reader — see below |
| Auto-compact | `compaction.enabled`, read again on every turn |
| Auto-retry | `retry.enabled`, read again on every failure |

**`terminal.showImages` was a setting pig stored and nothing read.** Building the screen is what
surfaced that, and the choice was between leaving the row off and giving the setting its reader.
Upstream has a real one — `tool-execution.ts` takes `showImages`, checks
`caps.images && this.showImages` before drawing, and has a `setShowImages()` for the transcript
already on screen — so pig now has the same: `ToolExecutionComponent` takes the flag and
`setShowImages()` redraws. Off draws the label `TerminalImage::fallback()` already produces for a
terminal that cannot draw pictures, from the same function, so "turned off" and "cannot" look
alike and neither pretends no picture came back. **Queue mode** is still off the list: it is fixed
when the agent is built and has no setter to reach, so offering it would mean inventing one for
the benefit of a screen.

`useTheme()`, `useHideThinking()` and `useShowImages()` being split out is the part that matters
most and is the least visible: ctrl+t writes the setting *and* tells every
`AssistantMessageComponent` already on screen. A second place writing only the setting is a
toggle that half works — the file says hidden and the transcript still shows thinking — so both
paths go through the one method. The theme is the exception and says so: the list holds the old
palette's closures, so new colours arrive the next time it is opened, which is the same thing
`/theme` already says about the transcript.

`Session\BranchSummarization` is upstream's `core/compaction/branch-summarization.ts`, and
`Session\BranchSummary` is the message it produces. `/tree` goes back to an earlier point and
carries on from there; the branch that was left is still in the file, but the model no longer
sees it — and the model is usually what did the work on it. So `/tree` asks whether to write it
down, and the answer is appended to the branch being *joined*, which is the whole point: it is
context for carrying on here, not a note on the branch it describes.

Almost all of it is compaction's machinery reached for rather than repeated —
`Compaction::estimateTokens()`, `serialize()`, `files()` and `SYSTEM_PROMPT` are the same
questions about a different span. What differs is the prompt (a handover: goal, progress,
blocked, next steps — not a recap) and five things worth keeping straight:

- **It replaces nothing.** A `CompactionSummary` stands in for the messages before it and
  carries a `replaced` count that `SessionManager::resolve()` splices on. A branch summary
  describes messages that were never on this branch, so it has no count and nothing is spliced.
- **File lists are collected from the whole branch, prose from as much as fits.** Two passes,
  and the first is the point: the budget may drop the older half of a long branch, but "which
  files did this touch" has to be complete or whoever reads it goes to a file on disk that no
  longer matches. The walk for prose is newest-first for the same reason compaction keeps the
  recent end.
- **Cancelling means not moving.** Escape during the summary returns `TreeJump(moved: false,
  aborted: true)` and the leaf stays where it was, so the tree list comes back up. Jumping
  anyway would give someone the move they asked for and silently drop the summary they also
  asked for.
- **A hook may write it instead**, through `SessionBeforeTreeResult(summary: …)` — and then
  the file lists are still pig's own reading of the branch. The prose is the hook's; which
  files were touched is not its to get wrong.
- **Its lists carry forward into a later compaction**, exactly as a compaction summary's do,
  *unless* a hook wrote it. Missing that would lose the other branch's files the first time
  the context filled up.

`Prompt\SlashCommands` is upstream's `core/slash-commands.ts`: a markdown file in
`~/.pig/commands/` or `.pig/commands/` becomes `/name`, and its body is the prompt.
`/review src/Foo.php` sends `review.md` with `$1` filled in.

Not the same thing as a skill, though both are markdown with frontmatter and the two are
easy to confuse. A skill is **offered** to the model, which reads it when a task matches; a
command is **sent** by the person, now, as the message. One is a capability, the other is a
macro. They are loaded separately for that reason.

Two things worth keeping straight: a built-in command is tried **first**, so a `help.md`
someone forgot they wrote cannot shadow `/help`; and `$@` is substituted **before** the
numbered placeholders, or an argument that happens to contain `$1` would have an argument
substituted into it.

`Prompt\Skills` is upstream's `core/skills.ts`. A skill is a folder with a `SKILL.md` whose
frontmatter says what it is for; only the name and description reach the prompt, and the
instructions are a file the model reads when a task matches. That is what makes many skills
affordable — a hundred of them cost a hundred lines, not a hundred documents.

Five roots, and they are upstream's, which means other tools' as well as pig's:
`~/.codex/skills`, `~/.claude/skills`, `.claude/skills`, `~/.pig/skills`, `.pig/skills`, plus
whatever `--skills-dir` adds. Someone who wrote a skill once should not have to write it again
per agent. The `~/.claude` roots are scanned **one level deep** and the others recursively,
because a folder per skill is the layout there and descending further finds a skill's own
examples rather than more skills.

Three things it does that are easy to get wrong:

- **The first root wins, and the loser is named.** Shadowed silently, the second copy looks
  like a skill that simply does not work.
- **One file reached through two roots is one skill, not a collision.** Symlinking a folder of
  skills into another root is a normal way to keep a single copy, so the check is on
  `realpath()`, not on the name.
- **A skill with something wrong is still loaded, and still complained about.** A name two
  characters too long is worth saying and not worth refusing over. The one exception is a
  missing description: it is the only thing the model sees, so a skill without one could never
  be chosen and would sit in the prompt as a name nobody can use.

Warnings go to stderr before the UI starts, not into the transcript: a malformed skill is its
author's problem, and the author is whoever just ran `bin/pig`.

The frontmatter is read a line at a time rather than with a YAML parser. The spec's fields are
all scalars and pig has no YAML parser to reach for; what this cannot read stays unread, which
for a nested `metadata:` block means its key and nothing else — and the key is all that is
validated anyway. `fnmatch()` is `minimatch` for `--ignore`-style patterns.

**All five of upstream's protocols are here.** The fifth took a correction on the way: this
used to say it "was never a protocol" — that `google-gemini-cli` was Gemini behind a sign-in. It
is not. Its models carry `api: "google-gemini-cli"` and upstream has a 603-line provider for it,
because Code Assist has its own endpoint and wraps a Gemini request inside a Cloud-project
envelope. The claim was wrong and is recorded as wrong; checking it took one grep of
`models.generated.ts` for the `api` field.

`Providers\GoogleGeminiCli` is 250 lines against upstream's 603, because upstream re-implements
the chunk walk and this shares it — see `GoogleShared`. **GitHub Copilot is done** too, and needed
no new protocol at all: it serves everything through the two OpenAI shapes.

### Signing in instead of pasting a key

`Ai\Utils\Oauth\` is upstream's `ai/src/utils/oauth/`, and **two of its four flows are
ported**: Anthropic's, which is what a Claude Pro or Max subscription is used through, and
GitHub Copilot's. Neither needs anything pig did not already have, which is why they went first
and the two Google ones have not: those are loopback PKCE flows that want an HTTP server on
`127.0.0.1` and a browser opened at it.

Anthropic's shows a URL, the person opens it, approves, and brings back a `code#state` string;
`exchange()` redeems it. Copilot's is a **device flow**: pig asks GitHub for a pair of codes,
shows one, and then asks over and over whether it has been typed in yet. No redirect to catch
either way, so no server and no port to hope is free.

The far end of it was already written and had been since the provider was ported:
`Providers\Anthropic` recognises an `sk-ant-oat` key, sends it as a bearer token under the
`oauth-2025-04-20` beta, and puts Claude Code's identity in front of the system prompt, because
that is the identity the token is issued to. So what was missing was only the part that
*obtains* one — which is why the first thing added here was a pair of tests asserting what goes
on the wire for each kind of key: an untested branch that had never run is not a ported branch.

Five things worth keeping straight:

- **`state` is the verifier**, not a second random string. Upstream's choice and Anthropic's
  flow: the callback hands back `code#state`, so the state travels home through the person's
  clipboard and the exchange can tell the code came from the request it made.
- **Half a paste is refused before anything is sent.** One deliberate difference from upstream,
  which sends no state and lets Anthropic answer `invalid_grant` — a message that describes six
  different mistakes equally badly. The callback always hands over both halves, so a string
  with no `#` is a paste that lost its end, and saying that is an answer somebody can act on.
  The paste is trimmed for the same reason: a code copied out of a terminal arrives with a
  newline on it about half the time.
- **The new refresh token replaces the old one.** Anthropic rotates them, so a session that
  kept sending the one it signed in with would work exactly once more.
- **Five minutes is taken off every expiry**, which is upstream's margin. A token that expires
  in flight fails the request it was attached to rather than the next one, and that request is
  a whole turn.
- **`available()` is false for the three that are not ported**, and they are still named.
  Both halves matter: the credentials file is keyed by these strings and shared with pi, so a
  name pig cannot sign in with is a name pig still has to *read* — and offering a sign-in that
  cannot finish is the same mistake as offering a model whose protocol is not ported.

`Pkce` is upstream's `pkce.ts` with two differences, both because PHP has what JavaScript had to
reach for: it is not asynchronous (`hash()` returns a string where Web Crypto's `digest()`
returns a promise), and `random_bytes()` *throws* when it cannot be a CSPRNG, where
`crypto.getRandomValues` has no failure to report. A machine with no entropy source stops the
sign-in rather than continuing with something guessable.

The client id is written out rather than hidden behind `atob()` of a base64 string as upstream
has it. It is in a published npm package and in every request this makes, so it is not a secret
— and a reader who cannot see what it is has to go and decode it to find out.

The token endpoint is a constructor parameter so the flow can be tested against a loopback
server. That is not a seam existing for the test alone, which this project otherwise refuses:
every provider in `ai` takes its base URL from outside already, and the alternative here is an
authentication flow with no coverage at all.

### The device flow

`Oauth\GithubCopilot` is upstream's `github-copilot.ts`. It ends with a swap that is easy to
miss: what the device flow produces is a **GitHub** token, and Copilot's API does not accept one.
`refresh()` trades it at `copilot_internal/v2/token` for a short-lived Copilot token — so the
GitHub token is stored as the `refresh` half and the Copilot token as the `access` half, which is
exactly what those two fields mean everywhere else here, and it is also why there is no separate
renewal endpoint: renewing *is* the swap, done again.

Four things about it:

- **The polling can be stopped.** The one addition to upstream, and the reason this flow waited
  for a decision: upstream loops for fifteen minutes with nothing able to interrupt it, which in
  pig is a fiber parked where nobody can reach it — the failure the hook dialogs already have a
  rule about. An `AbortSignal` ends it, escape raises one through `interrupt()`, and a
  cancellation comes back as **null rather than an exception**, because somebody changing their
  mind is an outcome.
- **Where Copilot answers is in the token.** Its claims carry
  `proxy-ep=proxy.individual.githubcopilot.com`, which becomes `api.individual.…`; a business
  account says something else and an enterprise install answers at `copilot-api.<domain>`. So
  the `api.individual…` in `Ai\Models` is a default and never the answer once there is a token —
  `Providers\OpenAiCompletions` and `OpenAiResponses` both ask `GithubCopilot::baseUrl()`
  instead, which is where the token and the request meet. Upstream rewrites it in its model
  registry, which pig has no equivalent of because pig's registry knows nothing about
  credentials.
- **Some models are off until the account accepts them**, Claude's and Grok's among them, so
  `enableModels()` POSTs a policy for each one after signing in. A refusal for one model is
  reported and does not stop the rest — the question asked was "may this account use that
  model", and no is an answer rather than a failure of a sign-in that is already finished. It is
  the one swallowed exception in this module and the docblock says why. The ids come from
  `Ai\Models` rather than from the flow: which models exist is the registry's question and the
  flow should not have a second answer to it.
- **`authorization_pending` is not an error and everything else is.** `slow_down` adds five
  seconds to the interval, as upstream does; anything else — `access_denied`, `expired_token` —
  ends the wait at once, because asking again says the same thing and a quarter of an hour spent
  on an answer that has already arrived is the wrong behaviour.

Something typed at the enterprise prompt that is not a host is **refused**, as upstream refuses
it: carrying on against github.com would sign somebody in to the wrong GitHub and look like it
had worked. Blank means github.com, which is what `allowEmpty` on the prompt is for.

### The loopback the browser comes back to

Google's flow has no device code and no paste: it redirects to
`http://localhost:8085/oauth2callback?code=…&state=…`, so something on this machine has to be
listening. Upstream reaches for Node's `http.createServer`; PHP has no HTTP server, so
`Oauth\CallbackServer` is one — deliberately the narrowest one that answers the question. One
path, three query parameters, one page written back, stop.

Five things about it:

- **The port is not negotiable.** `8085` is part of the redirect URI registered with Google, so
  a flow that picked a free port instead would be refused by Google rather than by the socket.
  `listen()` is therefore a separate step from `await()`: a port that cannot be taken has to be
  reported *before* anybody is sent to a browser, and it is reported by name.
- **`listen()` first, then open the browser.** The order matters and the split is what allows
  it — a browser that arrives before the socket exists gets a connection refused, and the
  sign-in is over with nothing to show for it.
- **It is on the loop.** `await()` parks the fiber on a `Deferred` while the loop keeps serving
  the terminal. A blocking accept would stop the UI for as long as the person took to sign in.
- **A browser opens more than one connection.** The callback, and usually a favicon request
  behind it. Each is read separately and only the one asking for the callback path answers the
  question; everything else gets a 404.
- **Cancel on Google's screen is a refusal, not a cancellation.** An `error=` in the callback
  throws, where escape returns null — something was said and it was no, which is different from
  walking away.

`stream_socket_server()` both warns and returns false, so the warning is caught with a handler
rather than suppressed: it is the only place the reason appears, and `@` is not allowed here.

`InteractiveMode`'s `onAuth` now **opens the browser** and prints the URL as an OSC 8 hyperlink,
which is upstream's behaviour and helps the two flows that were already here. The link text is
the URL itself rather than upstream's "Click here to login": a terminal that does not understand
the sequence shows the label, and a label is not something anybody can copy. `Ansi::strip()`
already knew about OSC 8, so the line measures correctly. Opening is best effort — the URL is on
screen, so a headless box or one without `xdg-open` is one extra copy-and-paste rather than a
broken sign-in.

### Google's sign-in

`Oauth\GeminiCli` is upstream's `google-gemini-cli.ts`, and the third shape of sign-in in three
flows: Anthropic's shows a URL and takes a paste, Copilot's shows a code and polls, and this one
**redirects to this machine** — `CallbackServer` listens, the browser goes to Google, and the code
arrives on a socket rather than through a person.

It is also the only one that does not finish when the tokens arrive. A Code Assist token is
useless without a Cloud project to spend it against and most accounts have none, so `project()`
asks for one and provisions a free-tier one when there is none — an API call that answers "not
yet" and has to be asked again. That is what `projectId` on `Credentials` is for, and why
`Provider::apiKey()` encodes it alongside the token: an api key field carries one string, and
Code Assist needs two things.

Six things worth keeping straight:

- **The state is checked and a mismatch is refused.** It is the verifier, so a code that came back
  with a different state came from a request this process never made. Upstream calls it a possible
  CSRF attack; this is the one check in the module that is about somebody else rather than about a
  mistake, and it has a test that drives a real socket to reach it.
- **`listen()` before the browser is sent anywhere**, which is the whole reason `CallbackServer`
  is two steps.
- **There has to be a refresh token**, and its absence gets its own complaint rather than being
  folded into "no tokens". Google only sends one for `access_type=offline` with `prompt=consent`,
  both of which are in the URL — so a sign-in that came back without one is worth saying plainly,
  because the fix is to try again.
- **A half-built project is not a finished one.** `onboardUser` answers with an id while it is
  still working, so `done` is checked as well: taking that id would name something nothing can be
  spent against.
- **Provisioning can be given up on**, which upstream cannot. Ten attempts three seconds apart is
  half a minute of a fiber nobody can reach.
- **The email is optional and its failure is ignored**, which is upstream's rule and the only
  place in the module where swallowing an error is right: it is a label on the credential and
  nothing downstream reads it.

**Google's client id and secret are not in this repository**, which is the one real difference
from upstream. They are constructor arguments, and `Auth::googleClient()` finds them — the
environment (`GEMINI_CLI_CLIENT_ID`, `GEMINI_CLI_CLIENT_SECRET`) and then the settings file
(`geminiCli.clientId`, `geminiCli.clientSecret`), which is pig's usual order. A flow built without
them is refused **in the constructor**, not at the first request: a flow that sends somebody to a
browser and then fails is worse than one that never starts, and the caller is what knows where to
look. Two pushes were refused before this was the answer — see the trap below, which is about what
scanners do rather than about what is secret.

Both READMEs say so now, because `/login` offers Gemini CLI and a reader who picks it needs to
know that two values have to come from somewhere else — and that port 8085 has to be free.

`available()` is true for it now. It was false for two turns while the protocol and the models
were missing, because a sign-in that unlocks nothing to choose is the same mistake as a model
whose protocol is not ported, from the other end.

`Providers\GoogleGeminiCli` is the protocol, and three things are its own:

- **The request is wrapped**: `{project, model, request: {…a Gemini request…}, userAgent,
  requestId}`, posted to `v1internal:streamGenerateContent?alt=sse`.
- **The answer is wrapped too**, one key deeper, so it unwraps and hands `GoogleShared::onChunk()`
  the same thing `Google` hands it. A chunk with no `response` in it is skipped rather than read as
  an empty candidate.
- **Thinking is only mentioned when it was asked for.** The one place the body differs from the
  public endpoint's: there, a turn that wants none has to say `thinkingBudget: 0`; here a
  `thinkingConfig` on a model that cannot think is rejected.

And the key is two things in one string — `Provider::apiKey()` encodes `{token, projectId}` —
which is taken apart in `credentials()`. **An ordinary Gemini API key arriving there is refused by
name**, because that is the likely mistake and a 401 three layers later names nothing.

**Upstream's in-provider retry is not ported**: three attempts with exponential backoff honouring
a server-suggested `retryDelay`, because Code Assist's free tier rate-limits hard. `Session\Retry`
already waits out a 429 one level up, and that one can be turned off with `retry.enabled`, counts
down on screen and stops on escape. Two retry mechanisms means neither is the one somebody is
looking at. If Code Assist turns out to need a tighter loop than a session-level wait, it belongs
in the provider and its docblock says so.

**Still not ported:** `google-antigravity` — the same protocol with a different client, a sandbox
endpoint and its own seven models.

### Where the keys live

`Auth` is upstream's `core/auth-storage.ts`, named after `Settings` rather than after upstream's
`AuthStorage` — both are one JSON file under the home directory with a typed reader over it, and
the developer chose to keep those two matching rather than `SessionManager`'s kept suffix. One
entry per provider, in upstream's own shape, either `{"type":"api_key","key":…}` or
`{"type":"oauth","refresh":…,"access":…,"expires":…}`.

**It is pi's file, not a copy of it.** `Auth::discover()` opens `~/.pi/agent/auth.json` when
that exists and only falls back to `~/.pig/auth.json`. That is not the same decision as reading
pi's sessions, and the reason is specific: Anthropic **rotates** refresh tokens, so the old one
is void the moment a new one is issued. Two files holding the same token is two tools taking it
in turns to log each other out — whichever refreshes first wins, and the other has to sign in
again. One file is the only arrangement where signing in once means signing in once. `Config`'s
docblock says so too, because it used to claim pig never writes in there.

Five things about it:

- **The order is upstream's**: what was typed, then a stored API key, then a stored OAuth token,
  then the environment. The last step is `Stream::envApiKey()` rather than a second table of
  variable names — it is upstream's `getEnvApiKey()` and it already knows that
  `ANTHROPIC_OAUTH_TOKEN` beats `ANTHROPIC_API_KEY`.
- **A renewal that fails is reported, not cleaned up.** Upstream deletes the stored credential
  and carries on to the environment, so one blocked network call throws away a login that was
  perfectly good — and the person finds out by being asked to sign in again for no reason.
- **A failed *write* throws**, where `Settings` records a problem and carries on. A preference
  that did not stick is a preference somebody sets again; a token that did not stick is a
  sign-in that said it worked, and the next thing that happens is an authentication error
  nobody can connect back to this.
- **`0600` in a `0700` directory, and the mode is set before there is anything to read.**
  Upstream writes the file and then chmods it, which leaves it world-readable for as long as
  those two calls take — long enough.
- **A provider name neither tool knows is kept.** The file is shared, so an entry pig has no
  enum case for still has to survive being read and written back.

`getApiKey` on `AgentOptions` is what it plugs into — a closure asked **per turn** rather than a
string resolved once, which is what makes renewing an expiring token mid-conversation possible
at all. It was already there and had no caller; `CodingAgent::create()` gained a parameter to
pass it through.

`/login` and `/logout` are the same `SelectList` in the same place as `/model`, `/resume` and
`/tree`, rather than a port of upstream's `oauth-selector.ts` — a fourth picker that behaves
like the other three is worth more than a literal port of a component that does less. Two
differences from upstream, both saying more: a provider pig cannot sign in with is greyed
**and** says why when it is chosen, where upstream ignores the key and reads as broken; and
`/logout` lists only what is actually signed in, because offering the other three would be
three ways of doing nothing.

Signing in **spawns**, because the paste box parks the fiber it is asked on and the command is
running inside the loop's own input callback — suspending there suspends the loop that has to
deliver the keystrokes. Same reason `send()` and `startCompaction()` spawn.

**`--no-save` does not apply to credentials.** It means "do not write this conversation down";
a stored sign-in is still the way in, and a token renewed during the run has to be kept or the
next run begins by renewing one that was already replaced.

Not ported from `auth-storage.ts`: `setFallbackResolver`, which resolves keys for custom
providers declared in a `models.json` pig does not have.

`setRuntimeApiKey` has its caller now: **`--api-key <key>`**, for this run and this provider
only, never written to `auth.json`. Three things about it, the first two being differences from
upstream:

- **It is applied after the model is resolved**, because the provider it belongs to is that
  model's. Upstream refuses the flag unless a model was named explicitly; pig always resolves
  one (`--model`, then `PIG_MODEL`, then the settings, then `claude-sonnet-4-5`), so there is
  nothing to refuse.
- **An empty value is refused by name.** `api-key` had to go in `Arguments::TAKES_A_VALUE` — a
  key is exactly the kind of thing that does not start with a dash — and an option at the end
  of the line with nothing after it gets `''`. Storing that would put an empty string at the
  top of the precedence order, where it beats the environment and then fails as a missing key
  three layers away.
- It beats everything else, including a stored sign-in, because it is the most recent thing
  anybody said.

**`CodingAgent::apiKey()` is gone**, and this is the other half of the same story. It read the
environment itself, naming `ANTHROPIC_API_KEY` where `Stream::envApiKey()` prefers
`ANTHROPIC_OAUTH_TOKEN` — so for `CodingAgent::create()` callers with both variables set, which
one won depended on which door you came in by. Its `default => strtoupper($provider) .
'_API_KEY'` arm was worse: `github-copilot` came out as `GITHUB-COPILOT_API_KEY`, which is not
a name a shell can even export. `create()` now passes a null key straight through and `Stream`
is the only thing that reads the environment. `examples/agent.php` is the one caller affected,
and the change is that it gains upstream's precedence.

`Hooks\` is upstream's `core/hooks/`, all of it. A hook is a PHP file in `~/.pig/hooks` or
`.pig/hooks` that returns a callable; the callable is handed a `HookApi` and registers what
it wants to hear about:

```php
<?php // ~/.pig/hooks/no-force-push.php

use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;

return function (HookApi $pi): void {
    $pi->on('tool_call', function ($event) {
        if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', '--force')) {
            return new ToolCallEventResult(block: true, reason: 'No force pushes from here.');
        }

        return null;
    });
};
```

**`require`, not a subprocess.** That was a decision with a cost, taken deliberately. A hook
runs in this process with this process's permissions: it can hand back an object, which a
command-line hook could not, and it can be written against pig's own classes because it is
running inside pig. What it cannot be given is a timeout or any isolation. A hook that loops
does not time out; one that calls `exit()` takes the session with it. What *is* caught is
everything PHP raises as a `Throwable` — including a syntax error, which an included file
raises as a catchable `ParseError` — so a broken hook is a complaint on the shell at startup
rather than a crash. `--no-hooks` starts without loading any.

Sixteen of upstream's eighteen events are fired. `session_before_branch` and `session_branch`
are not: they are about forking a conversation into a second session file, and pig branches
inside one file instead. Subscribing to either says so rather than silently never firing —
as does a typo, since the names are a list here rather than eighteen TypeScript overloads.

Four things worth keeping straight:

- **A `tool_call` hook that throws blocks the call.** Upstream's rule and the right one: a
  hook asked whether a tool may run and answering with an exception has not said yes. The
  alternative is a `rm -rf` guard that stops working the day someone leaves a typo in it,
  which is the day it matters. Every other event catches and reports instead — a hook that
  watches is not allowed to stop the thing it watches.
- **A handler that returns the wrong type is reported, not ignored.** Treating it as "no
  opinion" turns a typo into a hook that appears to work.
- **`context` is chained and nothing else is.** Each `context` handler is given what the last
  one returned, so two hooks can both edit the conversation without knowing about each other.
  A `tool_result` handler is given the tool's own output every time, so a hook can always tell
  the tool from another hook's edit of it.
- **A hook's note goes in through `prompt()`, not onto the state.** `before_agent_start`
  returns text that becomes its own user message in front of the prompt — appended to the
  agent's messages directly it would never reach the session file, and a resumed conversation
  would be missing the thing that explained it.

`HookedTool` is `tool-wrapper.ts` as a decorator, because PHP has interfaces where TypeScript
has `{...tool, execute}`. `Process::run()` grew an optional `$cwd` so `$pi->exec()` can run a
command where the project is.

`HookUi` is upstream's `HookUIContext`, and it is what makes `tool_call` more than a
yes-or-no rule: a handler can ask the person something and wait for the answer.

```php
$pi->on('tool_call', function ($event, $ctx) {
    if ($event->toolName !== 'bash' || !str_contains($event->input['command'] ?? '', 'rm -rf')) {
        return null;
    }

    return $ctx->ui->confirm('Let bash run?', $event->input['command'])
        ? null
        : new ToolCallEventResult(block: true, reason: 'You said no.');
});
```

**It blocks the turn, and that is the whole trick.** A handler runs inside the agent's
fiber, so awaiting an answer suspends that fiber and nothing else: the loop keeps serving
the terminal, the keystroke arrives, the deferred completes, the handler carries on where it
stopped and returns an ordinary value. This is the one thing `pig/async` exists for, and it
is why upstream can have this feature in JavaScript and a synchronous PHP port could not.

Three things that keep it from parking a session forever:

- **Every dialog can be escaped.** `SelectList` always could. `Input` could not — nothing
  pig opened had needed it, because every input was one somebody had asked for. An
  un-escapable prompt in front of a suspended fiber is a session that has to be killed, so
  `Input::setCancelHandler()` was added for this.
- **One dialog at a time.** A second `confirm()` while the first is open would take focus
  from it, leaving the first fiber waiting on a component nobody can reach. The second is
  refused with its safe answer instead.
- **Escape is a no, and so is having no terminal.** `NoUi::confirm()` returns false, which
  for a guard means a tool nobody could approve does not run. Upstream's choice, and the
  only safe direction.

All nine members are ported. `select` and `confirm` are `SelectList`, `input` is `Input`,
`editor` is a `CustomEditor` — the wrapper, not the bare `Editor`, because that is the piece
that turns escape and Ctrl+G into named keys, so they mean the same thing in the dialog as at
the prompt. `notify` is the transcript, `setStatus` is a third footer line that appears only
when something has put a line on it, and `theme` is `palette()`.

`custom()` hands a hook the `TUI`, the palette and a `done()`, and shows whatever component
it builds. An earlier note here said it was left out because it exposes the renderer to code
loaded off disk; that reasoning was wrong and is recorded as wrong — a hook is `require`d
in-process and can already call `exit()` or reach anything by reflection, so withholding
`$tui` was a lock on a door in an open field. What it does need is care: `done()` is
idempotent and always closes, a factory that returns something that is not a `Component` is
refused *and* releases the one-at-a-time latch, and a component that never calls `done()` and
ignores escape parks the turn — which nothing here can prevent, because it holds the keys.

### A hook with something to say

`sendMessage()`, `appendEntry()` and `registerMessageRenderer()` are the rest of upstream's
hook API, and the first two are opposites that are easy to confuse:

| | Where it goes | Who sees it | What it costs |
|---|---|---|---|
| `sendMessage()` | the conversation | the model, and a person unless `display: false` | context, like any message |
| `appendEntry()` | the session file only | the next run of the same hook | nothing |

`sendMessage()` makes a `Session\HookMessage` — an app message like `BashExecution`, turned
into a user message by `CodingAgent::toLlm()`. It is what a hook uses to tell the model
something the model had no way to find out: a build that just failed, a file that changed
underneath it, a rule about this repository. Its `content` goes to the model **whole** rather
than through `toText()`, because it is the only one of these whose content may be images —
which is how a hook gets a screenshot into a conversation.

`appendEntry()` makes a `Session\CustomEntry`, and the interesting thing about it is where it
is *not*: outside the tree. Every message has a parent, because going back to an earlier point
is what the tree is for, and a note about the session is not a point in the conversation anyone
could go back to. Upstream reads these with a flat scan; so does `customEntries()`.

Five things worth keeping straight:

- **A hook's message is not drawn as a user message.** It reaches the model as one, and
  showing it as one would have someone scroll back and find themselves saying something they
  never typed. `HookMessageComponent` labels it with the hook's own type instead, and the HTML
  export does the same.
- **`display: false` is honoured on screen and in the export**, and nowhere else. A reminder
  injected before every turn is talking to the model; a person who has to scroll past it every
  time stops reading the screen.
- **A note written before the first answer is held back, not dropped.** Nothing is written
  until the first assistant message, and upstream's own example is a `session_start` hook
  noting permissions — which happens before that. So the catch-up flushes held-back notes
  along with the held-back messages, interleaved by timestamp, or the file would read
  messages-then-notes and the common case would be lost entirely.
- **`triggerTurn` is a hook driving the agent.** Idle, it appends and runs; working, the
  message is queued as a follow-up and the flag is ignored, because a message between a tool
  call and its result is a request every provider rejects.
- **Calling either before a session exists throws.** A hook file is read at startup, before a
  mode has wired anything up, and a message that silently goes nowhere is a hook that appears
  to work.

`registerMessageRenderer()` is the same shape as a custom tool's `renderResult` and behaves the
same way: null means "draw it normally" and is not a complaint, a throw or a non-component
falls back **and says so**. One renderer per type, last registration wins, and a later hook
wins a clash — unlike a command clash, which is worth complaining about, two hooks claiming one
message type means one of them is drawing messages it did not send.

A compaction names it — `[Hook build]: …` — rather than folding it in as a user message, or a
summary would read "the user said the build is broken" and send the model looking for a
conversation that did not happen.

`BeforeAgentStartEventResult` still carries text rather than a `HookMessage`: upstream's is one,
and the part that survives into the conversation is the part that reaches the model.

**Nothing of upstream's hook API is left out now.**

**Each mode wires its own UI**, which is upstream's rule too. `InteractiveMode` builds the
`TerminalUi` and calls `HookRunner::initialize()` and `CustomToolSet::withUi()` itself;
`bin/pig` loads and constructs but wires none of it, because it has no screen to draw a
dialog on and a second mode will have a different one.

`CustomTools\` is upstream's `core/custom-tools/`, on the same loader. A tool lives in a
folder of its own — `~/.pig/tools/<name>/index.php`, or the same under `<cwd>/.pig/tools`
— because a tool is likelier than a hook to want a second file beside it, and a folder is
where that goes. The file returns a factory; the factory returns a `CustomTool` or a list
of them:

```php
<?php // ~/.pig/tools/wc/index.php

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolApi;

return fn (CustomToolApi $pi) => new CustomTool(
    name: 'wc',
    label: 'Count lines',
    description: 'Count the lines in a file.',
    parameters: [
        'type' => 'object',
        'properties' => ['path' => ['type' => 'string', 'description' => 'the file']],
        'required' => ['path'],
    ],
    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
        [new TextContent(trim($pi->exec(['wc', '-l', $params['path']])->stdout))],
    ),
);
```

Upstream's `CustomTool` is an object literal with five fields and three optional callbacks;
the PHP equivalent of an object literal is a value object built from closures, and the
developer chose that over an interface to implement. `WrappedCustomTool` turns one into an
`AgentTool` — upstream's `wrapper.ts` — and the one thing it adds is the session: `execute`
is given the same `HookContext` a hook gets, which is the reason to write a custom tool
rather than a built-in one. It can read the conversation, see which model is answering, and
stop the run.

Five things worth keeping straight:

- **The declaration validates itself.** A nameless tool, a tool with no description, a
  `parameters` that is not a JSON Schema object — all refused in the constructor, which the
  loader turns into a complaint naming the file. Left to the provider, a bad name fails the
  whole request rather than the tool, and a missing description is a tool the model can
  never choose.
- **A tool may not take a built-in's name.** `bash` is described in the system prompt; a
  custom tool answering to that name would be called in its place by a model that was told
  something else. Named, not dropped, because only the author can fix it.
- **Custom tools are wrapped with the built-ins, not after them.** `CodingAgent::create()`
  concatenates first and hands the lot to `HookedTool::wrap()`, so a `tool_call` hook guards
  a tool somebody wrote exactly as it guards `bash`. A guard that covered only the built-ins
  would not be a guard.
- **The session context is a closure, not a value.** `/model` replaces the model mid-session,
  and a tool that asked which one is answering must not be told about the one that was
  replaced.
- **The UI arrives after loading, not during it.** A factory runs at startup, before there
  is a screen; `CustomToolApi::ui()` answers as `NoUi` until the mode calls `withUi()` on the
  one shared API object every factory closed over. That is why `bin/pig` constructs the API
  itself and hands it to both the loader and the set.
- **`onSession` is fired from the interactive mode, not from the session.** All four moments
  a tool hears about — startup, `/new` and `/resume`, `/tree`, quitting — are things someone
  did in the UI, and the UI is the only place with somewhere to report a callback that
  failed. Routing them through the hook runner was the other option and was worse: `/hooks`
  would then list tool files as hooks. Upstream's fifth reason, `branch`, is the fork into a
  second session file that pig does not do.

`--no-tools` loads none of them. Settings name extra entry files under upstream's key,
`customTools`. The system prompt's "Available tools" list stays the built-ins, as upstream's
does: a custom tool reaches the model as a tool definition, which is the part that matters.

**`~/.pig/tools/` holds two unrelated things**, and that is upstream's doing rather than a
choice made here: `ToolInstaller` downloads `fd` and `rg` into it, and custom tools live in
it too. They are told apart by shape — a downloaded binary is a file, a custom tool is a
folder with an entry file — and the discovery glob is `*/index.php`, so neither sees the
other. Worth knowing before wondering why `ls ~/.pig/tools` shows a mixture.

`renderCall` and `renderResult` are ported too: a tool hands back a `Pig\Tui\Component` and
`ToolExecutionComponent` draws that instead of its own tool view — which is what stops a tool
whose result is a table from being shown through formatting built for files and commands. Four
things about them:

- **Two independent halves.** A tool may draw its heading and leave the result to the default
  text, or the other way round. `drawsItself()` is true if either is set, and each falls back
  on its own.
- **A renderer that throws falls back *and says so*, once.** Upstream catches and silently
  draws the default; a picture that quietly turns into plain text is a bug nobody reports. Once
  per call, because `draw()` runs again on every update and a broken renderer would otherwise
  fill the transcript with its own failure.
- **Returning null is not a failure.** "Nothing to draw here" is a legitimate answer and gets
  the default without a complaint; returning something that is not a component is a complaint.
- **`renderCall` gets the arguments as they stand**, which mid-stream may be half a JSON
  object. Every field is therefore optional to the renderer, exactly as it is to the built-in
  headings.

`CustomToolAPI.ui` is ported as `HookUi`, in both `$pi->ui()` and the context handed to
`execute`. Nothing of upstream's custom-tool module is left out.

### Picking a failed turn back up

A turn can fail for a reason that undoes itself — the provider is busy — or for a reason the
*next* request can fix: the conversation outgrew the window. `AgentSession` handles both,
after the run ends, from `afterTheRun()`.

Which one it is, is decided by what the provider said, and the two are mutually exclusive on
purpose. `Retry::worthRetrying()` asks `Overflow::happened()` first and answers false for an
overflow however retryable the rest of it looks — a 429 that says "prompt is too long" is a
429 that will say it again in four seconds.

**`Session\Retry` reads the status code, where upstream reads the prose.** Upstream matches
the error message against `/overloaded|rate.?limit|429|500|.../i` because its providers word
failures however they like. All four of pig's providers write
`"<provider> returned <status>: <message>"`, so the number is right there — 408, 429, 500,
502, 503, 504 and 529 are waited out, and everything else a provider returns is about the
request, which will not change by being sent again. The word list survives underneath for the
failures that never reached HTTP at all: a socket that died mid-stream has no status to read.

**`Ai\Utils\Overflow` is a table of what each provider actually says**, ported from upstream's
`ai/src/utils/overflow.ts` with its examples kept as comments. There is no status code for
"too long" and no field in any response that says so; every provider says it in prose, two say
it with an empty 4xx and no body at all, and z.ai does not say it — it accepts the oversized
request, answers, and bills for more input tokens than the window holds, so the only evidence
is the usage report. **A pattern with no example beside it is a guess**, and a guess here
compacts a conversation that was fine.

The four events are `RetryStartEvent`, `RetryEndEvent`, `AutoCompactionStartEvent` and
`AutoCompactionEndEvent`, and they implement `Pig\Agent\AgentEvent` so they reach the
listeners that are already there — `AgentEvent`'s docblock says why an event from above the
loop is on the loop's stream. Each mode draws them as it likes: the terminal turns a retry into
a loader that names the error and counts down, `--mode json` and RPC encode them through
`RpcEvents`, `-p` ignores them.

Five things that are load-bearing rather than tidy:

- **The failed message comes off the agent's state before the retry**, and stays in the session
  file. Leaving it would put "Anthropic returned 503" in the transcript for the model to read
  and try to make sense of.
- **Everything spawns.** `afterTheRun()` runs inside the agent's own event fan-out, and
  `continue()` starts another run — doing that there would re-enter the agent from inside its
  own notification, and the sleeping cannot happen in a callback at all. Upstream reaches for
  `setTimeout(..., 0)` "to break out of the event handler chain"; `Async::spawn` is the same
  idea with a name that says why.
- **The sleep is abortable**, because eight seconds that escape cannot reach is eight seconds
  of a terminal that will not answer. A timer and an abort listener race to complete one
  `Deferred`; the timer is cancelled on an abort rather than left to fire into nothing, because
  a pending timer keeps `Loop::isIdle()` false and `bin/pig` would not exit.
- **`prompt()` waits for a retry in progress.** A sleeping retry is not "streaming", so nothing
  else would have stopped a message racing it into the same agent.
- **The waits double** — 2s, 4s, 8s from `retry.baseDelayMs`. The failures this waits out are
  the ones where everybody else is also retrying, and a fixed delay brings the whole crowd back
  at once.

Settings are upstream's keys: `retry.enabled` (on unless turned off, like compaction),
`retry.maxAttempts`, `retry.baseDelayMs`.

### Where the startup time went

`Timings` is upstream's `core/timings.ts`, behind `PIG_TIMING=1` as upstream's is behind
`PI_TIMING=1`. Twelve marks in `bin/pig` — arguments, settings, credentials, context files,
skills, slash commands, the session, hooks, custom tools, the agent, the `@file` reads — and a
table on **standard error** at the point a mode takes over.

Four things about it:

- **Gaps, not moments.** Each mark records the time since the last, so what comes out is a list
  of steps and what each cost. A list of timestamps needs subtracting by whoever reads it, and
  that is the part nobody does. The first mark is an anchor and reads 0.
- **The marks are unconditional**, and `mark()` is a comparison and a return when the variable
  is unset. An `if` at each of twelve steps would be the same thing written twelve times.
- **Standard error, and before the mode starts**, not at the end. Standard output belongs to
  `--mode json` and `-p`, and a report printed when pig quits is one somebody has to scroll back
  an hour to find.
- **`table()` is separate from `report()`** because `report()` writes to standard error, which
  `ob_start()` does not capture — a test against it would pass whatever the table said. That is
  the same failure as the `/label` test that passed while doing the wrong thing, caught this
  time by noticing the assertion could not fail.

Exactly `1`, as upstream tests it: `PIG_TIMING=0` meaning "on" would be a variable that cannot
be turned off the obvious way.

### Three ways in

`bin/pig` picks one, by upstream's rule: **`--mode` given at all means no terminal, and `-p`
is the short way of saying `--mode text`.** So the terminal is what you get when neither was
said, and nothing has to ask for it.

| | What comes out | Ported from |
|---|---|---|
| terminal (the default) | a TUI | `modes/interactive/` |
| `-p` / `--mode text` | the last answer, on stdout | `modes/print-mode.ts` |
| `--mode json` | every event as a JSON line | the same file, its `"json"` branch |
| `--mode rpc` | JSON lines out, commands in | `modes/rpc/` |

`PrintMode` is the smallest of the three because `RpcMode` did the work: `RpcEvents` already
encodes the events, and wiring hooks and custom tools with no UI is already something that
happens. What is left is deciding what to print — and it prints **only the text blocks of the
last assistant message**, because thinking is the model talking to itself and a script would
have to strip it.

Two things about `--mode json` that are not obvious:

- **The exit code stays 0 on a failed turn.** The caller is reading events, and the failing
  turn arrived as one. `-p` exits 1, because there the failure has nowhere else to go.
- **Warnings go to standard error** — every one of them, which is why `bin/pig` was already
  written that way. A yellow sentence about a broken hook among the JSON lines stops whatever
  is parsing them at that line.

`PrintMode` hands hooks a `NoUi`, so a `confirm()` is false and a `select()` is null without
anybody being asked. That is the fail-safe direction: a `tool_call` guard that cannot reach a
person blocks the call. A hook that would rather behave differently checks `$ctx->hasUi`.

Positional arguments are the initial prompt, in every mode but `rpc`. In the terminal they are
sent before the first keystroke and then it is yours as usual — which is the difference from
`-p`, and the reason they go through the same path a typed message does rather than a shortcut
of their own.

`@file` (`Cli\FileArguments`, upstream's `cli/file-processor.ts`) is read in front of the
first message, wrapped in `<file name="/absolute/path">`. An image becomes an `ImageContent`
attachment with an empty element beside it naming the file — which is how a screenshot gets
into a conversation without a tool call. Empty files are skipped; a missing one throws rather
than calling `exit()`, because a class that exits cannot be tested and `bin/pig` is the one
place that knows how to end the program. `--mode rpc` refuses `@file` and positional messages
outright, as upstream does: there the conversation arrives as commands, so a file read here
would be prepended to a prompt that never comes.

`Cli\SessionPicker` is upstream's `cli/session-picker.ts` and `Cli\SessionList` is its
`components/session-selector.ts`: `bin/pig --resume` with nothing after it shows the earlier
conversations and opens the one that is chosen. Before it, that flag answered
`Could not read the session at ` — with nothing after the "at", because the value was the
empty string. A session file is named after six random bytes under a flattened project path,
so a flag that demands the path is a flag nobody can use from memory.

Three things about it:

- **It runs before the mode exists**, on a `Tui` it starts and stops inside the call, so what
  comes back is an ordinary return value and the session is opened by the same code that
  opens a `--resume <path>`.
- **Escape means "start a new one", not "stop".** Someone who opened the list and changed
  their mind still wanted pig; refusing to start would only make them type the command again.
- **Only with a terminal.** In `--mode text|json|rpc` there is nobody to ask, so a bare
  `--resume` stays the error it was — a clearer one now, naming what is missing.

Upstream's selector has a third key, delete. Not ported: a session file is a record of
something that happened, and a list you move through with the arrow keys is the wrong place
for an irreversible key.

### Finding the one you want

Thirty conversations under one project, each named by whatever its first line happened to be,
and the arrow keys as the only way through them. So `Cli\SessionList` has a search box over
the list, which is upstream's `SessionList` and the half of `session-selector.ts` that pig
borrowed `SelectList` for instead.

**The keys divide in two and nothing switches between them**: up, down, Enter, Escape and
Ctrl+C belong to the list, and *everything else is typing*. A search box you have to tab into
is a search box nobody finds, and there is no focus to lose this way.

What it matches is `SessionInfo::$text` — upstream's `allMessagesText`, every text block of
every user and assistant message run together, collected by `SessionManager::describe()` while
it is already walking the file for the count. Three things are left out of it and each matters:
**thinking**, because it is the model talking to itself and a hit on it finds a conversation by
something nobody ever read; **tool results**, because they are mostly files and a search across
every file ever read matches everything; and the **formatting**, because this is a haystack and
never a thing to show. What anybody remembers about a conversation three days later is a phrase
from the middle of it, which is the whole reason the field exists — matching the opening alone
would only find the sessions that are already easy to recognise in the list.

`Utils\Fuzzy` is upstream's `utils/fuzzy.ts`, arithmetic for arithmetic: every character of the
query in order, somewhere in the text. **The score is a pile of penalties, so lower is better**
— a run of consecutive characters is rewarded and the reward grows along the run, gaps cost,
the start of a word is worth a lot, and a late match costs a little. A space-separated query is
several searches over the same text and all of them have to hit, which is what makes a
transcript-sized haystack narrowable at all.

Four things about it:

- **The walk is over characters, not bytes** — the one deliberate difference from upstream, and
  the only one. JavaScript indexes a string by UTF-16 code unit, which for everything either
  project searches is one per character; `$text[$i]` in PHP is a byte, so a Chinese query would
  compare thirds of characters and score nonsense: `好` is the second *character* of `你好` and
  its fourth, fifth and sixth bytes, which a byte walk reads as a three-character run near the
  end of the string and scores about -13.8 instead of 0.1.
- **Ties keep the order they arrived in.** `usort` has been stable since PHP 8.0, as
  `Array.prototype.sort` is in JavaScript. That is load-bearing rather than incidental: the
  list is sorted newest-first before it gets here, and ties coming back shuffled would look
  like the list had lost its order. A blank query returns the input untouched for the same
  reason.
- **A non-match's score is 0 and means nothing.** Upstream's shape, kept; comparing one against
  a real score would rank the things that did not match above half the things that did.
- **The first character is treated as continuing a run**, because `lastMatchIndex` starts at -1
  and `i - 1` is -1 when `i` is 0. That is upstream's arithmetic and it is not obviously
  intended, but it is load-bearing for the example in upstream's own comment: without it the
  two word-boundary rewards inside `f_o_o` beat the run in `foobar`, and `foo` would rank them
  the wrong way round.

The list component is rebuilt on each keystroke rather than told about the change, because
`SelectList` takes its items at construction — upstream's does too, which is exactly why
upstream's session selector draws its own rows instead of using one. Thirty items and one
object per keystroke is not worth a setter that nothing else would call; the selected index is
carried across and clamped, so narrowing from the bottom of a long list lands somewhere
sensible rather than back at the top.

Enter with nothing matching does nothing at all — it does not close the picker. A search that
found nothing has nothing to choose, and closing on Enter would throw away the query somebody
is half way through typing.

`FakeTerminal::queue()` was added for its test. A component that starts its own loop and
blocks until it is answered has no "after the call" to type into, so the keys go in first and
arrive through `Loop::defer()` once the screen is open — which is also what a real terminal
does with whatever was in its buffer when the program took it over, and it keeps the loop
from deciding it has nothing to do and returning before anybody has typed.

The five short options are upstream's: `-c`, `-h`, `-p`, `-r`, `-v`. **A short option is an
abbreviation and nothing else** — it resolves to its long name and then goes through the same
handling, which is what keeps `-r path` and `--resume path` from coming to mean different
things. The first version gave every short option an empty value, which would have read that
path as a message; see the trap below.

One place pig's flags are not upstream's: **upstream's `--resume` takes no value at all** and
always shows the picker. pig's takes an optional path, because `--resume <file>` existed here
before the picker did and a flag that used to work should keep working. The cost is that
`--resume "fix the bug"` reads the prompt as a path — which fails loudly, naming what it tried
to open, rather than quietly.

`Cli\Arguments` is upstream's `cli/args.ts`, and it lives in the package rather than in
`bin/pig` for one reason: a script that calls `exit()` cannot be called twice by a test, and
the parser now has something worth testing. See the trap below about the flag that ate the
prompt. `--` ends the options, and everything after it is a message exactly as written — not
an option for starting with a dash, not a file for starting with an `@`. It is the only way to
say either, and half an escape hatch is not one.

### RPC mode — the second way in

`Rpc\RpcMode` is `bin/pig --rpc`: JSON lines on standard input, JSON lines on standard
output, no terminal at all. Three kinds of line come out — a `response` to a command, an
`event` as the agent works, and a `hook_ui_request` when a hook wants to ask something — and
`id`, when a command carries one, is echoed on its response so a host can match them up.

**Standard input goes on the same loop as the model's socket**, through `Loop::onReadable()`.
That is the whole reason this mode could not have been written before the hook dialogs were:
`RpcUi` is `HookUi` over the wire, and a hook calling `confirm()` parks its fiber on a
`Deferred` exactly as it does in the terminal — the line that resumes it is a
`hook_ui_response` instead of a keystroke. `readline()` on standard input would have blocked
the loop that has to deliver it.

Three things differ from `TerminalUi`, all because the other end is a program:

- **Every question has an id**, because a host may answer three of them in any order.
- **There is no "one dialog at a time".** The terminal refuses a second one because it would
  steal the keyboard from the first; a host has no keyboard to steal.
- **`custom()` returns null and `getEditorText()` returns `''`.** The first builds a
  `Pig\Tui\Component` and there is nothing to draw it on; the second reads an editor that
  belongs to the host. Upstream leaves both out of its RPC context for the same reasons.

Messages go out through `SessionCodec`, the encoder the session file already uses, so a host
reading `get_messages` and a `--continue` reading the file see the same shape. `RpcEvents`
is the one place the wire shape of an agent event is written down: upstream calls
`JSON.stringify(event)` and is done, because its events are plain objects, which also means a
field rename there changes the protocol silently.

`rpc-types.ts` and `rpc-client.ts` have no counterpart. The first is TypeScript types for the
wire shape, which here is `RpcMode`'s docblock plus `RpcEvents`; the second is a client for
driving the mode from TypeScript, and a host writes JSON lines in whatever language it is in.

**Six of upstream's commands are absent**, and the reasons divide in three:

| Upstream command | Why not |
|---|---|
| `queue_message`, `set_queue_mode` | the anchor commit split the one queue into `steer()` and `followUp()`; `steer` and `follow_up` are the two commands that replace them, rather than guessing which one a `queue_message` meant |
| `cycle_model` | `get_available_models` and `set_model` are what it is made of |
| `branch` | upstream forks a conversation into a second session file; pig branches inside one, as `go_to` with `get_branch` for the points to go to |
| `export_html` | it is `export` here, and it honours `outputPath` |

Twenty-three commands are there: `prompt`, `steer`, `follow_up`, `abort`, `get_state`,
`get_messages`, `get_last_assistant_text`, `get_session_stats`, `get_available_models`,
`set_model`, `set_thinking_level`, `cycle_thinking_level`, `compact`, `set_auto_compaction`,
`set_auto_retry`, `abort_retry`, `bash`, `abort_bash`, `get_branch`, `go_to`, `new_session`,
`switch_session` and `export`.

Every failure is a `success: false` response rather than a disconnection: a host asking for
something impossible should be told, not dropped. Warnings that the interactive mode would
draw on screen — a broken hook, a custom tool that failed to start — go out as `hook_error`
and `tool_error` lines, and everything `bin/pig` prints before a mode starts already went to
standard error, which is what keeps standard output the protocol's alone.

`SessionCodec::encodeContent()` and `plain()` went from private to public for `RpcEvents`.
Widening it was the cheaper of two bad options: a second content encoder living in `Rpc\` is
two things that can disagree about what a message looks like, and they would disagree the
first time a content type gained a field.

What is left unported, across every package, each for a reason:

| Upstream | Why not |
|---|---|
| `ai/utils/oauth/google-antigravity.ts` | Anthropic's, Copilot's and Gemini CLI's sign-ins are all ported, with storage, `/login` and their models. Antigravity speaks Code Assist's protocol, which *is* ported now — what it needs is its own loopback flow (a different client, a sandbox endpoint and its own headers) and its seven models |
| seventeen of the twenty-five selector components | the interactive mode needs eight; `/model`, `/resume`, `/tree`, `/login` and `/logout` are the same `SelectList` in the same place instead, and `settings-selector.ts` is `showSettings()` plus `Interactive\SettingsSubmenu` |
| `agent/proxy.ts` (340) | a stream function that routes LLM calls through somebody's server, so the server holds the keys. pig talks to providers directly; this arrives if something ever wants a proxy |
| `ai/cli.ts` (173) | a standalone `pi-ai` command whose only job is the OAuth logins. `/login` is where pig does that, and a second entry point would be a second thing to keep working |
| `ai/utils/validation.ts` + `typebox-helpers.ts` (104) | AJV and TypeBox, for checking a tool call against its schema. `Agent\ToolArguments` is pig's answer and its docblock states the trade: the two mistakes a model actually makes, and everything else through |
| the fuzzy half of `cli/list-models.ts` | `--models` lists them; upstream's takes a search pattern. `Utils\Fuzzy` is ported and the filter is one call — what it needs is `--models` becoming a value-taking option, which is a decision about pig's command line |
| `coding-agent/migrations.ts` | session-file migrations; pig writes pi's format and has never shipped another |
| `coding-agent/utils/changelog.ts` | shows a changelog on a version bump; pig has no releases |
| `coding-agent/modes/interactive/components/armin.ts` | an easter egg: 31×36 XBM art, animated |
| `coding-agent/core/sdk.ts` | a programmatic factory; `CodingAgent::create()` plus `examples/` is what pig offers instead |
| `coding-agent/modes/rpc/rpc-types.ts`, `rpc-client.ts` | TypeScript types for the wire shape, and a client for driving the mode from TypeScript. `RpcMode`'s docblock plus `RpcEvents` is the first; a host writes JSON lines in whatever language it is in |
| every `index.ts` | barrel re-exports, which is what an autoloader does here |

**This table has now been wrong three times.** Twice in the same way: the first time it said "the
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

So the sweep below finds names, and **a missing name is a question, not an answer.** A behaviour
can be ported into a file called something else — pig's is a component rather than a function,
because only `render()` knows the terminal's width. Before writing "not ported", read what the
upstream file does and grep pig for the behaviour, which is the same evidence a "done" claim
needs.

So the rule, which is the same rule and a wider sweep: **a claim that nothing is missing is worth
checking against the file list rather than against memory, in every package and not just the one
being worked on.** One command does it:

```bash
for p in ai agent tui coding-agent; do
  find /tmp/pi/packages/$p/src -name '*.ts' -not -name '*.test.ts' | sort
done
```

Read it against pig's own tree, and anything with no counterpart belongs in this table or in the
code. It is worth running after a run of porting, not during one — during one, every second file
is legitimately absent.

`examples/agent.php` runs the whole stack without a UI, read-only unless given `--write`.

`markdown.ts` has no `marked` under it here: `Pig\Tui\Markdown\Lexer` and `Inline` are a
hand-written subset — see the dependency note above. They are not CommonMark and do not try
to be; what they cannot parse stays a paragraph and is drawn as the text it was.

### Five upstream dependencies that are not needed here

`pi-tui` pulls in `get-east-asian-width` and leans on `Intl.Segmenter`, both for one question:
how many columns will the terminal give this string? Neither is needed here.

- **Grapheme clusters**: PCRE's `\X` implements UAX #29 extended grapheme clusters, correctly,
  including ZWJ emoji sequences and regional-indicator flags. `Graphemes::split()` is one
  `preg_match_all` call — no ext-intl, no table of Unicode ranges.
- **Character width**: `mb_strwidth()` carries the East Asian Width table, and PCRE answers
  `\p{Extended_Pictographic}` and `\p{Emoji_Presentation}` for the emoji cases it does not cover.

`chalk` is the third, and `Pig\Tui\Style` replaces it: a TUI needs "wrap this string in a
style and close it again", which is one file, not a package. Components take styles as
`Closure(string): string`, so `Style::dim(...)` is what gets passed around.

`marked` is the fourth, and the one that was a real decision rather than an obvious win.
`markdown.ts` uses it for a token stream and throws its HTML away, and a terminal renderer
needs a subset — headings, emphasis, code spans and fences, lists, quotes, links, rules,
tables. The developer chose to write that subset here rather than take `league/commonmark`,
which would have brought `league/config`, `dflydev/dot-access-data` and a row of Symfony
polyfills with it and ended the zero-dependency claim. It is not CommonMark and does not
try to be; anything it cannot parse is drawn as the plain text it came from.

`cli-highlight` is the fifth — it wraps highlight.js and its two hundred grammars, and
`theme.ts` uses it to colour fenced code blocks. `Theme\Highlight` replaces it with a
left-to-right scanner and a table of thirteen languages, which the developer chose over
the dependency. The trade is stated plainly in the class: fewer languages, and inside a
language a handful of things coloured slightly wrong that highlight.js would get right.

The one design point worth keeping: it is a **scanner**, not a pile of `preg_replace`
calls over a keyword list. A replacement-based highlighter paints the `if` inside
`"if you like"` blue and the `#` inside a URL grey, and a reader who has seen that once
stops trusting any of the colours. Asking "what starts here?" at each position means that
once the scanner is inside a string, nothing inside that string is anything else.

Two places it does guess — a Capitalised word is a type, a word before `(` is a call —
and both are documented as guesses. This is the one part of the codebase where a guess is
allowed, because the cost of being wrong is a wrong colour.

The one thing PCRE has no answer for is JavaScript's `\p{RGI_Emoji}`, which matches a whole emoji
*sequence*; PCRE properties test single codepoints. `Width` asks the question of the cluster's
first codepoint instead, which gives the same answer for everything a terminal actually draws.

### Porting the unions

PHP has union types. What it does not have is a way to **name** one or to use one as an array's
element type — `type Message = A|B` and `array<A|B>` are both parse errors. That, not any absence
of unions, is what decides how each of upstream's unions is encoded here:

| Union | Encoding | Why |
|---|---|---|
| `Message` (3 arms, closed) | real union type, aliased with `@phpstan-type` on `Context` | closed like upstream, and a `match` over it can be checked for exhaustiveness |
| `AssistantMessageEvent` (12 arms) | marker interface | with no alias, a union means copying twelve class names into every signature |
| `AgentMessage` (apps extend it) | marker interface | a union cannot be extended from outside the package |
| `Content` / `UserContent` / `AssistantContent` | marker interfaces | they are array elements, where the type is a docblock either way — and the interface still holds for a single block passed on its own |

**A marker interface is not exhaustively checkable.** Anything may implement one, so every
`match (true)` over an interface keeps its `default` arm. Only the closed unions get exhaustiveness.
At runtime the two encodings are equally safe: a `match` with no arm taken throws
`UnhandledMatchError` either way.

`UserContent` / `AssistantContent` exist so that "a user message cannot hold thinking" is a type
error rather than a convention. The twelve event classes are named after their wire strings —
`text_delta` → `TextDeltaEvent`; upstream leaves those arms anonymous, so the names are ours.

Deliberate deviations from upstream, both to spare every consumer an unpacking step:
`UserMessage` wraps a bare string into a `TextContent` at construction instead of keeping
`string | Content[]`, and `Context` takes `messages` first because PHP wants required parameters
before optional ones.

## Commands

```bash
composer install        # path repositories + PHPUnit 12
php test/lint.php       # php -l over every file (PHPUnit only parses what it loads)
vendor/bin/phpunit      # filter: vendor/bin/phpunit --filter Loop
```

PHPUnit 12 is the newest release that still runs on PHP 8.3, so it is what the floor allows.
PHPUnit 13 requires 8.4 and becomes available if the floor ever moves.

`test/AssertsThrows.php` adds one assertion on top of PHPUnit: `assertThrows()` asserts a throw
mid-test and hands back the exception, which `expectException()` cannot do because it scopes to
the whole method.

The dev container has no route to packagist, so tests run on the Mac, not in the agent sandbox.

## Known traps

### `stream_socket_enable_crypto()` returns `0`, not just `true`/`false`

On a non-blocking socket the TLS handshake needs several passes. `0` means "call me again after
the socket is readable"; treating it as failure breaks the connection intermittently — measured
2 passes against `api.anthropic.com`.

```php
while (true) {
    $ok = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($ok === true) break;
    if ($ok === false) throw new TransportError('TLS handshake failed');
    $r = [$sock]; $w = $e = null; stream_select($r, $w, $e, $timeout);  // $ok === 0
}
```

### A signal makes `stream_select()` return `false`

Terminal resize sends SIGWINCH. With a handler installed, an in-flight `stream_select()` is
interrupted and returns `false` with errno 4 — reproduced with `pcntl_fork` + `posix_kill`, and
the whole agent dies on the next resize. EINTR is not a failure: the handler already ran, so
return and let the next tick re-arm. Match the errno, not the message text, which is localized.

```php
error_clear_last();                        // so error_get_last() cannot report a stale warning
$ready = @stream_select($read, $write, $except, $seconds, $microseconds);

if ($ready === false) {
    $message = error_get_last()['message'] ?? 'unknown error';
    if (str_contains($message, 'Unable to select [4]')) return;   // EINTR
    throw new AsyncError("stream_select() failed: {$message}");
}
```

Regression test: `LoopTest::testASignalDuringSelectDoesNotKillTheLoop`.

### A tick polls after its callbacks, even when they ended the program

`tick()` runs deferred callbacks and only then polls. A coroutine always resumes in that first
phase, so the root coroutine can *finish* there — and the same tick then settles into
`stream_select()` with no timeout, waiting on watchers whose only interested party is gone.
Found as a hang in a test whose server socket stayed armed and never became readable again;
other tests survived only because a timer or a readable stream happened to break the poll.

`Async::run()` therefore wakes the loop from the fiber's `finally`. Anything else that finishes
work during the callback phase and leaves nothing to poll for must do the same.

```php
$fiber = new Fiber(static function () use ($main, $loop): mixed {
    try { return $main(); } finally { $loop->wake(); }
});
```

Regression test: `FutureTest::testRunReturnsWhenAWatcherOutlivesTheCoroutine` — with a watchdog,
because a regression here hangs the suite rather than failing it.

### Closing a stream without cancelling its watcher

`stream_select()` silently drops closed streams from the array, then fails with
`ValueError: No stream arrays were passed` — which names nothing useful. `Loop::poll()` checks
`is_resource()` per watcher and throws naming the watcher id instead. On EOF: `cancel()` first,
then `fclose()`.

### Resuming a Fiber that has not suspended yet

`$fiber->resume()` on a running fiber throws `FiberError: Cannot resume a fiber that is not
suspended`. So `FutureState` never invokes callbacks synchronously — every one goes through
`Loop::defer()`, which also keeps completion callbacks out of the completer's own stack.

### Forcing a render on resize skips the clear it needs

`Tui::requestRender(force: true)` empties `previousLines`, and `draw()` reads an empty
`previousLines` as "first frame ever" — which writes the new lines with no clear at all. A resize
needs the opposite: the previous frame must be remembered so the width change is *noticed*, and
then the screen and the scrollback are cleared before redrawing. So the resize handler calls
`requestRender()` plain, like upstream, and force stays for callers who know the screen was
overwritten by something else.

Caught by `TuiTest::testAResizeRedrawsEverythingAndClearsTheScrollback`, which asserts the
`\e[3J\e[2J\e[H` is there.

### An image is drawn from the cursor downwards, so the cursor goes up first

Both image protocols put the picture where the cursor is and grow it *down*. The renderer
works by comparing lines, so it has to know how many rows the picture occupies before the
terminal draws it. `Image::render()` therefore returns that many lines: the first `rows - 1`
empty, and the last one `\e[<rows-1>A` followed by the image sequence. By the time the
terminal draws, the cursor is back at the top of the block and the picture fills exactly the
rows already accounted for.

`Tui::checkWidth()` skips any line holding `\e_G` or `\e]1337;File=`: an image line is tens
of kilobytes long and zero columns wide, and measuring it would fail the width check.

### The terminal's reply to a query arrives as keystrokes

`CSI 16 t` asks how many pixels a character cell is, which is the only way to know how tall a
picture will be. The answer comes back on **stdin**, so it has to be sifted out of the input
before a component reads it as typing. It can also arrive split across reads, so a partial
escape sequence is held back — but only until something that looks finished turns up, because
a terminal that never answers must not swallow the user's typing for the rest of the session.
Only asked on a terminal that draws images, since nothing else uses the answer.

Regression tests: `ImageTest::testTheReplyIsTakenOutOfTheInputAndTheRestStillArrives` and
`testATerminalThatNeverAnswersDoesNotSwallowTyping`.

### Killing the shell does not kill what the shell started

`bash -c 'npm test'` makes the test runner a *grandchild*. Kill the shell and the runner keeps
going, holding the port it bound and writing to a terminal that has moved on — which is what
Escape looks like when it does not work.

Upstream puts the child in its own process group (`detached: true`) and kills the group. PHP
cannot do that through `proc_open`, and macOS has no `setsid` binary to borrow. So `Shell::killTree()`
reads the whole process table once with `ps -eo pid=,ppid=`, walks down from the shell, and kills
children before their parents so nothing gets a chance to start more. One `ps`, not one per node:
this runs while someone is waiting for Escape to take effect.

Regression test: `BashToolTest::testAbortingKillsWhatTheCommandStartedToo`, which starts a
grandchild that would write a file half a second later and asserts the file never appears.

### Output truncated by line count also needs somewhere to look

Upstream's bash tool writes the full output to a temp file only once it passes the **byte**
limit, and the truncation notice then names that file. Two thousand short lines is nowhere near
50KB and is still truncated by the line limit, so upstream's notice in that case says where to
find a file it never wrote. Here the spill starts when *either* limit is passed, which is the
same pair of limits truncation itself uses.

Regression test: `BashToolTest::testTruncatedOutputSaysWhereTheWholeOfItIs`.

### `--ignore-file` applies a nested `.gitignore` in the wrong place

Upstream's `find` collects every `.gitignore` below the search root and passes each one to
`fd` as `--ignore-file`, so that nested ones apply outside a git repository too. They do not
apply *where they sit*: `--ignore-file` patterns are matched against the whole search root, so
a `src/.gitignore` containing `generated/` also excludes `other/generated/`, and files that
should have been found are silently missing.

Both tools already have the flag for this. `--no-require-git` makes `fd` and `rg` read
`.gitignore` outside a repository with the ordinary nesting rules, which is what was wanted.
So the collection is not ported and the flag is passed instead.

Regression test: `SearchToolsTest::testANestedGitignoreAppliesOutsideAGitRepositoryToo`.

### `fd --glob` matches the file name, so every pattern with a directory in it found nothing

`fd --glob '*.php'` matches the **base name**, not the path. `src/*.php` therefore matches no
file that has ever existed, and fd says nothing about it — so `find` answered "No files found
matching pattern" for every pattern the model wrote with a `/` in it, and the model, believing
the answer, walked the tree with eight `ls` calls instead.

Two flags fix it, and both are needed. `--full-path` matches against the path; a `--full-path`
glob is then anchored at the search root, so an unanchored pattern also needs `**/` in front or
`src/*.php` only means the `src` directory at the top. `--full-path` is added only when the
pattern contains a `/`, because matching a bare `*.php` against the path would be worse.

This shipped broken because the PHP walk that preceded `fd` was deleted along with its tests —
including the only ones that passed a pattern with a slash. **A test deleted with the code it
covered takes its coverage with it; the replacement needs its own.**

Regression tests: `SearchToolsTest::testAPatternWithASlashMatchesThePathNotTheName`,
`testASingleStarDoesNotCrossADirectoryButTwoDo`, `testASlashPatternMatchesAtAnyDepth`,
`testAnAlreadyAnchoredPatternIsNotAnchoredTwice`.

### `fd` exits 0 when it found nothing, so failure cannot be read off an empty result

`rg` exits 1 for "no matches" and 2 for an error; `fd` exits **0** either way. So a non-zero
exit from `fd` is always a real failure — usually a glob it would not parse — and `find` was
reporting all of them as "No files found matching pattern", which is a silent fallback of
exactly the kind this project forbids: the model is handed a plausible answer and has no way
to tell it apart from the truth.

`Process::capture()` cannot express this, since it folds "ran and said nothing" into "did not
run". `Process::run()` was added to return the exit code and stderr separately, and `find` now
throws with fd's own message.

Regression test: `SearchToolsTest::testABadPatternIsReportedRatherThanReadAsNoMatches`.

### An input method draws where the terminal's cursor is, not where the caret is drawn

A component paints its caret as an inverted cell; the terminal has a cursor of its own,
and writing a frame leaves that one at the end of the last line. Nothing looked wrong
until someone typed Chinese: macOS draws the composing text and the candidate list at the
terminal's cursor, so the pinyin appeared over the footer, three lines below the box it
was going into.

So `Tui` moves the cursor to the focused component's caret at the end of every frame. A
component opts in by implementing `Caret` — a small separate interface, like
`InputHandler` — and reports the caret in its own coordinates; `Container::rowOf()` turns
that into a row in the frame. The cursor stays hidden; only its position matters.

Two things fall out of it. `cursorRow` has to be updated to the caret, or the next
differential draw counts rows from a bottom the cursor is no longer at. And `drawAll()`
now starts with `\r`, because the caret can leave the cursor part-way along a line and
that path writes from wherever it is.

A wrapper has to forward `caret()` or the whole thing silently does nothing —
`Interactive\CustomEditor` wraps the editor, and that is exactly what it did at first.

**`Components\Input` never implemented it at all**, which was missed because the interface was
added for the prompt and the prompt is an `Editor`. `Input` is the focused component every time
a hook asks a question and every time the session picker is open, so both of those drew the
candidate list at the bottom of the frame. It answers now — one row, and the column is the
prompt's width plus the cursor's own, less however far the line has scrolled. **Adding a
capability interface leaves every component that does not implement it silently wrong**; the
list to check is whatever can hold the focus.

Regression tests: `TuiTest::testTheCursorEndsUpAtTheFocusedComponentsCaret`,
`testTheNextFrameStillCountsRowsFromWhereTheCursorActuallyIs`,
`EditorTest::testTheCaretIsMeasuredInColumnsNotCharacters`, and the five
`InputTest::testTheCaret…` cases.

### `Container` is a `Component` and not an `InputHandler`, so a container given the focus eats every key

The same family as the one above, and it bit in the opposite direction: not a component that
should have implemented an interface, but a component that looked complete because the
interface it was missing is optional.

`/settings`' thinking row opens a submenu, and a submenu wants a title and a hint above and
below the list — so it was a `Container` with a `Text`, the `SelectList` and another `Text`
in it. That compiles, `setFocus()` takes it (focus is typed `Component`), and it draws
correctly. Then every key went nowhere: `Container` forwards drawing to its children and
forwards nothing else, so the arrows, Enter and Escape all landed on an object with no
`handleInput()`. The screen opened and could not be navigated *or closed*.

`Interactive\SettingsSubmenu` is that container with the keys wired through — upstream's
`SelectSubmenu`, which exists for exactly this reason and says so in one line.

**Anything that can hold the focus has to be asked whether it handles input, and a container
never does.** `SettingsList::handleInput()` checks `instanceof InputHandler` before
forwarding to a submenu, which is what keeps this a screen with nothing to press rather than
a crash — and is why the bug was silent.

Regression tests: `InteractiveModeTest::testAThinkingModelGetsARowThatOpensTheLevels` and
`testChoosingALevelSetsItOnTheSessionAndRemembersIt` navigate *inside* the submenu, which is
what a test asserting only that it opened would have missed;
`SettingsListTest::testASubmenuThatCannotTakeKeysIsDrawnRatherThanCrashedOn` holds the guard.

### `fwrite()` to a terminal returns short, and STDOUT is non-blocking whether you asked or not

`ProcessTerminal::write()` called `fwrite()` once and ignored what it returned. A frame is
tens of kilobytes; a tty buffer is a few. Measured: a 1,000,000-byte write put **65,536**
bytes on the wire and dropped the other 93%, and an 11,330-byte frame — the real first
frame at 178 columns — lost 3,138 bytes. What gets dropped is the middle of an escape
sequence, so from there every cursor move is against a screen holding something else. On
screen it looks like frames stacking up instead of replacing each other.

The second half is why it is easy to miss: **`stream_set_blocking($input, false)` makes the
output non-blocking too.** STDIN and STDOUT are dups of one open file description when both
are the terminal, and `O_NONBLOCK` belongs to the description, not the descriptor. So the
input watcher puts the output in a mode where short writes are normal, at a distance, in
another method.

`write()` now loops until everything is out, waiting on `stream_select()` for the writable
side when the buffer is full, and throws after five seconds rather than spinning. Anywhere
else this codebase writes to a stream it may not own, check the return value the same way —
a partial write is the quietest failure there is.

Regression test: `ProcessTerminalTest::testAWriteBiggerThanTheBufferStillArrivesWhole`. It
writes through a real pipe, because `FakeTerminal` accepts whatever it is given and so can
never reproduce this — which is exactly why it shipped.

### Closing a style with `\e[0m` closes whatever it was nested inside

`Style::bold()` and friends used to end with a full reset. A full reset turns off
*everything* — including a colour somebody else opened around that text. So a bold word
inside a red line left the rest of that line un-red, and the coding agent, which wraps
everything in a palette colour, hit it on the first component that used both.

Each attribute now closes with its own off-code: `22m` after bold or dim, `23m` italic,
`24m` underline, `27m` inverse, `29m` strikethrough, and `39m`/`49m` after a foreground
or background colour. The one exception is `Style::of()`, which is handed arbitrary
codes and cannot know which off-code goes with each — so a combination built that way is
a leaf style, and nesting is done with the named methods.

Regression tests: `ComponentsTest::testEachStyleClosesOnlyWhatItOpened` and
`testAStyleNestedInAColourLeavesTheColourStanding`.

### A style must not be left open at the end of a line

`Tui` compares frames line by line, so a line is the unit that has to be self-contained:
a colour opened on one line and closed on the next means the second line carries a style
it never asked for, and a differential redraw of only the first line leaves the rest of
the screen tinted.

This bites anything whose tokens can span a newline — a block comment, a triple-quoted
string. `Highlight` therefore splits every token on `\n` and styles each piece on its own
line, rather than styling the token once and splitting afterwards.

Regression test: `HighlightTest::testEachLineClosesItsOwnStyles`.

### A per-line prefix has to go on after the wrap, not before

`Markdown` styles a block, then wraps it. That is right for *inline* styling — the wrapper
carries escape codes across its own breaks — and wrong for anything that must appear at the
start of every line. A block quote built as `"│ " . $text` and wrapped afterwards comes out
with the border on its first row and the remaining rows hanging in the margin, which reads as
the quote having ended.

So `quote()` wraps its children to `width - 2` itself and prefixes each resulting line. Found
by looking at rendered output, not by a test — every assertion about widths and codes passed.

Regression test: `MarkdownTest::testEveryLineOfAWrappedQuoteKeepsItsBorder`.

### `Highlight` hands back the code unchanged when it has no grammar

Which is right in a terminal — an unknown language is printed as it is — and is an injection
hole on a page. `MarkdownHtml::code()` therefore asks `Grammar::for()` first and escapes the
lines itself when there is none, rather than trusting the highlighter to have touched them.

A tool result is the obvious way in (a `<script>` in a file the model read), but so is any
fenced block in an answer whose language pig has no grammar for, which is most of them.

Regression test: `HtmlExportTest::testAToolCallSaysWhichToolAndWhatOn` asserts `&lt;?php` in
an un-languaged result. `testAJavascriptLinkIsNotALink` covers the other half: a
`javascript:` href in a transcript is a script the model wrote, running when someone opens
the file, so only `http(s)`, `mailto` and `ftp` become links at all.

### A tool result outliving the call it answers

`OpenAiResponses` drops a tool call from a turn that ended in an error, because an aborted
call has half-parsed arguments and asking the model to continue from one is worse than
dropping it. `TransformMessages` runs *first* and, seeing a call with no result, invents one.
Put together, the request carries a `function_call_output` addressed to a `call_id` that was
never sent — which OpenAI rejects outright, so the conversation cannot be continued at all.

Upstream has the same two halves and the same gap. Found here by building the case rather than
by a 400: the shape is visible in the assembled request, which is why the provider tests assert
on what went out and not only on what came back.

The fix is in `input()`: it records the calls it actually emitted and drops a result whose call
is not among them. Both halves of a dropped turn go, or neither.

Regression test: `OpenAiResponsesTest::testAnAbortedTurnsThinkingAndCallsAreNotSentBack`.

### A token count measured before a compaction describes a conversation that no longer exists

`shouldCompact()` reads the last completed turn's usage, because the provider's own count
beats an estimate. Compaction keeps the most recent messages — usage and all — so straight
after one, the newest usage in the conversation is still the reading that *asked* for the
compaction. Nothing recomputes it until the next turn comes back.

Left alone, that is a loop: every turn starts by auto-compacting, the second one fails with
`Already compacted`, and the person gets a red error before everything they type. It is not
visible in any single-step test, because one compaction does work correctly.

`Compaction::lastUsage()` therefore returns null when the newest `CompactionSummary` is at
least as new as the assistant message it would otherwise use — by timestamp, not position,
since the summary sits in *front* of the messages it is newer than. `<=` rather than `<`:
the summary is written straight after the turn it replaces and a millisecond holds both.
Discarding a good reading costs one turn without one; keeping a stale one costs every turn
after it.

Regression test: `AgentSessionTest::testCompactingDoesNotLeaveTheSessionAskingToCompactAgain`.

### A hook that fails while being asked for permission has not given it

`tool_call` is the one event whose handler is not wrapped in a `try`. Every other event
catches a throwing handler, reports it and carries on, because a hook that watches must not
be able to stop what it watches. Permission is the opposite: a hook asked whether `bash` may
run `rm -rf` and answering with a `TypeError` has said nothing, and reading nothing as yes is
how a guard stops working on exactly the day someone leaves a typo in it.

So `HookRunner::emitToolCall()` lets the throwable out and `HookedTool::guard()` turns it into
a blocked call — `Blocked: a tool_call hook failed, and a hook that cannot answer is not
consent`, which the model reads as a tool error and can work around. Upstream does the same
thing in `tool-wrapper.ts` and calls it fail-safe.

Tests: `HookRunnerTest::testAToolCallHandlerThatThrowsIsNotCaughtHere` and
`testAToolCallHookThatThrowsBlocksTheCall`.

### Two hook files reaching the same path is a fatal error, not a doubled handler

`~/.pig/hooks` symlinked into a project's `.pig/hooks` is a normal way to keep one copy of a
hook, and the obvious reading is that loading it twice just registers its handlers twice.
It is worse than that: `require` on a file that declares a function a second time is a fatal
`Error`, so the second load kills the *first* hook as well and neither works.

`HookLoader` therefore deduplicates on `realpath()` before loading, the same way `Skills`
does — and for a harder reason. Test:
`HookLoaderTest::testTheSameFileNamedTwiceIsLoadedOnce`.

### A hook that prints corrupts the screen it printed onto

Hooks load before the UI starts, so an `echo` or a stray `var_dump` in a hook file lands on
the terminal a moment before the TUI takes it over and redraws across it. What the person
sees is a session that came up wrong, with nothing to read and nothing to search for.

`HookLoader` wraps the `require` in `ob_start()` and turns anything printed into a load
complaint naming the file and quoting what it printed. Test:
`HookLoaderTest::testAFileThatPrintsIsAComplaint`.

### A fixed unpack directory plus a glob installs last week's binary

`ToolInstaller::extract()` unpacks into `<tools>/extract_tmp`, which is upstream's fixed
name and is kept on purpose — see the note on the method for why a unique name was tried
and reverted. The fixed name brings one hazard upstream does not have: `finally` removes
the directory, but a killed run leaves it behind, and `findBinary()` looks for the binary
with `glob('*/fd')` rather than at one exact path. An interrupted v8 download therefore
sits in `extract_tmp/fd-v8…/fd` waiting to be installed as v9.

So the directory is removed *before* it is created as well as after. One line, and the
reason is only visible if you know the discovery globs; a future change back to a unique
name can drop it, and anything that keeps the fixed name must keep it.

Not covered by a test: reaching it needs a killed process and a real archive, and
`extract()` is private — the seam would be a public method existing for the test alone.

### Ctrl+G was being reported to nobody

`CustomEditor` has recognised Ctrl+G since it was ported — `claimed()` turns it into the
named key `'ctrl+g'` — and `InteractiveMode` never bound it. Pressing it did nothing at all,
while the component behaved as though someone were listening. A forwarded key with no handler
is invisible: there is no error, no warning, and nothing to search for.

It is bound now, to upstream's `openExternalEditor()`: write the prompt to a temp `.md`, stop
the TUI, hand the terminal to `$VISUAL` or `$EDITOR` through `Process::interactive()`, read it
back, start the TUI again. Non-zero exit keeps the original, which is what `:cq` means.

**One difference from upstream, and it is pig doing more rather than less: the loop keeps
turning while the editor is open.** Upstream blocks on `spawnSync`, which stops libuv for as
long as the person takes; a model streaming into a socket nobody reads fills the receive
buffer, goes zero-window, and eventually has its connection reset. So a long edit during a
turn can cost the turn.

The first version here guarded against that by refusing Ctrl+G while the agent was working.
That was wrong, and it is worth saying why: the case Ctrl+G is *most* wanted for is "the model
is busy, my next message is three paragraphs" — which is the same thing as typing while it
streams, pig's own headline feature, only in vim. The guard forbade exactly the best use, and
upstream not guarding it is very likely because that is how it is used. Upstream does guard
other things on `isStreaming`, so it is not an oversight of the pattern.

pig can have both, because it has an event loop where upstream has a blocking call:
`Process::interactive()` takes a `$yield` closure, polls the child, and yields between looks,
so the socket is read and the turn is appended exactly as it would have been. The TUI is
stopped, so none of it is *drawn* until the forced redraw on the way back — nothing can be
done about that while vim owns the screen, but there is nothing to lose by not reading.

Two things that make it safe, both checked rather than assumed:

- `ProcessTerminal::stop()` cancels the stdin watcher and restores the saved `stty`, so pig
  and the editor are never both reading the keyboard.
- A pending timer keeps `Loop::isIdle()` false, so the polling delay is itself what stops
  `run()` from returning — and the program exiting — while the editor is open.

Both callers must spawn a fiber: the key handlers run inside the loop's input callback, and
suspending there suspends the loop that called them. Same reason `send()` and
`startCompaction()` spawn.

`Process::interactive()` has **no timeout**, alone among that class's methods. That is not an
upstream difference — `spawnSync` takes one and `openExternalEditor()` does not pass it either
— it is a difference from pig's own convention, where every other method there has one so a
hung command cannot hang pig. Here a timeout would kill the person's editor mid-sentence.

Barely covered by a test: `interactive()` opens `/dev/tty` for all three streams, and a test
runner has no tty to open. What is tested is the empty-command guard and that a run with no
controlling terminal answers STOPPED without polling — which is the branch a session started
from a script takes.

### A session that can change conversations cannot have a `readonly` session file

`AgentSession::$store` was `readonly`, and both `/resume` and `/new` changed which
conversation was on screen without being able to change where it was written. Two probes,
not reasoning:

```
/resume B, then say something:
  screen:      B's conversation, then the continuation
  A on disk:   A one / A first / said after resuming B / answered after resuming B
  B on disk:   B one / B first          ← stopped growing when it was resumed

/new, then say something:
  one file:    the old conversation / old answer / a brand new conversation / new answer
  sessions listed for this project: 1   ← the new session never existed as one
```

So `/resume` produced two files and neither was what happened, and `/new` appended the new
conversation onto the last one as though they were the same. With the tree it is worse: the
restored messages are not in the old store's `entries` at all, so the new ones are parented to
the old store's leaf and the file replays as something nobody saw.

`writeTo(?SessionManager)` is the fix, and it is a missing piece of the port rather than an
addition — upstream's `AgentSession` owns its `sessionManager` and replaces it in
`switchSession()` and `newSession()`. `/resume` writes to the file it opened; `/new` creates
one; `--no-save` stays null and `/new` does not quietly start saving.

**The comment in `newSession()` claimed this was deliberate** — "the same file: `/new` forgets
the conversation but keeps writing where it was writing" — which is how a bug survives a
reading. A comment that explains why something surprising is correct is worth exactly as much
as the reasoning in it, and that one had none.

Regression tests: `InteractiveModeTest::testWhatIsSaidAfterResumingIsWrittenToTheFileThatWasResumed`,
`testANewSessionGetsAFileOfItsOwn`, `testANewSessionWritesNothingWhenNothingWasBeingWritten`.
All three were checked against the old code and fail on it.

### A `before_*` hook result is only heard if the runner thinks it decisive

`HookRunner::ask()` walks the handlers and stops at the first *decisive* answer, which each
event defines for itself. `emitBeforeCompact()` counted both `cancel` and a supplied
`compaction`; `emitBeforeTree()` counted only `cancel`. So a hook that wrote its own branch
summary was walked straight past, `ask()` returned null at the end of the list, and the model
was asked to write one anyway — the hook's work thrown away, silently, with no error anywhere.

It was caught by a test written at the same time as the feature
(`HookWiringTest::testAHookCanWriteTheHandoverItself`), which is the only reason it was caught
at all: nothing about the wrong behaviour is visible without a hook that supplies a summary.

Whenever a `before_*` result grows a field that means "I have handled this", the predicate in
its `emit…()` has to grow with it. There are three of these now and they are the place to look
first when a hook seems to have been ignored.

### A dialog opened in front of a suspended fiber has to be escapable

A hook's `tool_call` handler runs inside the agent's fiber. `$ctx->ui->confirm()` parks that
fiber on a `Deferred` and hands the keys to a component; the answer resumes it. If the
component has no way to say "no answer", nothing resumes it — the turn is stopped forever,
the tool never returns, and the only way out is killing pig. It does not look like a hang in
the tool: it looks like the agent went quiet.

Two things follow, and both are load-bearing rather than tidy:

- `Pig\Tui\Components\Input` got `setCancelHandler()` for this. `SelectList` already had
  one, and the multi-line dialog gets escape through `CustomEditor`. Any future dialog in
  `TerminalUi` needs the same before it is used — and `custom()` cannot be given it, which is
  why its docblock says so twice.
- `TerminalUi` refuses a second dialog while one is open, because opening one would take
  focus from the first and leave *its* fiber unreachable. The refusal answers with the safe
  value — null, or false for `confirm()`.

Tests: `HookUiTest::testEscapingAnInputAnswersWithNothing`,
`testASecondDialogIsRefusedRatherThanStacked`, and the three
`testAGuardCanAskBeforeLettingAToolRun` cases that drive the whole chain.

### A credential a scanner knows cannot live in the repository, encoded or not

`Oauth\GeminiCli` needs Google's client id and secret for the Gemini CLI. It carried them three
ways before it stopped carrying them at all, and each step is worth keeping:

1. **Written out**, with a comment arguing that neither is really secret — both ship in every
   Gemini CLI install and in a published npm package, and PKCE is what protects the exchange. All
   true. GitHub's push protection refused the push: it has patterns for exactly "Google OAuth
   Client ID" and "Google OAuth Client Secret".
2. **base64, as upstream's `atob()` wrappers have them.** Refused again, at the new line numbers:
   **the scanner decodes base64.** So upstream's encoding is not what gets past a scanner, and
   assuming it was is what cost the second push.
3. **Not in the repository.** They are handed to the constructor, and `CodingAgent\Auth`
   finds them: `GEMINI_CLI_CLIENT_ID` / `GEMINI_CLI_CLIENT_SECRET`, then `geminiCli.clientId` /
   `geminiCli.clientSecret` in the settings file — pig's usual order. Absent, `/login` refuses
   with a message naming both places and saying plainly that pig does not ship them.

Two rules out of it, and the second is the one that generalises:

- **Before writing any real credential into a file, assume the host scans for its provider's
  pattern — and that it decodes.** Obfuscating harder is an arms race against a control whose
  job is to protect that credential, and it fails on the day the scanner learns the trick.
- **A scanner that detects a credential also reports it to the issuer**, and Google revokes what
  is reported. So a plaintext copy in a public repository is a good way to break a sign-in for
  everyone using it, upstream included — which makes this a correctness argument and not only a
  policy one.

`Anthropic`'s and `GithubCopilot`'s client ids stay written out, because nothing objects to them:
one is a bare UUID, the other a GitHub OAuth *client* id, which is not a secret and is not what
GitHub looks for. The asymmetry is the scanners' and not a choice.

### An arrow function whose body returns nothing is a fatal error

`fn (): void => $this->say($note)` does not compile: an arrow function always returns its
expression, and returning the result of a `void` method is `A void method must not return a
value` — at parse time, so `php -l` catches it, but only after it has been written. A closure
with a body is the fix. Hit twice in two days, in `Cli\SessionList`'s submit handler and in
`InteractiveMode`'s sign-in progress callback: **a one-line callback that calls a `void` method
has to be a block.**

The same family: `$closure?->($argument)` is not syntax either. Nullsafe applies to `->method()`
and `->property`, never to invoking a variable, so a nullable callback needs an `if`.

### `stream_socket_pair()` with a dropped peer (tests)

`[$a] = stream_socket_pair(...)` garbage-collects the peer, putting `$a` at EOF — permanently
"readable", with `fread()` returning `''`. Keep both ends in scope.

### A missing `use Throwable` makes every catch in the file a no-op

`AgentSession` had no `use Throwable;` and had never needed one. The first three
`catch (Throwable $problem)` blocks written into it were therefore catching
`Pig\CodingAgent\Session\Throwable`, a class that does not exist — so nothing was ever
caught, the exception escaped the `Async::spawn` that was running the work, landed in a
`Deferred` nobody awaits, and **vanished**. What it looked like: `AutoCompactionStartEvent`
went out, and then the session sat there. No error, nothing on the shell, nothing in the
transcript.

`php -l` cannot see it — an unimported class is resolved at run time — and the class is only
resolved on the path that throws, which is the path nobody exercises by hand. It was caught by
a test written for the failure case, which is the only reason it was caught at all.

The sweep is worth keeping: for every file, collect its `use` statements and the names it
writes in `catch (X)`, `instanceof X`, `new X(`, `X::` and typed parameters, and report the
difference. Run over `packages/*/src` it found this one and nothing else real — three
docblock mentions and two names that resolve through a sibling file in the same namespace.

**It has happened three times now**, each in a different disguise, which is what makes it
worth a rule rather than a story:

| Where | What it did |
|---|---|
| `catch (Throwable)` with no import | never caught anything; the fiber died silently |
| `instanceof Component` with no import | would never have matched, so every hook renderer would have fallen back to the default |
| `new Text(...)` in a test with no import | threw where the code under test catches, so the test failed on the fallback rather than on the missing import |

The third is the one to remember, because it wasted the most time: the error surfaced as a
*feature* not working, because the thing that threw was inside a `try` belonging to the code
under test. **Run the sweep after adding an `instanceof` or a `catch` to a file you have not
touched before** — it is one command, and it is cheaper than reading the screen dump.

Two rules fall out, and the second is the one that generalises:

- A `catch` clause naming a class the file does not import is a `catch` that never fires.
- **Anything inside `Async::spawn()` whose future nobody awaits must not be able to throw.**
  `AgentSession::inTheBackground()` wraps the work and turns a throw into a `RetryEndEvent`,
  so a bug in there is a reported failure rather than a session that stops mid-sentence.

### An abort with nothing yet to abort reports a cancellation that did not happen

`abortRetry()` used to abort a controller created around the *sleep*, while `isRetrying()` was
already true from the moment the retry started. Between those two points escape said
"Retrying was cancelled.", reset the counter, completed the waiter — and the retry carried on,
slept, sent the request, and eventually announced a second, contradictory end.

The controller is now created together with the waiter, so there is never a moment where the
retry is running and nothing can stop it, and `carryOn()` checks the signal before starting a
run in case the abort arrived after the sleep finished. **Whenever a flag says "this is
happening", the thing that stops it has to exist by the time that flag is set** — not a line
later.

### `tick()` waits out the timer it is polling for (tests)

A test that wants to abort something mid-sleep cannot just tick twice and then abort. With a
one-second timer armed and no streams to watch, `Loop::poll()` has nothing to select on and
`usleep()`s the whole second — so two ticks really are two seconds, the sleep is long over,
and the abort lands on a retry that has already moved on to its next attempt. The symptom is
a second, successful end event that makes no sense next to the cancellation.

An expired timer of the test's own makes `pollTimeout()` ~0, so the tick returns with the
retry still parked:

```php
Loop::get()->delay(0.0, static fn () => null);
Loop::get()->tick();
```

Same family as the RPC trap above, opposite direction: there a tick blocked forever because
nothing was pending, here it blocked for a second because something was.

### A provider that throws hangs the agent, and swallows the reason

`AgentLoop::start()` and `continue()` push events from inside `Async::spawn()`, so the
producer is a coroutine of its own. A throw there — `Stream::simple()` failing to resolve a
hostname, a missing key, anything synchronous before the first event — escaped that fiber and
left the `EventStream` open forever. `Agent` was already written for this: its consumption of
the stream sits in a `try` whose `catch` calls `recordFailure()`. The throw just never crossed
the fiber boundary to reach it, so what a caller actually got was
`AsyncError: The event loop ran out of work while the root coroutine was still suspended` —
with the real cause gone.

Fixed with `EventStream::fail(Throwable)`: it closes the stream, fails `result()`, and hands
the throw to every parked consumer, so `foreach ($stream as $event)` rethrows it and `Agent`'s
existing catch does the rest. `AgentLoop`'s two spawned bodies are wrapped in a try that calls
it. Events pushed before the throw are still delivered — a producer that pushed three events
and then failed did three real things, and the prompt has already been announced, so a UI that
drew the user's message does not have to un-draw it.

`fail()` rather than `end($error)` because **a stream that ended has a result and a stream
that failed has a reason**, and a consumer that cannot tell them apart reads a dead connection
as the model having nothing to say.

The general shape, which is the part worth remembering: **anything inside `Async::spawn()` has
its own stack, so a `try` outside the spawn catches nothing that happens inside it.** Every
spawned producer needs a way to hand its failure to whoever is waiting.

### `NoUi` was reported as a UI, so `hasUi` lied

`HookContext::$hasUi` answers one question — will asking reach anybody — and `HookRunner`
computed it as `$this->ui !== null`. That was right for as long as nothing passed a `NoUi`
deliberately: the default is `null`, and `HookContext` turns null into `NoUi` with `hasUi`
false. `PrintMode` is the first caller to pass one on purpose, and it was told there was
somebody there, so a hook that checks before asking asked and heard nothing.

Now `$this->ui !== null && !$this->ui instanceof NoUi`. The predicate belongs with `NoUi`,
which is by definition nobody; a caller passing `hasUi: true` beside a `NoUi` is making a
claim that contradicts itself, and `HookContext` still lets one, which is worth revisiting.

### A flag that takes no value eats the argument after it

`bin/pig`'s parser decided whether an option took a value by looking at the next argument: if
it did not start with `--`, it was the value. That worked for exactly as long as nothing but
options was ever passed. The moment positional messages meant something,
`bin/pig --read-only "fix the bug"` set `read-only` to `fix the bug` and had no prompt left,
with nothing said about it.

`Cli\Arguments::TAKES_A_VALUE` is the list, and everything not on it is a flag. Upstream's
`args.ts` spells each option out for the same reason. **A CLI cannot afford a guess about what
the next word means**, and the failure mode of guessing is silent.

### A hex id used as an array key becomes an integer

An entry id is eight hex characters, and about one in forty-three is all digits. PHP turns an
array key that looks like an integer into one, so `$entries["12345678"]` comes back out of a
`foreach ($entries as $id => …)` as the **int** `12345678` — and a function typed `string $id`
then throws. Intermittently, in about two percent of runs, which is the worst rate there is:
often enough to happen to a user, rare enough to pass every time you look.

Lookups are safe — PHP normalises the subscript the same way — so only iteration is affected,
and the fix is `(string) $id` where the key comes out. It has been latent since ids existed;
pig's twelve-character ids hit it about one run in three hundred.

### A short option is not automatically a flag

`SHORT` maps `-p` to `print`, and the first version of the branch that handled it did
`$options[$short] = ''` and moved on — right for `-p`, and wrong for every short option whose
long form takes a value. Adding `-r` would therefore have made `-r some/path.jsonl` set
`resume` to the empty string, which `bin/pig` reads as "show me the picker", and left the path
in the message list to be sent to the model as a prompt.

The same mistake as the flag that ate the prompt, one level down: **two spellings of one
option must not each carry their own idea of whether it takes a value.** So the short branch
resolves to the long name and falls through into the same code, and a test asserts that every
pair in `SHORT` parses identically.

### One chunk is one key (tests)

`Keys::isEnter($data)` is `$data === "\r"`, and every other reader is the same shape: the
chunk that arrives *is* the key. So `type('hello' . ENTER)` in a `FakeTerminal` test delivers a
single six-character key that is not Enter, and it lands in the editor as text — the
characters appear, the submit handler never runs, and the test fails on something that looks
like the submit being broken. One `type()` per key, which is what every test in
`InteractiveModeTest` already did.

### Validating inside the fiber answers a command twice

`RpcMode::prompt()` read its `message` field inside the `Async::spawn()` that runs the turn.
A command with no `message` therefore threw in the *fiber*, where the only thing to catch it
was the fiber's own handler — which sent an id-less failure, **after** `dispatch()` had already
returned null and the success response had gone out. A host was told both that its prompt was
accepted and that it was not, in that order, and the second line named no command it had sent.

Whatever a command needs is read before the spawn. The rule generalises: anything spawned
answers separately from the command that spawned it, so everything that can fail as *the host's
mistake* has to fail on the near side of the spawn, and only the work itself belongs on the far
side.

### A loop with one readable watcher blocks forever on a zero-timeout tick (tests)

Driving `RpcMode` from a test means turning the loop by hand, and `isIdle()` is never true while
the stdin watcher is armed, so the stopping condition has to be a tick count. That much is
obvious. What is not: `pollTimeout()` returns null — block until a stream is ready — when there
are watchers and no timers, so a plain `tick()` with nothing to read never comes back.

`Loop::wake()` does not fix it. A tick runs the queue *before* it polls, so by the time
`pollTimeout()` is consulted the queue is empty again and the answer is still null. An expired
timer is still there when it is asked: `delay($seconds, fn () => null)` before each tick.

And the timeout has to be nonzero if a subprocess is involved. Forty ticks at a zero timeout are
over in microseconds — long before `echo hello` has written anything — so a `bash` command over
the protocol looked like it had produced nothing at all. A millisecond each gives the poll
something to wait with.

## Version floor: PHP >= 8.3

`Fiber` arrived in 8.1 and the whole async runtime rests on it, so 8.1 is the absolute floor;
8.3 is the floor actually declared, because 8.1 is end-of-life and 8.2 loses security support at
the end of 2026. `require.php` in the three composer.json files is the source of truth.

Off-limits until the floor moves, even though the dev machine runs 8.4:

| Feature | Since |
|---|---|
| Property hooks, asymmetric visibility (`public private(set)`) | 8.4 |
| `new Foo()->bar()` without parentheses | 8.4 |
| `array_find` / `array_find_key` / `array_any` / `array_all` | 8.4 |

Where 8.4 would express something better, say so in a comment rather than leaving it unexplained —
`FutureState` reads that way, since on 8.4 its readers would collapse into `public private(set)`.

Verify against the floor, not just the dev version — `php8.3 test/lint.php`. That does two things,
because `php -l` only parses:

- linting with the **floor's own binary** rejects 8.4-only *syntax* at parse time;
- a grep catches 8.4-only *functions*, which `php -l` never resolves. `array_any()` lints clean on
  8.3 and fails only on the line that reaches it.

Neither catches a **missing `use`**: an unimported class is resolved at run time too, and shows up
only on the code path that touches it. Exercise every branch — `ThinkingStartEvent` went unimported
and only the thinking test found it.

## Conventions

- **A new composer dependency is the developer's call, every time.** Ask before adding one, and say what
  it would cost to write instead — something small enough to write in an afternoon gets written
  here. Zero runtime dependencies is the point of the project, not an accident of it. `partial-json`
  and `sanitize-unicode` were both replaced by a file each rather than pulled in.
- `README.md` and `README.zh-CN.md` are one document in two languages. Every change to one lands
  in the other in the same commit — a half-translated README is worse than an untranslated one.
- `declare(strict_types=1)` in every file; PSR-12; one class per file.
- `match` over `switch`, `#[\Override]` on every override, `str_contains`/`str_starts_with`,
  constructor property promotion, `readonly` for anything that should not change after construction.
- No `@` error suppression — `test/lint.php` fails the build on one. A function that warns *and*
  returns false gets a `set_error_handler` around it, because the warning text is usually the only
  place the errno appears; where the condition is knowable in advance, check it instead
  (`SessionManager::lines()` checks `is_readable` rather than suppressing `file()`).
- No silent fallback: `catch` must re-throw (`throw new X(..., $e)`). No `?? default` to paper
  over a missing value, no `clamp`/floor without a reproduced bug behind it.
- `Deferred::complete()` twice throws. Where ported code relies on a JS promise ignoring its
  second resolve, the call site guards with `isComplete()` and says so in a comment — so the
  leniency stays local instead of becoming a global rule.
