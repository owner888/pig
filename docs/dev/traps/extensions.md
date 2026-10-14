# Known traps: Extensions

Extensions, hooks, MCP, codemode, skills, prompt templates, the bundled extensions. Moved out of `CLAUDE.md` as written — "this file" in the entries below means `CLAUDE.md`, where they were first written. The index is generated from the headings below.

- [A hook's message was the one long thing in the transcript ctrl+o could not fold](#a-hooks-message-was-the-one-long-thing-in-the-transcript-ctrlo-could-not-fold)
- [The Antigravity extension read the stored token raw, and it lasts an hour](#the-antigravity-extension-read-the-stored-token-raw-and-it-lasts-an-hour)
- [A skill whose description was not UTF-8 went into the prompt with no description](#a-skill-whose-description-was-not-utf-8-went-into-the-prompt-with-no-description)
- [One condition for two independent facts wired neither](#one-condition-for-two-independent-facts-wired-neither)
- [A hook that fails while being asked for permission has not given it](#a-hook-that-fails-while-being-asked-for-permission-has-not-given-it)
- [Two hook files reaching the same path is a fatal error, not a doubled handler](#two-hook-files-reaching-the-same-path-is-a-fatal-error-not-a-doubled-handler)
- [A hook that prints corrupts the screen it printed onto](#a-hook-that-prints-corrupts-the-screen-it-printed-onto)
- [Hooks: what upstream walls off for commands is not here, and the docs said "all of it"](#hooks-what-upstream-walls-off-for-commands-is-not-here-and-the-docs-said-all-of-it)
- [A hook's message sent mid-turn was queued where nothing reads it](#a-hooks-message-sent-mid-turn-was-queued-where-nothing-reads-it)
- [A hook's compaction summary was indistinguishable from pig's own](#a-hooks-compaction-summary-was-indistinguishable-from-pigs-own)
- [A session on disk could not be exported, and skills were the one loader with no off switch](#a-session-on-disk-could-not-be-exported-and-skills-were-the-one-loader-with-no-off-switch)
- [A `before_*` hook result is only heard if the runner thinks it decisive](#a-before_-hook-result-is-only-heard-if-the-runner-thinks-it-decisive)
- [`NoUi` was reported as a UI, so `hasUi` lied](#noui-was-reported-as-a-ui-so-hasui-lied)
- [Verified against the real deployment](#verified-against-the-real-deployment)
- [A user quota request sent as `[]` was refused, and `X-Goog-User-Project` triggered a 403](#a-user-quota-request-sent-as--was-refused-and-x-goog-user-project-triggered-a-403)
- [`/reload` and a class PHP cannot unload: say it, rather than fatal or `class_exists`](#reload-and-a-class-php-cannot-unload-say-it-rather-than-fatal-or-class_exists)
- [An extension tool's constrainedSampling was dropped](#an-extension-tools-constrainedsampling-was-dropped)
- [It just stopped: a TypeError in a listener walked into `Agent`'s catch and the whole turn vanished silently](#it-just-stopped-a-typeerror-in-a-listener-walked-into-agents-catch-and-the-whole-turn-vanished-silently)
- [The Antigravity accounts panel said "no accounts" while the conversation used one](#the-antigravity-accounts-panel-said-no-accounts-while-the-conversation-used-one)

## A hook's message was the one long thing in the transcript ctrl+o could not fold

`setExpanded()` exists on `ToolExecutionComponent`, `CompactionComponent` and
`BranchSummaryComponent`, and `InteractiveMode`'s ctrl+o handler names those three. It could not
name `HookMessageComponent`, because that class had no such method: a hook's message was drawn
whole, for ever, while ctrl+o folded every tool call around it. Upstream cuts it to five lines with
a `...`.

What that costs is not cosmetic, and it is visible in what the method is *for*: a hook's
`sendMessage()` is how a build failure, a lint report or a diff reaches the model, so the long
message is the common case rather than the edge one. This file's own example for the feature is "a
build that just failed".

The instructive part is the second commit. Folding the component made the four tests pass and **the
`instanceof` line was still untested** — removing it changed nothing, because the tests drove the
component directly. That is the same *wired at one end only* shape as the bug, made again in its own
fix, and it took a test that presses ctrl+o through `InteractiveMode` to close:
`testCtrlOOpensALongHookMessageTheWayItOpensEverythingElse`. **When the find is a missing
connection, a test of the two ends is not a test of the wire.** The mutation check is what says
which of the two you have written.

## The Antigravity extension read the stored token raw, and it lasts an hour

`/antigravity.usage` an hour after signing in: `401 Request had invalid authentication
credentials. Expected OAuth 2 access token`. The extension's `$getAuthAndToken` read
`$auth->credentials(Provider::Antigravity)->access` — the token **as stored**, which Google issues
for an hour. The renewal lived in one place, `Auth::apiKey()` → `fresh()`, and the extension could
not use that because `apiKey()` joins token and project into one string for `Providers\Antigravity`
to split, where the quota endpoint wants them apart. So it took the other door, and the other door
had no renewal behind it. **Two readers of one credential, one of which knew it expires** — the
first shape from the index, on a token.

`Auth::freshCredentials()` is `credentials()` plus the renewal `apiKey()` does, for a caller that
needs the parts. The extension goes through it; `Doctor` keeps `credentials()` because it only
counts. Both take a provider *name* now as well as the enum, since the provider is an extension's. Regression tests: `AuthTest::testFreshCredentialsRenewsTheWayApiKeyDoesRatherThanHandingBackTheExpiredHalf`
and `testFreshCredentialsIsTheStoredOnesWhileTheyStillHold`.

## A skill whose description was not UTF-8 went into the prompt with no description

`htmlspecialchars()` answers the **empty string** for input that is not UTF-8. `Skills::escape()`
is the one thing between a `SKILL.md` and the prompt, so one stray byte in a description put the
skill in front of the model as:

```xml
  <skill>
    <name>logs</name>
    <description></description>
    <location>/home/dev/.pig/skills/logs/SKILL.md</location>
  </skill>
```

Listed, loaded, warned about for nothing, and **impossible for the model to ever choose** — the
description is the only thing it sees. A `SKILL.md` is a file somebody wrote in whatever their
editor saved, so this is not an exotic input, and the failure says nothing anywhere.

`Utf8::sanitize()` first. `ENT_SUBSTITUTE` was the other candidate and is worse here: it would put
U+FFFD in front of the model where dropping the byte leaves the sentence readable. **The rule this
adds to the family:** a function that answers `''` on bad input is a silent truncation, not an
error — `htmlspecialchars()`, `json_encode()` and `preg_match_all()` each fail that way, and the
tell is that the caller has no way to know.

Found in the same read, and the same family in its seventh guise: **both length ceilings were
counted in bytes.** The spec says characters and upstream counts UTF-16 code units, which for
anything either project reads is the same number; `strlen()` is neither. An 800-character Chinese
description — comfortably inside the spec's 1024 — was complained about at startup for being 2400
long, with a number that counts nothing anybody asked about. `mb_strlen()` now.

And the third hand-rolled `~` expansion went the way of the two in the loaders: `Skills::expand()`
is `Paths::expand()`, so `--skills-dir` pasted out of Finder resolves; `Skills::userHome()` is
`Config::userHome()`, which is where those four lines already lived.

Regression tests: `SkillsTest::testASkillWhoseDescriptionIsNotUtf8StillHasOneInThePrompt`,
`testADescriptionInChineseIsMeasuredInCharactersNotBytes`,
`testANameInTheWrongAlphabetIsCountedInCharactersToo`.

## One condition for two independent facts wired neither

`HookRunner::initialize()` handed each hook its `sendMessage()` and `appendEntry()` writers
behind `if ($send !== null && $note !== null)`. Both are optional and independent, so a mode
offering only one got **neither** — and what the hook would then read is `sendMessage() needs a
session — call it from a handler`, which is a confident explanation of something that did not
happen. All three modes pass both today, so this was the next mode's bug rather than a live one;
upstream sets the two independently, which is what it does now.

The shape, and it is worth having a name for: **an `&&` over two unrelated conditions is a
silent disabling waiting for a caller who only satisfies one.** The tell is that the two halves
have separate error messages — if one fact can be missing on its own, it has to be *wired* on its
own.

Regression test: `HookMessagesTest::testAModeThatWiresOneWriterGetsThatOne`.

## A hook that fails while being asked for permission has not given it

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

## Two hook files reaching the same path is a fatal error, not a doubled handler

`~/.pig/agent/hooks` symlinked into a project's `.pig/hooks` is a normal way to keep one copy of a
hook, and the obvious reading is that loading it twice just registers its handlers twice.
It is worse than that: `require` on a file that declares a function a second time is a fatal
`Error`, so the second load kills the *first* hook as well and neither works.

`HookLoader` therefore deduplicates on `realpath()` before loading, the same way `Skills`
does — and for a harder reason. Test:
`HookLoaderTest::testTheSameFileNamedTwiceIsLoadedOnce`.

## A hook that prints corrupts the screen it printed onto

Hooks load before the UI starts, so an `echo` or a stray `var_dump` in a hook file lands on
the terminal a moment before the TUI takes it over and redraws across it. What the person
sees is a session that came up wrong, with nothing to read and nothing to search for.

`HookLoader` wraps the `require` in `ob_start()` and turns anything printed into a load
complaint naming the file and quoting what it printed. Test:
`HookLoaderTest::testAFileThatPrintsIsAComplaint`.

## Hooks: what upstream walls off for commands is not here, and the docs said "all of it"

Twentieth, and a documentation find rather than a code one. The hooks section claimed `Hooks\` was
upstream's `core/hooks/` "all of it", and then said — correctly — that sixteen of eighteen events
are fired. What it never mentioned is `HookCommandContext`: upstream gives a slash command's handler
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()`, walled off from event handlers
because calling them inside the agent loop deadlocks. pig has one `HookContext` for both and none of
the four.

Left unported, deliberately and now in writing: `branch()` is `AgentSession::fork()`, which a mode
reaches and a hook context does not, `navigateTree()` is `goTo()` — whose answer is a summary, an abort or text for the prompt rather than
a yes — and the other two would need a second context class to be safe from the deadlock upstream
avoids by having one. Two context types so that a hook command can restart the session is more
machinery than the thing is worth. A hook that wants a handoff can say so with `sendMessage()`.

Found in the same read: **`isError` on a `tool_result` result**. Upstream declares the field
("Override isError flag") and its wrapper reads neither direction, so a hook setting it there changes
nothing. pig honours `true` by raising — the verdict still comes from the one place the loop reads,
`execute()` throwing — and deliberately ignores `false` on a tool that did throw, because rescuing it
would be a second route to "this succeeded". The class's own docblock claimed `false` meant
"not an error after all", which was a promise the code never kept; it says what happens now.

## A hook's message sent mid-turn was queued where nothing reads it

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

## A hook's compaction summary was indistinguishable from pig's own

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
to *before*; `TreeList::search()`, public and documented "for whoever draws the search line", with
nobody drawing it. None failed a test, because each was internally consistent — the wiring was
missing, not wrong. What finds them is reading the *other* end: who consumes this list, who handles
this key, what does the caller do with what it gets back.

**The third shape is not about the port at all: PHP's value model is not JSON's.** Both languages
read the same documents off the wire and disagree about what two of them being *the same* means, in
opposite directions — and the disagreement is invisible until a value crosses the boundary.

| The find | PHP | JSON, and JavaScript's runtime |
|---|---|---|
| `const: 5` refused `5.0` | `int` and `float` are two types, and `===` says so | one number type; `5.0` **is** `5`, and neither can express otherwise |
| An empty arguments list went out as `[]` | one array type: `{}` and `[]` decode to the same value | `object` and `array` are two types, and `input: []` is refused |
| `uniqueItems` and whole floats | `json_encode(1.0)` is `1`, so nothing was wrong — the branch written to fix it did nothing | — |
| `Utf8::sanitize` exists at all | UTF-8 bytes; the failure is a truncated sequence | UTF-16; the failure is an unpaired surrogate |
| Up in the editor landed inside a character | a string offset counts **bytes**, so `2` is two thirds of `你` | a string offset counts code units, so `2` is two characters |
| `str_pad()` lined up an ASCII-only column | a *length* is bytes, so 代码审查 is 12 | a length is code units, so it is 4 — and the terminal gives 8, which is neither |

So PHP splits numbers **finer** and arrays **coarser**. Copying upstream's comparison faithfully is
how the first one happened: `!==` is the honest translation of `!==` and the wrong answer anyway,
because the question is not "is this the same PHP value" but "is this the same JSON document". The
reading that finds these is not "does this line match upstream" but **"is this value the same thing
in both languages"** — and the place to look is every boundary: `json_decode`, `json_encode`, and
any comparison of two things that came through one. The last two rows widen that: a **string offset**
is a value too, so the same question has to be asked of every number carried over from a `length`,
`slice` or `indexOf` — see the trap entry, which is the one member of this family that crashes.

And the last row adds a **third** unit, which is the one a terminal program actually needs: columns.
Bytes, code units and columns are three different numbers for 代码审查 — 12, 4 and 8 — so "port the
unit" is not a choice between two. Anything lining text up wants the third, which is `Width::visible()`
and `Width::pad()`; anything indexing into it wants characters; and only a byte buffer wants bytes.

## A session on disk could not be exported, and skills were the one loader with no off switch

`main.ts` is 451 lines and almost all of it is pig's already, in `CodingAgent::session()` and
`bin/pig`. What a flag-by-flag comparison of the two found is three things, and the first two are
fixed here.

**`--export <file> [out]`.** Upstream exports a session to HTML from the command line and exits.
pig had every piece — `HtmlExport`, `/export` inside a session, `export_html` over RPC — and no way in
from the shell, so somebody with a `.jsonl` and no wish to reopen the conversation was stuck.
`HtmlExport::fromFile()` is upstream's `exportFromFile()`, and it is in the export module rather
than in the entry point for the reason four classes in `Cli\` exist: *a script that ends in
`exit()` cannot be called twice by a test.* `export` had to join `Arguments::TAKES_A_VALUE`, which
is the trap that list is for — a session path does not start with a dash, so the flag would have
taken no value and the path would have been sent to the model as a message.

*And the three lines in `bin/pig` had a bug that only running them shows:*
`echo 'Exported to: ', HtmlExport::fromFile(…)` writes left to right, so a missing file printed
`Exported to: ` and then the error underneath it. The write happens first and the sentence after.

**`--no-skills`.** `--no-hooks` and `--no-tool-files` (then called `--no-tools`) have been there from the start; skills had
no flag — three loaders, two with a switch on the command line and one without, which reads as
"this one cannot be turned off for one run". `withSkills` on `session()`; `--skill` paths still load
under it, as upstream's `noSkills` keeps them.

**The third was a name collision, and the developer's call was to follow upstream, so the two flags
swapped.** pig's `--models` *listed* the registry; upstream's `--models` takes patterns and **scopes
the session**, with `--list-models` as the listing flag. The same word meaning two different things
in the two tools is the worst of the three outcomes — one of them prints and exits — so the listing
is `--list-models` now and `--models` is the scope, which brought upstream's
`resolveModelScope()` with it.

`ModelResolver::scope()` is that function, and the half the old note called "rows of work rather
than a dependency" was exactly that: `fnmatch()` for the globs, `parse()` for everything else, so
`--models sonnet` means what `--model sonnet` means. Five things came out of porting it, and the
third is the one worth remembering:

- **A pattern is a glob when it holds `*`, `?` or `[`**, and a thinking level is only stripped off
  one when the suffix really is a level — an id can hold a colon (OpenRouter's `:exacto`), so
  `claude-*:nonsense` is a pattern matching nothing rather than `claude-*` with a bad level on it.
- **The first entry of the scope is what the session opens on**, one step below `--model` in the
  order. A resumed conversation still beats it, and needs no condition of
  its own: `restoreSettings()` already puts the file's model back unless `--model` was typed.
- **The cycling moved from `ModelResolver` to `AgentSession`, and the reason is the thinking
  level.** `next()`'s docblock used to argue the opposite — *"everything else it needs is already
  done for it, so what is left is which model comes next"* — which held for exactly as long as the
  answer was only a model. `--models sonnet:high,haiku:low` makes it a model **and** a level, and a
  rotation that left the level to its two callers is the wired-at-one-end shape with two ends to
  forget. `ModelResolver::next()` is the arithmetic; the list, the key check and the level are the
  session's.
- **`modelsOnOffer()` is the one answer to "which models are on offer"**, and the first draft had
  two: the terminal narrowed to the scope *and* `cycleModel()` narrowed again, so the mutation check
  that made `cycleModel()` ignore the scope broke only the RPC test. Two answers to one question, in
  the fix for a find about two answers to one question. It narrows the `/model` picker, its
  completions, `/model <pattern>` and the cycling.
- **A host's `get_available_models` is not narrowed**, as upstream does not narrow it, and
  `set_model` takes any model a key reaches in both tools. A scope is what the keys in front of
  somebody walk through, not a restriction — which is worth stating, because the other reading is
  just as plausible and would need `set_model` to refuse.

One pattern that matches nothing is a **warning** naming it, and the session starts on the ordinary
order: a typo in a flag that is a preference should not be a refusal to start, and the other three
patterns are still a scope. `bin/pig` splits on commas — the flag's spelling is the command line's
business and what it resolves to is the library's, which is `--tools`' division — and a `--models`
with nothing after it is refused there by name.

`RpcClientTest::testTheModelsFlagNarrowsWhatTheAgentOpensOnAndCyclesThrough` is the one that holds
`bin/pig`'s end: it is the only test in the suite that starts the binary, and removing the
pass-through broke **nothing at all** before it existed.

Left out of `main.ts` with reasons, so the flag list is not compared twice:

| Upstream | Why not |
|---|---|
| `--hook <path>`, `--tool <path>` | pig adds hook and custom-tool paths through the settings only, which is where a path somebody uses twice belongs. The mirror of `--no-hooks`/`--no-tool-files`, which upstream lacks and pig has |

## A `before_*` hook result is only heard if the runner thinks it decisive

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

## `NoUi` was reported as a UI, so `hasUi` lied

`HookContext::$hasUi` answers one question — will asking reach anybody — and `HookRunner`
computed it as `$this->ui !== null`. That was right for as long as nothing passed a `NoUi`
deliberately: the default is `null`, and `HookContext` turns null into `NoUi` with `hasUi`
false. `PrintMode` is the first caller to pass one on purpose, and it was told there was
somebody there, so a hook that checks before asking asked and heard nothing.

Now `$this->ui !== null && !$this->ui instanceof NoUi`. The predicate belongs with `NoUi`,
which is by definition nobody; a caller passing `hasUi: true` beside a `NoUi` is making a
claim that contradicts itself, and `HookContext` still lets one, which is worth revisiting.

## Verified against the real deployment

`bin/pig -p hi --model antigravity/gemini-3.8-flash` answered on the first real request. That one
line validates six things at once, none of which a canned server can check:

1. the provider name in `auth.json` (`antigravity`) resolving to a key;
2. the host — no `sandbox` in it;
3. the `User-Agent` header the deployment insists on;
4. the envelope: `requestType`, `userAgent`, `requestId`, `sessionId`, the labels;
5. the routing — `gemini-3.8-flash` going out as `gemini-3.8-flash-low` at the default level;
6. the model enum naming a model the deployment recognises.

Any one of them wrong is a 4xx, so "it answered" is a stronger result than it looks. **What it does
not cover is a turn with tools in it** — `normalizeConversationTurns` exists in the reference
implementation to stop the deployment answering 400 on those, and whether pig needs it depends on
whether `GoogleShared::contents()` can produce the shapes it guards against. The reference built
its contents from an OpenAI-shaped payload and pig does not, so the answer is not obvious either
way and is worth a real tool-using turn before any of it is ported.

## A user quota request sent as `[]` was refused, and `X-Goog-User-Project` triggered a 403

Two bugs in Antigravity quota discovery (`QuotaClient`):
1. `postJson` serialized empty request bodies as `json_encode([])`, which produces `"[]"`. Google Cloud Code RPC endpoints require the root element to be an object message and returned 400 `Invalid JSON payload received. Root element must be a message.` Fixed by encoding empty arrays as `"{}"`.
2. Sending `X-Goog-User-Project: <projectId>` to personal quota endpoints (`v1internal:loadCodeAssist` and `v1internal:retrieveUserQuotaSummary`) caused Google Cloud IAM to demand `serviceusage.services.use` project permissions, returning 403 `Caller does not have required permission to use project`. Those endpoints belong to the user's personal Google subscription rather than a project quota, so that header is omitted.

`CodingAgent::session()` also clamps the startup thinking level (`ThinkingLevel::clampedFor($chosen, $level)`), so models with `thinkingLevelMap` (such as Antigravity models requiring reasoning) do not open on `off`. No environment variable chooses the model, provider or thinking level: `PIG_MODEL`, `PIG_PROVIDER`, `PIG_THINKING`, `PIG_TUI_MODE` and their `PI_*` spellings are not read, as upstream reads none of them.

## `/reload` and a class PHP cannot unload: say it, rather than fatal or `class_exists`

**Phenomenon**: `/reload` on an extension that declared a class or a function was `PHP Fatal error:
Cannot redeclare …`. The first answer was two wrong ones at once: the loaders refused any entry file
with a named class in it, and every bundled extension therefore kept its classes in sibling files
behind a hand-written `if (!class_exists(X::class, false)) require` — which made `/reload` run new
closures against the old classes in silence, and let two copies of an extension (the installed one
under `~/.pig/agent/extensions` and a checkout's) pick a winner by load order, also in silence.

**Cause**: Node drops a module from `require.cache`; a PHP class or function, once `require`d, is in
the process for good. Nothing can make a second `require` of the same file work, so the only honest
answers are "do not `require` it again" and "say so".

**Rule** (`Hooks\DeclaredSymbols`, used by `HookLoader` and `ExtensionLoader` alike): a named class
is allowed. Before a `require`, the loader tokenizes the file — the whole folder for an `index.php`,
`vendor/`, `node_modules/` and tests left out — for named classes, interfaces, traits, enums and
functions, namespace included.
- **A reload of a file with declarations runs the factory from its first load** against the fresh
  api, so the extension does not vanish on `/reload`; when the files have changed since, the load
  reports `reload: declares X, Y, which PHP cannot unload — running the version loaded at startup;
  restart pig to pick up the change`. A file of nothing but closures reloads whole, as before.
- **A class already in memory from outside the extension's own files** refuses the load:
  `declares X from /that/file, which is already in memory; two different copies of one extension
  cannot both be loaded`. Two *identical* copies (same content, any paths) are one extension, and the
  second is served by the first's factory without a word — the fingerprint is the files' contents
  with paths relative to the entry.
- Extensions `require_once` their class files plainly and keep no guard; the bundled seven were
  changed in the same batch. A test that `require`s an extension's classes before loading its entry
  is "the extension's own files" and is not a conflict.

Still true: `pig`'s own `packages/` classes cannot be reloaded either — that is a restart.

Regression tests: `HookLoaderTest::testAHookMayDeclareAClassAndAReloadRunsTheFactoryItFirstLoaded`,
`testASecondDifferentCopyOfAClassIsRefusedByNameAndAnIdenticalOneIsNot`,
`ExtensionLoaderTest::testAFolderExtensionsClassesAreSeenInItsSiblingFilesToo`,
`testADifferentCopyOfAnExtensionsClassIsRefusedByName`.

## An extension tool's constrainedSampling was dropped

**Symptom**: an extension or custom tool declaring `constrainedSampling` (strict: prefer) was still sent non-strict to a strict-capable model.

**Root cause**: upstream's `wrapToolDefinition()` carries `constrainedSampling` along with the name, description and schema; pig's `CustomTool` had no such field, and `WrappedCustomTool::definition()` built only the three. Upstream sets none for MCP tools, and neither does pig.

**Trap rules**:
- A new `Tool` field means checking every `new Tool(...)`, `WrappedCustomTool` included.
- Test: `CustomToolsTest::testAWrappedToolKeepsTheConstrainedSamplingItAskedFor`.

## It just stopped: a TypeError in a listener walked into `Agent`'s catch and the whole turn vanished silently

**Phenomenon**: after the model called a tool an extension had registered, the TUI showed one thinking block and then nothing — no red text, no turn in the session file, no retry, yet `agent_settled` fired (auto-naming ran, the "task done" notification popped). `/debug` was the only place the reason existed:
`InteractiveMode::toolRenderers(): Argument #2 ($custom) must be of type ?Pig\CodingAgent\Interactive\CustomTool, Pig\CodingAgent\CustomTools\CustomTool given`.

**Root cause**: two layers.
1. The signature `toolRenderers(string $name, ?CustomTool $custom)` named a bare `CustomTool`, and of the four `CustomTools\` classes the file imports, that one was missing, so the type resolved to a nonexistent class in the current namespace — the fifth variant of the missing-`use` trap. `$custom` is non-null only when the model calls a custom tool, which is why the built-in tools were fine all along. The same sweep found `getApiKey: fn (Model $m)` in `PrintMode.php` / `RpcMode.php` (no `use Pig\Ai\Model`; an extension calling `$ctx->apiKey()` under `-p`/rpc blew up), `catch (Throwable $e)` at `TcpConnection.php:104` (no `use Throwable`; the web daemon's "fault isolation" never caught anything), and `Http::class` at `HttpServer.php:231` (not imported; the `===` was always false).
2. A throw in a listener went all the way back to the catch in `Agent::run()`, whose old `recordFailure()` emitted only `agent_end` — while writing the session file, drawing the error component, the `sayError()` fallback and the RPC events all hang on `message_end`, so nobody saw the error message. Upstream HEAD's `handleRunFailure()` is the four-shot message_start → message_end → turn_end → agent_end (the anchor `d0a4c37` has only agent_end; this is taken from HEAD).

**Trap rules**:
- `Agent::handleRunFailure()` fires the four events as upstream does, through the same `$emit` as the loop; `apply()` puts the message into state at message_end and records the error at turn_end. Never back to "agent_end only".
- `test/lint.php`'s `unresolvedClassNames()` now scans class names token by token (`new`, `instanceof`, `extends`/`implements`, `catch (`, parameter/property/return types including `?T`, `A|B`, `...$x`, before `::`, `#[Attr]`, `use Trait;` in a class body), resolving by PHP's rules (the import's target, else the file's namespace; only a file without a namespace checks the global one), and is red when nothing resolves. Function names, constants and docblocks do not count; a name followed by `(` is a call, not a type (the `):` of a ternary `? f() : g()` nearly reported everything). One sweep found 5, all real.
- Run `php test/lint.php` when adding a `catch`/`instanceof`/type declaration; do not wait until that line runs.

**Tests**: `AgentTest::testAThrowThatEscapesTheLoopIsAFailedTurnEveryListenerSees` (red if changed back to agent_end only), `InteractiveModeTest::testTheModelCallingACustomToolIsDrawnRatherThanEndingTheTurnInSilence` (red without the import), `PrintModeTest::testAHookGetsTheSessionsKeyHereToo`, `RpcModeTest::testAHookGetsTheSessionsKeyHereToo`; each of the five lint imports removed in turn is reported, and a synthetic probe file hits all 11 spellings.

## The Antigravity accounts panel said "no accounts" while the conversation used one

**Symptom**: after `/login antigravity` in a terminal, chatting worked but the web page's Accounts
panel (and `/antigravity.accounts`) said there were no accounts — on a machine without pi-antigravity.

**Cause**: pig's sign-in writes `auth.json` only, and the list read `antigravity-accounts.json` only.
Upstream runs `syncCurrentAuth()` before every listing, filing what `auth.json` holds into the store;
pig had left it out. The page's server also kept the store it read at start, so a later sign-in in a
terminal never reached it.

**Rule**: everything in `pig-antigravity` that lists or picks accounts calls `$sync()` first
(`Accounts::syncCurrentAuth()`, with `auth.json` read again).
