# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## pig is a minimal agent

**The developer's standing rule, above every other decision in this file: pig stays minimal, and
nothing that muddles the logic gets added.** More tools, more options, more paths through the same
code are not free — the model spends part of every turn choosing between them, and every extra arm
is a place for the rules to disagree with each other, which is what most of the traps at the bottom
of this file turned out to be.

So a feature that upstream does not have needs a reason that survives being read out loud, and a
feature upstream *does* have arrives in upstream's shape rather than a wider one. When a choice is
between two workable designs, the one with fewer branches wins. When something is already in reach
another way — searching through `bash` rather than a third search tool — the second way does not get
added for convenience.

This is the rule that decided the built-in tool set: four by default, as upstream has it, with
`grep`, `find` and `ls` reachable by name through `--tools`.

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

**The rule has a second edge, and it is the one that gets missed:** where upstream is *working
around* something JavaScript does not have, the port is the PHP call, not the workaround.
`Changelog` was written with upstream's split-into-three-and-subtract version comparison before
`version_compare()` replaced it, and the hand-rolled one was wrong about `0.2` and `0.2.0-beta`.
Copying the arithmetic is not the same as porting the behaviour. Same story as `Graphemes::split()`
being one `preg_match_all` where upstream needs `Intl.Segmenter` and a package.

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

### Reaching the provider through a proxy

`Ai\Http\Proxy` has **no upstream counterpart, and is not optional** where pig runs. Node's
`fetch()` is undici, which reads `HTTPS_PROXY` through an agent somebody else wrote; PHP's streams
connect to the address they are handed and to nothing else. On a network where
`api.anthropic.com` is unreachable directly — the developer's, among others — pig without this does
not talk to a provider at all, so "direct" is not a worse route, it is no route.

Two protocols, because a local Clash or v2ray offers both and people use whichever port they
remember: **HTTP CONNECT** (RFC 9110 §9.3.6) and **SOCKS5** (RFC 1928, with RFC 1929 for a
password). `--proxy <url>`, then `https_proxy`/`all_proxy`, then `proxy.url` in the settings — the
order stated at the top of `bin/pig`, and `--no-proxy` ignores all three.

**The order inside `Proxy::open()` is the security property, not an implementation detail.** The
TCP connection goes to the proxy with TLS *off*; TLS starts only after the proxy has said the far
end is open, and it verifies the **provider's** certificate. That is why `Socket::enableTls()` had
to become public, and why it sets `peer_name` itself: a tunnelled socket was dialled to the proxy
and would otherwise be checked against the proxy's name. The proxy carries pig's bytes and can read
none of them.

Eight decisions worth the words:

- **A hostname is sent as a hostname**, so the *proxy* resolves it — `socks5h` behaviour for the
  `socks5` spelling too, deliberately. In the network that makes a proxy necessary, the local
  resolver is the other thing that does not work, and a name resolved here would already be the
  wrong address by the time the proxy saw it. `ProxyTest` asks for `provider.invalid` throughout:
  by RFC 2606 it cannot resolve, so a version of this that resolved locally fails the test rather
  than passing for the wrong reason.
- **Loopback is always direct, and that is not a convenience.** pig's own OAuth callback listens on
  `127.0.0.1:8085`; a tunnel through a proxy to reach the machine pig is on cannot work. It also
  means the whole test suite keeps talking to its own servers.
- **Nothing reads the environment on its own.** `bin/pig` and `bin/pig-ai` each do it once. The
  first draft had `HttpClient` do it in its constructor, and every test in this container — which
  has `HTTPS_PROXY` set — would then have tunnelled through the container's own agent proxy.
- **The default is process-wide**, which is `Models::register()`'s shape and for the same reason:
  `new HttpClient()` appears in thirteen places — five providers and four OAuth flows among them —
  and none of them is handed one. A provider that reached the network directly while the rest
  tunnelled would work right up until somebody switched to it.
- **A bad proxy URL is fatal at startup.** Falling back to a direct connection would report the
  *provider* as unreachable and send somebody looking at the wrong machine.
- **An unreachable proxy says it was the proxy.** The first version's message was `Cannot connect to
  127.0.0.1:7890`, which never says whose address that is; it now reads `Cannot reach the proxy
  socks5://127.0.0.1:7890: … (asked for api.anthropic.com:443)`.
- **`describe()` never prints the password**, and it is what every error message above uses.
- **`https://` as the proxy's own scheme is refused by name.** TLS to the proxy with TLS to the
  provider inside it is two crypto layers on one stream and
  `stream_socket_enable_crypto()` does one. Every proxy that speaks `https://` also speaks
  `http://` or `socks5://` on another port, so naming it *is* the fix. SOCKS4 is refused too: it
  cannot carry a hostname, which is the one thing that matters here.

`http_proxy` is not read, and an `http://` target is tunnelled with CONNECT rather than sent in
absolute form. Both follow from the same fact: every provider pig talks to is `https://`, and a
plain-HTTP endpoint in `models.json` is a local or self-hosted server that goes direct anyway.

The tests forward for real, through `test/TunnelServer.php` — a loopback proxy that speaks both
protocols — because a handshake can be byte-perfect and still leave one byte unread on the socket.
That was not hypothetical: deleting the read of SOCKS5's bound address makes four tests fail with
`Malformed status line: "  8HTTP/1.1 200 OK"`, which is the bound address arriving where the
response was meant to be. Asserting only on the bytes pig *sends* would have passed.

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
becomes which component, which key means what — and `bin/pig` is the entry point.

