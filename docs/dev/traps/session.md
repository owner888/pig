# Known traps: Sessions

The session file, compaction, branches, resuming, the system prompt, retries. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [`--continue` opened the conversation you were not working on](#--continue-opened-the-conversation-you-were-not-working-on)
- [A handover carried the files it was supposed to be summarising](#a-handover-carried-the-files-it-was-supposed-to-be-summarising)
- [A token count measured before a compaction describes a conversation that no longer exists](#a-token-count-measured-before-a-compaction-describes-a-conversation-that-no-longer-exists)
- [An HTML export coloured its code with a palette from neither theme](#an-html-export-coloured-its-code-with-a-palette-from-neither-theme)
- [One field in the session file was not pi's shape](#one-field-in-the-session-file-was-not-pis-shape)
- [`Agent::continue()` turned a misuse into a fabricated turn in somebody's conversation](#agentcontinue-turned-a-misuse-into-a-fabricated-turn-in-somebodys-conversation)
- [Switching models in a session must not clobber the user's default model in settings.json](#switching-models-in-a-session-must-not-clobber-the-users-default-model-in-settingsjson)
- [Going back to a question kept the question](#going-back-to-a-question-kept-the-question)
- [Compaction counted an image as nothing, so a conversation of screenshots could not be compacted](#compaction-counted-an-image-as-nothing-so-a-conversation-of-screenshots-could-not-be-compacted)
- [An abandoned branch was in the file and unreachable](#an-abandoned-branch-was-in-the-file-and-unreachable)
- [A new session recorded no thinking level, so `--continue` came back on `off`](#a-new-session-recorded-no-thinking-level-so---continue-came-back-on-off)
- [A session that can change conversations cannot have a `readonly` session file](#a-session-that-can-change-conversations-cannot-have-a-readonly-session-file)
- [`pig -p` printed the 503 and exited, while the retry that would have worked went with it](#pig--p-printed-the-503-and-exited-while-the-retry-that-would-have-worked-went-with-it)
- [A compaction refunded the bill](#a-compaction-refunded-the-bill)
- [A message typed while the conversation was being summarised was lost with a red line](#a-message-typed-while-the-conversation-was-being-summarised-was-lost-with-a-red-line)
- [`/new` during a retry left the old conversation's retry running in the new one](#new-during-a-retry-left-the-old-conversations-retry-running-in-the-new-one)
- [An abort with nothing yet to abort reports a cancellation that did not happen](#an-abort-with-nothing-yet-to-abort-reports-a-cancellation-that-did-not-happen)
- [The three files that can lose a conversation without saying so](#the-three-files-that-can-lose-a-conversation-without-saying-so)
- [pi pruned a message from the model's context and pig sent it anyway](#pi-pruned-a-message-from-the-models-context-and-pig-sent-it-anyway)
- [A crash is written down, so `/bug` has something to attach](#a-crash-is-written-down-so-bug-has-something-to-attach)
- [`/quit` while working left the agent, bash, retries and compactions running in the background](#quit-while-working-left-the-agent-bash-retries-and-compactions-running-in-the-background)
- [`SessionManager::describe()` must stream the file, or scanning several 10MB+ sessions hits PHP's 128MB OOM](#sessionmanagerdescribe-must-stream-the-file-or-scanning-several-10mb-sessions-hits-phps-128mb-oom)
- [`SessionManager::open()` must stream a huge session to avoid the 128MB OOM](#sessionmanageropen-must-stream-a-huge-session-to-avoid-the-128mb-oom)
- [O(N²) array reallocation walking the session tree and a full-file scan in `latestFor` made `pig -c` start very slowly](#on²-array-reallocation-walking-the-session-tree-and-a-full-file-scan-in-latestfor-made-pig--c-start-very-slowly)
- [Failed / aborted assistant turns were replayed as they were](#failed--aborted-assistant-turns-were-replayed-as-they-were)
- [Session auto-retry classifies by upstream's text patterns, reading neither status codes nor wait times in the error](#session-auto-retry-classifies-by-upstreams-text-patterns-reading-neither-status-codes-nor-wait-times-in-the-error)
- [The system prompt and tools are `SystemMessage`s in the transcript, not two fields on `Context`](#the-system-prompt-and-tools-are-systemmessages-in-the-transcript-not-two-fields-on-context)
- [Compaction and branch summaries retry too, and `agent_end` says whether a retry follows](#compaction-and-branch-summaries-retry-too-and-agent_end-says-whether-a-retry-follows)
- [The compaction and branch-summary loaders read as upstream's, saying that escape cancels](#the-compaction-and-branch-summary-loaders-read-as-upstreams-saying-that-escape-cancels)
- [Summary requests do not hard-code `ReasoningEffort::High`](#summary-requests-do-not-hard-code-reasoningefforthigh)
- [The `cwd` section is the directory alone, with no date or time](#the-cwd-section-is-the-directory-alone-with-no-date-or-time)
- [Resuming and tree navigation restore the tool set from the transcript; a forced prompt is projected, never recorded](#resuming-and-tree-navigation-restore-the-tool-set-from-the-transcript-a-forced-prompt-is-projected-never-recorded)

## `--continue` opened the conversation you were not working on

The most-used flag in the tool, wrong in the most ordinary way there is. `listFor()` sorted the
session files by **name**, with a comment arguing that the names sort themselves because they begin
with an ISO timestamp. They do — the timestamp the conversation *began* at, and a session file is
never renamed. So "newest" meant **most recently started**, where `--continue` asks for the one last
worked on.

Start A on Monday, start B on Tuesday, spend Wednesday in A. Measured through `latestFor()`:

```
A  2026-09-28T03-27-14-295Z_…jsonl   mtime=1790566095   ← worked on today
B  2026-09-28T03-27-15-296Z_…jsonl   mtime=1790566035
pig --continue picks:  B
upstream would pick:   A
```

Upstream's `findMostRecentSession` stats each file and sorts by `mtime`. It also decides the order of
the `--resume` picker, so the row under the cursor was the stale one — and `Tui\Fuzzy`'s own note
leans on that order ("the list is sorted newest-first before it gets here, and ties coming back
shuffled would look like the list had lost its order").

Three things worth keeping:

- **The name breaks a tie**, so two files written in the same second have a fixed order rather than
  whatever the directory hands over. `usort` is stable, so mtime alone would fall back to glob order
  — which is alphabetical, which is the bug again for exactly the files a tie affects.
- **A path that has gone between the `glob()` and the `filemtime()` is skipped**, not stat-ed. `@` is
  not allowed here and a file that vanished is not a session to list.
- **Both READMEs already said the right thing** — "`--continue` picks up the last one", "pick up
  where you left off", 「接上最近一次」 — so nothing there needed changing. *A sentence in the README
  describing intent is a test nobody runs*: it had been false since the flag existed.

Found by reading `session-manager/file-operations.test.ts` as a specification — the `it()` named
"returns most recently modified session", which is a claim about `mtime` and was the one line in that
file pig had no counterpart for.

Regression tests: `SessionManagerTest::testContinueOpensTheOneLastWorkedOnRatherThanTheOneStartedLast`
and `testSessionsWrittenInTheSameSecondDoNotShuffle` — one per end, and the second is what stops the
fix becoming "sort by mtime and let ties shuffle".

## A handover carried the files it was supposed to be summarising

`BranchSummarization::prepare()` drops tool results, because a result's context is in the assistant
message that asked for it and results are the bulk of a long branch. It dropped them **inside the
budget walk**, and the method short-circuits when the budget is 0 — which means "no limit" — so a
model that declares no context window got the whole branch *plus* every file it had read. Measured on
one 28KB `read`:

```
budget=0       kept=4  tool results kept: YES  request chars=28808
budget=100000  kept=3  tool results kept: no   request chars=792
```

36× the request, and with a real file the whole of it: the model is asked for a handover and handed a
file dump to write it from. Upstream drops them in `getMessageFromEntry()`, before any budget is
looked at, which is where the reason applies — **it is a rule about what a handover is made of, not a
way of saving room.** A rule gated on a budget is a rule in the wrong place.

Not reachable from `bin/pig` today: `CustomModels` refuses a `contextWindow` that is not positive and
no built-in has one, so this is latent on public surface — the same standing as `Input::setValue()`'s
cursor and the three image-encoder falsiness sites. The reason to fix it rather than note it is that
`Compaction::shouldCompact()` already carries a `contextWindow > 0` guard *because* a model declaring
no window is a case pig has acknowledged as real.

**And upstream has the mirror bug in the same function, which pig does not.** `prepare()`'s docblock
has always said the file lists come from every message while the prose comes from as much as fits.
Upstream's first pass collects only a nested branch summary's carried-forward lists;
`extractFileOpsFromMessage()` is in the **second** pass, inside the loop that `break`s at the budget
— so every ordinary message older than the cut contributes no files at all, while its own comment
says "collect file ops from ALL entries". A long branch there reports the files touched at its recent
end and silently forgets the rest, which is exactly the failure pig's arrangement was written to
avoid: a summary that forgets a file it edited sends whoever reads it to a file on disk that no longer
matches.

`prepare()` had **no direct test at all** — the same size of unchecked unit as `Config`'s path
arithmetic and `Process`'s 370 lines. Regression tests:
`CompactionTest::testAToolResultIsNeverPartOfAHandover` over three budgets including 0, and
`testTheFileListSurvivesABudgetThatDropsTheMessageThatNamedIt`, which is the one that goes red if the
file lists are ever moved behind the budget into upstream's arrangement.

## A token count measured before a compaction describes a conversation that no longer exists

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

## An HTML export coloured its code with a palette from neither theme

`HtmlExport::style()` had the six `hl-*` colours written out as hex — `#b5bd68`, `#b294bb`,
`#81a2be` and three more — and they belong to **neither of pig's two palettes**. What that costs
is worst on the light theme, where the export is a white page and those are colours chosen for a
dark one: pale blue keywords and mauve numbers, at a contrast nobody would pick. On the dark theme
it is milder and still wrong — an export that does not match the terminal the person had just been
reading.

The colours were right there: `Themes::getResolvedThemeColors()` hands every token of a theme over
as hex (upstream's export path), and `style()` generates the seven rules from `SYNTAX`, which is
the one place that has to know what `Highlight`'s class names mean.

The two *page* palettes stay written out, and that is not the same decision: a document needs a
card, a border and a rule that no terminal theme has an opinion about. What is shared is what both
media are colouring the same thing for.

Regression test: `HtmlExportTest::testTheSyntaxColoursAreTheThemesOwnInBothThemes`, which asserts
each theme's own `syntaxKeyword` **and** that the old hex is gone.

## One field in the session file was not pi's shape

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

## `Agent::continue()` turned a misuse into a fabricated turn in somebody's conversation

Upstream checks two things before starting a loop that adds no message: there has to *be* a
conversation, and the last message must not be the assistant's — a provider rejects a request that
ends on its own turn. pig had both checks, in `AgentLoop::continue()`, and not the one place that
matters.

`Agent::run()` wraps the whole loop in a `try`, and the loop's guards throw **synchronously**,
before the fiber starts. So the throw landed in `run()`'s `catch`, which does what it is there for:
turns a failed turn into an `AssistantMessage` with `stopReason: error`, appends it to the
conversation, sets `state->error` and emits `agent_end`. The caller saw nothing thrown. What a
misuse of the method left behind was **an assistant turn nobody's model produced, in the session
file**, saying "Cannot continue: no messages in context".

The two guards are in `Agent::continue()` now, where upstream has them, and the loop keeps its own —
it is a separate entry point and a public one. The rule this is an instance of: **a `catch` wide
enough to turn any failure into a message has to be narrower than the argument checks**, or every
programming error becomes conversation. Nothing in pig relied on the old behaviour; the suite was
green before the tests for it were written, which is exactly why it had survived.

## Switching models in a session must not clobber the user's default model in settings.json

The entry that used to be here claimed upstream always writes `setModel()` to `settingsManager`.
That was false: upstream's `agent-session.ts` guards with `if (options.persist)` and `persist`
defaults to **false**. In pig, `$persistAsDefault` defaulted to `true`, so every `/model <name>`
command, every `Ctrl+P` cycle, and every `Enter` on the model picker silently rewrote `defaultModel`
and `defaultProvider` in `~/.pig/agent/settings.json`.

What that cost: somebody with a configured default (e.g. `antigravity/gemini-3.8-flash • medium`)
who switched to `google/gemini-3.8-flash` for one session had their settings file clobbered with
`google`, and next time they opened a new session (`pig`), it reopened on `google` instead of
restoring their default. pi never touches `settings.json` on a session switch: the change is
recorded in the session log (`model_change`) so that conversation remembers it, while a fresh
session always opens on the configured default.

`setModel()` and `setThinkingLevel()` now default `$persistAsDefault` to `false`. Switching via
`/model <name>`, `Enter` in the picker, or keyboard cycling (`Ctrl+P`, `Shift+Tab`) modifies only
the active session. The model picker supports `Ctrl+S` (`app.models.save`) to explicitly set the
chosen model as the persistent default, and `/settings` continues to persist changes.

## Going back to a question kept the question

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

## Compaction counted an image as nothing, so a conversation of screenshots could not be compacted

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
4800 in both, taking the right one of upstream's two answers to the same question. (Upstream's comment there
says "4000 chars, or 1200 tokens" while the code says 4800; 4800 is what divides into 1200, so the
code is the half that was meant.)

Worth keeping in mind for the rest of the audit: **no test failed when either hole was open**, and
the existing `testEveryKindOfMessageHasASize` walked five message types and asserted `> 0` on each —
it just never listed the sixth. A test that enumerates cases by hand goes stale exactly where a new
case was added.

## An abandoned branch was in the file and unreachable

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

Three more the audit found in the list itself, all in what a row is made of:

- **What is typed had nothing on screen.** `search()` was there, public, documented "for whoever
  draws the search line" — and nobody drew it. Upstream has a component of its own for it, wired to
  the list's `getSearchQuery()`, which is one more thing to wire at one end only; here the second
  end was simply never written. So the rows narrowed and nothing said why: a typo could not be
  seen, an empty filter could not be told from a query that matched nothing, and backspace was
  blind. `TreeList::render()` draws it now, because the list owns the query — **always, even
  empty**, since the word `Search:` is what says typing does anything, and a query longer than the
  terminal is cut like every other line here.
- **Thinking counted as what a row says.** `textOf()` collected `ThinkingContent` along with text,
  and two things read it: the row, and the default filter's "did this turn say anything". Thinking
  plus a tool call is what most tool-calling turns look like on a provider that returns its
  reasoning — so the default tree grew a row of the model talking to itself between every question
  and its answer, and a search matched text nobody had ever read. It is out, which is upstream's
  rule and pig's own: `SessionInfo::$text` leaves thinking out of the *session* search for exactly
  this reason, and a tree is a list of points somebody might go back to. `[image]` stays in, and is
  the one addition — a screenshot pasted with nothing said about it is a point worth going back to,
  where upstream draws `user: ` and nothing after it.
- **A cleared name drew `[label: ]`.** Nothing is deleted from a session file, so clearing a name
  writes a `Label` entry with a null label, and its row under the `all` filter read like a
  rendering fault. Upstream's word for it is `(cleared)`.

## A new session recorded no thinking level, so `--continue` came back on `off`

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

## A session that can change conversations cannot have a `readonly` session file

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
one; `--no-session` stays null and `/new` does not quietly start saving. Which of the three
happens is `AgentSession::switchTo()`/`startNew()`'s to decide now, not each mode's — the audit
find below ("RPC's session switch and new-session skipped the three checks") says why.

**The comment in `newSession()` claimed this was deliberate** — "the same file: `/new` forgets
the conversation but keeps writing where it was writing" — which is how a bug survives a
reading. A comment that explains why something surprising is correct is worth exactly as much
as the reasoning in it, and that one had none.

Regression tests: `InteractiveModeTest::testWhatIsSaidAfterResumingIsWrittenToTheFileThatWasResumed`,
`testANewSessionGetsAFileOfItsOwn`, `testANewSessionWritesNothingWhenNothingWasBeingWritten`.
All three were checked against the old code and fail on it.

## `pig -p` printed the 503 and exited, while the retry that would have worked went with it

Upstream's `AgentSession.prompt()` ends with two lines and pig had ported one:

```ts
await this.agent.prompt(messages);
await this.waitForRetry();
```

pig waited for a retry **on the way in** — `prompt()`'s first statement, with the reason beside it —
and not on the way out. So `prompt()` returned the moment the agent's run ended, which for a turn
that failed with a 503 is *before the answer exists*: the retry has been decided and has not slept
yet.

Three of the four modes were only cosmetically wrong — the answer arrives, just after the call that
asked for it, and the events carry it to the screen or the host. **`-p` is the one that ends the
process.** `PrintMode::run()` loops `prompt()`, then prints the last assistant message, then
`bin/pig` exits — so with `retry.enabled` on by default, `pig -p "…"` against a busy provider printed
`Anthropic returned 503: overloaded` on standard error and exited 1, and the second attempt two
seconds later, which would have worked, never happened. A script's whole answer, lost to a pause.

The fix is upstream's shape: `prompt()` runs the turn through `runAgentPrompt()`, whose post-run loop
does the retry (`prepareRetry()`), the overflow summary (`checkCompaction()`) and the queued
messages in the caller's fiber, and only returns after `emitAgentSettled()`. So `prompt()` returns
when the prompt has settled, and there is no handle to create early or release late.

**Five retry tests had to change shape, and that is the real cost of the fix being in the right
place.** `Async::run(fn () => $session->prompt('hi'))` was how every one of them got control back
with a retry still in flight, and `prompt()` now does not return until the retries are done — so
those tests either sat through a half-minute sleep or came back to a retry that was already over.
They spawn now, through one documented helper, `startTurnAndParkOnTheRetry()`, which waits for the
**`RetryStartEvent`**: that is the moment the retry is parked on its timer.

**And the overflow half is the same bug by the other door**: a turn that outgrew the window comes
back as an error too, and `-p` printed `prompt is too long` and exited before the summary that fixes
it had run. The same loop covers it — `checkCompaction()` runs before `prompt()` returns.
`isRetrying()` is true only while `prepareRetry()` holds its controller, or it would answer true
every time the session summarised.

Regression tests: `PrintModeTest::testA503IsWaitedOutRatherThanPrintedAsTheAnswer` and
`testAnOverflowIsSummarisedRatherThanPrintedAsTheAnswer` for what the person sees, and
`AgentSettledTest` for the settle happening once, after the retries and the summary.

## A compaction refunded the bill

`/session` prints what this conversation has cost and the footer keeps a running total, and both
came from `AgentSession::stats()`, which summed **the messages in memory**. Compaction replaces what
it summarises with one `CompactionSummary` carrying no usage — so the moment a conversation got long
enough to need compacting, the number that says what it cost dropped back towards zero. Reproduced:
three turns at 0.25 each read `$0.750`, and one `compact()` later they read `$0.000`.

**Upstream has both answers and does not notice.** Its footer sums `sessionManager.getEntries()` —
the file — and its own `getSessionStats()`, which is what `/session` prints, sums `state.messages`.
Two readers of one question, in one tool, disagreeing exactly after a compaction; pig had copied the
wrong one into both places.

The split that settles it: **the counts are about the conversation and the money is about the
session.** After a compaction the conversation *is* one summary and whatever was kept, so "you said
three things" about a transcript showing one answers a question nobody asked — counts stay on
`messages()`. The money is what a provider charged, and every assistant message in the file came
back from one, including on a branch later walked away from. Neither compacting nor changing your
mind is a refund, so the total comes from `SessionManager::everyMessage()` — every entry, file order,
no branch to walk. With no file at all (`--no-session`) there is nothing else to count and the two
questions share an answer again.

Regression test: `AgentSessionTest::testACompactionDoesNotRefundWhatTheSessionSpent`, which asserts
the money survives *and* that the counts do not — the second half is what stops the fix being
"sum everything over the file" and calling a two-message transcript six messages long.

## A message typed while the conversation was being summarised was lost with a red line

`Editor::$disableSubmit` is honoured, `CustomEditor::disableSubmit()` is public, and **nothing in
pig ever called either** — the third piece of dormant machinery found in one read, after
`onDebug` and ctrl+p before it. Upstream has exactly one use for it, and it is the one
window where a message can neither be sent nor queued: a conversation being summarised out from
under it.

What it cost, reproduced through the real screen: the submit handler clears the editor and spawns
the turn, `prompt()` parks on the compaction's own handle, and then the compaction's carry-on starts
a run — so the parked turn wakes up to `Agent is already working. Use steer() or followUp().`, which
`sendAndWait()` draws as a red line. **The text was already gone from the editor, so what the person
typed is nowhere at all.**

Before `prompt()` learned to wait, the same keystroke was wrong the other way round: the turn went
out *during* the summarisation and the compaction's own `continue()` was the thing refused. Two
arrangements, two losses; the flag is the answer that loses nothing, because the keystroke never
becomes a submit and the line stays where it was typed.

Wired on `CompactionStartEvent` and off on `CompactionEndEvent` when their `reason` is not `manual`,
which is upstream's pair.
Not on the manual `/compact`, and upstream does not either: that one was started by somebody who is
at the keyboard and just typed it, where the auto one arrives in the middle of their sentence.

**The flag's own docblock said it was for "while the agent is working", which pig does not use it
for and never did** — a message typed mid-run is *queued*, because the person watching a tool run is
exactly who has something to add. The fourth shape from the index, on a field.

The mutation check took three goes here, and the third one is the lesson. Removing the *re-enable*
broke nothing at first; then it broke nothing again, because the test asserted the typed line was
on screen — and with submit still disabled it is on screen, in the editor instead of in the
transcript. **An assertion that holds either way is not an assertion.** What only happens if the
turn ran is the model's reply, so that is what it asserts now.

Regression test: `InteractiveModeTest::testEnterDuringAnAutoCompactionKeepsWhatYouTypedRatherThanLosingIt`,
whose two halves fail separately when each end of the flag is removed. The harness gained one thing
for it — `holdTheAgent(letThrough: 3)`, so the call that is held can be the *summariser* rather than
the first turn.

## `/new` during a retry left the old conversation's retry running in the new one

`startNew()` and `switchTo()` stop whatever is in flight before they throw the conversation away,
and both asked the wrong question first:

```php
if ($this->isStreaming()) { $this->abort()->await(); }
```

**A retry that is sleeping is not streaming.** `abort()`'s own docblock says so, two hundred lines
down — *"a retry that is sleeping has no agent to interrupt: the run is already over and the next
one has not started. Escape has to reach both"* — and `prompt()` says it a third time and deals
with it a third way, by waiting the retry out. So the rule was written down twice, with its reason,
and the two methods in front of it asked `isStreaming()` instead. Upstream calls `await this.abort()`
unconditionally in both.

What it cost: press `/new` in the two-to-eight seconds a retry is waiting, and the retry wakes up in
the conversation that replaced the one it was rescuing. The agent has been emptied by then, so
`continue()` refuses and `carryOn()` turns the refusal into `RetryEndEvent` — **"Retry failed:
cannot continue, no messages in context", in a brand new conversation nobody has said anything in
yet.** Type something first and it is worse: the retry lands mid-turn, `continue()` refuses for
being busy, and the same red line arrives on top of an answer that is still streaming.

Both call `abort()` unconditionally now, which needs no `isStreaming()` of its own: `abort()` on an
idle agent does nothing, `abortRetry()` returns early when nothing is being retried, and
`waitForIdle()` on an idle agent hands back a completed future — so the `await()` does not even
require a fiber, and no caller changed.

**The two tests that were written first passed while doing the wrong thing**, which is the part
worth keeping. Both ended in `self::settle()`, and the sleep in them is half a minute: the loop had
nothing else to poll, so `tick()` slept the timer out, the retry ended **by itself**, and
`assertFalse($session->isRetrying())` was true whatever `startNew()` had done. Thirty seconds per
case, green either way. They assert straight after the call now, with no settle at all — the same
failure as the `/label` test that asked about the leaf, and the same fix: *ask whether the assertion
could fail.*

Read in the same pass and **left alone**, so nobody re-derives it: `goTo()` and `compact()` have the
same `isStreaming()` guard and neither is this bug. Upstream's `navigateTree()` does not abort — it
has no streaming guard at all, pig's being its own — and `compact()` cannot, because pig reuses it
for auto-compaction (`compactAndCarryOn()` calls it from inside the retry machinery, so aborting the
retry there would kill its own caller) where upstream has a second implementation for that path.
Neither has a reproduced consequence; if one turns up, this is the entry.

Regression tests: `AgentSessionTest::testStartingANewSessionStopsARetryThatWasStillWaiting`,
`testSwitchingSessionStopsARetryThatWasStillWaiting` — one per method, and each stays green when the
other's guard is put back — and `testARetryDoesNotCarryOnIntoTheSessionThatReplacedIt`, which is the
one that reproduces what the person actually sees.

## An abort with nothing yet to abort reports a cancellation that did not happen

`abortRetry()` used to abort a controller created around the *sleep*, while `isRetrying()` was
already true from the moment the retry started. Between those two points escape said
"Retrying was cancelled.", reset the counter, completed the waiter — and the retry carried on,
slept, sent the request, and eventually announced a second, contradictory end.

The controller is now created together with the waiter, so there is never a moment where the
retry is running and nothing can stop it, and `carryOn()` checks the signal before starting a
run in case the abort arrived after the sleep finished. **Whenever a flag says "this is
happening", the thing that stops it has to exist by the time that flag is set** — not a line
later.

## The three files that can lose a conversation without saying so

Next tier after the ten, by the same rule, and the criterion had to widen a little: not *a file the
user cannot get back* but **a conversation that changes under somebody and never says it did**.
`Session/Compaction` decides what is thrown away, `Session/AgentSession` coordinates every way a
conversation is left or replaced, and `Settings` is what carries a person's choices to the next run.
1,301 mutations — 144, 985 and 172 — and the shapes are not the ones the earlier rounds found.
**208 survived and 168 after the tests below**: `Compaction` 40 to 33, `AgentSession` 126 to 114,
`Settings` 50 to 21.

**`Compaction`: the boundaries, and an assertion that could not fail.**

- **The compaction threshold was pinned on neither side.** `shouldCompact()` is the arithmetic that
  decides when a conversation gets summarised away, and the cases were 190k against a 200k window
  and 150k against the same — both a long way from the line. `> window - reserve` and `>=` both
  passed. Exactly `window - reserve` is still room for the answer the reserve is *for*, so that is
  the last size that does not summarise, and the pair is now the test.
- **The `<=` this file has a whole entry about was unpinned.** "A token count measured before a
  compaction describes a conversation that no longer exists" ends with *"`<=` rather than `<`: the
  summary is written straight after the turn it replaces and a millisecond holds both"* — and `<`
  passed, because every fixture had them a full second apart. The consequence is the one that entry
  opens with: the stale reading is believed, the session asks to compact before every turn, and the
  second attempt fails with `Already compacted` in front of everything the person types.
- **`request()`'s `<previous-summary>` block: the test asserted the tag and the tag is in the
  prompt.** `UPDATE_PROMPT` says "provided in `<previous-summary>` tags", so
  `assertStringContainsString('<previous-summary>', …)` holds whether or not the block was ever
  added — and inverting the condition that adds it left the model told to update a summary it was
  not given, which silently drops everything the first compaction said. It asserts the summary's
  *text* now. **Fifth time in this document that an assertion held either way**; the tell is always
  that the string being asserted appears somewhere else in the same output.
- Smaller, same file: a file list is de-duplicated and sorted on the read side and neither on the
  modified side, and a tool call whose arguments never arrived — an interrupted turn, half-parsed
  JSON — put a null in the list of files the summary claims were touched.

**`AgentSession`: 985 mutations, 859 killed in pass 1 — the best rate of any file swept, and the
126 that were left are concentrated in exactly the recipe this document already has an entry for.**
The entry is "RPC's session switch and new-session skipped the three checks the terminal's have",
and its fix moved the whole recipe into `startNew()` and `switchTo()`. What the sweep says is that
the recipe arrived without tests of its own: the cancellable `session_before_switch` hook could be
deleted from either method, the queue could stop being cleared, `restoreSettings()` could stop being
called on a resume, and the `session_start` event could stop being emitted — nine mutations across
the two methods, none of which any test could see. **A fix that moves a rule into one place still
needs the rule tested in that place**, which is the same lesson as "after wiring anything through,
mutate each end", one level up.

Two more in the same file, both about what reaches disk: a `!command`'s `BashExecution` is appended
to the session file directly, because there is no `message_end` to carry it — and the append could
be deleted, on both the immediate path and the flush that runs when a command was held back during a
turn. A conversation that reopens without what was run in it is missing the half that explains the
rest.

**`Settings`: the accessors, as a family.** This document already records `updateCheckEnabled()`
being "entirely unpinned"; the sweep says that was one of a set. Deleting the `set()` out of
`setHideThinking`, `setShowImages`, `setRetryEnabled`, `setQueueMode` or `setLastChangelogVersion`
broke nothing, and so did deleting the `get()` out of `queueMode()`, `lastChangelogVersion()`,
`hooks()` and `customTools()` — a setter that stopped writing leaves a screen agreeing with itself
and a file that does not, and a getter that stopped reading is a preference that silently does
nothing. Three table-driven cases cover the family instead of one test each: every switch out and
back, every default an untouched file means, and the four numeric accessors against the three ways a
hand-written file misses `is_int($value) && $value > 0` — **a quoted number** (the mistake
`models.json`'s `cost` block has its own entry for), a zero, and a negative. Under `strict_types` a
quoted number comes back out of a method declared `int`, which is a TypeError from somewhere that
has nothing to do with the file.

**One equivalent mutant worth writing down, because it looks like a gap.** `startNew()` and
`switchTo()` both guard `if ($refusal !== null && $refusal->cancel)`, and no mutation of the `&&`
can fail a test — `emitBeforeSwitch()` goes through `HookRunner::ask()`, whose decisiveness
predicate *is* `$r->cancel`, so a result that does not cancel never comes back at all. The two are
one rule in two places. Harmless, kept, and measured rather than assumed — and the test that covers
the behaviour (a hook that only watches does not block the switch) says on itself that it cannot
kill that mutation.

## pi pruned a message from the model's context and pig sent it anyway

Found by reading the developer's own session file — 24MB, written by pi 0.87, header `version: 3`
where pig's reader and writer knew only 2. The v3 diff is small and one piece of it is a whole
mechanism pig did not have: **`context_edit`**, an append-only line saying *what the model is shown
of an earlier entry has changed* — `replacement: null` omits it, a string or content list replaces
its content — while the entry itself stays in the file, the tree, the UI and the money. pi's
`buildSessionProjection()` applies the latest edit per target on the active branch; pig's rule for
a line it cannot read is "a node holding nothing, walked past", so **the target was walked past too,
and sent to the model whole.** Five of them in that file; all small, which is luck and not design —
the same entry is what pi writes when it prunes a tool result that has outlived its use.

**And the write side was pig's own gap, under a sentence this file already had.** *"The failed
message comes off the agent's state before the retry, and stays in the session file"* — true, and
it stops one step short: nothing in the file said it had come off, so **`--resume` put the failed
turn back into the agent's state.** Upstream's `_omitRecoveryAttempt()` writes a `context_edit(null)`
for exactly this. `AgentSession::dropLastAssistantMessage()` does now, through
`SessionManager::entryOf()` — identity on the message object, because the one `append()` was handed
on `message_end` is the one the agent's state holds — and `appendContextEdit()`, which refuses an id
the file has not got the way a label does.

Three things decided in the projection:

- **Edits are collected before the walk**, because an edit sits *after* the message it edits, and
  only edits on the path count — which is pi's "branch-relative" for free, since `pathTo()` is the
  branch. The test puts two edits of one target on the live branch and a third on an abandoned one.
- **A string replacement is one text block**, pi's rule for the roles whose content has to be an
  array, and the role and metadata are kept — a replaced tool result still answers its call id.
- **The kept range of a compaction is edited too**, or a pruned result inside the kept window would
  come back the moment the conversation was compacted.

**`VERSION` is 3 now, and `upgrade()` had to learn the difference between 1 and 2.** It read
`version < VERSION` as "v1" and ran the re-id-every-line path — correct for v1, destructive for a
v2 file that already has its tree. `upgradeFromV2()` is pi's `migrateV2ToV3()`: the header and the
one rename (`hookMessage` → `custom` on a message's role, which pig never wrote), nothing else
touched. Two tests pinned `version === 2` and both were pinning the number rather than the rule —
"pi's current version" — and say 3 now.

`usage` entries — pi's cache-warm accounting lines — are `Session\UsageEntry`: outside the
conversation, counted in `stats()`' cost, and written by pig's own cache warmer (see "Keeping the
prompt cache warm"). `systemMessage` on a compaction is read and
written now (`CompactionSummary::$systemMessage`), and a `system` message entry is an ordinary
message entry, as upstream's are.

Regression tests: `PiFormatTest::testAContextEditWithNullOmitsItsTargetFromTheModelAndFromNowhereElse`,
`testAContextEditWithAStringReplacesTheContentAndKeepsTheRole`,
`testTheLatestEditOnTheBranchWinsAndOneOnAnotherBranchDoesNot`, `testAnEditIsWrittenInPisShapeAndReadBack`,
`testAV2FileIsBroughtToV3WithoutReIdingAnything`, and
`AgentSessionTest::testAFailedTurnTakenOffTheStateStaysOffItWhenTheSessionIsResumed` — which goes
red when the `appendContextEdit()` in `dropLastAssistantMessage()` is removed. Verified against the
real file: 0 of the 5 pruned targets reach the model.

## A crash is written down, so `/bug` has something to attach

`CrashLog` is upstream's `core/crash-log.ts`: `~/.pig/agent/crashes.json`, five records, the newest
last, each with the time, version, kind, message, stack, session file and directory. Three writers
and two readers:

- **`bin/pig`'s `set_exception_handler`** writes `fatal_error`, hands the terminal back, prints the
  message with the throw site, and says how to report it — upstream's `handleFatalRuntimeError()`.
  Verified live with a malformed `trust.json`, which is an uncaught throw before any screen exists.
- **`InteractiveMode::reportLoopFailures()`** writes `loop_error` for a throw the loop *caught*.
  pig survives those and draws a red line, which is the entry on `Loop::setErrorHandler`; they are
  recorded anyway, because a thing the loop had to catch is a pig bug by definition and the person
  looking at the red line is exactly who `/bug` is for. Once per message, like the line.
- **`start()`** says the newest unnotified crash once, if it is under a week old — upstream's
  wording — and marks them all told.
- **`BugReport::build()`** attaches them under `## Recent crashes`, and **the file is cleared once
  a report is out** — an upload or an exported zip (`recordBugReport()`, which also appends the
  `pig.bug-report` entry), or RPC's `bug` command (`write()`) — as upstream clears it: attached to a report that was
  sent, they are not attached to the next one too.

`/bug` itself is upstream's flow: what went wrong, whether to include the transcript (a summary by
the current model instead when not), then **Upload Report** (to `BugReport::SERVER_ENDPOINT`, refused
under `PIG_OFFLINE`), **Export as Zip** (`pig-bug-report-<id>.zip` in the working directory, also
offered when an upload fails) or Cancel. The old clipboard copy, GitHub issue link and
`bug-reports/` file are gone from the interactive command; RPC's `bug` still uses `write()` and
`issueUrl()`.

**Everything in it is best effort, and that took two goes.** A crash log that throws is a second
crash with the first one lost, so `record()` swallows everything — the one place in `coding-agent`
allowed to. The first version still leaked a *warning*: `mkdir()` warns *and* answers false, and
`phpunit.xml`'s `failOnWarning` is what said so. A `set_error_handler` scoped to the write turns
the warning into the throw the swallow already covers; `@` is not allowed here.

And `getTraceAsString()` **starts at the caller of the throwing frame**, so the throw site — the
one line a report needs most — is not in it. The stack is `Class: message in file:line` and then
the trace, which is what the test asserts and what the first version did not have.

Regression tests: `CrashLogTest` (7), `BugReportTest::testRecentCrashesAreAttachedAndHandedOverOnceWritten`,
`InteractiveModeTest::testTheLastCrashIsAnnouncedOnceAtStartup` and
`testAThrowTheLoopCaughtIsWrittenDownForBug`.

## `/quit` while working left the agent, bash, retries and compactions running in the background

**Phenomenon**: When `/quit` or `/exit` was entered while the agent was `working` (streaming an LLM response, running tools, retrying, or compacting), the process failed to terminate in-flight work cleanly, and hooks like `system-notify.php` continued firing desktop notifications on turn completion.

**Cause**:
`InteractiveMode::stop()` calls `$this->session->dispose()`, `$this->tui->stop()`, and `Loop::get()->stop()`. However, `AgentSession::dispose()` previously only disconnected the agent listener and cleared `$this->listeners`. It omitted:
1. `$this->abortRetry()` (sleeping retry timers kept firing and starting new turns in the background)
2. `$this->abortCompaction()` (background compaction continued)
3. `$this->abortBash()` (running bash subprocesses kept executing)
4. `$this->agent->abort()` (the in-flight LLM stream and tool loops were never aborted)
5. `$this->clearQueue()` (queued follow-up and steering messages were not discarded)

As a result, background fibers and subprocesses continued running to completion and emitting events.

**Countermeasure**:
Aligned `AgentSession::dispose()` with upstream `agent-session.ts`'s `dispose()`: wrapped in a `try...catch (Throwable)` and explicitly invoke `$this->abortRetry()`, `$this->abortCompaction()`, `$this->abortBash()`, `$this->agent->abort()`, and `$this->clearQueue()` before unsubscribing and clearing listeners.

**Test**:
`InteractiveModeTest::testQuittingWhileTheAgentIsWorkingAbortsTheAgentAndStops`.

## `SessionManager::describe()` must stream the file, or scanning several 10MB+ sessions hits PHP's 128MB OOM

**Phenomenon**:
Loading a huge `.jsonl` session (16MB, 14MB — long context, compaction summaries and tool calls) in Web mode or in the session picker died with `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in SessionManager.php on line 144`.

**Cause**:
`SessionManager::lines()` used `file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)`, reading the whole file into one huge PHP array of lines, then shallow-copied it with `array_slice`. A PHP array of long strings costs 5–8× the file size in zvals and hash table; one 16MB session took close to 100MB as an array, and `describe()` over 30 sessions in one directory blew through the default 128MB `memory_limit` at once.

**Countermeasure**:
1. `SessionManager::describe()` parses line by line (`fopen` + `fgets`), decoding and releasing each line as it goes — constant memory, a few KB even for a 100MB file.
2. When `describe()` collects the search text `$said`, a `$totalSaidLength < 65536` cap stops tens of thousands of long messages from accumulating.
3. `HttpServer::listAllWorkspaces()` reads the first line's `cwd` with one `fgets($fh)` instead of `file()` on the whole file.
4. `bin/pig` and `bin/pig-ai` set `ini_set('memory_limit', '512M');` as a second line of defense.

## `SessionManager::open()` must stream a huge session to avoid the 128MB OOM

**Phenomenon**:
Resuming a session with thousands of turns or a lot of code diffs — 50MB to 65MB+ — threw `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in SessionManager.php on line 150`.

**Cause**:
`SessionManager::open()` relied on `SessionManager::lines()`, which read the whole 65MB, tens-of-thousands-of-lines JSONL file into one string array with `file($path)`, then doubled it in `array_slice` — over 150MB at once, past PHP's default 128MB.

**Countermeasure**:
`SessionManager::open()` reads line by line (`fopen` + `fgets`), parses each line into the session tree and frees the string at once; memory fell from 150MB+ to under 10MB, and a 65MB session opens in 1.7 seconds under the standard 128MB limit.

## O(N²) array reallocation walking the session tree and a full-file scan in `latestFor` made `pig -c` start very slowly

**Phenomenon**:
In a working directory with large session history (tens of megabytes, tens of thousands of turns), `pig -c` (resume the latest session) took 2.28 seconds to its first frame where upstream `pi -c` takes about 0.5 — more than 3.6× slower.

**Cause**:
Profiling found four (then five) heavy hitters:
1. **`SessionManager::latestFor()` scanned everything**: it called `listFor($cwd)`, which fully streams and `describe()`s the first 30 sessions. With a few large files in the directory that alone cost close to 400ms, when all that was needed was the one file with the newest mtime.
2. **`SessionManager::pathTo()` O(N²) memory shuffling**: the `while` loop walking from leaf to root did `array_unshift($path, $id)`. PHP's `array_unshift` is O(N) — it moves the whole block and renumbers every key — and on a chain of 28,000 nodes it ran 28,000 times: 718ms.
3. **`SessionManager::settings()` walked the whole tree forward**: it built the full `pathTo($this->leaf)`, then walked more than twenty thousand nodes root-to-leaf to find the last settings change: 725ms. The newest settings should be found by walking *up* the `parent` chain from `leaf` and `break`ing at the first model and thinking level seen.
4. **`SessionManager::resolve()` spread-copied in a loop**: `$messages = [...$messages, ...self::edited([[$id, $item]], $edits)];` deep-copies the existing array on every iteration — O(N²) allocation.
5. **`SessionEntries::decode()` did redundant work**: every line went through the slow `self::millis()` (`strtotime` plus `preg_match`) unconditionally, and on `message` lines — 99% of the file — the result was never used.

**Countermeasure**:
1. **`SessionManager::latestPathFor()` sniffs the first line**: as upstream's `findMostRecentSession` does, it reads only the header line instead of `describe()`ing a whole 71MB session — 206ms down to 1.6ms (128×).
2. `SessionManager::pathTo()` appends with `$path[] = $id` (O(1)) and does a single `array_reverse($path)` (O(N)) after the loop — 717ms down to 3ms (230×).
3. `SessionManager::settings()` walks up the `parent` chain from `leaf` and stops at the newest settings — 725ms down to 0.6ms (1200×).
4. `SessionManager::resolve()` appends with `$messages[] = ...`, no spread copy — 30ms down to 7ms.
5. `SessionEntries::decode()` calls `millis()` only for the non-`message` entries that need a timestamp.
6. Measured on the real 71MB, 28,000-line session, `pig -c` reaches its first frame in **489ms** instead of 2275ms — level with `pi -c` (440–540ms) and ahead of it in some runs.

## Failed / aborted assistant turns were replayed as they were

**Symptom**: on an OpenAI Responses model (gpt-5 / codex), pressing Esc after the reasoning item finished but before the body started, the next question replayed a turn holding a lone signed reasoning item followed by the user message — OpenAI answered 400 "reasoning was provided without its required following item" and the session was stuck. Other providers received half a body and a tool call with truncated arguments, plus a fabricated `No result provided` for it.

**Root cause**: in the pass that closes dangling tool calls, upstream's `transform-messages.ts` skips an assistant turn whose `stopReason` is `error` or `aborted` entirely (closing the previously pending calls first, then skipping); pig's `TransformMessages::fillOrphanedCalls()` lacked that step, and `OpenAiResponses`'s own guard knew `Error` but not `Aborted`.

**Trap rules**:
- Failed turns are filtered in `TransformMessages`, in upstream's order: `$flush()` first, then skip; the history a provider receives has no failed turns, so a new provider does not judge them again on its own.
- A failed turn's tool calls do not enter `$pending`, so no result is fabricated for them.
- Tests: `AnthropicTest::testAFailedTurnIsNotReplayedAndNeitherAreItsCalls`, `testCallsPendingFromBeforeAFailedTurnAreStillAnswered`, `OpenAiResponsesTest::testATurnAbortedAfterItsReasoningDoesNotSendTheReasoningBackAlone`.

## Session auto-retry classifies by upstream's text patterns, reading neither status codes nor wait times in the error

**Symptom**: pig had its own `Session\Retry`: it dug the HTTP status out of the error to decide on a retry, waited the `retry in 39s` written in the error, refused to retry past a 60-second wait and rewrote the message to `Quota reached…`; `retry.maxRetries: 0` counted as unset and retried 3 times anyway; there was a `before_retry` hook upstream does not have. None of it matched pi.

**Root cause**: upstream's `isRetryableAssistantError()` in `packages/ai/src/utils/retry.ts` uses only two text pattern tables (`RETRYABLE_PROVIDER_ERROR_PATTERN` / `NON_RETRYABLE_PROVIDER_LIMIT_ERROR_PATTERN`), the wait is `retryDelayMs()` (`baseDelayMs` doubling, capped at `maxAgentDelayMs`), and `settings.retry?.maxRetries ?? 3` accepts 0.

**Trap rules**:
- Classification lives only in `Pig\Ai\Utils\Retry`, both tables copied entry by entry from upstream, with no words of pig's own.
- A transport failure is retried only when reported in Node's words (`Connection error.`, `Request timed out.`, `fetch failed`, `terminated` — see `SdkRequest::errorMessage()`); a new failure path first asks how upstream's runtime would word it.
- `prepareRetry()` does `attempt--` when over budget and the post-run reports the failure; the cancellation text is `Retry cancelled`.
- An old `pig-antigravity` installed under `~/.pig/agent/extensions/` still subscribes to `before_retry` and fails to load with `No event called 'before_retry'` — update the global copy.
- Tests: `packages/ai/test/Utils/RetryTest.php`, `AgentSessionTest::testNoRetriesInTheSettingsMeansNone`, `testARetryBudgetThatRunsOutReportsTheAttemptsThatWereMade`, `SettingsTest::testTheRetryKeysAreUpstreamsSpellings`.

## The system prompt and tools are `SystemMessage`s in the transcript, not two fields on `Context`

**Symptom**: pig had one system prompt and one tool table; adding or removing a tool mid-session, a skill or `AGENTS.md` change, or a refresh of the `cwd` section resent the whole prompt (invalidating the whole cache); the session file did not record the prompt; RPC `get_messages` had no `system` messages.

**Root cause**: upstream puts the prompt and the tools in the message stream: the first `SystemMessage` (`sections` + `toolsAdded`, timestamp 0) is the prompt, later `SystemMessage`s carry only the changed sections (`null` meaning removed) and `toolsAdded`/`toolsRemoved`; a provider uses `resolveTranscript()` to decide between sending in place and collapsing into one (`supportsMidConvoSystemMessages`), and `resolveTranscriptTools()` to decide between loading in place and resending the whole table.

**Trap rules**:
- Providers receive only `TranscriptContext`; `Stream` folds an old `Context` into the first system message with `Transcript::normalizeContext()`. A new provider calls `resolveTranscript()` first, then `getInitialSystemMessage()`/`getCurrentTools()`, never `systemPrompt`/`tools`.
- Tool definitions are compared only with `Transcript::declarationsEqual()`: PHP has two spellings of an empty object, `[]` and `stdClass`, the session file reads back the former, and a direct comparison would redeclare every tool on every resume.
- Deltas come from `AgentLoop`'s `declareToolChanges()` and `AgentSession`'s `prepareNextTurnWithContext` (`SystemPrompt::diffSections()`); `Agent` has no `setSystemPrompt()` any more — changing the prompt means appending a `SystemMessage`.
- Compaction: the summarized part holds no `SystemMessage`; `CompactionSummary::$systemMessage` stores the one to replay, rebuilt in the order `[system, summary, …kept]`.
- The `context` hook does not see system messages (`HookRunner` removes them first and puts them back in place); use `context_with_system` to see them.
- Tests: `packages/ai/test/Utils/TranscriptTest.php`, `Providers/SystemMessagesOnTheWireTest.php`, the declare/prepareNextTurn cases of `AgentLoopTest`/`AgentTest`, `coding-agent/test/SystemMessageTranscriptTest.php`, `RpcModeTest::testGetMessagesRoundTripsThroughTheSessionCodec`.

## Compaction and branch summaries retry too, and `agent_end` says whether a retry follows

**Symptom**: one `overloaded_error` during compaction failed the whole compaction; the summary request carried none of the session's request options (provider retry, timeout, the extensions' payload hook); RPC/extensions receiving `agent_end` could not tell the session was about to retry.

**Root cause**: upstream's summaries go through `retryAssistantCall()` (the same `isRetryableAssistantError()` and backoff) with the session's `streamFn` and request options; the `agent_end` the session forwards carries `willRetry` (`_willRetryAfterAgentEnd()`).

**Trap rules**:
- Summaries go only through `Retry::retryAssistantCall()`, with the callback turned into a `SummarizationRetryEvent` (RPC `summarization_retry_*`); no loop written at the call site.
- `AgentEndEvent::$willRetry` is set only by `AgentSession`; the loop itself always emits false.
- Tests: `RetryTest::testRetryAssistantCall…`, `SystemMessageTranscriptTest::testASummaryRetriesATransientFailureWithTheSessionsRetrySettings`, `testAgentEndSaysWhetherTheSessionWillRetry`.

## The compaction and branch-summary loaders read as upstream's, saying that escape cancels

**Symptom**: during `/compact`, threshold compaction, overflow compaction and `/tree`'s branch summary the editor border said only `Summarising the conversation...` / `Summarising the branch...` without naming the cancel key, while the retry loader said `(escape to cancel)`. After a cancel the branch summary said pig's own `Branch summary cancelled — still where you were.`.

**Root cause**: upstream's `CompactionStatusIndicator` is `Compacting context... (${keyText("app.interrupt")} to cancel)` (manual), `Auto-compacting... (…)` (threshold), `Context overflow detected, Auto-compacting... (…)` (overflow); `BranchSummaryStatusIndicator` is `Summarizing branch... (…)`. On abort, manual reports the error `Compaction cancelled`, the automatic ones only the status line `Auto-compaction cancelled`; an aborted branch summary is the status line `Branch summarization cancelled` and reopens the tree on the entry that was selected (`showTreeSelector(entryId)`). Commit bff3d61 had removed these hints; they now follow upstream.

**Trap rules**:
- Wording goes only through `InteractiveMode::compactionLabel()` / `branchSummaryLabel()`, key names only through `Keybindings::keyText()`; `summarization_retry_attempt_start` uses the same two.
- pig's loader timer inserts ` · 5s` before the parenthesis; assertions on screen text must allow for it.
- Tests: `InteractiveModeTest::testCompactingSaysEscapeCancelsItAndEscapeDoes`, `testSummarisingTheBranchSaysEscapeCancelsItAndEscapeReopensTheTree`, `testEnterDuringAnAutoCompactionKeepsWhatYouTypedRatherThanLosingIt`.

## Summary requests do not hard-code `ReasoningEffort::High`

**Symptom**: compaction and branch summaries both requested high reasoning regardless of the session's thinking level or whether the model reasons at all; the branch summary's output ceiling was 2048, and neither was cut to the model's own `maxTokens`.

**Root cause**: upstream's `createSummarizationOptions()` sets `reasoning = thinkingLevel` (the session's current level) only when `model.reasoning && thinkingLevel && thinkingLevel !== "off"`; `generateBranchSummary()`'s request options are `{ apiKey, headers, env, signal, maxTokens }` with no reasoning and `maxTokens = Math.min(4096, model.maxTokens)`; compaction is `Math.min(floor(0.8 * reserveTokens), model.maxTokens)`.

**Trap rules**:
- A null `$thinkingLevel` in `AgentSession::summarise()` means no reasoning; compaction passes `thinkingLevel()`, the branch summary passes nothing.
- Tests: `SystemMessageTranscriptTest::testACompactionAsksAtTheSessionsThinkingLevelOnlyForAModelThatReasons`, `testABranchSummaryAsksForNoReasoningAndAtMostFourThousandTokens`.

## The `cwd` section is the directory alone, with no date or time

**Symptom**: the system prompt's `cwd` section carried `Current date and time: …, HH:MM:SS`; the seconds changed on every `ToolLoadout::apply()`, the section changed with them, and the next request carried an extra system message changing only `cwd` (invalidating the cache prefix).

**Root cause**: upstream is `promptSections.cwd = cwd.replace(/\\/g, "/")`, with no time.

**Trap rules**:
- `cwd` in `SystemPrompt::sections()` holds only the directory (backslashes to `/`); pig's own `Current working directory: ` prefix stays.
- Tests: `SystemPromptTest::testTheWorkingDirectoryComesLastAndNoDateOrTime`, `SystemMessageTranscriptTest::testAnUnchangedPromptAddsNothingAndAChangedToolSetSendsOnlyWhatChanged`.

## Resuming and tree navigation restore the tool set from the transcript; a forced prompt is projected, never recorded

**Symptom**: after `--continue` or going back somewhere with `/tree`, a tool set an extension had narrowed (plan mode and the like) was lost and every tool was back; MCP deferred tools loaded through `tool_search` had to be searched again after a resume; `before_agent_start` could neither change prompt sections nor swap the whole prompt for one turn; the model did not know where an MCP server's codemode/deferred tools came from.

**Root cause**: upstream's `_restoreToolsFromTranscript()` (in the constructor and after `navigateTree()`) sets the names in the current system message's `toolsAdded` as the active set, puts unregistered ones in `_pendingToolNames`, and activates them on registration; `setActiveToolsByName()` clears pending when it deactivates any tool, and `_runAgentPrompt()` clears it at its start. `before_agent_start`'s `systemPromptOptions` is editable (`sections`), its resulting `systemPrompt` becomes `forceSystemPrompt`, and `_installAgentForcedPromptProjection()` folds the request's system messages into one `{content: forced, toolsAdded: current}` after `context` processing, with the transcript recording only structured sections. The MCP extension writes the `mcp_servers` section on every prompt (`renderServersSection()`).

**Trap rules**:
- Restoring goes only through `AgentSession::restore()` → `restoreToolsFromTranscript()`; pending lives only in `ToolLoadout` (`restore()`/`refresh()`/`clearPending()`/`isPending()`), and every `apply()` moves the activated ones out of pending.
- Registry changes go only through `ToolLoadout::refresh()` (`onChange` is wired to it, not `apply()`): in a narrowed set, a newly registered tool is activated (upstream's `_isActivatedOnRegistration()`), and so is a pending one.
- pig's deferred MCP tools are not registered before `tool_search`, so pig-mcp registers one directly (and lists it in `DeferredTools` as `loaded`) when `ExtensionApi::isToolPending()` is true — that is what upstream's "register but do not activate, then pending activates" looks like in pig.
- Extra sections go only through `SystemPrompt::withSections()`: the name matches `/^[a-z][a-z0-9_-]*$/` and is not `preamble`, the content is wrapped as `<name>\n…\n</name>`, and empty content does not appear; pig's own sections are not tagged.
- The turn's options live in `AgentSession::$runSystemPromptOptions`, cleared when `runAgentPrompt()` ends; the projection is installed on `Agent::$transformContext` (now public, as upstream).
- Tests: `SystemMessageTranscriptTest::testAForcedPromptIsSentAsTheLeadingPromptForTheRunAndNeverRecorded`, `testAHandlersSectionStaysForTheRunAndTheEventRendersThePromptWithIt`, `testAResumedSessionRestoresTheLoadoutItsTranscriptDeclared`, `testGoingBackRestoresTheLoadoutDeclaredAtThatPoint`, `testARestoredToolThatRegistersLaterIsActivatedAndTheNextRunDropsTheRest`, `testALoadoutSetBeforeARestoredToolRegistersDropsItOnlyWhenItDeactivatesSomething`, `SystemPromptTest::testAHandlersSectionsAreTaggedAfterTheRestAndDiffIntoAPatch`, `HookRunnerTest::testASystemPromptAnswerIsForcedAndLaterHandlersSeeItAndTheSections`, `McpExtensionTest::testADeferredToolAResumedTranscriptDeclaredIsRegisteredWhenItsServerConnects`, `testEveryPromptListsTheServersWhoseToolsAreNotDeclared` and the three `testTheSection…`.
