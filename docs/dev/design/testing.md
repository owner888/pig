# Design: Testing and releasing

The test suite, mutation sweeps, live provider tests, packaging. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [`packages/agent`'s test suite, read as a specification](#packagesagents-test-suite-read-as-a-specification)
- [`coding-agent`'s session-manager tests, read the same way](#coding-agents-session-manager-tests-read-the-same-way)
- [The rest of `coding-agent`'s tests, and the `try` that turned out to have none](#the-rest-of-coding-agents-tests-and-the-try-that-turned-out-to-have-none)
- [`tui`'s tests, where the specification was the find](#tuis-tests-where-the-specification-was-the-find)
- [`ai`'s tests are all live-provider, and two of them are still a specification](#ais-tests-are-all-live-provider-and-two-of-them-are-still-a-specification)
- [`test/live.php`, and what Anthropic said back](#testlivephp-and-what-anthropic-said-back)
- [How much of it has been read against upstream](#how-much-of-it-has-been-read-against-upstream)

## `packages/agent`'s test suite, read as a specification

Upstream's tests are the only written statement of what its code is *supposed* to do, so the sweep
after auditing `agent-core` was: for each `it(...)` over there, is there something here that would
fail if pig got it wrong? Five were not pinned, and all five turned out to pass — which is the useful
answer, because the alternative was not knowing:

- **`transformContext` runs before `convertToLlm`.** The order is the point: a transform works on the
  app's own message kinds — compaction and the `ContextEvent` hook both arrive there — and the
  conversion then drops what the model cannot read. Reversed, a transform would be handed a list with
  the app's own kinds already removed, which is the one thing it exists for. Pinned by reversing the
  two in `AgentLoop` and watching the test go red.
- **An app's own message may be the last one when continuing.** Only an assistant message is refused;
  a hook message at the end is the caller's business, because `convertToLlm` is what turns it into
  something a provider accepts. Refusing it would refuse the case `continue()` exists for.
- **`continue()` while streaming is refused**, the other half of the guard `prompt()` has.
- **`abort()` on an idle agent does nothing** — there is no controller, and a UI's escape key does
  not know whether a turn is in flight.
- **The state mutators each write their own field**, and `replaceMessages()` re-indexes. Upstream
  asserts that one as "should be a copy", which PHP does by itself; the half worth pinning here is
  `array_values()`, since a message list with a gap in its keys reaches `count()` and
  `$messages[count - 1]` in three places and both read the wrong thing.

`e2e.test.ts` is the same five scenarios against seven live providers and needs real keys, so it is
not a spec pig can run; the one part of it that is not provider-specific — `Agent.continue()`'s two
validation throws — is the find above it in this file, which upstream tests at the `Agent` level and
pig had only in the loop.

## `coding-agent`'s session-manager tests, read the same way

The same sweep over `test/session-manager/` — five files, 1,098 lines, the suite that describes the
file pig shares with pi. **Two of its `it()` names had no counterpart here and both were real**, and
they are the two entries below: the `--continue` that opened the wrong conversation ("returns most
recently modified session" — a claim about `mtime`, where pig sorted by name) and, from reading
`branch-summarization.ts` alongside it, the handover that carried the files it was summarising.

The rest lined up, and the four worth writing down are the ones where the answer is *not* a matching
line:

- **"uses last entry when leafId not found" has no case here.** Upstream's `buildSessionContext` is a
  free function handed a leaf id from outside, so it needs a fallback; pig's is a method on the class
  that owns both, and the leaf is always an entry that exists — `open()` sets it to the last line read
  and `goTo()` refuses an id it does not have. A fallback for a state that cannot arise would be a
  branch only a test could reach.
- **"handles orphaned entries gracefully" already agrees.** `pathTo()` stops when a parent is not
  there, so an entry whose parent is missing comes back as itself and nothing before it — upstream's
  one message, for the same reason. It is the other half of the rule the file format section states:
  *an entry you cannot use is still an entry other entries point at.*
- **The label cases are pinned three ways already**: out of the conversation (`isSaid()`), last one
  wins (read in file order), refused on an id nothing has.
- **The `createBranchedSession` cases are `SessionManager::fork()`'s now** — the path to the leaf,
  labels re-chained and written again, a compaction's cut remapped — in `SessionManagerTest`'s fork
  cases.

`build-context.test.ts`'s remaining claims — the thinking level and model tracked from their own
entries *and* from the last assistant message, a summary in front of the kept messages, the latest of
several compactions winning, a branch summary appearing on the path — are each already a test here,
which is what makes this read cheap: the expensive part of the file format was done when it became
pi's.

## The rest of `coding-agent`'s tests, and the `try` that turned out to have none

The same sweep over the other sixteen files — `agent-session-*`, `compaction`, `compaction-hooks`,
`tools`, `skills`, `args`, `model-resolver`, `fuzzy`, `truncate-to-width`, `rpc`. No bug, one real
coverage gap, and one divergence in pig's favour; the value of writing it down is the *evidence*, so:

**The gap: `HookRunner::ask()`'s `try` had no test at all.** Removing it broke **nothing** in 2,433
cases. Four events are *decisive* — `session_before_compact`, `session_before_tree`,
`session_before_switch`, `before_agent_start` — and they go through `ask()`, which is a second walk
over the handlers with its own `try`, beside `emit()`'s. Only `emit()`'s was pinned
(`testAHandlerThatThrowsIsReportedAndTheRestStillRun`). So a hook with a typo in a
`session_before_compact` handler would, if that `try` were ever removed, throw out of `compact()`,
`goTo()` or `switchTo()` instead of letting the default happen — and upstream's suite is what names
the rule: *"should continue with default compaction if hook throws error"*. **The first shape from the
index, on a `try` rather than on a rule**: two methods over the same handlers, one of them tested.
`HookRunnerTest::testAThrowingHandlerOnADecisiveEventIsReportedAndTheNextOneStillAnswers` and
`testADecisiveEventWhoseOnlyHandlerThrowsAnswersNothing` are the two ends.

**The divergence: an empty `--model` is refused here and is a lottery upstream.** Its own test says so
— *"empty pattern matches via partial matching: Empty string is included in all model IDs, so partial
matching finds a match"* — so `--model ""` there silently opens on whichever model the substring pass
happens to rank first. `ModelResolver::parse('')` answers null and `CodingAgent::session()` refuses by
name: `No model matches ''. Try --list-models for the list.` Verified through the binary.

Everything else in those sixteen files is already pinned or has no case here, and the six worth
naming because the check was not a grep:

- **Where a branch summary's parent lands**, upstream's two cases: navigating to a *user* message
  attaches it to that message's parent, navigating to an answer attaches it to the answer. pig's
  `goTo()` moves the leaf to the resolved target and appends afterwards, so both fall out of one line
  — and `abandoning($entryId)` is passed the **original** id, not the resolved one, which is what
  upstream passes to `collectEntriesForBranchSummary` too: the message whose text went to the editor
  is not also summarised.
- **`sonnet:`, `sonnet:random` and `sonnet:high:random`** all answer the model with thinking `off` and
  a warning naming the level, which is upstream's three cases (its own are spelled with OpenRouter's
  `qwen3-coder:exacto`, an id pig's registry does not carry).
- **The `truncate-to-width` crash from upstream's issue report** — `'✔ script to run › dev $ …'` at
  terminal width 67 — comes out of `Width::truncate()` at exactly 65 columns, 67 with the cursor. Run,
  not reasoned about.
- **`grep` on a single file still names the file**, because pig passes `--json` as upstream does and
  the filename is in the event. Plain `rg` output would have dropped it for a single path, which is
  what that test exists to catch.
- **Every one of `skills.test.ts`'s validation claims is pinned**: 64 characters (in characters),
  the character class, a leading or trailing hyphen, two hyphens in a row, the folder name used when
  the frontmatter has no `name`, and XML escaping. (The folder-name-mismatch and unknown-field
  warnings went when upstream dropped them.)
- **A `session_compact` event fires after the summary is saved**, which is upstream's *"should include
  entries in compact event after compaction is saved"*.

The five per-root skill switches upstream once had (`skills.enableCodexUser` and four more) are gone
here with the roots they governed — upstream reads none of those folders now, and `skills` is a list
of paths; see "Skills, prompts and themes are resource lists".

## `tui`'s tests, where the specification was the find

Five files, 1,492 lines, and the useful one is `wrap-ansi.test.ts` — six cases about style bleeding
across a wrap, which is the family two entries in the traps section already belong to. pig satisfies
all six as written, **and the comment inside the rule they describe is wrong about two attributes**:
the entry below on inverse and strikethrough is what came out of reading them.

The other four files needed no change and the evidence is worth keeping:

- **`truncated-text.test.ts`'s nine claims all hold**, run rather than read: exact padding to the
  width at every size, one line whatever the input, the `\e[0m` before the ellipsis so the ellipsis is
  not styled with whatever was truncated, the first line only when the text has newlines, and an empty
  text still padded to width.
- **`editor.test.ts` and `markdown.test.ts` are the two files already answered by a corpus** — 1,308
  keystroke sequences against `editor.ts` and 54 documents against `marked`, both in the traps below.
  A corpus compares behaviour where the tests state intent, and where a corpus has been run the tests
  are the weaker instrument: it is how the byte offset that crashed on CJK and the two list bugs were
  found, neither of which any `it()` over there names.
- **`autocomplete.test.ts`'s four cases are the `/`-is-not-a-path rule**, which has its own entry
  below and eight regression tests.

## `ai`'s tests are all live-provider, and two of them are still a specification

Twelve files, 6,017 lines, and **every one of them needs real keys**: they are the same handful of
scenarios repeated per provider, `e2e.test.ts`'s shape one package over. So none of them is a suite
pig can run — but two state a *cross-provider invariant*, which is checkable against a canned server,
and that is where the whole batch's value was.

**`tokens.test.ts`: an interrupted turn still reports what it cost.** Anthropic and Google send usage
at the *start* of a stream, so a turn escape stopped half way has real numbers; the two OpenAI APIs
only report in the final chunk and upstream's test documents them as the exception. pig gets this
right — measured through a canned stream, `input: 1200`, `cost.input: 0.0036`, `stopReason: aborted`
— **and had no test for it**, because the existing abort case serves a stream with no `message_start`
in it, so no usage ever arrives and nothing would catch a regression. The mutation that names it is
one line in `fail()` — `$this->usage = new Usage()`, the plausible mistake of "an aborted turn
produced nothing so it cost nothing" — and it breaks **exactly one** test in 2,438: the new one. What
it would have cost is a bill that misses every interrupted turn, on the key pig is built around.
`AnthropicTest::testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived`.

**`empty.test.ts`: what goes on the wire for a message with nothing in it.** Four shapes — an empty
content array, an empty string, whitespace only, and an empty assistant message mid-conversation —
built for three providers against a canned server and read off the request:

| | anthropic | openai-completions | google |
|---|---|---|---|
| empty content array | `[]` | `[]` | `[]` |
| empty string | dropped | **sent**, `text: ""` | **sent**, `text: ""` |
| whitespace only | dropped | **sent** verbatim | **sent** verbatim |
| empty assistant | dropped | dropped | dropped |

pig matches upstream on every cell, **including where upstream's own two providers disagree with each
other**: `anthropic.ts` filters a text block on `trim().length > 0` and `google-shared.ts` and
`openai-completions.ts` do not. So a conversation of one empty message reaches Anthropic as
`messages: []` and reaches Gemini as a part holding the empty string, and which of the two is right
is not decidable from here — upstream's assertion is deliberately tolerant ("either handle gracefully
or return an error"), and only a live call would say. It is written down so nobody tidies pig's Google
path into agreeing with pig's Anthropic path and thereby diverges from both.

Worth knowing about that row: **an empty assistant message is dropped by all three, which leaves two
consecutive user messages.** That is upstream's behaviour too (`if (blocks.length === 0) continue;`),
and it is the one shape where the dropping changes the *structure* of the conversation rather than
just removing nothing.

The other ten files are live-only with nothing to extract: `abort`, `stream`, `handoff`,
`image-tool-result` and `context-overflow` are per-provider round trips (their invariants are already
entries below — the overflow table, `TransformMessages`, the Copilot headers), `image-limits`
*discovers* each provider's limits empirically rather than asserting one, `total-tokens` and `xhigh`
are the two finds already recorded against them, and `unicode-surrogate` is `Utf8::sanitize()`, whose
docblock carries its own verification.

## `test/live.php`, and what Anthropic said back

The worry the provider-SDK section states plainly — *"a bug in them shows up as tokens going missing
rather than as an error, and there is no vendor implementation to fall back to"* — is about 4,977
lines of hand-written protocol with 167 tests over it, **every one of which answers a server pig
wrote itself, from fixtures pig wrote itself.** A fixture written to agree with the code is a failure
this project has had twice: Anthropic's `message_delta` usage and `retry.maxAttempts`. So the
canned-server suite cannot settle the one question that matters most, which is not "does pig parse
what a provider sends" but **"does the provider accept what pig sends"**.

`test/live.php` is that question, as a script rather than a PHPUnit test — it costs money and needs
credentials, so it must not be reachable by a runner at all, which is upstream's `skipIf(!API_KEY)`
one step further. Keys come from `Auth::discover()` and the environment, never from an argument,
because argv is in the process list. Twelve scenarios per provider, each capped at a few hundred
tokens; the overflow case is refused before it is billed. `php test/live.php anthropic google` names
which to run, and `php test/live.php google/<model-id>` names the **model** — added the first time a
question was about one model rather than a provider, since the table's ids are a cheap default and
editing it to ask one question is how a default stops being one. What was typed wins over the table
and over `models.json`, and adds a provider neither offered. A provider name that is not one is now
said so as well — before this, a typo in the one argument this script takes printed a summary of zero
scenarios and no reason for it, and a model skipped for want of a key did not say which model it
would have used, which is exactly what somebody checking whether their `provider/id` took effect
needs to see.

**And the first real use of it hit the anchor, which is the second time.** The id asked for was
`gemini-3.8-flash` — a model that exists and that `Ai\Models`, pinned at 2026-01-02, has never heard
of, so the answer was `no such model in the registry`. True, and it sends somebody looking for a typo
in what they typed. So the message now names the cause and the route, and **the route was measured
end to end rather than asserted** — a `models.json` declaring the model under the `google` provider:

```
$ PIG_CODING_AGENT_DIR=… php test/live.php google/gemini-3.8-flash
google / gemini-3.8-flash   (reasoning: yes, images: yes)
  NO  text   google returned 403: Host not in allowlist: generativelanguage.googleapis.com
$ PIG_CODING_AGENT_DIR=… bin/pig --list-models gemini-3
google  gemini-3-flash-preview  1.0M  65.5K  yes  yes
google  gemini-3-pro-preview    1M    64K    yes  yes
google  gemini-3.8-flash        1.0M  65.5K  yes  yes
```

The reasoning and image columns are read off the declaration, the built-in Google rows are untouched
(`Models::register()` fills gaps rather than overwriting), and the 403 is this container's egress and
not the wiring. So "a model pig has never heard of is reached through `models.json`" is now a
verified sentence rather than a plausible one, and it holds for `bin/pig` at the same time — which is
the answer to the staleness generally, and the reason the pin stays affordable.

**The other half of that turn is a documentation rule.** The example in these three places was
`google/gemini-3-pro-preview`, and it was pointed out as already dated. It is an id the pin
*guarantees*, so the command works — but the sentence it sits in is the one telling somebody how to
reach a model the registry is too old for, and naming a perishable model there is the worst place for
one. The READMEs say `google/<model-id>`, and `live.php`'s own docblock names an id the pin carries
and says why it is not the newest one. **An id written into documentation dates the documentation**,
which is the same rule this file already applies to measured figures ("a number in this file is worth
a re-run rather than a quotation").

**Run against the real Anthropic API: twelve of twelve.** The four that only the far end can rule on
are the reason it exists:

| | |
|---|---|
| a replayed thinking signature | **accepted** — it is signed, and meaningless to anybody but the issuer, so nothing local could have told us |
| `TransformMessages`' invented `No result provided` | **accepted** — the claim that every provider refuses a dangling call, tested on one |
| the live wording of a too-long prompt | **matched** `Ai\Utils\Overflow`'s table — `prompt is too long: 300024 tokens > 200000 maximum`, caught by Anthropic's own row rather than by one of the three generic ones |
| `cache_control: ephemeral` on the last block | **acted on** — wrote 5,611 tokens, read 5,611 back on the second call |

And four that pin what earlier reads had only argued: an aborted turn keeps the usage that arrived, a
finished turn has both an input and an output count (the `message_delta` merge, live), `max_tokens`
comes back as `StopReason::Length`, and an empty conversation is refused with `400 messages: at least
one message is required` — **which is not read as an overflow**, so an empty prompt cannot set a
compaction going. That last one settles the question the canned-server read left open two batches
earlier: pig drops an empty message and sends `messages: []`, exactly as upstream does, and Anthropic
says no. Both halves were predicted; only the wording needed a call.

**Then the developer ran it on a machine with three keys**, which is the half this container cannot
reach: only `api.anthropic.com` is allowlisted here and on the desktop bridge alike, every other
provider host answering `CONNECT tunnel failed, 403`. Anthropic, OpenAI and Google, 36 scenarios plus
two handoffs, and **one real bug** — the entry below on a JavaScript index past the end, where a call
from another provider reached the Responses API with an item id it validates the shape of, refusing
the whole conversation. The two handoffs passed, which is what made it worth finding rather than
obvious: `handoff anthropic → openai` carries no tool call, so the case that breaks is the one a
conversation reaches by switching model *mid-tool-use*.

Two things the run said about pig that were not failures and are worth keeping: `gpt-5-mini` answers
an empty message rather than refusing it, where Anthropic refuses — the two providers' own choice,
reached by pig's faithful reproduction of upstream's split behaviour — and the Responses API reports
no usage before the final chunk, so an aborted turn there has none, exactly as upstream's
`tokens.test.ts` documents.

**Three of the four failures were the harness**, and all three are the same lesson from different
angles, so the fixes are commented where they are rather than quietly applied:

- **A 1×1 PNG** is a valid PNG that Anthropic refuses with `400 Could not process image` — on screen
  indistinguishable from a provider rejecting pig's image encoding. 16×16 passes.
- **A fixed 1.1MB prompt** overflows Anthropic's 200k window and fits comfortably inside gpt-5-mini's
  400k and Gemini's 1M, so two providers came back "accepted a prompt larger than its window" for a
  prompt that was nothing of the kind. Sized from `$model->contextWindow` now — and with spaced
  single letters rather than prose, because four characters per token is `Compaction`'s estimate for
  English, which is about 4.5, so sizing that way errs *under* the window and would have reported the
  wrong thing a second time.
- **A 64-token budget** on a reasoning model is spent on thinking, so the turn stops at the limit with
  no text in it and the scenario said `no text arrived as deltas` — a sentence that reads like a
  protocol fault. 512 now, and the failure message names the stop reason and the block kinds, so the
  next one cannot be mistaken the same way. (A one-token budget was the same mistake elsewhere: the
  Responses API refuses `max_output_tokens` below 16 outright.)

*A live harness can fail for reasons that have nothing to do with the thing under test, and the first
failure is worth suspecting in the harness before the port.* Three of four, on the first run.

Still untested against a real endpoint: Groq, xAI, Cerebras, z.ai, Mistral and Copilot — their
protocol code rests on canned servers alone. And none of this is a one-off: the numbers here are
worth re-running rather than quoting, which is what the script is for.

## How much of it has been read against upstream

The sweep above answers "is anything missing". This is the other question: **which ported files have
been read against their upstream line by line**, which is what every find in the traps at the bottom
came out of. 127 upstream files, 42,644 lines, excluding tests and `index.ts` barrels:

| | files | lines |
|---|---|---|
| read difference by difference | 114 | 41,268 |
| ruled out, reason on record | 13 | 1,376 |
| **not yet read** | **0** | **0** |

**The queue is empty.** Every upstream file that has a counterpart here has been read against it
difference by difference, and the thirteen that do not have one have a reason on record in the table
above. That is what the traps at the bottom of this file are: each one came out of a read, and the
last of them — a key claimed and bound to nothing — came out of a 106-line component nobody expected
anything from.

**What the number does *not* mean**, and this matters more now that it is complete than it did while
it was a queue:

- **It is not a claim that pig matches upstream.** It is a claim that every place the two differ has
  either a reason written beside it or a trap entry. Several of those differences are pig being
  *right* where upstream is not — `diffWords` drawing the wrong text on a removed line, a 0×0 PNG
  asking for 150,000 rows, `input.ts` drawing lines of the wrong width on CJK.
- **It is not a measure of quality.** `theme.ts` was read this way and found nothing;
  `bash-executor.ts` was not on anybody's list at all until a file-list sweep turned it up. A file
  being ported and passing its tests said nothing about whether it had been read — every entry below
  came from one that was.
- **It goes stale the moment upstream moves.** The anchor is `d0a4c37`; the count is against that
  snapshot. Re-running it means re-running the sweep command above, not trusting this table.

The reads that found nothing are worth as much as the ones that did, and only with the evidence
attached: `theme.ts` (all 16,777,216 colours through `to256`, both palettes name by name),
`terminal.ts` (every escape sequence and both size defaults), `box.ts`, `text.ts`, `spacer.ts`,
`truncated-text.ts`, `loader.ts` and its two subclasses, `dynamic-border.ts`, `user-message.ts`,
`assistant-message.ts`, `hook-message.ts`, `compaction-summary-message.ts`,
`branch-summary-message.ts` and `bordered-loader.ts` — fifteen components that line up member for
member. Two dead ends in upstream turned up in them and are not ported: `Text`'s `[""]` fallback for
a render that produced no lines, which its own wrapper cannot produce, and `ImageOptions`'
`maxHeightCells`, declared twice and read nowhere.

**`markdown.ts` was the one a surface map could not read**, and the corpus is what read it — see the
trap on the two list bugs it found. The rule it confirms: where upstream leans on a package pig had to
replace, there are no two lists of methods to line up, and the only honest comparison is a corpus run
against the package itself.

**The two largest files came off that list together, and both were read by surface rather than top
to bottom** — `agent-session.ts`, 74 upstream methods against pig's 66, and `interactive-mode.ts`,
68 against 88: mapped name by name, then every unmatched one chased down. Six of the traps below came
out of those two reads. The method is the finding as much as the bugs are: **a file of two thousand
lines is not read line by line by anybody, and the gaps are all in the names that have no pair.** Of
the twelve unmatched names in `interactive-mode.ts`, three were bugs, one was a command left out on
its merits, four were arrangements pig differs on deliberately, and four were pig's own code under
another name.

All four of its gaps are fixed in the traps below — `isCompacting`/`abortCompaction`, `waitForRetry`
at both ends of `prompt()`, the `isStreaming()` guards in front of `abort()`, and
`_tryExecuteHookCommand`, which is the entry on a slash command that only worked in front of a
screen.

`get_state` is upstream's `RpcSessionState`: `isCompacting`, `steeringMode`, `followUpMode`,
`sessionId` (a `--no-session` run has one too, `AgentSession::sessionId()`), `pendingMessageCount`,
and `model` / `sessionName` / `sessionFile` left out when unset rather than sent as null.

**Two things this scoreboard is not.** It is not a measure of quality — `theme.ts` was read this way
and found nothing, and `bash-executor.ts` was not on anybody's list at all until a sweep turned it
up. And a file being *ported and working* says nothing about whether it has been read: every entry in
the traps section below came from a file that was already passing its tests.

`examples/agent.php` runs the whole stack without a UI, read-only unless given `--write`.

`markdown.ts` has no `marked` under it here: `Pig\Tui\Markdown\Lexer` and `Inline` are a
hand-written subset — see the dependency note above. They are not CommonMark and do not try
to be; what they cannot parse stays a paragraph and is drawn as the text it was.