The screen is five containers between the banner and the footer: `$chat` (the transcript),
`$pending` (what is queued), `$status` (what is *happening* — the working loader, a retry
countdown), `$overlay` (what is being *asked* — a picker, `/settings`, a hook's dialog), then the
editor. `$status` and `$overlay` are separate on purpose and the trap below says why in full: the
overlay holds whatever has the focus, and only the thing that gave it the focus ever clears it.

The
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

`Components\Rule` is upstream's `DynamicBorder`, renamed: "dynamic" there means "asks the width
at render time", which is what every component in `pig/tui` does — there is no static kind for it
to be the opposite of, and what it is is a rule. The colour is a closure taken at construction,
which upstream's own note argues for in the hardest way available: its `theme` is a module-level
global, and a hook loaded through jiti gets a second module cache where that global is
`undefined`, so a border drawn from inside a hook crashed on a theme present everywhere else. pig
has no module caches, but the shape is right for a second reason — `/theme` replaces the palette,
and a component holding a closure it was given cannot hold a stale colour table.

`Interactive\BorderedLoader` is upstream's, and it is for hooks: a hook that goes away for a while
hands one to `HookUi::custom()`, and the rules are what separate it from the transcript above and
the prompt below. `TerminalUi`'s own dialogs draw no rules, because a question reads as one thing;
this is a **wait**, and a wait with nothing around it reads as output that stopped. It is a
`Container` **and** an `InputHandler`, which is the trap on `SettingsSubmenu` again — and here the
key a container would eat is the only one that cancels.

Its `dispose()` is not a nicety: the spinner reschedules itself, so a loader nobody disposed of is
a loop that never runs out of work and a `bin/pig` that never exits.
`testARunningBorderedLoaderNeverLetsTheLoopSettleAndADisposedOneDoes` is the test, and its name is
long because the first version of it was wrong: it asserted `isIdle()` immediately after
`dispose()`, which is false for a moment because **a pending render counts as work**. That looked
like a bug in `dispose()` and was a bug in the test. The question worth asking is not whether the
loop is idle now but whether its work *ends*.

`Interactive\ArminComponent` is upstream's easter egg, reached by typing `/arminsayshi` — known
and not listed, like `quit`, because `COMMANDS` is also what `/help` prints and the autocomplete
offers, and something you have to already know about is the whole idea. Upstream leaves it out of
its own command list for the same reason.

It earns a place in a port for something other than the joke: it is the only thing in either tree
that draws a **bitmap with half-block characters**, two pixel rows to a cell — `█` for both, `▀`
and `▄` for one. The 144 bytes are upstream's, LSB first, and **a zero bit is foreground**, which
is an XBM convention and the one detail that turns the picture into a photographic negative if it
is guessed at.

**One of the seven effects never ends, in pi and in the first version here.** `rain` finishes a
column when `settled >= height`, and `settled` is `height - target` — so it only reaches `height`
for a column with ink in row 0. Four of these 31 columns have that and two have no ink at all, so
27 can never satisfy it: `allSettled` is never true, the effect never reports done, and because pi
creates this component and **never calls `dispose()`**, a one-in-seven roll leaves a 30fps redraw
running for the rest of the session.

It was found by driving each effect to its end in a test and watching one of them pass 100,000
frames, then by printing the topmost ink row of all 31 columns rather than reasoning about it. The
fix is the condition `settled >= height` was reaching for: a column is done when there is nothing
left above what has landed. Nothing about the finished picture changes — every effect was already
verified to end on the byte-identical grid — and all seven now stop between 8 and 186 frames.

Three smaller notes:

- **`shuffle()`, not upstream's hand-written Fisher-Yates**, which is what `shuffle()` already is.
  The same rule as `version_compare()`: where upstream is working around a missing library call,
  the port is the call.
- **Three of the seven effects are one function.** typewriter, fade and dissolve are "reveal N
  cells per frame in a given order"; upstream has three copies because the order lives in three
  differently-named state bags. Written once here as `reveal()`.
- **The effect can be named in the constructor.** A seam that upstream has no equivalent of,
  because seven effects cannot be tested by starting this seven times and hoping — and it is what
  found the one that never ended.

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

**The list only offers things that take effect**, which is what decided its seven rows:

| Row | Reaches |
|---|---|
| Theme | `useTheme()`, split out of `switchTheme()` so a row can name a theme rather than toggle |
| Thinking | a submenu of the levels *this model* offers, so a model that cannot reason gets no row at all rather than six ways to change nothing |
| Thinking blocks | `useHideThinking()`, split out of the ctrl+t handler |
| Pictures | `useShowImages()`, and its reader — see below |
| Queued messages | `AgentSession::setQueueMode()`, which tells the agent and writes the setting |
| Auto-compact | `compaction.enabled`, read again on every turn |
| Auto-retry | `retry.enabled`, read again on every failure |

**Queue mode is a row now, and getting there meant the anchor commit's own break.** Upstream has
one `setQueueMode()`; pig has two queues, because `d0a4c37` is the commit that split the single
queue into `steer()` and `followUp()` and it changed `packages/agent` only — `agent-session.ts` at
that same commit still calls a method that no longer exists there. So there is no working original
to copy, and `Agent::setQueueMode()` picks the queue the *setting* is about: a message typed while
the agent is working is a **follow-up**, which is where the submit handler puts it, and steering is
a different act with a different key. `setSteeringMode()` arrives if something ever wants it apart.

Three smaller decisions fell out of it:

- **The modes are copied out of `AgentOptions` into fields on `Agent`.** `AgentOptions` is readonly
  and stays that way: it records what an agent was *set up* with, and a settings screen writing a
  field on it would turn that record into a record of the present.
- **`AgentSession`'s constructor applies the stored mode**, not whoever built the agent. It is the
  class holding both the agent and the settings, and `setQueueMode()` writes through the same
  pair — a caller passing it into `AgentOptions` instead would be a second route for one fact.
- **Upstream's `queue-mode-selector.ts` has no consumer.** Checked before deciding not to port the
  component: the only `QueueModeSelectorComponent` in upstream's tree is its own definition, and
  the live path is a row in its settings screen. So the row is the port and the standalone
  component is dead code there.

Writing the test for it found a gap in the test harness rather than in the code:
`InteractiveModeTest` was passing **null settings to `AgentSession`** while giving the same
settings to `InteractiveMode`, which `bin/pig` does not do. Three settings the session reads — the
queue mode, auto-compaction and auto-retry — were live in production and inert in every test.

**`terminal.showImages` was a setting pig stored and nothing read.** Building the screen is what
surfaced that, and the choice was between leaving the row off and giving the setting its reader.
Upstream has a real one — `tool-execution.ts` takes `showImages`, checks
`caps.images && this.showImages` before drawing, and has a `setShowImages()` for the transcript
already on screen — so pig now has the same: `ToolExecutionComponent` takes the flag and
`setShowImages()` redraws. Off draws the label `TerminalImage::fallback()` already produces for a
terminal that cannot draw pictures, from the same function, so "turned off" and "cannot" look
alike and neither pretends no picture came back. Queue mode is on the list now and has its own note above.

`useTheme()`, `useHideThinking()` and `useShowImages()` being split out is the part that matters
most and is the least visible: ctrl+t writes the setting *and* tells every
`AssistantMessageComponent` already on screen. A second place writing only the setting is a
toggle that half works — the file says hidden and the transcript still shows thinking — so both
paths go through the one method. The theme is the exception and says so: the list holds the old
palette's closures, so new colours arrive the next time it is opened, which is the same thing
`/theme` already says about the transcript.

`Changelog` is upstream's `utils/changelog.ts`, with two readers: `/changelog` shows the whole
file and startup shows only what is newer than the version this person last saw. The second is the
one that has to be right — a tool that greets you with its whole history every morning is a tool
whose greeting you stop reading.

Four things in it, three of which are upstream's and one of which is arithmetic:

- **`version_compare()` does the comparing.** Upstream splits the number into three and
  subtracts, because JavaScript has nothing else; the first version here copied that and was
  worse than the platform call in two ways that only showed up against real input — `0.2` read
  as `0.2.0` and `0.2.0-beta` read as `0.2.0`, so both compared *equal* to a release they are
  not, and somebody on a pre-release saw nothing after upgrading to the release it preceded.
  `version_compare` gets those right and `0.10.0 > 0.9.0` too, which is the one the hand-rolled
  version existed for. **Copying upstream's arithmetic is not the same as porting it** — where
  JavaScript is working around a missing library call, the port is the call.
- **A `##` with no version in it ends the entry and starts nothing**, discarding what it had
  collected. Right for a file people edit by hand: folding an `## Unreleased` section into the
  release above would file unreleased notes under a released number.
- **A string that is no kind of version means everything is new**, because it sorts below every
  release. Same end as upstream's `Number()` of a missing part: showing the whole file once beats
  silently showing nothing because a settings file had a typo in it.
- **The version is written down when the notes are shown**, not when pig starts, so a run that
  showed nothing does not mark them as seen. `-c` and `-r` skip it: somebody picking a
  conversation back up is in the middle of something.

**pig has no `CHANGELOG.md`**, so all of this answers nothing today — which was the reason it went
unported, and is the wrong reason: it is the same answer somebody who deleted theirs gets, and the
machinery is worth having working before the file exists rather than written the day it appears.

`Session\BranchSummarization` is upstream's `core/compaction/branch-summarization.ts`, and
### Where compaction cuts, and the half-turn upstream drops

`Compaction::cutPoint()` returns one index: everything before it is summarised, everything from it is
kept. Upstream's `findCutPoint()` returns three things — the index, whether the cut lands mid-turn,
and where that turn started — and `prepareCompaction()` then ends the summarised history at the
**turn's start** rather than at the cut, while the kept range still begins at the cut.

Reading their code, that leaves the messages between the two in neither: not summarised, because the
history stopped short of them, and not replayed, because `buildSessionContext()` emits the summary and
then everything from `firstKeptEntryId`. `turnPrefixMessages` collects exactly those messages, and
**nothing in upstream's own compaction reads it** — it is put on the preparation object for a
`session_before_compact` hook, and its own comment says they are messages that "will be turned into
turn prefix summary", which is a sentence about something not done yet.

pig summarises up to the cut, so the split turn's first half is inside the summary and nothing falls
between. That is a divergence in pig's favour and it is stated as a reading rather than a measurement:
it was found by comparing the two, not by running upstream.

The rest of the file matched, and was checked rather than assumed: `contextTokens()` prefers the
provider's own total; `shouldCompact()` is the same comparison plus a `contextWindow > 0` guard, which
upstream lacks and which stops a model declaring no window from asking to compact for ever; the
enabled flag lives in `AgentSession::shouldCompact()` rather than inside the arithmetic;
`estimateTokens()` divides by four; the cut never lands on a tool result; `files()` carries an earlier
summary's lists forward and takes read minus modified, sorted; `request()` wraps the conversation in
`<conversation>` tags with `<previous-summary>` when there is one, and appends `Additional focus:` for
custom instructions; the summariser gets `0.8 * reserveTokens` and high reasoning.

One cosmetic difference, written down so nobody later builds on the assumption that it is not there:
**the two summarisation prompts are re-wrapped.** The words are upstream's exactly, but pig's heredoc
breaks the long lines, so where upstream has a space mid-sentence pig has a newline.

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

Seven roots, in this order: `~/.codex/skills`, `~/.claude/skills`, `.claude/skills`,
`~/.pi/agent/skills`, `.pi/skills`, `~/.pig/skills`, `.pig/skills`, plus whatever `--skills-dir`
adds. Someone who wrote a skill once should not have to write it again per agent. The `~/.claude`
roots are scanned **one level deep** and the others recursively, because a folder per skill is the
layout there and descending further finds a skill's own examples rather than more skills.

Five of the seven are upstream's. **pi's two are pig's own addition**, and upstream has no reason to
have them — it *is* pi, so `~/.pi/agent/skills` is the root pig renamed to `~/.pig/skills`. Reading
pi's as well follows from what pig already does everywhere else: it opens pi's sessions, its
`auth.json` and its `models.json`. Keeping somebody's conversations and credentials across the move
and silently dropping their skills is the half-migration that is worse than none. Note the depth:
`getAgentDir()` upstream is `join(homedir(), ".pi", "agent")`, so a root pointed at `~/.pi/skills`
would find nothing — there is a test whose only job is to say so.

**Order is precedence, lowest first: a later root overrides an earlier one of the same name.** So
`pig > pi > claude > codex`, a project folder beats the home one of the same tool, and `--skills-dir`
beats all of them because it was typed. `SkillsTest` proves the whole chain at once rather than one
link — four folders, one name, and the pig one is what loads.

**This is the second divergence, and upstream is the other way round.** It keeps the *first* one and
warns `"skipping this one"`, in this same order — which makes `~/.codex/skills` outrank everything,
including pi's own skills. Nobody who edits a skill in `~/.pig/skills` expects a copy in another
tool's folder to be the one that runs, and between two answers to the same name the more specific one
is right. The override is named in a warning either way, so which of the two files is in effect is
never a guess: `name taken: "review" overrides the one from ~/.pi/agent/skills/review/SKILL.md`.

One file reached through two roots — `~/.claude/skills` symlinked into `~/.pig/skills` — is still one
skill and no warning, because the `realpath` check comes first. That is the normal way to keep one
copy, and it is not an override.

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

`Ai\Utils\Oauth\` is upstream's `ai/src/utils/oauth/`, and **all four flows are ported now**.
They arrived in that order and the order is the story: Anthropic's, which is what a Claude Pro or
Max subscription is used through, and GitHub Copilot's went first because neither needed anything
pig did not already have. The two Google ones waited on a piece that did not exist — they are
loopback PKCE flows that want an HTTP server on `127.0.0.1` and a browser opened at it, so
`CallbackServer` had to be written before either could work.

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

### Antigravity, which is the same protocol against a different deployment

`Utils\Oauth\Antigravity` and the seven models in `Models::ANTIGRAVITY_MODELS`. Upstream's
`google-antigravity.ts`, and the last of its four sign-ins to arrive. What it buys is models
Gemini CLI does not offer at all: two of Anthropic's, three Geminis and an open-weights one, all
on a subscription rather than per token.

**A separate class, not a base one shared with `GeminiCli`.** Upstream has two files and so does
this. The overlap is real — both are Google's PKCE loopback flow against the same token
endpoint — but the two differ in exactly the part that matters, which is what happens *after* the
tokens arrive: `GeminiCli` provisions a Cloud project and polls until it exists; this one asks two
endpoints and gives up into a constant. A shared parent would have to hold both shapes, and the
seam would fall where they are least alike.

Four things that are its own, each of which breaks it if guessed:

- **Its own OAuth client**, not Gemini CLI's — and therefore its own pair of settings keys and
  environment variables (`ANTIGRAVITY_CLIENT_ID`, `antigravity.clientId`). Not in this
  repository, for the reason in the credentials trap.
- **Five scopes where Gemini CLI asks three.** `cclog` and `experimentsandconfigs` are the extra
  two, and the sandbox checks for them: a token minted with Gemini CLI's scopes reaches the same
  endpoint and is refused.
- **Port 51121 and `/oauth-callback`**, both registered with Google against that client id. A
  redirect Google has not been told about is refused before anybody sees a consent screen, so
  neither is a preference.
- **`user-agent: antigravity/1.11.5 darwin/arm64`.** The sandbox answers 403 to Gemini CLI's, so
  this is load-bearing. The version and the platform in it have nothing to do with the machine
  it runs on; it is upstream's literal string and stays literal, because what the sandbox wants
  is to be talking to Antigravity.

**Which header set a request gets is decided by the provider name, not by the host** — a
deliberate difference from upstream, which asks `endpoint.includes("sandbox.googleapis.com")`.
That is the right question there, where the endpoint is a variable built per request; here the
two deployments are two registry providers with two fixed base URLs, so the name is the thing
actually being asked. And it is what makes this testable: a test cannot point a model at the real
sandbox host *and* have the request arrive at a local canned server, so under the host rule the
one header set that is load-bearing was the one no test could see.

Two more things worth knowing:

- **`google-antigravity` is in `RESOLD`, and it matters more here than for the other two.**
  `claude-sonnet-4-5` is Anthropic's own id. A bare one has to keep meaning Anthropic's, or
  somebody with an Antigravity sign-in would find `--model sonnet` quietly going through Google.
  Regression test: `testAntigravityDoesNotStealAnthropicsOwnId`.
- **The fallback project is somebody else's.** `rising-fact-p41fc` is upstream's constant, used
  when neither discovery endpoint names a project, and it is not pig's and not a secret — it is
  what Antigravity itself falls back to. Requests that land on it are attributed to it, which is
  worth knowing rather than discovering. Discovery swallows every failure on purpose: a 403 from
  the production endpoint is the normal case for an account that was always going to use the
  sandbox.

`available()` is true for all four now, so nothing in `/login` is greyed and the "not ported yet"
label has no case left to describe. The method stays, **as a table and not as a computed answer**:
upstream's `getOAuthProviders()` carries `available` as a literal field on each of its four
entries, so here it is a `match` with one line per provider beside `label()`, matching it line for
line. `return true` would read as a condition that cannot be false — a claim the code makes and
does not keep — where four lines that each say `true` are a table whose rows currently agree, and a
fifth provider ported halfway has somewhere to say so without anybody inventing a condition for
it. No `default`, so a new case stops the `match` instead of defaulting to available.

Its false branch is unreachable today, and therefore untested, in three places: `showSignIns()`
greys the row, `signIn()` refuses before spawning, and `Auth::login()` refuses again. That is
known rather than overlooked.

### `pig-ai`, the second entry point

`Cli\SignIn` is upstream's `ai/cli.ts` and `bin/pig-ai` is nine lines over it: `list`, `login`,
`login <provider>`, `help`. `/login` does the same thing inside pig, so this is for the times there
is no terminal UI to be inside — a machine being set up over ssh, a container being built, or a
flow that has to be watched because it is not working.

Two things about it, and the first is the reason the logic is in `Cli\` rather than in the script:

- **The first version was inline in `bin/pig-ai`, and its `$onPrompt` was a `static function`
  with no `use ($ask)`** — so the moment Anthropic's flow asked for the pasted code, it called
  null. Nothing catches that except *reaching the prompt*, and a script that ends in `exit()`
  cannot be reached twice by a test. `Arguments` already says this about itself and the lesson did
  not travel; it has now. `SignInTest` reaches all four flows by escaping each at its first
  question.
- **It writes where pig reads.** Upstream's `AUTH_FILE` is the string `"auth.json"` — a relative
  path, so a login run inside a checkout leaves a file full of refresh tokens next to the code,
  and nothing reads it there afterwards either. Here it goes through `Auth`, into the one file
  `pig` and `pi` both read, with the mode that file gets. The usage text says which file, because
  that is the one thing somebody on a fresh machine needs to know.

Not added: a `bin` key in `composer.json`. `bin/pig` has never had one either — pig has never
shipped — and declaring executables is a packaging decision rather than part of this port.

### Tidying pi's directory, which is pig's problem

`Migrations` is upstream's `migrations.ts`, two one-time tidies run before `Auth::discover()`.
Neither is about pig's own history, and that is why the old reason for skipping them — "pig writes
pi's format and has never shipped another" — was answering the wrong question. **They migrate
pi's** old shapes, and pig lives in pi's house: it reads pi's sessions and shares pi's `auth.json`.
So a pi install left mid-upgrade has credentials and conversations pi can see and pig cannot.

- **`oauth.json` and `settings.json`'s `apiKeys` into `auth.json`.** Nothing happens at all if
  `auth.json` is already there, which is upstream's first line and the whole safety of it: a file
  that exists is the current shape. An OAuth credential beats an api key for the same provider,
  because signing in is what replaced pasting a key.
- **Session files pi 0.30.0 left at the root of its own directory** instead of under
  `sessions/<the project's path, flattened>/`. Where each belongs is read from the `cwd` on its own
  header line, and filed with `SessionManager::slug()` — the same slug, or this would move a file
  out of pi's sight without bringing it into pig's. Upstream's issue #320.

**pig writing in pi's directory is a real thing to be uneasy about**, so the bound is worth stating
rather than assuming. What happens here is exactly what pi itself would do on its next start, so
running it converges rather than diverges. Nothing is deleted: `oauth.json` is renamed, a session
is moved, a file that already exists at the destination wins. Every step is skipped on the first
sign of trouble, because a half-migration of somebody else's data is worse than none.

**Which is the argument for auditing every difference rather than only the ones that felt like
decisions** — "worse than none" is exactly what a near-miss produces. The audit found two real
gaps and one thing that had been written down as a deviation and is not one:

| Difference | Verdict |
|---|---|
| `glob('*.jsonl')` | **A gap.** A glob does not match a leading dot and upstream's `readdirSync` does, so `.something.jsonl` was a stray session this could not see. Now a scan. The test helper in `MigrationsTest` had the same bug a few lines later, which is how thoroughly the habit travels |
| the message | **A gap.** Upstream shows one warning with the providers joined; this printed one stderr line per provider in its own words. Now upstream's line, once. stderr because that is where pig puts warnings from before the UI exists, beside the settings and credential ones |
| pi's `settings.json` keeps its own permissions | **Not a deviation at all** — upstream's plain `writeFileSync` does the same, and this entry used to claim otherwise. It was a deviation from the *first draft here*, which passed `0600` and would have tightened a file that was never pig's to tighten |
| `auth.json` written with 4-space JSON and a trailing newline | Deliberate: upstream's is `JSON.stringify(x, null, 2)` with no newline, but **`auth.json` is a file pig itself writes**, and `Auth::save()` writes it this way. Matching upstream would make the migration produce a shape pig never produces, so the next ordinary save reformats the whole file |
| the session directory created `0700` | Deliberate: `SessionManager` and `Auth` create directories with `0700` and pig creates this exact one in ordinary use. Upstream's default umask would put one project's sessions under two modes |
| only the first line of a session file is read | Deliberate: upstream reads the whole file to split off line one, and a session file is a conversation long. The header is line one either way |

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

`setFallbackResolver` is here as `setCustomProviderKeys()`, narrowed: upstream takes a closure
and the one thing the closure ever did was look a provider up in a map, so pig takes the map.
The indirection bought a second place to look when a key does not resolve. It sits **after** the
stored answers and **before** `Stream::envApiKey()`, which is the only order that works —
`envApiKey()` has a fixed table of variable names and answers null for a provider it has never
heard of, which is every provider this is for.

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

### Somebody else's endpoint, declared in a file

`CustomModels` is upstream's `core/model-registry.ts` — the half of it that reads `models.json`.
The other half was already here under other names (`Ai\Models`, `Auth`, `ModelResolver`), which
is exactly how this one stayed invisible long enough to be the fourth thing the left-out table
got wrong.

`~/.pig/models.json`, or pi's `~/.pi/agent/models.json` when pig has none — a fallback rather
than a merge, because one file is what upstream has and somebody who already told pi about
their box should not have to say it twice. Neither is ever written to. `--no-save` reads
neither: that flag means the run touches nothing of the person's.

Every key is upstream's, so a file written for pi works here unchanged:

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "MY_BOX_KEY",
  "api": "openai-completions", "authHeader": true, "headers": { "X-Tenant": "kaka" },
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] } } }
```

Five things decided here:

- **`apiKey` names an environment variable before it is a key.** Upstream's
  `resolveApiKeyConfig`, and it earns its keep: this is the one config file in pig whose
  natural contents are a credential, and a file in a repository is a credential in a
  repository. `authHeader: true` resolves it before putting it in `Authorization: Bearer …`,
  because a proxy handed the literal string `MY_BOX_KEY` answers 401 and explains nothing.
- **A built-in always wins a collision.** The table and `find()` are both keyed `provider/id`,
  so `Models::register()` fills gaps rather than overwriting — a config file able to quietly
  redefine a shipped model is a bug report nobody could read. A provider with a name of its own
  collides with nothing, which is the normal case.
- **`Models::register()` is static, because `Models` is.** Upstream threads a `ModelRegistry`
  instance through everything; here `--model`, `/model`, restoring a session and `RpcMode` all
  ask `Models` by id, and a model only some of them could see is a model that works until you
  save the conversation. The price is `forgetRegistered()`, which every test that registers has
  to call.
- **Nothing throws and nothing is all-or-nothing.** A bad line loses only itself: the good
  models in the same file still load, the built-ins are untouched, pig still starts, and every
  problem names the file, the provider and the model. That is `Settings`' rule — a file that is
  not JSON is named, not ignored — applied to a file with far more ways to be wrong. There is no
  AJV and no TypeBox; the checks are written out, which is `Agent\ToolArguments`' trade again.
- **No `compat` block means null**, so `OpenAiCompat::detect()` still works it out from the URL.
  That is a better default than any set of flags: a local llama.cpp gets what it needs with
  nothing written. A block uses upstream's four key names (`supportsStore`,
  `supportsDeveloperRole`, `supportsReasoningEffort`, `maxTokensField`) so files stay portable,
  and pig's four extra flags are accepted too — a file using those is a pig file, which is the
  trade and it is stated.

`--models` moved to **after** this loads, which it had to: a listing that cannot show the model
somebody just declared is a listing they will not trust about the rest either. It costs a
settings read on a command that prints and exits.

### Checking a tool call against its schema

`Ai\Utils\JsonSchema` is upstream's `utils/validation.ts` — the AJV part, written out. `ToolArguments`
is still what `AgentLoop` calls and still owns the **message**, which is upstream's format down to
the blank line before the arguments; what moved out is the deciding.

**It replaced a two-rule checker, and the reason is the reason it was written that way.**
`ToolArguments` used to look for a missing required property and a wrong primitive type and pass
everything else through, because nothing was bundled that could read a schema. That was defensible
until there *was* something, and then it was two notions of what a tool's schema means — the shape
that goes wrong quietly, and the narrower one is the one nobody would have thought to look at.

The subset is `type` (one or several), `enum`, `const`, `required`, `properties`,
`additionalProperties`, `items` (single and tuple), the number bounds, `multipleOf`, the string
lengths, `pattern`, the item and property counts, `uniqueItems`, and `anyOf`/`oneOf`/`allOf`/`not`.
Left out with reasons in the docblock: `format` (an annotation in draft-07; `ajv-formats` makes it
an assertion and a tool has a better place to check an email), `$ref` (needs a resolver and a cycle
guard, and a tool's parameters are self-contained everywhere in either tree), and
`if`/`then`/`else`, `dependencies`, `patternProperties`, `propertyNames`, `contains` (nothing uses
them).

**An unknown keyword is ignored, never a failure.** That is the most important line in the file: a
schema using something unimplemented has to keep working, or adding a keyword to a tool breaks the
tool instead of tightening it.

Five things that took a real answer rather than a guess:

- **The messages are AJV's wording**, not invented ones — `must be integer`, `must have required
  property 'path'`. The reader is the **model**: it is about to correct itself from this sentence
  and it has seen AJV's phrasing everywhere else. One existing test changed wording because of it.
- **A missing property is reported at its own path.** Upstream prints `err.params.missingProperty`,
  the bare name, so a nested one cannot say which object it was missing from; here it reads
  `edits/0/path`.
- **`[]` is both an empty array and an empty object.** `json_decode(assoc: true)` gives a PHP array
  for both, so the two are told apart by `array_is_list()` — and the empty one is
  indistinguishable. It satisfies either, because there is no third answer and rejecting one would
  reject a valid document for being ambiguous with another valid one.
- **`multipleOf` took three tries.** `fmod(0.3, 0.1)` is `0.0999999999999999778` — close to *the
  divisor*, not to zero — so a one-sided tolerance on the remainder calls 0.3 not a multiple of
  0.1. It asks whether the quotient is whole, to a tolerance. AJV asks
  `Number.isInteger(value / multipleOf)` and therefore *does* reject 0.3, which is the standard's
  literal reading and is deliberately not copied: "0.3 must be multiple of 0.1" is a correction a
  model cannot act on.
- **`pattern` is delimited with `#`, and lengths are counted in characters.** A schema's pattern is
  an undelimited ECMA-262 regex, and `/` as the delimiter would silently change every pattern
  containing a path. `strlen` would make a four-character Chinese string twelve and reject what the
  schema allows.

