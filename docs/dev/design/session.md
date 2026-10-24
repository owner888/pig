# Design: Sessions

The session file, compaction, branches, resuming, the system prompt, retries. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [A replayed summary had no end the model could find](#a-replayed-summary-had-no-end-the-model-could-find)
- [The session file is pi's file](#the-session-file-is-pis-file)
- [What a conversation was being had with](#what-a-conversation-was-being-had-with)
- [Naming a point](#naming-a-point)
- [Forking a branch into a session file of its own](#forking-a-branch-into-a-session-file-of-its-own)
- [`/scoped-models`, `/export x.jsonl`, `/import`, `/share`](#scoped-models-export-xjsonl-import-share)
- [Where compaction cuts, and the split turn](#where-compaction-cuts-and-the-split-turn)
- [`SYSTEM.md`, `APPEND_SYSTEM.md` and the two flags](#systemmd-append_systemmd-and-the-two-flags)
- [A session carries on in its own directory](#a-session-carries-on-in-its-own-directory)
- [`/bug` read a property the class never had, and nothing could have said so](#bug-read-a-property-the-class-never-had-and-nothing-could-have-said-so)
- [Picking a failed turn back up](#picking-a-failed-turn-back-up)
- [Carrying on a run that stopped inside a tool call](#carrying-on-a-run-that-stopped-inside-a-tool-call)
- [Finding the one you want](#finding-the-one-you-want)

## A replayed summary had no end the model could find

`messages.ts` is 189 lines and four of them are the constants that wrap a summary when it is
replayed:

```ts
COMPACTION_SUMMARY_PREFIX = "…compacted into the following summary:\n\n<summary>\n"
BRANCH_SUMMARY_PREFIX     = "…a summary of a branch that this conversation came back from:\n\n<summary>\n"
```

pig had **its own sentence and no tags at all** on both — `"The conversation so far, summarised:"`
and a preamble of its own. A summary is prose a model wrote *about a conversation*, so it contains
lines like `Assistant: I reverted it` and, if the model was thorough, instructions it found in what
it was summarising. Replayed with a sentence in front and nothing behind it, **where the summary
stopped and the live conversation resumed was something the next model had to work out** — and pig
already wraps its summarisation *request* in `<conversation>` tags for exactly this reason, which is
the same fact one direction over.

Both are upstream's constants now, word for word, with pig's `<read-files>` and `<modified-files>`
after the close, because a file list is not part of the prose. **Upstream's two suffixes disagree
about a leading newline** and that is kept: it reaches a prompt either way, and a port that tidies
one of two literals is a port whose next diff shows a change nobody made on purpose.

The branch preamble's own docblock said **"Upstream's wording"** and it was not — the fourth shape
from the index, in the one place where the claim was the whole justification for the deviation. It
went with the constant rather than being corrected in place, and the reason it gave (that the
sentence should say *the user* went somewhere else, so the model does not read the summary as its own
last turn) survives in upstream's sentence anyway: "a branch that this conversation came back from"
says the same thing.

Regression test: `CompactionTest::testAReplayedSummaryIsWrappedSoItsEndIsFindable`, over both
message types, which also asserts the file lists are outside the tags.

The rest of `messages.ts` matched, and one difference is pig's own with a reason: `toText()` rtrims
the command output before fencing it, so `"hi\n"` does not come out as a fence with a blank line in
it and an output of nothing but newlines reads as `(no output)` rather than an empty fence.

`AgentSession` is 1901 lines upstream and ~1000 here. What is here is the conversation, the event
fan-out, the queue of messages someone typed while the agent was working, the thinking level, what
the session has cost, persistence, compaction, tree navigation, forking a branch into a file of its
own (`fork()`, below) and the hook events. Each of the rest can arrive on its own when something
needs it.

Themes are ported from upstream's `theme.ts` / `theme-json.ts` / `system-theme.ts` / `theme-controller.ts` (the developer asked for 100% alignment; the old `Theme\Palette` is deleted, with no alias left behind): `Theme\Themes` is `theme.ts`'s module functions and the global theme (`Themes::theme()` is upstream's `theme` proxy), `Theme\Theme` is upstream's `Theme` class, and the color math lives in `Pig\Tui\Colors` / `Pig\Tui\Oklab` (`colors.ts` / `oklab.ts`). The built-in `dark.json` / `light.json` are upstream's byte for byte (OKHSL notation); `labra.json` is a built-in theme pig keeps in addition. With no theme setting, upstream's `system` theme applies: grayscale until the terminal reports its colors, then `InteractiveThemeController` generates one from `TUI::queryTerminalColors()`, following DEC 2031 light/dark switches automatically (and switching between the pair when the setting is a `light/dark` pair).

**Trap rules**: components read `Themes::theme()->fg(...)` at render time and do not keep a `Theme` instance, or a theme switch changes nothing; a component that bakes colors into strings rebuilds them in `invalidate()` (as upstream does), and the controller `invalidate`s the whole UI on a switch. `Theme::fg()` throws for a background token — use `bg()`. `InteractiveMode` installs the `ThemeJson::validateThemeJson` validator: a custom theme missing a token is refused with the reason (no longer filled in from dark). Custom theme directories are pig's four (`~/.pig/agent/themes`, `~/.pi/agent/themes`, and once the project is trusted `<cwd>/.pig/themes`, `<cwd>/.pi/themes`), matched by file name first, then by the `name` inside the file; a theme that cannot be read is recorded in `Themes::getCustomThemeErrors()` and named when themes are listed. Syntax highlighting is still pig's `Highlight`/`Grammar` (`Themes::highlightCode()`). `/theme` with no argument opens the picker (moving previews, Esc restores), `/theme <name>` switches and saves. The Web UI (`pig --mode web`) shows the same themes: `Web\ThemeStylesheet` writes every theme but `system` into `/theme.css` as `--color-*` tokens, one `:root[data-theme]` block each (every theme colour in kebab case, plus semantic names on top: `--color-primary` is `border`, `--color-border` is `borderMuted`, backgrounds from the `export` section or upstream's `deriveExportColors()`), and lists them at `/api/themes`; the choice is remembered in `localStorage`. **Trap rule**: no colour literal in the page's CSS or JS — a `--color-*` token, with `color-mix()` for a translucent one; a CSS rule scoped to one theme name is a bug.

**Text with baked-in colors uses `ThemedText`** (upstream `components/themed-text.ts`): `new ThemedText(fn () => Themes::theme()->fg('dim', $msg), 1, 0)`; after `invalidate()` the next render rebuilds it with the current theme, so the data has to be snapshotted before the component is built. `say()`/`sayError()`/`sayWarning()`, the banner (`ExpandableText`, ctrl+o via `setExpanded()`) and every command's output go through it. A plain `new Text(Themes::theme()->fg(...))` keeps its color across a theme switch — and since the `system` theme is grayscale until the terminal reports its colors, such text stays gray forever.

**The extension theme interface follows upstream's `ExtensionUIContext`**: `getAllThemes()` returns `list<ThemeInfo>` (`->name`, `->path`), `getTheme($name): ?Theme`, `setTheme(string|Theme): array{success, error?}`; `NoUi` answers `UI not available`, `RpcUi` answers `Theme switching not supported in RPC mode` (no longer forwarded to the host). Extension components do not keep the `Theme` that `custom()` hands them; they read `Themes::theme()` at render time (upstream's `theme` is a proxy).

**The startup information layout follows upstream**: after `headerContainer` (the built-in header `ExpandableText`) comes `loadedResourcesContainer`, filled by `showLoadedResources()` with one `ExpandableText` per section (Context, Skills, Prompts, Extensions, plus a Tools section in pig); problems are `ThemedText` blocks headed `[Skill conflicts]`/`[Extension issues]`; ctrl+o expands and collapses all of them, `/reload` rebuilds them.

**`Markdown::invalidate()` must clear the cached default-style escape**: the escape that restores the default style after an inline span (`**bold**`, `` `code` ``) is computed once and stored; clearing only the line cache and not that leaves the text after a bold run in the old color after a theme switch (user messages and compaction/branch summary bodies were all affected).

**Mouse**: `Box`, `Editor`, `SelectList` and `SettingsList` have `handleMouse()` as upstream does. A wrapping component (such as `CustomEditor` around `Editor`) must implement `MouseHandler` and forward, or clicking the input does nothing; `Mouse::dispatchMouseEvent()` gives focus to the wrapper itself.

| Old | New |
|---|---|
| `Theme\Palette`, `PaletteTest` | deleted; `Theme\Themes` + `Theme\Theme` |
| `$palette->fg/bg('x', $t)`, `$palette->of('x')` | `Themes::theme()->fg/bg('x', $t)` (read at render time); closure `static fn ($t) => Themes::theme()->fg('x', $t)` |
| `$palette->hex('x')` | `Themes::getResolvedThemeColors($name)['x']` |
| `markdownTheme()/selectListTheme()/editorTheme()/settingsListTheme()` | `Themes::getMarkdownTheme()/getSelectListTheme()/getEditorTheme()/getSettingsListTheme()` |
| `highlightTheme()` + `Highlight::lines` | `Themes::highlightCode($code, $lang)` |
| `thinkingBorder($l)`, the bash-mode border, `isTruecolor()` | `Themes::theme()->getThinkingBorderColor($l)`, `getBashModeBorderColor()`, `getColorMode() === 'truecolor'` |
| `Palette::named/dark/light/labra`, `names()`, `customThemes()` | `Themes::setTheme()/initTheme()/getThemeByName()`, `setCustomThemesCwd()` + `getAvailableThemes()`, `getAvailableThemesWithPaths()` |
| `HookUi::palette()`, `FooterComponent::setPalette()` | `HookUi::theme()`; the footer reads the global theme directly |
| `InteractiveMode::useTheme()/$palette`, constructor `(session, palette, cwd, version, theme, …)` | `$themeController` (`InteractiveThemeController`), `(session, cwd, version, ?themeSetting, …)` |

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
to, under `~/.pig/agent/sessions/<the project's path, flattened>/`. Appending rather than
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
- **Format version 3, and it is pi's version 3.** It was 2 — pi's 2 — until pi moved, and a
  pig file under the old number was rewritten by pi on every open. See the `context_edit` trap
  below for what v3 adds; there is still no reader for the shape pig briefly wrote under `2`.
- **The end of the file is the leaf.** A branch is only ever made by appending, so the newest
  entry is always on the branch that was being talked on when the session was last open.

## The session file is pi's file

**It was not, and it said it was.** pig wrote the message flattened onto the line with
`entryId` and `parent` and an integer millisecond timestamp, under a header saying
`version: 2` — which is the number pi uses for a different shape. So pi opening a pig file
would agree about the version and then find no `type` on any line. A different version number
would have been honest; the same number for a different format is the one thing that cannot be.

It is pi's now, field for field:

| | pi, and now pig | pig, before |
|---|---|---|
| project directory | `--Users-dev-Dev-pig--` | `Users-dev-Dev-pig` |
| file | `2026-01-02T21-29-30-123Z_<uuid>.jsonl` | `2026-01-02-212930-<12 hex>.jsonl` |
| header timestamp | `"2026-01-02T21:29:30.123Z"` | `1735849770123` |
| a message | `{"type":"message","id","parentId","timestamp","message":{…}}` | the message, flat, plus `entryId` and `parent` |
| entry id | eight characters off a UUID | twelve hex characters |
| compaction | `type:"compaction"` with `firstKeptEntryId` | `role:"compactionSummary"` with `replaced` |
| a hook's message | `type:"custom_message"` | `role:"hookMessage"` |
| a hook's note | `type:"custom"`, in the tree | `type:"custom"`, outside it |
| what the model is not shown | `type:"context_edit"` with `targetId` and `replacement` | nothing — the message was taken off the state and the file said nothing |

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
  `join(homedir(), ".pi", "agent")`. `PI_CODING_AGENT_DIR` — upstream's own, whose name is
  *built* from `APP_NAME` rather than written anywhere, which is how pig came to look for
  `PI_AGENT_DIR` instead — and `PI_HOME`, pig's own, override it.

**There is no reader for the old shape, on purpose.** The developer's call, and the reasoning
is worth keeping because it is the kind that gets forgotten: pig has never been released and
nobody has ever had a session file in the old format, so a reader for it would be compatibility
with nothing — code that can only ever be exercised by a test written to exercise it. It was
written first and then deleted, along with the two tests that covered it. Anything that is not
a line pi could have written is a line pig has nothing to do with, and takes the same path as
`thinking_level_change`: a node holding nothing, walked past on the way out.

The general rule, for the next time this comes up: **back-compatibility is a debt to real users,
and there are none until there is a release.** Before that, a format change is a format change.

**And that rule is about pig's own old shape, which is the distinction that hid a real gap for
months.** pi's v1 sessions are somebody else's history, and there are real users with those:
`--resume` lists them, because pig reads pi's directory. A v1 file has **no `version`, no `id` and
no `parentId` — the order of the lines is the chain** — and a compaction there names its cut by
`firstKeptEntryIndex`, an index into the file with the header counted.

Read as v2, every entry is parentless, so every entry is a root, so walking back from the leaf finds
exactly one message. Measured on a three-message file: **pig opened it as its last line and nothing
else.** Somebody moving over from an older pi saw their conversation in the list, opened it, and got
one sentence.

`SessionManager::upgrade()` is upstream's `migrateV1ToV2()`, and like upstream **the file is
rewritten**. That is the decision worth arguing and the argument is `Migrations`': what happens is
exactly what pi does on its next start, so running it converges rather than diverges. Leaving it
alone is worse than it sounds — pig would append v2 entries to a v1 file and pi's own migration would
then re-id every line from the top, flattening whatever branches pig had made in between. A file that
cannot be written is read correctly and left alone, which costs one more upgrade next time.

What made it findable at all was reading the upstream file's **top-level functions** rather than its
class: `migrateV1ToV2`, `migrateToCurrentVersion` and `migrateSessionEntries` are three of the nine
functions above `class SessionManager`, and a method-by-method comparison of the two classes skips
all nine.

Regression tests: `PiFormatTest::testAV1SessionComesBackWholeRatherThanAsItsLastLine`,
`testOpeningAV1SessionUpgradesTheFileTheWayPiWould`,
`testAV1CompactionsIndexBecomesTheIdItPointsAt` — one per end, and each stays green when the other
two are broken.

**The class docblock had inventoried what was missing**, and every item on the list had since been
ported: the tree, labels, the model and thinking-level entries, the migrations. *A docblock that
lists absences is a docblock that goes stale silently* — the fourth shape from the index, at its
largest, since the list was four items long and wrong about all four. It says what is there now; the
one absence it then named — `branch()` in upstream's sense, forking into a second file — has since
arrived as `fork()`, see [Forking a branch into a session file of its own](#forking-a-branch-into-a-session-file-of-its-own).

## What a conversation was being had with

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
- **A model this pig has no entry for is not an error.** pig's registry carries only the models
  whose protocol is ported, so a pi session on one of the others (one a pi extension pig lacks brings, say) restores to
  whatever pig had. The conversation still opens, and the footer says which model is answering.

Recorded only when something actually changed: `setModel()` is also how the thinking level gets
clamped, and a line per call would be a file full of a model changing to itself.

## Naming a point

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

## Forking a branch into a session file of its own

`/fork` and `/clone` are upstream's — `createBranchedSession()` in `session-manager.ts`, `fork()`
in `agent-session-runtime.ts`, the `fork` / `clone` / `get_fork_messages` RPC commands and the
`session_before_fork` event. `/tree` moves inside one file and leaves every branch where it was;
this is for when one branch should *become* a conversation: resumable on its own, listed on its
own, the other branches no longer in it. `/fork` lists every message somebody typed, on any branch
(an abandoned branch is forked back into a conversation this way), and the fork holds everything up
to *before* the chosen one, whose words come back in the prompt — `goTo()`'s rule, in a new file.
`/clone` forks at the leaf: this conversation as it stands, in a second file.

`SessionManager::fork($leafId)` hands back a **new instance** rather than turning this one into the
fork, because `$path` and `$id` are readonly and `AgentSession::writeTo()` is how a session changes
files. Three things from upstream are kept because each is a file pi could not read otherwise: labels
are taken off the path and written again at the end, hanging off the new leaf (a label is a real
entry with children, so the path is re-chained around it); a compaction's `firstKeptEntryId` that
named a removed label names the next kept entry; and the file is written now only when the path has
an assistant message in it — `append()`'s own rule, so a fork of nothing but a question leaves no
file until something answers. The header carries `parentSession`, which `open()` reads back as
`SessionManager::$parentSession`.

`AgentSession::fork($entryId, $position)` is `startNew()`/`switchTo()`'s recipe with the hook asked
first: refused with `switched: false` when a hook says no, thrown for a session not being saved
(`--no-session` has nothing to fork; upstream forks one in memory, pig has no second in-memory
conversation to put it in), for one with no file yet (upstream's sentence), and for `before` on a
point nobody said. Custom tools hear it as `fork`.

Regression tests: `SessionManagerTest`'s five `testAFork…` cases, `AgentSessionTest`'s seven under
"forking a branch into a file of its own", `RpcModeTest::testForkingFromAMessage…`,
`testCloning…`, and `InteractiveModeTest::testForkMovesToANewFile…`, `testCloneCopies…`.

`Session\SessionCodec` has no upstream counterpart at all: a message there is a plain
object and `JSON.stringify` is the whole persistence layer. PHP objects do not survive
that, so the shapes are written out by hand — which is the one place that has to change
when a message type gains a field. A tool's `details` is `mixed`, so it is flattened to
arrays on the way out; the only things anything reads back out of it are `edit`'s diff and a
truncation `notice`, which are strings and integers and survive exactly.

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
  it is a request every provider rejects outright, so `findCutPoint()` collects the legal
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
  sends the model to read them again. `prepareCompaction()` also carries an earlier
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

Not ported from upstream's compaction: the split-turn prefix summary — upstream generates a
second, smaller summary when the cut falls inside a turn, which needs turn boundaries that the
linear log here does not mark. Both summaries (compaction and the branch summary of `/tree`) go
through `Retry::retryAssistantCall()` with the session's retry settings and the session's own
stream function and request options, as upstream's `retryAssistantCall()` callers do; a retry is
reported as `SummarizationRetryEvent` (RPC `summarization_retry_*`). A compaction records the
replayed system message (`CompactionSummary::$systemMessage`, upstream's `systemMessage` on the
entry), and the rebuilt context is `[system, summary, …kept]`.

`Interactive\` is the terminal front end. `InteractiveMode` is the arrangement — which event
becomes which component, which key means what — and `bin/pig` is the entry point.

The screen is five containers between the banner and the footer: `$chat` (the transcript),
`$pending` (what is queued), `$status` (what is *happening*), `$overlay` (what is being *asked* —
a picker, `/settings`, a hook's dialog), then the editor. `$status` and `$overlay` are separate on
purpose and the trap below says why in full: the overlay holds whatever has the focus, and only
the thing that gave it the focus ever clears it.

**The loaders are not in `$status` any more — they are in the prompt's top border**, which is
where pi draws them (`CustomEditor.renderTopBorder()` with `embedWorkingStatus`): `── ⠋ Working... ─────`
(with dynamic elapsed time `· 16s` while running). Working, retry, both compactions and the branch summary all go through
`InteractiveMode::showLoader()`/`hideLoader()`, which hand the `Loader` to
`CustomEditor::setWorkingStatus()` → `Editor::setBorderStatus()`; the editor asks the closure on
every frame, so the spinner ticks without the editor knowing one is there, and it is painted in
the border's own colour so the rule reads as one rule. What it replaced was a line of its own with
a blank above it, which grew the frame by two rows at the start of every turn and shrank it at the
end. The retry countdown lost the error text on the way in — it is already a red line in the
transcript — and says upstream's `Retrying (1/3) in 30s... (escape to cancel)`, counting down once a
second. The key name is upstream's `keyText("app.interrupt")` (`Keybindings::keyText()`), which prints
the raw key id — `escape`, not `esc` — so the label follows a rebound key. `$status` stays for
anything that is not a loader.

The
components it draws with are `UserMessageComponent`, `AssistantMessageComponent`,
`ToolExecutionComponent`, `FooterComponent`, `DiffView`, `BashOutputComponent`,
`CompactionComponent` and `CustomEditor`. They keep upstream's `…Component` names rather than `pig/tui`'s suffix-free
`Text` / `Box` / `Markdown`, because `UserMessage` and `AssistantMessage` are already taken by
`Pig\Ai`; the developer chose matching upstream over matching the sibling package.

`InteractiveMode` is ~1200 lines against upstream's 2439, and the difference is almost entirely
selectors: upstream has twenty-five of them — models, sessions, settings, hooks, OAuth, branch
trees — and each needs a subsystem that is not ported. What is here is the loop that makes it
an agent you can talk to, thirty-four slash commands plus whatever the hooks add, the keys, and
the dialogs a hook or a custom tool can open mid-turn (`Interactive\TerminalUi`).

**Audited against its 2439 lines by surface** — 68 upstream methods mapped name by name against
pig's 88, then every unmatched one chased down, which is the method `agent-session.ts` was read
with and the only one that works on a file this size. It found three things to fix, each its own
trap below (the editing keys named nowhere, `/debug` three-quarters ported, a message lost to an
auto-compaction), and four places where pig's arrangement differs on purpose. Those four, so nobody re-derives them:

- **A status line is appended, not replaced.** Upstream's `showStatus()` overwrites the previous
  one when it is still the last thing in the chat, so toggling ctrl+t twice leaves one line there
  and not two. pig has one `say()` where upstream has `showStatus` *and* `showError`/`showWarning`,
  and `say()` also prints `/help`, `/skills` and the hook list — replacing those would make
  `/help` vanish when the next thing is said. Splitting it in two is the fix if the duplicate
  lines ever matter; the payoff is cosmetic and the cost is deciding, per caller, which kind each
  `say()` is.
- **Compaction leaves the transcript where it is**, in both paths, with the reason on the code: it
  is what was said, and the summary is a note about it rather than a replacement for anyone's
  memory of reading it. Upstream clears the chat and rebuilds it from the compacted messages, in
  the manual path and the automatic one both, so its screen matches the model's view and its
  scrollback loses what was summarised. pig's two paths agree with each other, which is the part
  that matters.
- **A `!command`'s output goes straight into the transcript**, so there is nothing to flush.
  Upstream draws it in the pending area and moves it into the chat when the next message is
  submitted (`flushPendingBashComponents`), because its component and its *message* arrive at
  different moments. pig's message still joins the conversation on `AgentEndEvent` —
  `AgentSession::flushBash()` — and only the drawing is immediate.
- **A picker goes in the overlay, above the editor, not in place of it.** Upstream's
  `showSelector()` clears the editor's own container; the trap below on `$overlay` is why pig does
  not, and it is the arrangement that made pig's "focus on an invisible list" bug possible in the
  first place.

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

**The footer's two markers were both missing, and both for the same reason.** Upstream writes
` (auto)` after the context percentage while auto-compaction is on, and ` (sub)` after the cost
while the model is reached with a signed-in account rather than a key — and it shows the cost at
all in that case, even at `$0.000`. Neither was here. `(auto)` had a docblock saying it was "not
ported: nothing to report yet", which was true when it was written and stopped being true the day
auto-compaction landed; a comment asserting a thing about the rest of the repository is a comment
that goes stale silently, which is the `PI_AGENT_DIR` trap in a different file. `(sub)` was never
mentioned. What they cost: a percentage that means two different things — a pause and a summary,
or a request that gets refused — with nothing saying which, and a subscriber reading a running
bill that is not a bill.

Upstream pushes the first one in, with a `setAutoCompactEnabled()` that whoever changes the
setting has to remember to call. Here both are **read on every frame** — `Settings` and `Auth` are
constructor arguments and the render asks them — because the footer redraws constantly anyway, the
two facts are already stored where they can be looked up, and a setter is one more thing to wire
at one end only. `InteractiveMode` passes `$this->settings` and not its own argument: with no
settings given, that is the in-memory object `/settings` writes to, and the footer has to read
what that screen changes.

**A command the person typed and one the model ran are two components, as upstream has them.**
`ToolExecutionComponent` draws the model's `bash` in a box with a background and keeps five lines
(`tool-execution.ts`'s `BASH_PREVIEW_LINES`); `BashExecutionComponent` draws `!cmd` as
`bash-execution.ts` does — a blank row, a rule in the bash-mode colour (`dim` for `!!`), `$ cmd`
in bold, the last twenty lines, the loader while it runs, then the status parts (`... N more lines
(ctrl+o to expand)`, `(cancelled)`, `(exit N)`, the spill path) and a closing rule. They were one
component with two line counts for a long time; the developer asked for upstream's two, to the
line, and the `Not added to the conversation` note `!!` used to print went with it — the `dim`
rule is how upstream says that.

**Padding comes from two settings, both upstream's.** `outputPad` (1, or exactly 0) is the blank
column either side of every message, tool box, command block and error line; every component that
draws one takes it as a constructor argument and has a `setOutputPad()`, and `/settings`' *Output
padding* row walks the chat and the pending area calling it, as upstream's `onOutputPadChange`
does. `editorPaddingX` (0–3) is the blank column either side of what is typed —
`Editor::setPaddingX()`, which lays the text and the suggestion rows out at the width between the
pads and takes the pad off a click's `x`; the rules above and below still span the whole width.
`Text`, `Box` and `Markdown` gained a `setPaddingX()` for it, and `BashOutputComponent` a
`paddingX`, because upstream rebuilds its components on a change where pig's keep theirs.

**The spacing in the transcript is upstream's row for row**, checked against `interactive-mode.ts`
and every component under `components/`: a `Spacer(1)` before each note (`say()`, `sayError()`,
`sayWarning()`), one between *What's New* and the notes on top of the markdown's own top padding,
`✓ New session started` padded a row below (`ThemedText(…, 1, 1)`), `/hotkeys` in the same
rule–title–spacer–body–rule frame as the changelog (its rows are pig's columns rather than
upstream's markdown tables, because `Pig\Tui\Components\Markdown` draws no tables), and a
`Spacer(1)` after every overlay list, which is where upstream's selectors put theirs.

**A truncation notice has two readers, so it is said twice.** Every tool that cuts its own output
ends the text with a line saying so — `[Showing lines 1-2000 of 8431. Use offset=2001 to
continue]` and its siblings — and *the last line of the output is exactly the line a collapsed
tool view cuts*, because the preview keeps the first ten, twenty or fifteen. So for a while
`read`, `ls`, `find` and `grep` told the model what was left out and told the person nothing;
`bash` was fine by accident, since its preview keeps the tail, which is where its notice already
is. Upstream has the same two copies for the same reason: one in the text, one in the component,
where collapsing cannot reach it.

**The tool builds the sentence; the result carries it.** The notice goes in `details` under
`notice`, as the same string the text ends with, without its brackets. The alternative, upstream's,
is for the component to build a second sentence out of structured fields — a second wording of one
fact, which then has to be kept in step with the first. Guessing from the text was the other
candidate and it guesses wrong: a `read` of a file whose last line is `[section]` would be handed a
notice it does not have.

## `/scoped-models`, `/export x.jsonl`, `/import`, `/share`

Four upstream commands that arrived together; each is upstream's flow on pig's pieces.

- **`/scoped-models`** (`Interactive\ScopedModelsSelector`) picks the `--models` scope in the session,
  on the `app.models.*` keybinding ids; `app.models.save` writes it to `enabledModels`.
- **`/export <path>.jsonl`** (`Export\JsonlExport`, upstream's `exportSessionToJsonl()`) writes
  `SessionManager::serializeBranch()` — a fresh header and the root-to-leaf entries, so the file is one
  conversation. Quoted paths work. `/export md` and `/export pr` are pig's own and stay.
- **`/import <path>`** asks, copies the file into this project's session directory (`-1`, `-2` on a
  name clash) and resumes it — in its own directory, see "A session carries on in its own directory".
- **`/share`** (`Export\SessionShare`) takes upstream's second road unless Radius is chosen: `gh auth status`, the HTML export
  to a temp file, `gh gist create --public=false`, and a link on `PIG_SHARE_VIEWER_URL` (default
  `https://pigagent.dev/session/`, the site's viewer page: it fetches the gist from GitHub's API in
  the browser and draws the HTML export in a sandboxed frame). Upstream's first road, uploading to Radius, is
  `sessionShareDestination` (`auto` uses it when signed in to Radius). A secret gist is unlisted, not private; the link is the access.

## Where compaction cuts, and the split turn

`Session\Compaction` is upstream's `core/compaction/compaction.ts` and `utils.ts` under upstream's
names — `calculateContextTokens()`, `shouldCompact()`, `getLastAssistantUsage()`,
`estimateContextTokens()`, `findCutPoint()`, `findTurnStartIndex()`, `prepareCompaction()`,
`serializeConversation()`, `createFileOps()` / `extractFileOpsFromMessage()` /
`computeFileLists()`, `generateSummary()`, `compact()` — and its value classes
(`CompactionPreparation`, `CompactionResult`, `CutPointResult`, `FileOperations`,
`CompactionSettings`) are upstream's shapes. The point of the names is that an extension can do
what upstream's can: take the `CompactionPreparation` a `session_before_compact` handler is given,
call `serializeConversation()` or `compact()` itself, and answer with a `CompactionResult`.

**A cut inside a turn is two summaries, merged.** `findCutPoint()` returns the index, whether the
cut is mid-turn and where that turn started; `compact()` summarises the history before the turn
(or keeps the previous summary when there is none) and the turn's own beginning
(`turnPrefixMessages`) separately, at half the reserve, and joins them under
`**Turn Context (split turn):**`. pig used to summarise straight up to the cut instead — a reading
of an older upstream that left `turnPrefixMessages` unread; upstream reads it now, and so does pig.

What else was checked rather than assumed: `calculateContextTokens()` prefers the provider's own
total; `shouldCompact()` takes `CompactionSettings` (its `enabled` included) and has a
`contextWindow > 0` guard, so a model declaring no window is never compacted for; `estimateTokens()`
divides by four; the cut never lands on a tool result; `serializeConversation()` takes LLM messages,
so a hook message, a `!` command and an earlier summary reach the summariser as `[User]: …`, and cuts
each tool result at `TOOL_RESULT_MAX_CHARS` (2,000); the summariser gets `0.8 * reserveTokens` and the
session's thinking level. `tokensBefore` is the projected estimate (`estimateProjectedContextTokens()`),
which is what "Compacted from N tokens" shows. A hook's `firstKeptEntryId` and `tokensBefore` are used
as given.

One divergence left: **the summary text is the prose alone and the lists stay in `details`**, where
upstream's `compact()` also appends `<read-files>` / `<modified-files>` to the text. The model sees
the same thing either way, because `CompactionSummary::toText()` adds the tags on replay; a
`CompactionResult`'s other `details` keys are not stored. The prompts themselves are upstream's
verbatim, the update prompt's format block included.

`Session\BranchSummarization` is upstream's `branch-summarization.ts`:
`collectEntriesForBranchSummary()` walks from the old leaf back to the common ancestor,
`prepareBranchEntries()` fits that to the budget (the window, 128k when the model states none, less
the reserve), `generateBranchSummary()` asks for a handover — goal, progress, blocked, next steps —
with `PREAMBLE` in front, `Additional focus:` for custom instructions or `replaceInstructions` for a
whole prompt, and "No content to summarize" for an empty branch. `/tree` asks whether to write one,
and it is appended to the branch being *joined*: context for carrying on here, not a note on the
branch it describes.

- **It replaces nothing.** A `CompactionSummary` stands in for the messages before it; a branch
  summary describes messages that were never on this branch, so nothing is spliced.
- **File lists come from what fits the budget**, plus the lists of earlier branch summaries pig wrote
  — upstream's rule ("the messages being summarized"). pig used to collect them from the whole branch;
  that divergence is gone.
- **Cancelling means not moving.** Escape while summarising returns `TreeJump(moved: false,
  aborted: true, cancelled: true)` and the tree list comes back up; a hook's `cancel` returns
  `cancelled: true` and the TUI says "Navigation cancelled". The leaf stays where it was either way.
- **A hook may write it instead** (`SessionBeforeTreeResult(summary: …)`), used only when a summary
  was asked for; its `customInstructions`, `replaceInstructions` and `label` apply either way. A
  hook-written summary is `fromHook` in the file, and its lists are the hook's own `details`.
- **Its lists carry forward into a later compaction** unless a hook wrote it.

`session_before_compact` and `session_before_tree` stop at the first handler that cancels, else take
the last answer (`HookRunner`). The events carry upstream's fields: `SessionBeforeCompactEvent
{preparation, branchEntries, customInstructions, reason, willRetry, signal}`, `SessionCompactEvent
{compactionEntry, fromExtension, reason, willRetry}`, `SessionBeforeTreeEvent {preparation, signal}`,
`SessionTreeEvent {newLeafId, oldLeafId, summaryEntry, fromExtension}`. A hook that cancels a
compaction makes it throw `Compaction cancelled`.

## `SYSTEM.md`, `APPEND_SYSTEM.md` and the two flags

`Prompt\SystemPromptFiles` is the part of upstream's `DefaultResourceLoader.reload()` that settles
`getSystemPrompt()` / `getAppendSystemPrompt()`. `SYSTEM.md` replaces pig's default prompt (role, tool
list, rules — the context files, skills and directory still follow); `APPEND_SYSTEM.md` is added after
whichever prompt that leaves. Each is looked for in the **trusted** project's `.pig/` first, then the
agent directory; the first found wins and the two are never combined. A missing or empty file leaves
the default. `--system-prompt` and `--append-system-prompt` (repeatable) are
`CodingAgent::session(systemPrompt:, appendSystemPrompt:)` and are used **instead of** the discovered
file; a value that is an existing path is read as a file, anything else is the text. Not done:
`/reload` does not re-read them.

Context files are upstream's too (`Prompt\ContextFiles`): the agent directory's, then one per
directory from the root down to the working directory, each the first of `AGENTS.override.md`,
`AGENTS.md`, `AGENTS.MD`, `CLAUDE.md`, `CLAUDE.MD`; inside a linked git worktree nested in its main
checkout, the main checkout's copy is left out (`findShadowedContextFile()`).

## A session carries on in its own directory

Resuming another project's conversation — `--session`, the picker's all-projects scope, `/import`,
RPC's `switch_session` — carries on **in the directory its header names**, as upstream's
`switchSession()` does. Everything bound to a directory (tools, context files, `SYSTEM.md`, the
project's settings, trust, hooks, extensions) belongs to that directory, so:

- **`bin/pig` chooses the session before trust, settings and extensions** and builds the runtime for
  the session's cwd. Inside a running session, `AgentSession::switchTo($path, $cwdOverride, $relocate)`
  disposes this session and calls the caller's runtime factory (`$createRuntime`, built around
  `CodingAgent::session()`); `SessionSwitch::$replacement` is the new one, and `InteractiveMode`
  `rebind()`s to it, as `RpcMode` does for `switch_session`. A switch within the same directory stays
  in place, and so does a library caller with no factory.
- **A directory that has gone is not quietly replaced** (`Session\SessionCwd`, upstream's
  `session-cwd.ts`): `MissingSessionCwdError` is thrown before anything is torn down; a terminal asks
  "continue in current cwd" (`Cli\StartupSelector` at startup) and retries with `$cwdOverride`
  (`SessionManager::open($path, $cwdOverride)`); anything else exits 1 with upstream's
  `Stored session working directory does not exist: …`. Cancelling says `Resume cancelled` /
  `Import cancelled`.

## `/bug` read a property the class never had, and nothing could have said so

Reported with the screen: `PHP Warning: Undefined property: InteractiveMode::$terminalUi` the moment
`/bug` was typed. The field is `$ui`; `reportBug()` and `handleCommitCommand()` both read
`$this->terminalUi`, and the confirm they call on it was then a method on null inside a spawned
fiber, where the only thing on screen is PHP's warning. **The one command that exists for reporting
a fault was the one command no test typed** — `BugReportTest` drives `BugReport::build()` and
`write()` directly, which is the "two ends tested, wire untested" shape this file keeps naming.

Two fixes. The test, `InteractiveModeTest::testSlashBugAsksBeforeItWritesAnything`, types the command
and asserts the question is on screen and PHP's warning is not. And **`test/lint.php` now sweeps for
the class of mistake**, because `php -l` parses and does not resolve properties, exactly as it does
not resolve function names — the lint already carries a list of 8.4-only functions for that reason,
and this is the same gap one token over. `undefinedPropertyReads()` walks PHP's own tokens (the first
version was a regex and lost the rest of `SignIn.php` at a `'` inside a docblock): every
`$this->name` not followed by `(`, against every visibility-keyword-then-variable declaration,
promoted constructor parameters included; classes that `extend` anything or define `__get` are
skipped, because an inherited or magic property cannot be seen from one file. Over 564 files it
found this one site and nothing else, costs nothing measurable beside the per-file `php -l`, and
putting the typo back turns it red.

## Picking a failed turn back up

A turn can fail for a reason that undoes itself — the provider is busy — or for a reason the
*next* request can fix: the conversation outgrew the window. `AgentSession` handles both,
after the run ends, from `handlePostAgentRun()` in `runAgentPrompt()`'s post-run loop —
`prepareRetry()` for the first, `checkCompaction()` → `runAutoCompaction('overflow')` for the second.
`checkCompaction()` is upstream's `_checkCompaction()` and also covers the threshold: it runs after
every run and before every prompt, in every mode, so the terminal no longer
decides on its own when to compact. An overflow is compacted and retried **once**
(`overflowRecoveryAttempted`); a second overflow ends as `compaction_end` with upstream's
`Context overflow recovery failed after one compact-and-retry attempt…` and `session_compact_failed`.
Nothing is announced when `prepareCompaction()` finds nothing to cut.

Which one it is, is decided by what the provider said, and the two are mutually exclusive on
purpose: upstream's `_isRetryableError()` answers false for an overflow however retryable the rest
of it looks — a 429 that says "prompt is too long" is a 429 that will say it again in four seconds
— and `handlePostAgentRun()` follows upstream's order: retry, then report a retrying that ended in
failure, then compaction.

**The classification is upstream's `utils/retry.ts`, by the error's text** (`Pig\Ai\Utils\Retry`):
`isRetryableAssistantError()` matches `RETRYABLE_PROVIDER_ERROR_PATTERN` and not
`NON_RETRYABLE_PROVIDER_LIMIT_ERROR_PATTERN`, both lists copied entry for entry. pig used to read an
HTTP status out of the message and keep its own word list; neither exists any more. What makes a
transport failure retryable is that pig words it as Node does (`Connection error.`, `fetch failed`,
`terminated`, `Request timed out.`) — so a new failure path in a provider has to be worded the way
upstream's runtime words it, or it is not retried.

**`Ai\Utils\Overflow` is a table of what each provider actually says**, ported from upstream's
`ai/src/utils/overflow.ts` with its examples kept as comments. There is no status code for
"too long" and no field in any response that says so; every provider says it in prose, two say
it with an empty 4xx and no body at all (only Cerebras's bodiless 400/413 is taken for one, by
provider, as upstream does), z.ai does not say it — it accepts the oversized request, answers,
and bills for more input tokens than the window holds, so the only evidence is the usage report —
and Xiaomi MiMo cuts the prompt to fit and stops `length` with nothing written. A rate-limit or
throttling message is never an overflow, whatever tokens it mentions (`NON_OVERFLOW_PATTERNS`). **A pattern with no example beside it is a guess**, and a guess here
compacts a conversation that was fine.

The four events are `RetryStartEvent`, `RetryEndEvent`, `CompactionStartEvent` and
`CompactionEndEvent` (each with a `reason`: `manual`, `threshold` or `overflow` — one pair for every
compaction, where the old `AutoCompaction*` pair was overflow-only), and they implement `Pig\Agent\AgentEvent` so they reach the
listeners that are already there — `AgentEvent`'s docblock says why an event from above the
loop is on the loop's stream. Each mode draws them as it likes: the terminal turns a retry into
a loader that names the error and counts down, `--mode json` and RPC encode them through
`RpcEvents`, `-p` ignores them.

Five things that are load-bearing rather than tidy:

- **The failed message comes off the agent's state before the retry**, and stays in the session
  file. Leaving it would put a `529 {"type":"error",…}` in the transcript for the model to read
  and try to make sense of.
- **Nothing is decided in the agent's event fan-out.** `handlePostAgentRun()` runs in the
  prompt's own fiber after `agent->prompt()`/`continue()` has returned, as upstream's
  `_handlePostAgentRun()` does inside `_runAgentPrompt()` — so the sleep blocks there, and the
  next `continue()` does not re-enter the agent from inside its own notification.
- **The sleep is abortable**, because eight seconds that escape cannot reach is eight seconds
  of a terminal that will not answer. A timer and an abort listener race to complete one
  `Deferred`; the timer is cancelled on an abort rather than left to fire into nothing, because
  a pending timer keeps `Loop::isIdle()` false and `bin/pig` would not exit.
- **`prompt()` waits for a retry in progress.** `isStreaming()` is true for the whole prompt,
  sleep included, so the screen queues; a caller without a queue — RPC's `prompt`, `-p` with
  several messages — waits for the prompt to settle rather than racing it into the same agent.
- **The waits double** — `retryDelayMs()`: `retry.baseDelayMs` (2s) × 2^(attempt−1), capped at
  `retry.maxAgentDelayMs` (60s). Nothing in the message changes it.

Settings are upstream's keys, read as `getRetrySettings()` reads them (`Settings::retrySettings()`):
`retry.enabled` (true), `retry.maxRetries` (3 — and 0 means none), `retry.baseDelayMs` (2000),
`retry.maxAgentDelayMs` (60000). The provider layer's are `getProviderRetrySettings()`'s
(`Settings::providerRetrySettings()`): `retry.provider.timeoutMs`, `retry.provider.maxRetries` and
`retry.provider.maxRetryDelayMs` (60000, the legacy `retry.maxDelayMs` counting for it), which the
session puts on every request (`AgentSession::buildRequestOptions()`, upstream's
`buildRequestOptions()`), with `httpIdleTimeoutMs` (300000, `disabled` = 2147483647) as the default
`timeoutMs`.

## Carrying on a run that stopped inside a tool call

`continueRun()` used to refuse a conversation that ended on tool calls, so a scheduled run the daemon
was in the middle of failed whenever the stop came during a tool — which is most of a run's time.
pi-durable's answer is a replay policy on each tool (`replay?: "safe" | "unsafe"`, omitted
`unsafe`): after a restart a `safe` call runs again, any other is answered with an `interrupted`
error result and the model decides. pig takes the policy, not the runtime: the session file already
holds the call before the tool runs (the assistant message is written on `message_end`), so it is
the intent record pi-durable writes, and nothing new is stored.

- **`Pig\Agent\ToolReplay`** (`Safe`/`Unsafe`) and **`ReplayableTool`**, optional as the field is;
  `read`, `grep`, `find` and `ls` are `Safe`. Each wrapper (`HookedTool`, `DescribedTool`,
  `ResultImagesTool`, `WrappedCustomTool`) forwards `ToolReplay::of()` of what it wraps, or the
  declaration is lost one layer down. An extension declares it with `CustomTool(replay: ToolReplay::Safe)`.
- **`AgentLoop::resume()` / `Agent::resume()`**, pig's own; `continue()` stays upstream's and still
  refuses an assistant message. `unansweredToolCalls()` is the last assistant message's calls with
  no result after it — a crash between two tools leaves some answered and some not, and before this
  `continue()` went ahead and the provider layer answered the rest `No result provided`.
- **Only the first unanswered call can have been running**: tools run one after another and each
  result is written when it ends. That one is `Safe` → run again, otherwise (or its tool is gone)
  `Tool <name> was interrupted and may have partially run`, pi-durable's wording. The calls after it
  never started and run as they would have.
- **The hooks run again**, where pi-durable skips `beforeTool` on recovery: it stores the arguments
  the hooks settled on, pig's file only has what the model sent, so a `tool_call` guard that blocked
  or rewrote the call has to be asked again.
- **What the call had printed comes first in that result** (pi-durable's `fromSlot()`: the output
  so far, then the notice). pi-durable commits it to its `pi.live` document as the tool reports;
  pig writes it to `<session>.running.json` beside the session file (`Session\ToolProgress`) — the
  session file stays pi's — rewritten whole from each `tool_execution_update` at pi-durable's pace
  (100ms apart at least, held back further by its size at 100KB/s), kept to `Truncate::tail()`, mode
  0600, and removed when a tool result is written. A nested call's updates (`parentToolCallId`) are
  its caller's business and are not kept. Read only when its `toolCallId` is the interrupted call's;
  deleting the session from the picker or the page deletes it too.

**Opening one is not running it.** `AgentSession::isStoppedMidRun()` — the last message is the
question or a tool result, or a call has no result — is narrower than what `continueRun()` carries
on: a turn that failed or was stopped with escape ended where somebody saw it end. The TUI says
`This conversation stopped in the middle of a run. /continue carries it on.` after the replay at
startup and after `/resume`; the page reads `isStoppedMidRun` from `get_state` (pig's field) and
draws the same line with a Continue button that sends `continue`. Nothing runs on its own, because a
conversation opened a month later is not one to start running commands in. The replay keeps the rows
of the unanswered calls (`InteractiveMode::$tools`, the page's `toolCards`), so the resumed call is
answered in the row already drawn rather than in a second one.

Regression tests: the six `Resume*`/`AnInterruptedCall*`/`WhatTheInterruptedCall*` cases in
`AgentLoopTest`, `ToolProgressTest`, the four `continueRun` cases about tool calls in `AgentSessionTest`
`SessionPickerTest::testAToolsKeptOutputGoesWithItsSession`,
`AgentSessionTest::testAConversationStoppedMidRunIsOneAProcessLostNotOneSomebodyStopped` and
`InteractiveModeTest::testAConversationThatStoppedMidRunSaysSoAndContinueCarriesItOn`.

## Finding the one you want

Thirty conversations under one project, each named by whatever its first line happened to be,
and the arrow keys as the only way through them. So `Cli\SessionList` is upstream's
`session-selector.ts` — header, list and search box in one component, drawing its own rows (a fork
tree needs a prefix per row).

**Typing is always the search**, and the list's own keys are keybinding ids, so there is no focus to
lose: up/down/page keys and Enter move and open, Escape is "not this session" and Ctrl+C "not pig";
`tui.input.tab` switches between this folder's sessions and every project's; `app.session.toggleSort`
(ctrl+s) cycles threaded → recent → fuzzy; `app.session.toggleNamedFilter` (ctrl+n) keeps named
sessions only; `app.session.togglePath` shows paths; `app.session.rename` (ctrl+r, only from
`/resume`, which has a rename handler) and `app.session.delete` (ctrl+d — confirmed, the current
session refused, trash before unlink). The query language is `Cli\SessionSearch`, upstream's
`session-selector-search.ts`: fuzzy words, a `"quoted phrase"`, or `re:<pattern>` (one that does not
compile matches nothing). At startup Escape is upstream's `No session selected`, exit 0, and the
list opens even when this project has no sessions, because Tab can still reach the others.

**`/resume` from inside a session shows this same list**, and for a while it did not. It built its
own rows into a plain `SelectList` — so the searchable list existed, was already ported, and was
reachable from exactly one of the two places that need it, which is this audit's commonest shape.
Upstream draws the searchable selector in both. The thirty-conversations problem is worse inside a
session than at startup, not better: the overlay is eight rows tall. Escape and ctrl+c both close
the overlay there — the distinction `bin/pig` needs has nothing to answer inside a running
session, where the conversation is still behind the overlay either way.

What it matches is the id, the name, the working directory and `SessionInfo::$text` — upstream's
`allMessagesText`, every text block of
every user and assistant message run together, collected by `SessionManager::describe()` while
it is already walking the file for the count. Three things are left out of it and each matters:
**thinking**, because it is the model talking to itself and a hit on it finds a conversation by
something nobody ever read; **tool results**, because they are mostly files and a search across
every file ever read matches everything; and the **formatting**, because this is a haystack and
never a thing to show. What anybody remembers about a conversation three days later is a phrase
from the middle of it, which is the whole reason the field exists — matching the opening alone
would only find the sessions that are already easy to recognise in the list.

`Pig\Tui\Fuzzy` is upstream's `packages/tui/src/fuzzy.ts`, arithmetic for arithmetic: every character of the
query in order, somewhere in the text. **The score is a pile of penalties, so lower is better**
— a run of consecutive characters is rewarded and the reward grows along the run, gaps cost,
the start of a word is worth a lot, and a late match costs a little. A space-separated query is
several searches over the same text and all of them have to hit, which is what makes a
transcript-sized haystack narrowable at all.

Five things about it, and all of them have now been **run** against `fuzzy.ts` rather than read —
3,078 comparisons, which is where the entry on the search box came from:

- **The walk is over characters, not bytes, and not code units either.** `$text[$i]` in PHP is a
  byte, so a Chinese query would compare thirds of characters and score nonsense: `好` is the second
  *character* of `你好` and its fourth, fifth and sixth bytes, which a byte walk reads as a
  three-character run near the end of the string and scores about -13.8 instead of 0.1. This used to
  say code units and characters are the same "for everything either project searches" and **the
  corpus refuted it**: an astral character is two code units and one character, so every position
  after an emoji differs. pig's is the better answer and the reason is on the class.
- **The whitespace class is the union of PCRE's and JavaScript's**, which differ by exactly three
  codepoints — see the trap entry, and the bug that was hiding behind the tokenizer not being `/u`
  at all.
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

`Cli\ModelList` is `Fuzzy`'s second reader and upstream's `cli/list-models.ts`: `--list-models`
lists the registry, `--list-models gem pro` narrows it, fuzzily, over `"{provider} {id}"` as one
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

`--list-models` is on neither of `Arguments`' lists: it takes a search only when one follows,
upstream's rule, and it prints and exits, so there is no prompt left for it to eat.

`FakeTerminal::queue()` was added for its test. A component that starts its own loop and
blocks until it is answered has no "after the call" to type into, so the keys go in first and
arrive through `Loop::defer()` once the screen is open — which is also what a real terminal
does with whatever was in its buffer when the program took it over, and it keeps the loop
from deciding it has nothing to do and returning before anybody has typed.

The short options are upstream's, every one (`Arguments::SHORT`, the two- and three-letter ones
included). **A short option is an abbreviation and nothing else** — it resolves to its long name and
then goes through the same handling, which is what keeps `-t list` and `--tools list` from coming to
mean different things; see the trap below.

`--resume` takes no value, as upstream's: it always shows the picker, and `-r` with no terminal is an
error. A particular conversation is `--session <id|path>` — an id prefix or a path, this project's or
another's (another project's is offered as a fork into this one, `[y/N]`, on standard error when
nothing can answer) — `--session-id <id>` opens that exact id or creates a session with it, and
`--fork <id|path>` copies one into a new file here (`SessionManager::locate()`, `forkFrom()`).

`Cli\Arguments` is upstream's `cli/args.ts`, and it lives in the package rather than in
`bin/pig` for one reason: a script that calls `exit()` cannot be called twice by a test, and
the parser now has something worth testing. See the trap below about the flag that ate the
prompt. `--` ends the options, and everything after it is positional — a message even when it starts
with a dash, and still a file when it starts with an `@`, as upstream's. Piped standard input is
part of the first message (`Cli\InitialMessage`: standard input, then the `@file` text, then the
message).
