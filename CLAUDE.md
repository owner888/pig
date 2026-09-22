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
`components/`, `terminal-image.ts` 340, `image.ts` 87). `pig/tui` is done.

`coding-agent` is upstream's biggest package — 23k lines at the anchor, and most of it is not
the agent: RPC mode, hooks, custom tools, compaction, HTML export, OAuth, twenty-five selector
components. What is being ported is the part that makes it a coding agent: `core/tools/` (done),
`core/system-prompt.ts` (done), enough of `core/agent-session.ts` to hold a session (done —
`Session\AgentSession`), and an interactive mode built on `pig/tui` (done — `Interactive\`).
The rest is left out until something needs it.

`AgentSession` is 1901 lines upstream and ~640 here, because everything it coordinates that is
not ported is not there to coordinate: auto-retry, branching and tree navigation, hooks, custom
tools and HTML export. What is left is the conversation, the event fan-out,
the queue of messages someone typed while the agent was working, the thinking level, what the
session has cost, persistence, and compaction. Each of the rest can arrive on its own when
something needs it.

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

Upstream's is 1129 lines to this one's ~250, and the difference is the tree: upstream
gives every entry a parent, which is what makes `/branch` and `/tree` possible. That needs
a UI to navigate it and a compaction system that understands branches, so the log here is
a line. The file format is upstream's, so adding the parent later is adding a field.

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

`InteractiveMode` is ~1070 lines against upstream's 2439, and the difference is almost entirely
selectors: upstream has twenty-five of them — models, sessions, settings, hooks, OAuth, branch
trees — and each needs a subsystem that is not ported. What is here is the loop that makes it
an agent you can talk to, nine slash commands, and the keys. Also not ported: custom-tool
rendering, images in tool output, and `/copy` (which needs a clipboard *writer*;
`SystemClipboard` only reads).

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
ported: Anthropic's 21, and the 72 across Cerebras, Groq, Mistral, xAI and Zai that speak
`openai-completions`. Offering a model and then failing to send the request is a worse answer
than "no such model", so the rest arrive with their protocols. OpenRouter's 236 speak a ported
protocol and are still left out: that list is a directory of everyone else's models and goes
stale fastest. The figures are upstream's *at the anchor commit*, not whatever models.dev says
today: a port should agree with the thing it was ported from.

The table is keyed `provider/id`, and `get()` takes an id alone only because no two providers
here claim the same one — `ModelsTest::testNoIdIsClaimedByTwoProviders` is what keeps that
true, and the first table that breaks it is where `get()` has to go.

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

`ModelResolver` is upstream's `model-resolver.ts`: `sonnet` finds the model, `sonnet:high`
finds it and sets the thinking level. When several match, the alias beats the dated build
behind it — someone typing `sonnet` wants the current one, not the June 2024 build that sorts
first. The pattern is tried whole before it is split on a colon, because an id can contain one
(OpenRouter's `:exacto`). Not ported: the glob scopes (`--model 'anthropic/*:high'`) for
running several models against one task — `fnmatch()` is the whole of what `minimatch` was
doing there, so that is rows of work rather than a dependency when something wants it.

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

Nothing here is deliberately unported any more. What is left is the two Google protocols
(`google-generative-ai`, `google-gemini-cli`), GitHub Copilot (which needs an OAuth device
flow), `/copy` (which needs a clipboard *writer*), images in tool output, custom-tool
rendering, and branch and tree navigation.

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

Regression tests: `TuiTest::testTheCursorEndsUpAtTheFocusedComponentsCaret`,
`testTheNextFrameStillCountsRowsFromWhereTheCursorActuallyIs`, and
`EditorTest::testTheCaretIsMeasuredInColumnsNotCharacters`.

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

### `stream_socket_pair()` with a dropped peer (tests)

`[$a] = stream_socket_pair(...)` garbage-collects the peer, putting `$a` at EOF — permanently
"readable", with `fread()` returning `''`. Keep both ends in scope.

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