A pattern that is not a valid PCRE is the schema's mistake and is ignored rather than reported
against the value, which would send the model looking where it did nothing wrong. Not ported:
upstream's `validateToolCall(tools, call)`, which is `validateToolArguments` plus a find-by-name —
it has no caller upstream either, because `AgentLoop` has the tool in hand by the time it
validates and needs it afterwards to run.

### Talking to a gateway instead of a provider

`Agent\StreamProxy` is upstream's `agent/proxy.ts`. **It is the second thing in this repository
called a proxy and it does the opposite of the first.** `Ai\Http\Proxy` carries pig's own encrypted
bytes to the provider and cannot read them; this one posts the conversation, the system prompt and
the tool schemas to a server that holds the provider keys and makes the call. For a team that does
not want keys on laptops that is exactly the point, and for anybody else it is a reason not to use
it — so nothing turns it on. It is a `streamFn`, passed in:

```php
$proxy = new StreamProxy('https://genai.example.com', $token);
$agent = new Agent(new AgentOptions(streamFn: $proxy->stream(...)));
```

The wire shape is upstream's, so a gateway written for pi serves pig unchanged: `POST
{proxyUrl}/api/stream`, `Authorization: Bearer …`, a body of `{model, context, options}`, and
events back with the `partial` field stripped to save bandwidth — the client rebuilds it.

**That rebuilding is the whole file.** `AssistantMessage` is readonly here, so there is no object to
mutate as upstream does; every event pig hands on carries a real snapshot from
`AssistantMessageBuilder`, which is the same accumulator the five providers use. Reaching into it is
a reach across an `@internal` line, and it is the faithful one: upstream imports
`pi-ai/dist/utils/json-parse.js` here with a comment saying it is an internal import, for the same
reason — a second answer to "what does a half-finished tool call look like" is one too many.

Six things that took a decision:

- **`MessageJson` moved to `pig/ai` for this.** The request body is the session file's message
  shape — upstream sends the same objects to both — and the encoder was in `coding-agent`, where
  `agent-core` cannot reach it. Copying it would have been a second hand-written notion of a
  message, which is the mistake `ToolArguments` had already made once about tool schemas; twice is a
  pattern. `SessionCodec` now keeps the three roles only pig has and delegates the rest, and went
  from 302 lines to 146.
- **The stream is read line by line, not with `SseParser`** — and not merely because upstream does
  it that way. Splitting on `\n` and treating each `data:` line as a whole event is an assumption,
  and a sound one: `JSON.stringify` never emits a newline, so an event is always exactly one line.
  Because a blank line does not start with `data:`, this reader also handles a properly framed
  stream, which makes it the **superset**; a strict SSE parser is not, because it waits for the
  blank line and joins consecutive `data:` lines into one event, so against a gateway sending events
  back to back it stalls or glues two together. The gateway's source is in neither repository, so
  which wire it sends cannot be checked, and only one of the two readers is right either way. Four
  tests hold it down: no blank lines, blank lines, CRLF, and an event split across two TCP reads.
- **Two details around that reader *are* bugs upstream, and the proof is that pi contains the same
  twelve lines twice.** `proxy.ts` tests `startsWith("data: ")` and slices 6; its own
  `google-gemini-cli.ts` tests `startsWith("data:")`, slices 5 and trims, and wraps `JSON.parse` in
  `try/catch { continue }`. The event-stream spec makes that space optional and strips exactly one,
  so the gemini one is correct and `proxy.ts` silently drops every event from a gateway that writes
  `data:{…}`. Putting upstream's rule back in pig fails one test in the ugliest possible way: the
  turn returns `stopReason: stop` with **zero content**, because the `done` line in that fixture
  happens to have a space and the content lines do not. pig takes the gemini spelling of both — one
  optional space, and a line that is not JSON is skipped rather than fatal, so a `: keep-alive`
  comment cannot kill a working stream.
- **These are the only two hand-rolled event-stream readers in pi at all.** Everywhere else the
  Anthropic, OpenAI and Google SDKs parse it, which is why upstream never wrote the parser pig had
  to write — and why `GoogleGeminiCli` here uses `SseParser` while this does not: Google's Code
  Assist frames properly and can be checked, a gateway cannot.
- **A stream that ends without `done` is a failure.** `stopReason` defaults to `stop`, so a gateway
  that died mid-sentence would otherwise be indistinguishable from a model that finished one. This
  is a divergence: upstream calls `stream.end()` and reports success.
- **An unknown `done` reason is a plain stop, and an unknown event type is ignored.** The turn did
  finish; refusing it over a word this pig does not know loses the work. Same rule as `JsonSchema`'s
  unknown keywords, applied to a wire protocol.
- **`model` goes over whole — `headers` and `compat` included, and `cost` not `pricing`.** The
  server reads the provider and api off it to decide who to call, and pig renamed `cost` to
  `pricing` internally while the wire keeps the name the server was written against. A custom
  provider's extra auth header therefore reaches the gateway; that is upstream's behaviour and it is
  written down rather than quietly trimmed, because a model whose headers pig withheld would fail at
  the gateway for a reason nobody could see.

The usage a gateway reports is trusted as sent, except the cost, which the builder recomputes from
the model's public price list — so a gateway reselling at its own rate is reported at list price.
Upstream has the same gap. Not ported: `validateToolCall(tools, call)`, which has no caller upstream
either, and `ProxyAssistantMessageEvent`, which is a TypeScript type whose counterpart is the event
classes in `pig/ai`.

### Starting up, and why it had to stop living in `bin/pig`

`CodingAgent::session()` is upstream's `createAgentSession()`. The rest of `sdk.ts`'s 673 lines has
no counterpart and needs none: about 35 are re-exports, which an autoloader does, and about 155 are
`discoverHooks()`, `discoverSkills()`, `loadSettings()` and four more like them — thin wrappers so
an SDK user need not import from `./internal/…`. pig's loaders were public from the start, so
`ContextFiles::load()`, `Skills::load()`, `SlashCommands::load()`, `HookLoader::load()`,
`CustomToolLoader::load()`, `Auth::discover()`, `CustomModels::discover()`, `Settings::load()` and
`SystemPrompt::build()` are already the things to call.

**The remaining ~235 lines were the ones that mattered, and until this they were `bin/pig`'s
middle.** `create()` above builds the `Agent`; nothing built everything around it. So the resolution
order — which of `--model`, `PIG_MODEL`, the settings and the built-in default wins, when a thinking
level is clamped, which file `--continue` picks, what a broken hook does — existed only as top-level
script statements, and *a script that ends in `exit()` cannot be called twice*. That is the same
sentence that made `Cli\Arguments`, `Cli\ModelList` and `Cli\SignIn` classes; this is the fourth
time, and the largest.

Three rules make it testable, and they are worth keeping when it grows:

- **Nothing writes to a stream.** Warnings come back as `StartedSession::$warnings`, already worded,
  in the order they were found; whoever asked prints them. There is a test whose whole assertion is
  that a startup with a broken hook *and* a malformed skill prints nothing at all.
- **Anything fatal throws `CodingAgentError`** with a sentence worth showing as it is. `bin/pig`
  catches it, prints it red and exits 1 — the same refusal reads the same to a terminal, a
  JSON-lines host and a test.
- **What needs a screen stays with the caller.** `--resume` with no value draws a list, so
  `bin/pig` resolves that to a path first; `session()` takes a path or nothing. The theme is the
  caller's too, and so are `Auth` and the custom models — because `--models` prints the registry and
  exits, and it has to see a model somebody just declared without a session file being created on
  the way past.

`bin/pig` went from 539 lines to 411.

`Hooks\` is upstream's `core/hooks/`. A hook is a PHP file in `~/.pig/hooks` or
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

**What is not here is upstream's `HookCommandContext`**, and this is the one place to look for it.
Upstream gives a slash command's handler four methods an event handler does not get —
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()` — walled off that way because
calling them from inside the agent loop deadlocks. pig has one `HookContext` for both, with
everything that can be read at any moment plus `abort()`. The four are not ported, and not as an
oversight: `branch()` is the session-file fork pig does not do, `navigateTree()` is `goTo()`, whose
answer is a summary, an abort or text for the prompt rather than a yes; and the other two would need
a second context class to be safe from the deadlock upstream avoids by having one. Two context types
so a hook command can restart the session is more machinery than the thing is worth — see the rule
at the top of this file. A hook that wants a handoff can say so with `sendMessage()` and let the
person press `/new`.

**`isError` on a `tool_result` result is honoured in one direction**, which is one more than
upstream: `true` raises, so a hook can call a successful tool's output a failure, and the verdict
still comes from the one place the loop reads it — `execute()` throwing. `false` on a tool that
*did* throw is not read, because rescuing it would be a second route to "this succeeded". Upstream
declares the field and reads neither direction, so setting it there does nothing.

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
`retry.maxRetries`, `retry.baseDelayMs`. **That sentence was false until the audit read it**: the
middle one was `retry.maxAttempts` here, so a `settings.json` written for pi had its `maxRetries`
ignored and got the built-in three. Its two siblings were upstream's, which is what gave it away —
one key out of a group of three not matching is a typo, not a decision. The method is still
`retryMaxAttempts()`, because attempts is what the number counts; the file speaks upstream's
dialect and the code speaks its own.

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

`Cli\ModelList` is `Fuzzy`'s second reader and upstream's `cli/list-models.ts`: `--models`
lists the registry, `--models gem pro` narrows it, fuzzily, over `"{provider} {id}"` as one
string — which is what makes naming the provider the way to narrow an id several of them
resell. Two things it does *not* do, both upstream's:

- **The rows come back sorted by provider and id, not by score.** Best-match-first is right for
  a picker somebody is arrowing through and wrong for a table somebody scans.
- **The match is a subsequence, so a search finds more than a substring would.** `haiku` also
  matches `moonshotai/kimi-k2-instruct`, because h·a·i·k·u are all in there in order. That is
  the algorithm, not a bug in the query, and upstream's listing behaves the same way.

Six columns rather than pig's old three, because the questions asked straight after finding an
id are how much it holds, how much it can say, and whether it can think. The human name went
with them: `Claude Sonnet 4.5` beside `claude-sonnet-4-5` is the same string twice, and the one
thing it said that the id did not — a model resold under somebody else's id — is what the
provider column already answers. Widths are measured from the values, so `google-generative-ai`
does not push every row after it out of line, and the last column is trimmed so no row ends in
spaces.

`--models` became a value-taking option to get there, which is the thing `Arguments`' docblock
warns about — an option that eats the next word. It is safe here for a reason worth writing
down rather than assuming: `--models` prints and exits, so there is no prompt left for it to
eat. `--resume` is the same shape and was the precedent: a value opens that one, nothing opens
the list.

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
- **There is no "one thing in the overlay at a time".** The terminal refuses a second question
  because it would steal the keyboard from the first, and refuses one arriving while a picker
  is open for the same reason; a host has no keyboard to steal and no screen to lose.
- **`custom()` returns null and `getEditorText()` returns `''`.** The first builds a
  `Pig\Tui\Component` and there is nothing to draw it on; the second reads an editor that
  belongs to the host. Upstream leaves both out of its RPC context for the same reasons.

Messages go out through `SessionCodec`, the encoder the session file already uses, so a host
reading `get_messages` and a `--continue` reading the file see the same shape. `RpcEvents`
is the one place the wire shape of an agent event is written down: upstream calls
`JSON.stringify(event)` and is done, because its events are plain objects, which also means a
field rename there changes the protocol silently.

`rpc-client.ts` **is** ported, as `Rpc\RpcClient`. `rpc-types.ts` is not, and the reason is worth
more than one line because "PHP has no named union type" is the weakest of the reasons and the one
that comes to mind first.

Its 203 lines are `RpcCommand` (24 object shapes discriminated on `type`), `RpcSessionState` (one
interface of ten fields), `RpcResponse` (24 more shapes plus an error arm), the two hook-UI unions,
and `RpcCommandType = RpcCommand["type"]`. In PHP that is about sixty readonly classes, a
`@phpstan-type` alias for each union — the trick `Context` already uses for the Message union — and
one string-backed enum.

**The hard reason is that all of it describes JSON that arrived from outside the process.** A static
type cannot check a byte that came over a pipe, and the host at the other end is deliberately not
PHP — that is what the mode is for. So the runtime checks stay either way: `RpcMode::text()` throws
`'message' is required and must be a string.` today, and a class per command would need that same
check to construct itself before `dispatch()` could switch on a class instead of a string. Same
checks, sixty more classes. Upstream gets value from the union because **its host is TypeScript too**,
so the union is a contract both ends share at compile time; pig's host shares nothing but the bytes.

Two things would be real, and neither is a port of this file:

- **`RpcCommandType` as a string enum** would be *better* than the TypeScript it came from, because a
  PHP enum exists at runtime: `tryFrom()` replaces a `match` arm that throws. What it buys is one
  list of command names instead of two — `RpcMode`'s `match` and `RpcClient`'s methods. What it does
  **not** buy is catching the mistake that actually happened: `RpcClient` sent `path` where the wire
  wants `sessionPath`, and that is a *field* name, which no command enum touches.
- **Typing `RpcClient`'s eleven `array<string, mixed>` returns** — `state()`, `bash()`,
  `sessionStats()`, `compact()` — would help a PHP host, at a real boundary, with types that can
  actually be guaranteed because the client is the one decoding.

Neither is done, and the reason is the standard the rest of this document is held to: **no defect has
been traced to either.** Every other item ported late in this port was justified by a reproduced
failure. The only caller of those eleven methods today is `RpcClientTest`; designing types for a PHP
host that does not exist yet is the work this project keeps declining to do. When there is one, its
needs decide the shapes.

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
| nine of the selector components, as files | every one of them is here as something else, and the audit that checked it is below: `hook-selector`, `hook-editor` and `hook-input` are `TerminalUi::select()`, `editor()` and `input()`; `queue-mode`, `show-images`, `thinking` and `settings-selector` are `/settings`' rows plus `thinkingSubmenu()`; `theme-selector` is that list's theme row; `oauth-selector` is `showSignIns()`; `session-selector` is `Cli\SessionPicker`; `model-selector` is `showModels()`. **`tree-selector.ts` is no longer among them** — it is `Interactive\TreeList` |
| `ai/utils/typebox-helpers.ts` (24) | `StringEnum`, a TypeBox helper that emits `{type:"string", enum:[…]}` because TypeBox's own `Type.Enum` emits `anyOf`/`const` and Google's API rejects that. In PHP a schema **is** an array, so there is nothing to help with — you write the array, and `JsonSchemaTest` says so where the enum is tested |
| `coding-agent/modes/rpc/rpc-types.ts` | 203 lines of types for JSON that arrives from outside the process, where a static type guarantees nothing and the runtime checks are the contract — the long version is in the RPC section, including the two things that *would* be worth typing and why neither is done yet. `RpcMode`'s docblock plus `RpcEvents` is where the wire shape is written down. Its `rpc-client.ts` **is** ported, as `Rpc\RpcClient` |
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

### The upstream dependencies that no package can replace: the provider SDKs

The five dependencies above were choices. **This one is not, and it is the largest single
difference between the two trees**, so it is written down with the facts it was decided on rather
than left to be rediscovered.

`pi-ai` does not implement a provider protocol at all. `@anthropic-ai/sdk`, `openai`,
`@google/genai` and `@mistralai/mistralai` are in its `dependencies`, and each one builds the
request, reads the event stream and hands back typed events. Upstream hand-writes an event-stream
reader in exactly **two** places in the whole repository — `google-gemini-cli.ts`, because Code
Assist has no SDK, and `agent/proxy.ts`. Everywhere else an SDK does it.

pig hand-writes all of it: 4,734 lines across `Providers/` and `Http/`, with 144 tests over them.
That is not a preference. As of the date on this section:

- **Anthropic has an official PHP SDK** — `anthropics/anthropic-sdk-php`, PHP `^8.1`, on `psr/http-client`
  via `php-http/discovery`.
- **OpenAI has none.** `openai-php/client`, the one everybody uses, describes itself as
  community-maintained.
- **Google has none.** The official GenAI SDK ships for Python, JavaScript/TypeScript, Go, Java and
  C#; PHP is not on the list, and the PHP Gemini clients are community projects.

So the SDK route covers **one provider out of five**, and the other four are hand-written either
way — which would leave the tree less consistent than it is now, not more.

**And that one has a conflict that matters more than the count.** The official PHP SDK streams by
iterating a PSR-18 response body synchronously, and its own documentation says what happens with a
client that does not stream: *"With a buffering client, the `foreach` loop yields every event at once
when the response completes instead of incrementally"*. Even with Guzzle, the loop blocks the one PHP
thread while it waits for the next event. pig's whole shape is one `stream_select()` waiting on the
model's socket **and** the keyboard at the same time — the first paragraph of the README. A blocking
read inside `AgentLoop` costs interrupting a running turn with Esc, typing while the model streams,
and the spinner moving at all.

There is a route that would work, and it is worth knowing about rather than reinventing later: PSR-18
is only an interface, so a PSR-18 client and a PSR-7 stream backed by `Pig\Async\Socket` would make
the SDK's `foreach` suspend its fiber instead of the process, and the loop would keep running
underneath. It was not taken, and the reason is the arithmetic above: five new packages
(`anthropic-sdk-php`, `psr/http-client`, `psr/http-factory`, `php-http/discovery`,
`standard-webhooks`) plus an adapter, to replace one of five providers and leave the other four as
they are.

Two things follow for anybody reading this later. The first is that `Http\SseParser`,
`Http\ChunkedDecoder` and `Http\HttpClient` are **load-bearing in a way they are not upstream**: a
bug in them shows up as tokens going missing rather than as an error, and there is no vendor
implementation to fall back to. The second is that this is a dated judgement, not a principle — if an
official PHP SDK appears for OpenAI and for Gemini, and the streaming question is answerable, the
arithmetic changes and this section should be re-run rather than quoted.

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

### A line starting with `/` is not a command, and neither is one that merely looks like a name

Reported from a real session, and the screen said the whole story:

```
Error: No command called /var/folders/mk/…/T/pig-clipboard-bf6615a630ccc5f2.png. Try /help.
```

Pasting a picture at the prompt writes it to a temp file and puts **the path** in the editor —
that is `Clipboard\ClipboardFile`, and it is deliberate, because a path works with every tool
that already takes one. So the line the person sends begins with `/`, and the submit handler
tested exactly that: `str_starts_with($text, '/')` → treat as a command. The path was reported
as an unknown command, and **the question typed after it was swallowed with it** — the model
never saw either half.

**The first fix was still a guess, and that was the real lesson.** It borrowed the
autocomplete's rule — a `/` at the start with no second `/` in the first word — which does tell
a path from a name, and kept pig's "No command called /setings" for the typo case. But "looks
like a name" is not "is a name": it is a second, looser notion of what a command is, living
beside the real one, which is the shape of the original bug rather than a fix for it.

So dispatch is **exact, against the names that exist** — `COMMANDS`, the hooks' registered
commands, and the ones kept as files, matched on the first word. `InteractiveMode::commandName()`
answers with a name or null, and null means the line is a message. Upstream's rule, which it
spells eighteen times (`text === "/settings"`); once here, because the hook and file names are
only known at runtime.

What follows, and is the price: **`/setings` goes to the model as text.** There is no error and
no "did you mean", because deciding a typo is a typo means guessing at what somebody meant, and
that is the thing being removed. `/nonsense` is a message too.

**The same rule was spelled by hand in three places**, and each hand-spelling was wrong in its
own way:

| Where | Question it is really asking | Was |
|---|---|---|
| the submit handler | is this line a command? | `str_starts_with($text, '/')` → read a path as one |
| `apply()` | should the completion insert `/name ` or a path? | right already — `couldBeACommandName()` |
| `shouldCompleteFiles()` | does Tab mean "complete a path"? | `starts with / and has no space` → **would not complete an absolute path typed at the start of a line**, while completing the same path fine one space to the right |

The third was found by asking the other two's question of it, and it is fixed the same way:
Tab belongs to the command list only while the cursor is still in the first word *and* that word
could still become a name. Past the first space Tab is a path again, which is what
`/export ~/out.html` needs.

**Two callers, two questions.** `couldBeACommandName()` is about *typing* and is private to the
autocomplete: a bare `/` counts, and so does a half-typed `/mod`, because that is what a
completion list is for. Dispatch is about *a finished line* and is exact. Sharing one predicate
between them is what made the first fix wrong, and there is a smaller version of the same trap
inside the autocomplete: requiring the name to be non-empty — correct for a submitted `/`, which
names nothing — silently broke command completion, because a bare `/` is the prefix every
command completion starts from, so picking `compact` off the list inserted `compact` with the
slash eaten. **A predicate two callers share is answering two questions, and the stricter caller
does not get to tighten it for the other.**

Regression tests: `InteractiveModeTest::testAPastedPathIsAMessageAndNotABadCommand`,
`testALineThatNamesNoCommandIsSentAsTheTextItIs`,
`testANearMissIsNotTreatedAsTheCommandItResembles`,
`testAFileCommandsNameIsMatchedExactlyLikeAnyOther`; and in `AutocompleteTest`, the eight
`testACommandNameIsToldFromAPathWhileTyping` rows,
`testTabCompletesAnAbsolutePathTypedAtTheStartOfTheLine`, and
`testCompletingFromABareSlashKeepsTheSlash` — the last being the one that fails if the
non-empty check goes back in.

### Whatever holds the focus must be in a container only its own opener clears

Found by being asked the right question about a field I had just deleted. `InteractiveMode` had
a `private ?SelectList $picker` written by four callers and read by none, and "written and never
read" looked like a complete answer: the object is kept alive by the container that draws it, so
the field could go. It was the wrong answer. **The field was a missing reader, not a redundant
write** — and what was missing was reachable in three keystrokes.

`$this->status` had three kinds of writer. The pickers and `/settings` put a focused component
in it. The progress loaders — `onStart()`, `onEnd()`, `onRetryStart()`, `onOverflow()` — clear it
and put a `Loader` in, and those run from **agent events, which arrive without anybody pressing a
key**. `TerminalUi` puts a hook's dialog there as a third. So:

```
/settings while a turn is running   → the screen is drawn, focus is on it
the turn ends, onEnd() clears       → the screen is gone
                                    → THE FOCUS IS STILL ON IT
next keystroke                      → goes to an invisible list
```

Reproduced before it was fixed, and the second half is the part worth remembering: after the
screen vanished, Down and Enter still changed a setting. Somebody typing what they thought was a
message into the editor was walking down an invisible list changing things, with nothing on
screen to say so. `/settings`, `/login` and `/logout` reach it directly — the three pickers that
refuse while streaming refuse for a different reason (they would change the conversation
underneath it) and are covered by accident.

The fix is a second container, `$overlay`, drawn between `$status` and the editor: what is
*happening* and what is being *asked* have different writers, and the writers do not know about
each other. Upstream never had this bug because its arrangement already separates them — a
selector replaces the **editor** (`editorContainer`) while progress lives elsewhere.

**Not a flag.** Restoring `$picker` as something the loaders check was the cheaper option and the
wrong one twice over: it makes every *other* writer responsible for remembering, which is the
arrangement that just failed, and the only behaviour it can offer is hiding the retry countdown
while a picker is open — a countdown that exists so eight silent seconds do not look like a hang.
Two containers means both are drawn, which is the honest answer, because both are true.

The rule, and it generalises past this screen: **the two halves of closing something — the
container letting go and the focus moving — have to happen in the same place.** Every clear of a
container that can hold the focus sits next to a `setFocus()`: `closePicker()` and
`TerminalUi::close()` are the only two, and that is the invariant.

Splitting the containers left one writer still able to collide, from the other side: a hook's
dialog and a picker both belong in the overlay, so a tool call arriving while `/settings` was
open cleared it. Milder — the dialog *takes* the focus and hands it back to the editor, so
nothing is invisible-and-focused — but the person's screen still vanished without a word.

`TerminalUi` already had the answer for its own half of this and it generalised: **a second
dialog is refused with its safe answer rather than stacked**, because taking focus from the
first would park that fiber forever. So the guard now asks two questions rather than one —
`$busy` for a dialog of its own (which covers the moment a `custom()` factory is still
building, when nothing is drawn yet and the overlay looks free), and `$overlay->children()`
for anything else holding the focus. A refusal answers `false` from `confirm()` and `null`
from the rest, which is exactly what escape answers, so it travels as an answer somebody
could have given and no turn is parked.

And it is **said**. `notify()` puts a line in the transcript naming why the question was not
asked, because a tool quietly denied is a tool that looks broken — the same rule as no silent
fallback, applied to a UI.

The overlay's contents are the state here, not a flag: `canAsk()` asks the container what is
in it. A flag would be a second copy of the same fact, and a second copy that can disagree is
the shape of the bug this whole entry is about.

Regression tests: `InteractiveModeTest::testATurnEndingDoesNotEraseAnOpenScreenFromUnderTheCursor`
(fails if `showSettings()` is pointed back at `$status`),
`testAWorkingLoaderAndAnOpenScreenBothFitOnTheScreen`, and
`HookUiTest::testADialogIsRefusedWhileSomethingElseHoldsTheOverlay`,
`testARefusedDialogSaysWhyRatherThanFailingQuietly`,
`testTheOverlayBeingFreeAgainLetsTheNextOneThrough` (all three fail with the overlay half of
`canAsk()` removed).

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

### One field in the session file was not pi's shape

Nineteenth, and the only one found in the file format — which matters more than its size, because a
conversation opening in either tool is the promise `PiFormatTest` exists to keep. Upstream writes a
branch summary's origin as `fromId: branchFromId ?? "root"` — **a string either way**. pig wrote
null when there was no leaf. A pi reading a pig file finds null where its own type says string, and
a pig reading a pi file gets `"root"` as though it were an entry id.

`SessionEntries` now writes `root` and reads it back as null, so pig's own meaning is unchanged and
the line on disk is pi's. The wire codec keeps null on purpose and says so: that is pig's own
protocol, where "there was no leaf" is an answer, and one translation in one place is the point.

**`PiFormatTest` had no branch-summary case at all** — the file's newest entry type was the one the
fence did not cover. It has two now, one for each direction.

### Hooks: what upstream walls off for commands is not here, and the docs said "all of it"

Twentieth, and a documentation find rather than a code one. The hooks section claimed `Hooks\` was
upstream's `core/hooks/` "all of it", and then said — correctly — that sixteen of eighteen events
are fired. What it never mentioned is `HookCommandContext`: upstream gives a slash command's handler
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()`, walled off from event handlers
because calling them inside the agent loop deadlocks. pig has one `HookContext` for both and none of
the four.

Left unported, deliberately and now in writing: `branch()` is the session-file fork pig does not do,
`navigateTree()` is `goTo()` — whose answer is a summary, an abort or text for the prompt rather than
a yes — and the other two would need a second context class to be safe from the deadlock upstream
avoids by having one. Two context types so that a hook command can restart the session is more
machinery than the thing is worth. A hook that wants a handoff can say so with `sendMessage()`.

Found in the same read: **`isError` on a `tool_result` result**. Upstream declares the field
("Override isError flag") and its wrapper reads neither direction, so a hook setting it there changes
nothing. pig honours `true` by raising — the verdict still comes from the one place the loop reads,
`execute()` throwing — and deliberately ignores `false` on a tool that did throw, because rescuing it
would be a second route to "this succeeded". The class's own docblock claimed `false` meant
"not an error after all", which was a promise the code never kept; it says what happens now.

### The model had seven tools where upstream gives it four, and no way to say otherwise

Eighteenth, and the one the developer decided rather than the code: `CodingAgent::session()` started
with `ToolSet::ALL`, so every run handed the model `read, bash, edit, write, grep, find, ls`.
Upstream's default is `codingTools` — the first four — and its `--tools read,grep,find,ls` names the
set when something else is wanted. **pig had the wider default and not the flag.**

Both are fixed the same way round: the default is upstream's four, and `--tools` is ported, with a
name that matches nothing answered on the shell before anything loads ("No tool called 'nope'. There
is read, bash, edit, write, grep, find, ls."). `--read-only` still *swaps* the set for the read-only
four rather than narrowing whatever was asked for, which is what it always did.

**The reason is the rule at the top of this file, in the developer's own words: more tools is more
confusion.** A model with seven spends part of every turn choosing between them, and searching
through `bash` with `rg` is what the system prompt already asks for — `grep`, `find` and `ls` stay
ported, tested and one flag away. The test that asserted the old behaviour was called
`testTheDefaultIsEveryTool`; it now asserts the four and is called
`testTheDefaultIsUpstreamsFourAndNotEveryToolPigHas`, with a sibling for each of `--tools` and
`--read-only` so the three answers cannot drift apart.

`--tools` had to join `Arguments::TAKES_A_VALUE`, which is the trap that list exists for — and it is
one letter from `--no-tools`, which is about the tools **somebody wrote** in `~/.pig/tools` and not
about this. `ArgumentsTest` states both, next to each other.

### The overflow pattern for Cerebras and Mistral could never match pig's own words

Seventeenth, and the narrowest kind of porting bug: a regex ported with its subject left behind.
`Overflow::NO_BODY` matches `"400 status code (no body)"` — the **OpenAI SDK's** phrasing, which is
what upstream reads. pig has no SDK: all five of its providers write
`"<who> returned <status>: <message or body>"`, so a 4xx with an empty body ends at the colon and
that pattern could not fire.

Cerebras and Mistral answer an oversized prompt with exactly that — a bare 400 and no body — so the
one case the pattern exists for was the one it missed. `Retry::worthRetrying()` then found a
retryable status and pig sent the same too-long request three more times with backoff before giving
up, where compaction was the answer.

`EMPTY_BODY` is pig's own wording, anchored at the end so a 400 that *did* explain itself is still
not a guess, and `NO_BODY` stays for messages that come from somewhere else. **The test that was
there asserted the SDK string**, which no provider here produces; the new one goes through a real
provider and a canned 400, so the pattern and the message it has to match cannot drift apart again.

Checked at the same time and found matching, so nobody has to check them twice: the other
`packages/ai` utilities. `Utf8::sanitize()` handles what upstream's `sanitizeSurrogates()` handles
and the CESU-8 surrogate case its docblock claims (verified, not assumed); `Overflow`'s table is
upstream's, pattern for pattern; `Credentials`, `Provider` and all four OAuth flows carry upstream's
endpoints, client ids and the same five-minute renewal margin; `SseParser` follows the spec on the
one optional space, multi-line `data:`, comments and CRLF; `PartialJson` is a hand-written stand-in
for the `partial-json` package and agrees with it on complete JSON, which is the only case that
reaches a tool. `Retry` differs from upstream deliberately and says so: it reads the status out of
pig's own message shape instead of matching bare numbers anywhere in the prose, and adds 408 and
529 — Anthropic's real "overloaded" — to upstream's list.

### Anthropic was the one provider of four that never cleaned the conversation up

Fifteenth, from the request-building side, and it is the same count as the others: upstream calls
`transformMessages()` in all four providers; pig called it in three. `Anthropic::messages()` walked
`$context->messages` raw.

Both things that transform does are **refusals** on this API, not degradations:

- A thought is signed by the model that had it, and the signature means nothing anywhere else.
  pig's own rule downgraded an *unsigned* thinking block to text, so a Gemini thought — which has a
  signature, just not one Anthropic can read — went out as a signed `thinking` block and Anthropic
  rejected the whole request. **`/model gemini` then `/model sonnet` broke the conversation** until
  it was compacted past.
- A tool call with no result is what an interrupted turn leaves behind, and Anthropic refuses a
  `tool_use` with no matching `tool_result`. So the next thing anybody typed failed.

One line, and both tests fail on the old code. Two differences from upstream in `TransformMessages`
itself, both noted while reading it: pig flushes pending calls at the **end** of the conversation as
well as before each assistant or user message — upstream only inserts synthetic results when a
later message arrives, so a conversation that *ends* on a dangling call is still unsendable there —
and pig treats any non-result message as closing the turn rather than listing the roles.

### The provider that talks to Copilot sent none of Copilot's headers

Sixteenth, and a sharp one: `github-copilot`'s models are **`openai-responses`** in the registry, and
the three headers Copilot needs — `X-Initiator`, `Openai-Intent`, `Copilot-Vision-Request` — existed
only in `OpenAiCompletions`. Upstream has the same block in both providers because Copilot serves
both APIs; pig had it in the one Copilot never uses. So every Copilot turn went out without them:
an agent follow-up after a tool result was billed and rate-limited as though a person had just typed
it, and **an image was refused outright**, which is the whole of `/paste`-a-screenshot on Copilot.

**Nothing covered those headers in either provider**, which is how the one that mattered ended up
empty. They live in `Providers\Copilot` now — one implementation, two call sites, a test on each
side — for the reason this audit keeps arriving at. The call goes *after* `$model->headers` in both,
which is upstream's order: a registry entry must not be able to turn off the headers that make the
request acceptable at all.

Testing them needs one trick worth knowing: with a non-empty key, `endpoint()` asks
`GithubCopilot::baseUrl()` where to go and the request leaves for the real Copilot API, so a canned
server never sees it. The tests pass an empty key, which is the branch that keeps the model's own
base URL.

Two deviations in the request bodies that are **deliberate** and now written down:

- **Gemini's public endpoint gets an explicit `thinkingBudget: 0`** when nothing asked for
  thinking, where upstream sends no `thinkingConfig` at all. Saying nothing to Gemini is not saying
  no — it thinks by default — so upstream's "off" is really "Gemini decides". pig says no. (The
  Code Assist endpoint is the opposite way round: it rejects a `thinkingConfig` on a model that
  cannot think, and reads its absence as none, so that one sends nothing.)
- **The `# Juice: 0 !important` hack for gpt-5** keys off `$model->id` where upstream reads
  `model.name`. Upstream's registry names are `GPT-5.2` and the like, so `startsWith("gpt-5")` on
  the name is false and their own workaround never fires; pig's check is the one their comment
  describes.

### Every Anthropic turn's input count was wiped out by its own last event

Eleventh find, from reading the four providers' stream events. `message_delta` is the event that
carries the stop reason and the final usage, and both pig and upstream read its `usage` with a
`?? 0` / `|| 0`. Anthropic's `MessageDeltaUsage` types `input_tokens`, `cache_read_input_tokens` and
`cache_creation_input_tokens` as **`number | null`** — "may not be reported" — and the streaming
documentation's own example carries nothing but `output_tokens`. So the final event replaced the
counts `message_start` had already given with zeros.

What that costs: the input cost of every Anthropic turn disappears from `/stats` and the footer,
and `Compaction::contextTokens()` — which believes `totalTokens` — sees a conversation the size of
its last answer. **Auto-compaction never fires on Anthropic**; the turn recovers only when the API
rejects the request as too long, which is late, and by accident.

`Anthropic::update()` now merges: a field the delta reports updates, a field it does not leaves the
earlier number standing. That is right under either API behaviour, and the nullable type is the
argument for it — `null` means "no update", not "zero". It is a deliberate divergence from upstream's
literal `|| 0`, on the same ground as every other "no silent fallback" rule in this file.

**The fixture repeated the input in `message_delta`**, which real Anthropic does not, so the suite
agreed with the code. It now carries the documented shape, and two cases state the rule from both
sides: a delta with `input_tokens: null` keeps 1000, and a delta that does report 1200 is believed.

### OpenAI's final tool-call arguments were read from the deltas only

Twelfth. On the responses API a function call's arguments arrive as `function_call_arguments.delta`
events **and** whole, as `arguments` on the `response.output_item.done` item. Upstream builds the
finished `ToolCall` from that item (`JSON.parse(item.arguments)`); pig used only what the deltas had
accumulated and never looked at the field. Usually the same JSON, so usually the same call — but a
stream that sends no argument deltas, which this API allows and a compatible endpoint may do (Copilot
speaks this API and implements it itself), left the call with **no arguments at all**.

`AssistantMessageBuilder::setJson()` replaces rather than appends, for the same reason
`setSignature()` exists: the usual case is the same JSON arriving twice, and appending would give
`{"a":1}{"a":1}`.

### A Gemini tool call's thought signature was collected and then dropped

Thirteenth, and the clearest "wired at one end only" of the whole audit. Four places handle a tool
call's `thoughtSignature`: `GoogleShared` reads it off the part, `setSignature()` stores it,
`GoogleShared::messages()` writes it back into the request, and `TransformMessages` carries it
across. The fifth — `AssistantMessageBuilder::toContent()`, the one place that *builds* the
`ToolCall` — constructed it with three arguments out of four, so `ToolCall::thoughtSignature` was
always null for a call that came from a stream.

So Gemini never got its own thought context back with the call it came out of, which is what the
field is for and what Gemini 3 expects. The write half could not be tested before this, because
nothing could produce a call that had one; it has a test now, and so does the read half.

**Two smaller things in the same read.** A Gemini `Part` is a one-of by convention rather than by
schema, and upstream reads `text` and then `functionCall` from the same part; pig checked for the
call first and returned, dropping any text that shared the part. And pig keeps the last non-null
`thoughtSignature` on a thinking block where upstream assigns `part.thoughtSignature` every time,
including `undefined` — which clears a signature an earlier part had set. pig's is kept.

### OpenRouter's encrypted reasoning was neither read nor sent

Fourteenth, and both halves were missing, which is why nothing looked wrong. A reasoning model
reached through OpenRouter returns its chain of thought as `reasoning_details` — a list of
`reasoning.encrypted` objects, each naming a tool call by id — rather than as text in one of the
three `reasoning*` fields pig already read. Upstream files each one against the matching call's
`thoughtSignature` and writes them back beside `tool_calls` on the next request. pig did neither, so
**multi-step tool use through OpenRouter lost the model's reasoning between every turn.**

Both halves are ported, with the whole detail kept rather than just its `data`, because the whole
object is what goes back. Anything that will not decode is left out rather than sent as a string:
the field is a list of objects and is rejected otherwise.

Two differences in the completions provider that are **kept** rather than aligned, now that they are
written down: a tool call whose id arrives *after* its first delta stays one call here and becomes
two upstream (`toolCall.id && currentBlock.id !== toolCall.id` splits on the id appearing, where pig
also requires the open call to have had one); and pig pushes no `toolcall_delta` for a chunk that
carries an id and no arguments, where upstream pushes one with an empty delta.

### Twelve models were never told how hard to think

Ninth find, from auditing the providers — the part of pig that is hand-written where upstream has
an SDK, so the expectation was mistranslation and what turned up was a missing `match` arm.

`Stream::translate()` (upstream's `mapOptionsForApi()`) had four arms for five APIs. There was no
`Api::GoogleGeminiCli`, and the `default` handed back plain `StreamOptions` — so every model on the
Code Assist API got no thinking configuration at all: the five `google-gemini-cli` models **and the
seven `google-antigravity` ones**, which speak the same API. `--thinking high` on any of them asked
for nothing and was answered by a model that did not think, without a word anywhere. The `off` case
came out right by accident, because Code Assist reads a missing `thinkingConfig` as none.

**The `default` arm is the actual finding.** `Stream::start()`, ten lines below, matches on the same
enum with no `default` at all — so a new API would fail there loudly and silently get provider
defaults here. Upstream's own default is an exhaustiveness check that throws. `translate()` now has
one arm per API and no `default`, which is the same shape as its sibling and the reason the gap can
not recur silently.

Two rules were stated as being about the protocol when upstream has them about the model, and both
are now the model's:

- **xhigh** was clamped for every `openai-completions` model on the grounds that "xhigh is OpenAI's
  alone". True of the registry, false of a `models.json` proxy reselling `gpt-5.2` over
  chat-completions — which is the common shape where the direct API is unreachable. Upstream asks
  `supportsXhigh(model)` in both OpenAI arms; so does pig now.
- **Gemini 3** was `str_contains($id, 'gemini-3')` in the public arm and upstream's two checks
  (`3-pro`, `3-flash`) in the one being added. Two arms of one file disagreeing about which models
  are Gemini 3 is the shape of every other find here, so both now ask upstream's question.

The Code Assist arm is deliberately *not* the public Google arm: upstream gives it one flat budget
table for all 2.x models where the public arm has per-model ceilings (`2.5-pro` starts at 128 there
and 1024 here). The Gemini 3 level path is shared, because that part is the same.

**`StreamTest` covered one API of the four.** The tests it had were good ones — every reasoning
level against Anthropic's budget table — and the arm that was missing entirely belonged to a
provider nothing in that file mentioned. A data provider over one arm reads like coverage of the
method.

### The token total was recomputed for providers that report one

Tenth find, and the numbers it moves are the ones compaction decides on.
`AssistantMessageBuilder::setUsage()` called `withTotalTokens()` for every provider — Anthropic's
rule (it reports the components and no total) applied to the three that do report one. For Anthropic
and OpenAI's completions the sum is the same figure either way, so it looked harmless.

**For Google it is not.** Gemini's `promptTokenCount` *includes* the cached tokens, so
prompt + output + cacheRead counts them twice, and `totalTokenCount` — the figure Google sends,
which does not — was thrown away. `Compaction::contextTokens()` prefers `totalTokens`, so a
conversation with most of its prompt cached reported a context far fuller than it was and compacted
early: a summarisation paid for, and a shorter window than the model actually had. Upstream computes
the total in exactly the two providers whose API gives none and reads it in the two that do; that is
now the one line in `setUsage()`.

**The test fixture was written to agree with the bug.** `GoogleTest` asserted `totalTokens === 170`
against a `usageMetadata` whose `totalTokenCount` *was* 170 — 100 prompt + 10 candidates + 40
thoughts + 20 cached, which is not arithmetic Google does. Gemini would have sent 150. A fixture
invented to match the code under test is the same failure as a claim in this file that nobody
diffed.

Not changed, because it is upstream's too: the **cost** still charges `promptTokenCount` at the
input rate *and* `cachedContentTokenCount` at the cache rate, so the cached part is paid for twice
in the displayed cost. Upstream's `calculateCost` does exactly this with exactly these fields. It is
a divergence from reality rather than from upstream, and it belongs in one place — here — until
upstream moves.

### A hook's message sent mid-turn was queued where nothing reads it

Sixth find, from auditing `agent-session.ts`' queues. `sendHookMessage()` while the agent is
working did this:

```php
$this->followUps[] = $message->toText();   // and nothing else
```

`$this->followUps` is **this session's own record of what a person typed**, kept so the footer can
count it and `clearQueue()` can hand it back to the editor. The agent has its own queues and reads
neither of pig's lists. So a hook that noticed something during a turn — the case the method exists
for — had its message counted as pending for ever, never delivered to the model, and handed to the
person as their own text the next time they pressed escape. Upstream's line is
`await this.agent.queueMessage(appMessage)`: the message goes to the agent and **not** into the
queued-text list, which is the pair of decisions pig had exactly inverted.

`$this->agent->followUp($message)` is the fix — a follow-up rather than steering, because steering
interrupts the tools queued behind the current one and a hook's note is not a change of mind. It
stays out of `$this->followUps` for the reason upstream keeps it out: handing somebody a hook's
sentence to re-send is not putting their text back.

`HookMessagesTest::testAMessageSentWhileTheAgentIsWorkingStillArrives` sends one from inside the
first turn — the harness gained a `duringTurn` closure for it, since that is the only moment
`isStreaming()` is true and a test still has control. **Nothing covered this branch at all**, which
is why three lines of bookkeeping could stand in for the delivery.

### Ctrl+P was taken off the editor and bound to nothing

Seventh find, and the cheapest kind to have: `CustomEditor::claimed()` has always answered
`'ctrl+p'` and `'shift+ctrl+p'` — taking both keys away from the text field — and
`InteractiveMode::bindKeys()` never registered a handler for either. Upstream binds them to
`cycleModel("forward")` and `cycleModel("backward")`, which pig never ported. So the keys did
nothing, and a key that is claimed and then does nothing is worse than either half.

Ported as `ModelResolver::next()` — a rotation over a list, which is all that was left once
`setModel()` gained the key check, the file write and the settings write. It lives beside `parse()`
rather than on `AgentSession` because two callers need the same arithmetic: ctrl+p, and RPC's
`cycle_model`. **That command is now here too**, and the docblock that said it was not needed
("`get_available_models` and `set_model` are what it is made of") had to go: it was true for a host
until pig needed the same rotation itself, and then leaving it out would have meant two
implementations of one list walk — this audit's subject exactly.

One quirk kept on purpose: a current model that is not in the list counts as being at position 0,
so ctrl+p from there lands on the *second* model. That is upstream's `indexOf` returning -1, and
`--model` pinned to a provider whose key has since gone is how somebody gets there.

### The terminal remembered the model for next time and a host did not

Same shape as the four before it, on two fields at once. Upstream's `AgentSession.setModel()` and
`setThinkingLevel()` each write the choice to the settings manager; pig's wrote only the session
file, and `InteractiveMode` added the settings line in three places of its own —
`useModel()`, `cycleThinking()`, `useThinkingLevel()`. RPC's `set_model`, `set_thinking_level` and
`cycle_thinking_level` had no such line, so a host's choice was forgotten by the next run while the
same choice made in the terminal was remembered.

Both writes are in `AgentSession` now, which is where `setQueueMode()` already put its own — *"this
is the class that holds both the agent and the settings"* — and the three lines in the terminal are
gone. `RpcModeTest` asserts the two new cases; the harness had to start passing its `Settings` into
the `AgentSession` it builds, as `CodingAgent::session()` does, because it had been handing it only
to `RpcMode` and no test had needed otherwise.

### Going back to a question kept the question

Eighth find, and the biggest behavioural one in `agent-session.ts`. Upstream's `navigateTree()`
resolves the chosen point before moving: **a message somebody said resolves to the point before
it**, so the leaf lands on its parent, the message leaves the conversation, and its text is handed
back as `editorText` for the prompt. pig moved the leaf *onto* the message. The difference is the
whole "go back and ask it differently" flow: in pig the old wording stayed in the conversation and
the only way to re-ask was to type it again.

`TreeJump` gained `editorText`, `SessionManager::entry()` exists so the parent and the message can
be read as one fact about one entry, and both modes use it — the terminal fills the prompt, RPC
returns it. A hook's message is treated the same way, as upstream treats it: nothing is *sent* from
there, so offering the text for editing is not re-sending a hook's sentence as the person's, and
dropping the message while keeping nothing of it would lose it for no reason.

Three smaller parity gaps came out of the same read:

- **Going to where you already are** ran the whole recipe — hook, summary, replay. Upstream's first
  guard returns early, so `TreeJump` gained a fourth answer: `moved: false` with `aborted: false`,
  which the terminal must not report as "branch summary cancelled". A fourth state in a result
  object is cheaper than a caller guessing.
- **`summarise: true` with no model** moved without a summary. Upstream throws, and so does pig now:
  somebody who asked for the branch to be written down and got the move without it has lost the
  branch. Unreachable from the terminal (`goBackTo()` only offers the summary when there is a
  model), reachable over RPC.
- **A hook's summary was taken even when nobody asked for one.** The hook is *told* whether anyone
  wants a summary — it is the last argument of `SessionBeforeTreeEvent` — so prose returned anyway
  has misread the event, and upstream ignores it.

### A model with no API key could be switched to, listed, and resumed onto

Fifth find, and the pattern from the fourth one again with a different word in the middle: upstream
asks its model registry one question — *is there a key for this?* — in five places, and pig asked it
in none of them. Everything still worked, because `Stream` throws `No API key for provider: x` when
the turn goes out. That is the wrong place for it:

- **`AgentSession::setModel()`** switched to a model this machine cannot talk to, wrote a
  `model_change` into the session file recording the conversation as being on it, and failed on the
  *next* turn from inside `Stream` — where it reads as the provider's fault. Upstream's first two
  lines are the key check and the throw; pig's are now the same, before anything is applied.
- **`restoreSettings()`** restored such a model from the file, so `--continue` on an afternoon spent
  under a borrowed `--api-key` reopened onto a model whose every turn fails. Upstream's
  `restoreModelFromSession()` checks the key as well as the model and falls back on either.
- **Three listings** — `--models`, `/model`, and RPC's `get_available_models` — showed every model
  pig knows of. Upstream's word for all three is `getAvailable()`, and it means *there is a key*: a
  host drawing a menu from that list offered twenty models with nineteen of them broken, and
  `/model gemini` with no Google key switched to Gemini instead of saying there is no such model
  here.

`Auth::hasKeyFor()` and `Auth::availableModels()` are the one home for the question — `Auth` is
what pig has instead of upstream's `ModelRegistry`, and three lists filtered three ways is the
shape this whole audit keeps finding. `hasKeyFor()` deliberately does **not** ask `apiKey()`:
that renews an expiring OAuth token on the way past, which is right for a turn and wrong for a
list — drawing `/model` would refresh every signed-in provider, and one unreachable network
would empty a listing of twenty usable models. So an expired stored token counts as a key here,
where upstream (which does refresh) would drop the provider until the refresh succeeded. The
one-per-provider question is asked once, not once per model: twenty-one models share five
providers and the answer cannot change mid-list.

`setModel()` throwing is why `InteractiveMode::useModel()` now catches: the lists are filtered, so
what is left is a `models.json` provider whose key variable is empty and a sign-out in another
window between drawing the picker and choosing from it.

**Three tests were passing because the checks were missing**, which is the fourth time this audit
has found that: `CodingAgentSessionTest`'s resumed-thinking-level case restored a model it had no
key for, and two `RpcClientTest` cases switched to `anthropic/claude-3-5-haiku-latest` and expected
`claude-sonnet-4-5` in the model list from a home with no Anthropic key at all. And the
environment they run in was part of the problem: this container exports `GITHUB_TOKEN`, which is a
key for github-copilot and therefore nineteen models, so "no keys anywhere" quietly meant
"nineteen models". `Pig\Test\WithoutProviderKeys` takes all thirteen provider variables out for the
length of a test and puts them back — because `putenv()` is process-wide and `RpcClientTest`
spawns `bin/pig` with this process's environment on top of its own.

### RPC's session switch and new-session skipped the three checks the terminal's have

Fourth find, and the clearest instance of the pattern the others taught: **the same operation in two
modes, with the checks on only one of them.**

`InteractiveMode` asks the hooks before leaving a conversation (`mayLeave()` →
`HookRunner::emitBeforeSwitch()`), and `/resume` is unreachable while a turn is streaming because
`showSessions()` guards on `isStreaming()`. `RpcMode::switchSession()` did none of it:

- **The cancellable `session_before_switch` hook was never fired.** A hook that refuses to leave a
  conversation worked in the terminal and was ignored over RPC. `emitBeforeSwitch()` and
  `SessionBeforeSwitchEvent` both existed — one of two callers used them.
- **A turn in flight was not aborted.** Upstream's `switchSession()` awaits `abort()` first. Without
  it the turn belonging to the conversation being left carries on writing into the one just opened.
  The terminal is safe from this by accident, not by rule: the command cannot be reached mid-turn.
- **The queue was not emptied.** Upstream sets `_queuedMessages = []`. What was queued was typed into
  the conversation being left, and sending it into the next one is the same crossing as appending to
  the wrong file — which is the bug `writeTo()`'s docblock was written for.

All three are now done in upstream's order, and the answer gained `cancelled`, which is what
upstream's client reads from the same place.

**`new_session` had all three too, and that is the payoff of the pattern rather than a second
coincidence.** Having found the shape once, its sibling was the next thing to read: `/new` in the
terminal goes through `mayLeave('new')`, upstream's `newSession()` fires the same cancellable hook
with reason `'new'`, awaits `abort()` and empties the queue before `reset()`. Over RPC the agent was
reset out from under a turn in flight, and what had been queued for the conversation being thrown
away was still queued for its replacement. Both commands now answer with `cancelled`.

The tests go through `bin/pig --mode rpc` with a real hook file in a temporary home — the first use
of `RpcClient` for something other than testing itself, and the reason a client was worth porting:
**a mode nothing drives the way a host drives it is a mode whose checks nobody misses.**

**Then the checks moved to where the operation lives, which is the actual fix.** Adding the three
lines to `RpcMode` made the two modes agree today; it left the next mode — the SDK, a web UI — free
to disagree, because nothing about `writeTo()` and `agent->reset()` says that a hook has to be asked
first. `AgentSession::startNew()` and `AgentSession::switchTo()` now hold the whole recipe (the
cancellable hook, the abort, the emptied queue, the new or opened file, `restore()` +
`restoreSettings()`, the `session_switch` emit), the way `goTo()` already held its own guards inside.
Both modes call them and keep only what is theirs: the screen, and `customTools->notify('switch', …)`
— `AgentSession` has no custom tools, and upstream's equivalent call from inside is the one piece not
followed. `InteractiveMode::mayLeave()` is gone with them; a hook refusal comes back as
`SessionSwitch(switched: false)` — a returned answer, like `TreeJump`, because a hook saying no is not
a failure, while a path that is not a session still throws.

One improvement on upstream's order while moving it: the new file is created, and the file being
switched to is opened, **before** the turn in flight is aborted and the queue emptied. Upstream
aborts first, so a session directory that cannot be written leaves the conversation aborted, emptied,
and still writing to the file it was about to leave. Now the throw costs nothing —
`testAPathThatIsNotASessionCostsTheConversationNothing` asserts the file, the messages and the queue
are all still there, and it fails if the two lines are put back in upstream's order.

**And the terminal gained the check it was missing in return.** `/new` never emptied the queue: text
typed during a turn and then thrown away with the conversation was still waiting to be sent in its
replacement. Reading two callers finds which one is wrong; giving them one implementation is what
stops the question coming back.

### One settings key out of seventeen was not upstream's

Third find from the audit, and the cheapest one to have prevented: **`retry.maxAttempts` where
upstream writes `retry.maxRetries`.** A `settings.json` written for pi had that number silently
ignored and got the built-in three.

What gave it away was not reading the key — it was reading its **siblings**. `retry.enabled` and
`retry.baseDelayMs` are both upstream's spellings, and `baseDelayMs` even carries a docblock saying
so ("as upstream writes it"). One key out of a group of three not matching is a typo, not a decision.
Sixteen of pig's seventeen settings keys were upstream's; this was the seventeenth.

It had a second layer: **CLAUDE.md asserted the thing that was false.** "Settings are upstream's keys:
`retry.enabled`, `retry.maxAttempts`, `retry.baseDelayMs`" — a sentence that would have stopped
anybody from checking. A claim about matching upstream is worth a diff, not a sentence.

And a third: **a test was passing because of the bug.** `AgentSessionTest` set `maxAttempts` in a
fixture and asserted two attempts; with the key corrected, the fixture stopped applying and the
assertion failed at three. The test had been documenting the wrong key rather than the behaviour.
That is worth remembering for the rest of the audit — a green suite proves the code and the tests
agree, and both were written by the same hand on the same day.

The method is still `retryMaxAttempts()`: attempts is what the number counts, and upstream's own name
is the odd one (it counts attempts after the first). The file speaks upstream's dialect; the code
speaks its own.

### A hook's compaction summary was indistinguishable from pig's own

Second find from the same audit. pi's `CompactionEntry` carries `fromHook?: boolean` — *"True if
generated by a hook, undefined/false if pi-generated"* — and `CompactionSummary` here did not have
it. `BranchSummary` did, which is what made the omission visible: `Compaction::files()` had the rule
on one arm and not the other.

```php
if ($message instanceof CompactionSummary) { …carry its file lists forward… }
if ($message instanceof BranchSummary && !$message->fromHook) { …carry them forward… }
```

Two things followed from the missing field. `Compaction::files()` carried a **hook's** read and
modified lists forward as though pig had found them, which upstream refuses
(`if (!prevCompaction.fromHook && …)`); and **pi reading the same file could not tell who wrote the
summary**, which matters more, because "a session file from either can be read by the other" is a
stated goal and a field pi defines was simply absent from what pig wrote.

The field is written by `SessionEntries` and `SessionCodec`, read back as false when absent — pi's own
rule for it, and what a file written before this existed actually means.

**The pattern worth taking from this one:** the same rule existing on one of two sibling arms is a
stronger signal than either arm on its own. The author knew the rule when writing `BranchSummary`;
the compaction arm could not follow it because the field was not there, and nothing failed.

**And it turned out to be the whole audit.** Most finds are one shape — *a rule present in one
place and absent in its sibling* — and none of them is a mistranslation of upstream: a `match` arm
missing a type (`BranchSummary`, `ImageContent`), a field one arm checks and the other has not got
(`fromHook`), one settings key out of three (`retry.maxRetries`), two session-leaving recipes with
different ideas about them (`RpcMode` vs `InteractiveMode`), one question — *is there a key for this
model?* — that upstream asks in five places and pig asked in none, and a settings write the terminal
did three times and RPC never. So the first tool to reach for is not "read this file against
upstream" but **"who else does this, and do they agree?"** — and the fix that sticks is one
implementation rather than the missing lines added twice, which is why `startNew()`/`switchTo()`,
`Auth::availableModels()`, `ModelResolver::next()` and the settings writes inside `setModel()` all
exist.

**The second shape, from the same sweep: a thing wired up at one end only.** A hook message pushed
onto a list the agent never reads; ctrl+p taken off the editor and bound to nothing; `TreeJump`
carrying no editor text because `goTo()` never worked out that a question is something you go back
to *before*. None of the three failed a test, because each was internally consistent — the wiring
was missing, not wrong. What finds them is reading the *other* end: who consumes this list, who
handles this key, what does the caller do with what it gets back.

### Compaction counted an image as nothing, so a conversation of screenshots could not be compacted

The first find from auditing an already-ported file difference by difference, rather than from
anybody hitting it. `Compaction::estimateTokens()` had two holes, both under-counting, and
under-counting is the dangerous direction: the walk back through the conversation never reaches its
budget, so the cut point stays at the beginning and **compaction runs, pays for a summarisation call
and frees nothing**.

```
BranchSummary    (3900 chars) estimates as 0 tokens
CompactionSummary(3900 chars) estimates as 985 tokens
a tool result with one image estimates as 3 tokens (upstream: 1203)
cutPoint over 12 turns of images, keepRecent=2000: 0 of 24 messages dropped
```

After:

```
BranchSummary    (3900 chars) estimates as 1001 tokens
a tool result with one image estimates as 1203 tokens
cutPoint over 12 turns of images, keepRecent=2000: 22 of 24 messages dropped
```

**`BranchSummary` was simply missing from the `match`.** Every other message type was there,
including `CompactionSummary` right beside it, and the `default => 0` arm meant the omission was
silent — which is the cost of a `match` over types with a default: adding a type is not a compile
error, it is a zero.

**The image is the more interesting one, because upstream contradicts itself.** Its `toolResult` arm
adds 4800 characters per image; its `user` arm counts only text and adds nothing. So the same
screenshot is worth 1200 tokens if a tool returned it and nothing if somebody pasted it. pig counts
4800 in both, which is the second time this port has taken the right one of upstream's two answers
to the same question — the first was the `data:` prefix in `StreamProxy`. (Upstream's comment there
says "4000 chars, or 1200 tokens" while the code says 4800; 4800 is what divides into 1200, so the
code is the half that was meant.)

Worth keeping in mind for the rest of the audit: **no test failed when either hole was open**, and
the existing `testEveryKindOfMessageHasASize` walked five message types and asserted `> 0` on each —
it just never listed the sixth. A test that enumerates cases by hand goes stale exactly where a new
case was added.

### An abandoned branch was in the file and unreachable

`SessionManager::goTo()`'s docblock has always said it:

> Nothing is deleted and nothing is rewritten. The entries after this one are still in the file with
> their parents intact, so the branch that was abandoned **can be gone back to in exactly the same
> way**.

The first half was true and the second was not reachable. `/tree` listed `branch()`, which is the
path being talked on, and `SessionManager` had no public walker for anything else — so going back to
an abandoned branch needed an id that nothing would show. Reproduced before the fix:

```
before going back, branch() shows: A, re: A, B — about to be abandoned, re: B
after going back,  branch() shows: A, re: A, C — a different direction, re: C
entries mentioning the abandoned branch, still in the file: 2
```

Found by auditing the unported-components row rather than by anybody hitting it, which is the
interesting part: the row said those files were "mostly UI for unported subsystems", and for nine of
them that was right. For this one the subsystem was ported — the tree is in the file, in pi's own
format — and only the way in was missing. **A row that explains away a whole group is worth checking
one member at a time**; this table has now been wrong five times.

The fix is `SessionManager::tree()` plus `Interactive\TreeList`, upstream's `tree-selector.ts`. Two
things in the walker are worth keeping straight:

- **Children come out in file order.** Not sorted by id — an id is eight characters off a UUID, so
  sorting by it scrambles a fork's arms, and the first version did exactly that until the test
  asserting which arm comes first caught it. Not by timestamp either: that is the *message's*, and
  the entries of one turn share it to the millisecond. `$this->entries` is insertion-ordered, which
  is the file's order, so walking it once is the whole sort.
- **An orphan is a root, not a dropped entry.** A file written by something newer can hold an entry
  whose parent is not there, and losing part of somebody's conversation to keep a tidy tree is the
  wrong way round. Upstream guards the same case.

### `--mode rpc` could not be started, by either route

Found by porting `rpc-client.ts` and pointing it at the real binary. Two guards in `bin/pig`, each
right on its own, closed the door between them:

```php
if ($mode === 'rpc' && ($fileArgs !== [] || $messages !== [])) { … exit(1); }   // a message makes no sense
if (!$interactive && $messages === [] && $fileArgs === []) { … exit(1); }        // "Nothing to say"
```

`bin/pig --mode rpc "hello"` was refused for having a message, and `bin/pig --mode rpc` was refused
for having none. **So rpc mode — one of the three documented ways in, with its own class, its own
twenty-two commands, its own test file and a section in this document — had never been reachable
from the command line.** `RpcModeTest` did not notice because it constructs an `RpcMode` in process
and hands it two streams; nothing started the binary.

The fix is `Arguments::needsAMessage()`, which is false for the terminal *and* for rpc, tested
directly. The condition could have stayed inline in `bin/pig` as `$mode !== 'rpc' && !$interactive`,
and then the next person to touch it would have had nothing to run either.

The general rule: **a mode that nothing starts the way a person starts it is a mode that can be
broken by a line somewhere else entirely.** `RpcClientTest` is now the one test in the suite that
spawns `bin/pig`, and that is the point of it — it also caught `RpcClient` sending `path` where the
wire wants `sessionPath`, which no amount of reading would have.

### A new session recorded no thinking level, so `--continue` came back on `off`

Found by extracting `bin/pig`'s startup into `CodingAgent::session()` and then writing the first test
for it. Upstream ends `createAgentSession()` with two lines pig had never ported:

```js
// Save initial model and thinking level for new sessions so they can be restored on resume
if (model) sessionManager.appendModelChange(model.provider, model.id);
sessionManager.appendThinkingLevelChange(thinkingLevel);
```

**The model survived without them by luck.** `SessionManager::settings()` walks the branch and takes
a `ModelChange` if it finds one — and falls back to the provider and model on the last
`AssistantMessage`, which every answered conversation has. **The thinking level has no such
fallback**: an assistant message does not carry one, and nothing else wrote one, because
`AgentSession::setModel()` only records a change *when it changes*. So a conversation started with
`--thinking high`, answered, and picked up with `--continue` came back with thinking **off** — no
warning, no line in the file, and on a reasoning model a visibly different answer to the same
question.

Reproduced before it was fixed, which is the only reason the shape above is stated rather than
guessed:

```
run 1 model=claude-opus-4-1 thinking=high
settings recorded in the file: {"model":{…,"modelId":"claude-opus-4-1"},"thinking":null}
run 2 model=claude-opus-4-1 thinking=off
```

The fix is upstream's two lines, in upstream's place — the `else` of "was this resumed". Two things
about it are worth knowing:

- **It costs no file for a session nobody had.** `SessionManager::append()` holds everything before
  the first assistant message in memory and flushes it in front once the conversation is worth
  keeping. pig already had the mechanism that makes recording this up front safe; it just never made
  the record.
- **The model is recorded explicitly now too**, even though the fallback would usually cover it. The
  fallback reads the model the last *answer* came from, which is not the model the next turn will
  use if `--model` said otherwise — so the file said one thing and the run meant another.

The general rule this is the second instance of: **where pig gets the right answer through a
fallback rather than a record, check what happens to the fact that has no fallback.** The first was
`version_compare` — a flattened `0.2.0` compared equal to `0.2.0-beta`, and the pre-release case was
the one nobody could see.

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
one; `--no-save` stays null and `/new` does not quietly start saving. Which of the three
happens is `AgentSession::switchTo()`/`startNew()`'s to decide now, not each mode's — the audit
find below ("RPC's session switch and new-session skipped the three checks") says why.

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
