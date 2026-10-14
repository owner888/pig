# Known traps: Tools and the CLI

Built-in tools, subprocesses, command-line flags, directories and configuration. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [An edit was approved with nothing on screen but a path](#an-edit-was-approved-with-nothing-on-screen-but-a-path)
- [`pig @notes.txt` on a latin-1 file was a dead session, and the reason is a platform difference](#pig-notestxt-on-a-latin-1-file-was-a-dead-session-and-the-reason-is-a-platform-difference)
- [One byte that is not UTF-8 in a tool's output took the session down](#one-byte-that-is-not-utf-8-in-a-tools-output-took-the-session-down)
- [One `read` of a binary file ended a gateway session for good](#one-read-of-a-binary-file-ended-a-gateway-session-for-good)
- [Editing a file with one byte that is not UTF-8 in it took the session down](#editing-a-file-with-one-byte-that-is-not-utf-8-in-it-took-the-session-down)
- [A subprocess whose standard error nobody reads blocks forever with nothing on standard output](#a-subprocess-whose-standard-error-nobody-reads-blocks-forever-with-nothing-on-standard-output)
- [The `@` picker had the third copy of `Paths::expand()`, and it was the worse one again](#the--picker-had-the-third-copy-of-pathsexpand-and-it-was-the-worse-one-again)
- [Killing the shell does not kill what the shell started](#killing-the-shell-does-not-kill-what-the-shell-started)
- [A command finishes twice, and the pipes closing is the first of them](#a-command-finishes-twice-and-the-pipes-closing-is-the-first-of-them)
- [A command's output was cleaned on the way to the screen and not on the way to the model](#a-commands-output-was-cleaned-on-the-way-to-the-screen-and-not-on-the-way-to-the-model)
- [The model was told the command was cut and the person watching it was not](#the-model-was-told-the-command-was-cut-and-the-person-watching-it-was-not)
- [Output truncated by line count also needs somewhere to look](#output-truncated-by-line-count-also-needs-somewhere-to-look)
- [`--ignore-file` applies a nested `.gitignore` in the wrong place](#--ignore-file-applies-a-nested-gitignore-in-the-wrong-place)
- [`fd --glob` matches the file name, so every pattern with a directory in it found nothing](#fd---glob-matches-the-file-name-so-every-pattern-with-a-directory-in-it-found-nothing)
- [`fd` exits 0 when it found nothing, so failure cannot be read off an empty result](#fd-exits-0-when-it-found-nothing-so-failure-cannot-be-read-off-an-empty-result)
- [A tool result outliving the call it answers](#a-tool-result-outliving-the-call-it-answers)
- [`AGENTS.md` that could not be read was skipped without a word](#agentsmd-that-could-not-be-read-was-skipped-without-a-word)
- [Two loaders each had their own worse copy of `Paths::resolve()`](#two-loaders-each-had-their-own-worse-copy-of-pathsresolve)
- [A fixed unpack directory plus a glob installs last week's binary](#a-fixed-unpack-directory-plus-a-glob-installs-last-weeks-binary)
- [The override for pi's directory was a variable name nothing sets](#the-override-for-pis-directory-was-a-variable-name-nothing-sets)
- [The model had seven tools where upstream gives it four, and no way to say otherwise](#the-model-had-seven-tools-where-upstream-gives-it-four-and-no-way-to-say-otherwise)
- [A flag that takes no value eats the argument after it](#a-flag-that-takes-no-value-eats-the-argument-after-it)
- [A short option is not automatically a flag](#a-short-option-is-not-automatically-a-flag)
- [`~/.pig` became `~/.pig/agent`, and the downloaded binaries left `tools/`](#pig-became-pigagent-and-the-downloaded-binaries-left-tools)
- [A backgrounded command (`&`) leaves its pipes with the grandchild and `Run::wait()` hangs forever](#a-backgrounded-command--leaves-its-pipes-with-the-grandchild-and-runwait-hangs-forever)
- [The bash timer (Elapsed / Took) and batching `test/lint.php`](#the-bash-timer-elapsed--took-and-batching-testlintphp)
- [An empty tool result was described as "see attached image"](#an-empty-tool-result-was-described-as-see-attached-image)
- [Built-in tool argument conversion differed from upstream](#built-in-tool-argument-conversion-differed-from-upstream)

## An edit was approved with nothing on screen but a path

`edit-diff.ts` has a second reader pig did not have: `computeEditDiff()`, which upstream calls from
`setArgsComplete()` the moment the assistant message ends — so the diff is drawn from the file **as
it still stands**, before the tool runs. `EditDiff::preview()` is it, and `ToolExecutionComponent`
now computes it in the same place.

**The reason for porting it is not that the diff arrives a second early.** The gap between "the model
finished writing this call" and "the call ran" is normally nothing — and is however long a
`tool_call` hook takes to ask whether the edit may run. That guard is the whole reason hooks can ask
anything, and until this it asked *"Let edit run?"* with a path and no diff: approving a change to a
file without being shown the change.

The comment that used to sit in `ToolExecutionComponent::edit()` said this was left out because a
preview "would mean a second copy of the replace logic". That was wrong twice, and both halves are
worth keeping: upstream does not have a second copy either — `edit.ts` and `computeEditDiff()` share
`edit-diff.ts` — and the sharing was available here too, which is what `EditDiff::apply()` is. **A
reason written next to a deviation is only worth what is in it**, and "we would have to duplicate it"
should always be read as a question about whether it can be shared.

Three things decided while porting it:

- **The result's diff still wins once the tool has run.** Upstream keeps showing the preview, which
  is the same text in every case but one — somebody changed the file in between — and there the file
  on disk is the honest answer.
- **`apply()` is shared, so a refusal cannot be worded twice.** Upstream's two readers have already
  drifted: `No changes made to` against `No changes would be made to`, one fact in two sentences.
  `testAPreviewRefusesInTheWordsTheEditWouldHaveUsed` asserts the two messages are the same string.
- **No cwd means no preview**, rather than resolving a relative path against whatever directory the
  process happens to be in. The two `!command` call sites pass none and neither is ever an edit.

**And the harness had two gaps that this could not have been tested through**, which is the part
worth remembering. `InteractiveModeTest`'s provider stub pushed `StartEvent` and `DoneEvent` and
nothing between, so no `MessageUpdateEvent` was ever emitted and a tool call's component was created
by `tool_execution_start` — *after* the message had ended. And it called `$agent->setTools()` without
`HookedTool::wrap()`, so **no `tool_call` hook could fire in any test in that file**: a guard that
works in production was unreachable from the one place that drives the whole front end. Both are
fixed, and the second is the same shape as the settings that were "live in production and inert in
every test" a few entries down.

Regression test: `InteractiveModeTest::testAnEditIsOnScreenBeforeAHookIsAskedWhetherToAllowIt`,
which parks a real turn on a real dialog and checks the diff is up and the file is not yet changed.
It fails if either end of the wire is removed — the `setArgsComplete()` loop or the cwd. The
component's own end is the five `ToolExecutionTest` cases under "an edit shown before it happens".

## `pig @notes.txt` on a latin-1 file was a dead session, and the reason is a platform difference

The same killer as the three entries below, on the one input that looked obviously safe: **what the
person said.** Four transcript paths had been found and guarded — a tool's output, a diff, a hook's
message, a typed command's output — and `UserMessageComponent` was not among them, because a
keystroke is a keystroke. It is not:

```
pig @notes.txt "what is wrong here"        # a latin-1 file, or anything saved from an editor
pig $'ask about \xe9 this'                  # a shell hands over bytes, not text
```

Either one throws `Grapheme split failed` out of `render()` on the **first frame**, before a word of
it reaches the model. Traced twice, because fixing the transcript uncovered a sixth path:
`TreeList::oneLine()` — so `/tree` on such a conversation was a second dead session.

**The root of it is a platform difference, not an oversight.** Upstream reads an `@file` with
`readFile(path, "utf-8")` and gets its argv from `process.argv`; Node decodes both as UTF-8 and
substitutes U+FFFD for a byte it cannot read, so upstream's `<file>` element and its messages are
**always text**. `file_get_contents()` and PHP's `$argv` hand over bytes. That is the mirror of the
usual case in this file — where PHP has what JavaScript reaches for a package for — and it is worth
stating as its own question: **what does upstream get for free from its runtime that PHP does not?**
Both doors are decoded where they read now, which is where upstream's decoding happens.

And the display paths are guarded too, because the entry doors are not the only way in — a **paste**
goes through the editor, and `mb_str_split()` keeps a malformed byte as it finds it. That is
`UserMessageComponent` (the fifth path) and `TreeList::oneLine()` (the sixth).

**Then the same read found four implementations of "fold a message into one line for a list", each
wrong in its own way** — the first shape from the index, at its largest so far:

| | pattern | what a malformed byte did |
|---|---|---|
| `SessionManager::opening()` | `/\s+/u` | nothing: it only ever reads what `append()` wrote, and that went through `JSON_INVALID_UTF8_SUBSTITUTE` |
| `HtmlExport::opening()` | `/\s+/u` | `preg_replace` answers **null**, `(string) null` is `''`, so the heading fell through to "a conversation" and the page lost what it was about |
| `TreeList::oneLine()` | `/[\r\n\t]+/`, no `/u` | never null and never cleaned either, so the bytes reached `Width::truncate()` — the dead session above |
| `InteractiveMode::describe()` | `/\s+/u` | nothing, because **it has no caller**: `/tree` uses `TreeList::describe()`. Dead code, left alone pending a decision — see below |

The rule the second and third rows share: **a `/u` pattern over text from outside is a silent
truncation**, exactly as `htmlspecialchars()` and `json_encode()` are, and the same family this file
already names. `preg_last_error()` *does* say 4 afterwards, and nobody asks it; the `(string)` cast
turns a failure into an empty string one character later.

`SessionManager::opening()` is the interesting row: it is right, and only because a guard one method
away happens to cover it. `testASessionThatOpensWithBytesThatAreNotUtf8StillHasALabel` pins that
rather than the code, so the day somebody reads the label from memory instead of from the file, it
goes red.

Regression tests, one per end, each of which the other four leave green:
`FileArgumentsTest::testAFileThatIsNotUtf8ArrivesAsTextRatherThanAsItsBytes`,
`ArgumentsTest::testAMessageFromTheShellArrivesAsTextRatherThanAsBytes`,
`InteractiveModeTest::testATranscriptDrawsAMessageWhoseBytesAreNotUtf8`,
`testATreeRowSaysWhatWasSaidEvenWhenItsBytesAreNotUtf8`,
`HtmlExportTest::testATitleSurvivesAFirstMessageWhoseBytesAreNotUtf8`.

## One byte that is not UTF-8 in a tool's output took the session down

`cat` on anything that is not text — a binary file, a latin-1 log, `head /dev/urandom` — ended the
session:

```
Pig\Tui\TuiError: Grapheme split failed: Malformed UTF-8 characters, possibly incorrectly encoded
```

Everything that measures or cuts a line goes through `Graphemes::split()`, which is
`preg_match_all('/\X/u')`, and `preg_match_all` **answers false on malformed UTF-8**. So one stray
`0x80` in what a tool said threw out of `render()`, inside the loop's own input callback, where
nothing catches it. Reproduced from four shapes: a stray continuation byte, a truncated multi-byte
sequence, a lone `0xff`, and a surrogate code point encoded as UTF-8.

Upstream has `sanitizeBinaryOutput` for exactly this, applied in the same method, with a comment
saying it is there because its width function crashes otherwise. **And pig already had the function
— `Ai\Utils\Utf8::sanitize()` — with no caller on this side of the tree**, because it was written for
the other end of the same problem: making a request body encodable. One end of the pipe sanitised and
the other did not, which is this file's commonest shape with an unusually bad consequence.

Two things worth keeping:

- **The session file was already safe and that is why nothing noticed.** `SessionManager` encodes
  with `JSON_INVALID_UTF8_SUBSTITUTE`, so a binary tool result persists as U+FFFD rather than
  failing the write. The display path was the only one without a guard, and the display path is the
  one that must never throw.
- **`Graphemes::split()` returning false is a whole class, not one site.** Anything that reaches it
  with bytes from outside — a hook's message, a custom tool's text, a pasted line — has the same
  ending. Sanitising at the display boundary is what was reproduced and fixed; if another source
  turns up, this is the entry, and `Utf8::sanitize()` is the call.

Regression tests: the four `ToolExecutionTest::testOutputThatIsNotUtf8IsDrawnRatherThanFatal` rows,
which assert the frame is drawn *and* is UTF-8 rather than merely not throwing.

## One `read` of a binary file ended a gateway session for good

Five places encode a conversation, and four of them had an answer to bytes that are not UTF-8: the
providers sanitise each text block, `SessionManager` and `RpcMode` pass
`JSON_INVALID_UTF8_SUBSTITUTE` — and `RpcMode`'s docblock says exactly why, which is the sentence
that names the bug in the fifth:

> a tool that read a binary file must not be able to stop the protocol dead

`Agent\StreamProxy::request()` was the fifth, and it encoded the whole conversation with neither.
So `read` or `bash` on one latin-1 log made `json_encode` answer false and the turn died with
`The conversation could not be encoded for the proxy` — **and then so did every turn after it**,
because the result stays in the conversation, and compacting it away needs a model call through the
same encoder. A gateway session was over, permanently, with a message that names nothing the person
did.

Fixed with the flag rather than `Utf8::sanitize()` per field: this encodes the whole request in one
call, so a flag cannot miss a field somebody adds later. The `=== false` guard stays for what the
flag does not cover — a recursive structure, a float that is not a number.

**The shape is the first one in the index, at its purest**, and worth noting how it was found: not
by reading `StreamProxy`, but by asking of `read`'s output "where does this end up, and who deals
with it there" — which is the same question the `edit` diff entry above came from, pointed at a
different pipe. `HtmlExport` is a sixth site and was checked: it keeps every byte.

Regression test: `StreamProxyTest::testAToolResultThatIsNotUtf8StillReachesTheGateway`.

## Editing a file with one byte that is not UTF-8 in it took the session down

The same killer as the entry below about `cat`, through the one path that fix did not cover, and
found by writing down *why* pig keeps a file's odd bytes where upstream rewrites them:

```
Pig\Tui\TuiError: Grapheme split failed: Malformed UTF-8 characters, possibly incorrectly encoded
```

`edit`'s display text is not the tool's output — it is a **diff, built from the file's own bytes**.
So `edit` is the one tool that can hand the renderer something that is not UTF-8 with no tool output
involved at all, and it is the only display path that never went through `Utf8::sanitize()`:
`ToolExecutionComponent::output()` sanitises, `edit()` handed `details['diff']` straight to
`DiffView::render()`. One latin-1 file, one log with a stray byte, and the next frame threw out of
`render()` inside the loop's own input callback.

The fix is at the diff's display boundary — `DiffView::render()`, whose docblock already said it is
the thing that paints a diff, and which is now where the bytes are made safe for every future caller.
The tool's own text is left alone: it goes to the model and into the session file, and both of those
have their own answers (`JSON_INVALID_UTF8_SUBSTITUTE` for the file).

**The shape, and it is the second time this exact question has been worth asking:** a sweep for
"which display paths sanitise" is not the same sweep as "which display paths are built from *text a
tool printed*". This one is built from a file, and that is why it was not on the first list.

Regression tests: the four `DiffViewTest::testADiffOfAFileThatIsNotUtf8IsDrawnRatherThanFatal` rows
at the boundary, and the four `ToolExecutionTest::testAnEditDiffThatIsNotUtf8IsDrawnRatherThanFatal`
rows through the component, which are the ones that reproduce the session ending.

## A subprocess whose standard error nobody reads blocks forever with nothing on standard output

Pressing `@` froze the terminal for good. `CombinedAutocompleteProvider::runFd()` opened `fd`
itself, read standard **output** to EOF, and closed standard error unread — and `fd` writes a
warning per path whose metadata it cannot read. On a tree with enough restricted directories that
fills the stderr pipe, `fd` blocks writing to it, so it never closes stdout, so the read never
returns. There was no timeout either, and this runs from a keystroke inside the loop's own input
callback, so the whole UI went with it. Reproduced with a stand-in for `fd` that writes 360KB to
stderr before answering: the call never came back.

**`Process::run()` already drains both pipes, and its own comment names this exact failure** —
*"Read stderr too, or a command that writes a lot to it fills the pipe and blocks forever with
nothing on stdout to show for it."* So this is the first shape from the index above at its purest: a
rule written down in one place, with the reason beside it, and a sibling forty lines long that
re-implemented the same subprocess worse and did not follow it. The fix is not the missing drain
added a second time, it is the sibling deleted — `runFd()` now calls `Process::run()`, which brings
the timeout it never had.

The timeout is short (2s) and that is the interesting half: the query re-runs `fd` on **every
keystroke**, so a picker able to freeze the terminal for longer than a moment is worse than one that
comes up empty on a very large cold tree. Upstream blocks indefinitely, because `spawnSync` does.

**The general rule: `proc_open()` with a pipe nobody reads is a deadlock waiting for a noisy
command.** Either read every pipe you opened, or do not open it — and in this tree the answer is
almost always `Process::run()`, which is what the other callers use.

Regression tests: `AutocompleteTest::testFdFloodingStandardErrorDoesNotHangThePicker` — which hangs
rather than fails if the old read goes back, so it is worth knowing that a hanging suite here means
that line — and `testAQuietFdStillAnswers` beside it, so the fix cannot become "stop running fd".

## The `@` picker had the third copy of `Paths::expand()`, and it was the worse one again

Word for word the same find as the two hook loaders below — `~` expanded, spaces not normalised —
one package over, which is why the sweep that caught those two did not see this one.
`CombinedAutocompleteProvider::expandHome()` is in `pig/tui`, and `Tools\Paths::expand()` is in
`pig/coding-agent`, so the picker could not have called it. What it cost: a path pasted out of
Finder has U+202F where the name on disk has a space, `is_dir()` says no, and the picker answers
**nothing at all** — the same silence, on the one input that arrives by paste rather than by typing.

U+202F is not whitespace to `\S`, which is why this is reachable: a pasted path with spaces in it
arrives as **one word**, where the same path typed with ordinary spaces is cut at the first one by
`pathPrefix()`. So a path with real spaces is not completable by either spelling — upstream's
behaviour too, and the one thing in that method that is a limit rather than a rule.

`Tui\Paths::expand()` is the implementation now and `Tools\Paths::expand()` calls it, which is the
only direction that works. Two things about the fix are worth more than the move:

- **Normalising is not enough on its own, because it breaks the other direction.** A macOS
  screenshot really is called `Screenshot … 4.31.05<U+202F>PM.png` on disk, and a folder somebody
  named `Q1<U+202F>2026` really has that space in it. So the directory is looked for under the
  normalised spelling **and then under the one that was typed** — the pair of answers
  `Tools\Paths::resolveForRead()` already arrives at by retrying — and the name comparison
  normalises **both sides**, so a pasted needle finds the file with the ordinary space and a real
  U+202F still matches itself.
- **Half of that second point was dead code until a test was written for it.** Mutating the
  name-side normalisation away broke nothing: the pasted-needle case passes with the needle alone
  normalised, and the reverse direction cannot be reached from here at all. What makes it
  load-bearing is the case neither test covered — a name holding U+202F, typed or pasted with
  U+202F — which matches only if both sides are normalised. Same as the whole-float branch in
  `JsonSchema::identity()`, except that one really was doing nothing and was deleted; **the
  mutation check is what tells those two apart, and only if you go looking for the missing case
  rather than deleting on the first silent mutation.**

Regression tests: `AutocompleteTest::testAPathCopiedOutOfAFileManagerStillCompletes`,
`testAPastedNameMatchesTheFileWhoseNameHasTheOrdinarySpace`,
`testAFileWhoseRealNameHoldsThatSpaceStillMatchesItself` and
`testADirectoryWhoseRealNameHoldsThatSpaceIsStillListed` — four, because there are two spellings on
each of two sides and no one case covers a second.

## Killing the shell does not kill what the shell started

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

## A command finishes twice, and the pipes closing is the first of them

The three rows above failed on 8.3 in one full-suite run and passed on 8.4 and in twenty-five runs of
their own file:

```
expected "Command exited with code 15" to contain "exited with code 143"
```

The first hypothesis was a difference between the two PHP versions, and a probe disproved it —
worth recording, because the probe was written to confirm rather than to test, and it reported
`running=false signaled=true termsig=15` six times out of six on both. What it did not do was
reproduce the conditions: the failing run had the other version's suite beside it.

**The two events are the pipes reaching EOF and the process exiting, and they arrive in that
order.** Both `Run` and `Process::runAsync()` complete their `Deferred` on the first, so the status
was asked for in the window between — where `running` is still true, so `signaled` is false and the
number that comes back is the bare signal. Under four spinning CPUs that window caught **23 of 120
runs** of `kill -15 $$`, on 8.3 and 8.4 alike.

`Process::awaitFinish()` is the wait for the second event, polled at `POLL_MICROSECONDS` because
PHP offers no waiting that leaves the status readable — `proc_close()` is the blocking wait and it
is also the end of the resource. Three things about it are load-bearing:

- **It is on the loop.** By the time it is reached the pipes are shut, so nothing else would wake
  the loop, and `proc_close()` holds the one thread for as long as the command has left. That was a
  second, worse bug hiding behind the first: `bash -c 'exec 1>&- 2>&-; sleep 30'` froze pig for
  thirty seconds — no keystrokes, no spinner, no escape — which is exactly the freeze
  `runAsync()` was written to remove, arriving by the one path that skipped it.
- **It happens while the timer is still armed**, inside the `try` rather than after the `finally`
  that cancels it. Otherwise a command that outlived its output is waited out in full and then
  reported as having *worked*: the timeout it blew through was cancelled before the wait began.
  That is what `testATimeoutStillReachesACommandThatOutlivedItsOutput` and its escape twin hold
  down, and moving the call one block out is what makes them fail.
- **There is no deadline of its own**, for the same reason: the caller's is still running, and a
  second one underneath would cut a command short on a rule nobody asked for.

A command that shuts its own standard output and then keeps working is what makes all of this
reproducible without load — the window becomes the whole of its remaining life.
`testACommandWhoseOutputEndsBeforeItDoesStillReportsTheSignal` exists in both files for that
reason: *a race is worth a test that is not one.*

## A command's output was cleaned on the way to the screen and not on the way to the model

`bash-executor.ts` has no counterpart file here and that is right — it is upstream's *second*
command runner, the one `AgentSession.executeBash()` uses for `!command`, where `core/tools/bash.ts`
is the one the tool uses. pig has one `Tools\Run` for both, which is the better arrangement and is
also how this hid: reading the sweep as "one file, no counterpart, because pig shares" stops one
line too early. The line after it is upstream's own comment:

```ts
// Sanitize once at the source: strip ANSI, replace binary garbage, normalize newlines
const text = sanitizeBinaryOutput(stripAnsi(data.toString())).replace(/\r/g, "");
```

pig cleaned the same text, with the same three rules, in what was then `Interactive\SafeText` — **at the display
boundary**. So the screen was right and the two readers nobody looks at until later were not: the
**model** got `\e[32m` and the `\r` that redraws a progress line, and so did the **session file**,
which is pi's file. `npm`, `cargo` and `docker` colour their output whether or not anybody is
watching, and anything with a progress bar writes `\r` by the hundred — so what the model read of a
failed build was a line overwritten twenty times and a third of its tokens spent on escapes.

`Run::read()` cleans each chunk now, through the same one rule, so the model, the session file,
the spill file and the screen all get one text. Three things about it:

- **It is a deliberate step past upstream for the `bash` *tool*.** Upstream cleans at the source in
  `bash-executor.ts` and not in `bash.ts`, where only the component cleans — so there the model
  reads the escapes from a tool call and not from a `!command`. Two answers to one question, and
  this takes the one with the reason written beside it, the same way the image token count did.
- **An escape split across two reads survives**, because each chunk is cleaned on its own. Upstream
  has that too, and the alternative is holding bytes back in case the rest of a sequence arrives,
  which would stop the output being live. Same for a multi-byte character cut in half, which
  `Utf8::sanitize()` turns into U+FFFD at the seam.
- **The spill file is cleaned too**, as upstream's is. It is named in the truncation notice so the
  model can go and read the part that was cut, so it has to be the same text as the part that was
  not.

**Where upstream keeps this rule is what decided where pig keeps it.** `utils/shell.ts` exports
exactly three things — `getShellConfig`, `killProcessTree` and `sanitizeBinaryOutput` — and the first
two are `Tools\Shell::bash()` and `Tools\Shell::killTree()`. So pig's `Tools\Shell` **is** that file,
and it was missing the third: it sat in `Interactive\SafeText`, which was the right home for as long
as every caller drew on a screen and one namespace too narrow the moment `Run` needed it. It is
`Tools\Shell::sanitize()` now, and `Run` was already using `Shell` for the other two.

That the two belong together is not a filing convenience. Killing a command's process tree and
reading what the command printed are the two things you cannot do by asking the shell politely, and
the second is only safe to skip downstream **because** it happened at the source — which is the same
sentence as the entry above and as the fourth killer path below.

What upstream does *not* have is one composition: each of its three readers writes the steps out, and
the three do not agree. `bash-executor.ts` and `tool-execution.ts` both spell
`sanitizeBinaryOutput(stripAnsi(x)).replace(/\r/g, "")`, while `bash-execution.ts` is
`stripAnsi(chunk).replace(/\r\n/g, "\n").replace(/\r/g, "\n")` — no `sanitizeBinaryOutput`, and `\r`
turned into a newline rather than dropped. That third spelling is safe only because the runner had
already cleaned what it was handed, which is worth knowing before anybody reads it as the intended
rule.

| pig had | now |
|---|---|
| `Interactive\SafeText::of()` | `Tools\Shell::sanitize()` |

Regression tests: `AgentSessionTest::testWhatACommandPrintedIsCleanedAtTheSourceAndNotOnlyOnScreen`
for `!command`, `BashToolTest::testWhatTheModelReadsHasNoEscapesOrProgressLinesInIt` for the tool,
and `testTheFullOutputFileIsCleanToo` for the file the notice points at.

## The model was told the command was cut and the person watching it was not

`bash-execution.ts` is upstream's component for a typed `!command` — its own bordered block, its own
`$ cmd` header, its own loader, and a status line it builds out of up to four parts: how many lines
are hidden, `(cancelled)`, `(exit N)`, and `Output truncated. Full output: …`. pig draws a typed
command with the **same** `ToolExecutionComponent` the model's `bash` calls use, which is the
arrangement the note about the two preview sizes describes and is worth keeping.

What it cost is the last three of those parts. `AgentSession::executeBash()` returns a
`BashExecution` carrying the exit code, whether escape stopped it, whether the output was cut and
where the whole of it was spilled — and `runCommand()` handed the component the output text and one
boolean. So:

- **`!exit 3` was the right colour and silent about the 3.** Which number it is, is the entire point
  of the entry on signal numbers: 137 is a thing a person recognises and `1` is not.
- **A command escape stopped looked like a command that failed**, same colour, no word.
- **`!seq 2500` was cut on screen with nothing saying so**, while `BashExecution::toText()` appended
  `[Output truncated. Full output: /tmp/pig-bash-…]` for the **model**. The notice has two readers —
  this file says so a few entries up — and here exactly one of them was being served, the one that
  cannot ask.

The facts are read out of `details` rather than taken as new constructor arguments, because the
model's `bash` tool already writes `fullOutputPath` there under pi's own name; so its truncated calls
gain the same line, which is what upstream shows for both.

**And the first version of the fix lost two of the three, in a way the mutation check named
exactly:** `drawBash()` returns early when the output is empty, and `exit 3` and an escape-stopped
`sleep` are precisely the two runs that print nothing at all — so the status went above that return.
Upstream keeps its status parts outside its own output branch for the same reason.

**The harder half was the test, and it is the "ask whether the assertion could fail" rule again.**
The cancellation case ended in `settle()` before pressing escape, and `settle()` polls with no
timeout: with the command's pipes the only thing to wait on, it sat through the whole five seconds,
so escape arrived at a command that had already finished cleanly — `cancelled: false, exit: 0`, and
a test that could never have gone red for the right reason. `turnTheLoop()` arms an expired timer of
its own, so the poll comes back at once and the command is still running when the key goes in; the
test asserts that too, before it presses anything.

Also worth knowing, since the file was read for this: the existing `testAFailingCommandIsMarkedAsOne`
checked the error colour on screen and then read the exit code off the **message**. That is the
shape that let this survive — *a fact checked where it is present is not a fact checked where it is
read.*

Regression tests: `InteractiveModeTest::testTheExitCodeIsOnScreenAndNotOnlyInTheConversation`,
`testACancelledCommandSaysItWasCancelledRatherThanJustFailing`,
`testTruncatedOutputSaysSoAndWhereTheRestIs`.

## Output truncated by line count also needs somewhere to look

Upstream's bash tool writes the full output to a temp file only once it passes the **byte**
limit, and the truncation notice then names that file. Two thousand short lines is nowhere near
50KB and is still truncated by the line limit, so upstream's notice in that case says where to
find a file it never wrote. Here the spill starts when *either* limit is passed, which is the
same pair of limits truncation itself uses.

Regression test: `BashToolTest::testTruncatedOutputSaysWhereTheWholeOfItIs`.

## `--ignore-file` applies a nested `.gitignore` in the wrong place

Upstream's `find` collects every `.gitignore` below the search root and passes each one to
`fd` as `--ignore-file`, so that nested ones apply outside a git repository too. They do not
apply *where they sit*: `--ignore-file` patterns are matched against the whole search root, so
a `src/.gitignore` containing `generated/` also excludes `other/generated/`, and files that
should have been found are silently missing.

Both tools already have the flag for this. `--no-require-git` makes `fd` and `rg` read
`.gitignore` outside a repository with the ordinary nesting rules, which is what was wanted.
So the collection is not ported and the flag is passed instead.

Regression test: `SearchToolsTest::testANestedGitignoreAppliesOutsideAGitRepositoryToo`.

## `fd --glob` matches the file name, so every pattern with a directory in it found nothing

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

## `fd` exits 0 when it found nothing, so failure cannot be read off an empty result

`rg` exits 1 for "no matches" and 2 for an error; `fd` exits **0** either way. So a non-zero
exit from `fd` is always a real failure — usually a glob it would not parse — and `find` was
reporting all of them as "No files found matching pattern", which is a silent fallback of
exactly the kind this project forbids: the model is handed a plausible answer and has no way
to tell it apart from the truth.

`Process::capture()` cannot express this, since it folds "ran and said nothing" into "did not
run". `Process::run()` was added to return the exit code and stderr separately, and `find` now
throws with fd's own message.

**And `grep` was the sibling that did not follow it**, found by auditing `grep.ts` long after this
entry was written. It streams rather than collecting, so it used `Process::stream()` — which read
standard error only to stop the pipe filling and **threw the text away**. A bad pattern therefore
reached the model as `ripgrep exited with code 2`, where rg had said:

```
regex parse error:
    (?:[unclosed)
       ^
error: unclosed character class
```

One of those names the character to fix. `stream()` now hands back `[exit, stderr]` like `run()`
does — one caller, and being the odd one out of the two was the bug. Worth noticing *how* it hid:
there was a regression test, `testABadRegexIsReportedRatherThanReadAsNoMatches`, and it asserted
`'ripgrep exited'` — **it pinned the shortfall**, because that string was what there was when it was
written. A test written against what the code says rather than against what the caller needs will
hold a half-fix in place for as long as nobody re-reads it. It asserts rg's own words now, and that
the code number is *not* there.

Regression tests: `SearchToolsTest::testABadPatternIsReportedRatherThanReadAsNoMatches` for fd and
`testABadRegexIsReportedRatherThanReadAsNoMatches` for rg.

## A tool result outliving the call it answers

A turn that ended in an error or was aborted is incomplete — half-parsed call arguments, a
reasoning item with nothing after it — and asking the model to continue from one is worse than
dropping it. `TransformMessages` drops the whole turn, as upstream's `transformMessages()` does,
*before* it invents results for dangling calls, so no `No result provided` is made for a call that
is never sent. Had it invented one first, the request would carry a `function_call_output`
addressed to a `call_id` that was never sent — which OpenAI rejects outright, so the conversation
could not be continued at all.

Regression test: `OpenAiResponsesTest::testAnAbortedTurnsThinkingAndCallsAreNotSentBack`.

## `AGENTS.md` that could not be read was skipped without a word

Every loader in this repository hands a problem it cannot fix back to `bin/pig` to print before
the UI starts — `Skills`, `HookLoader`, `CustomToolLoader`, `Settings`, `CustomModels`. `ContextFiles`
was the one that swallowed it. Its comment said *"skipped rather than fatal"*, which answers
whether to **stop** and says nothing about whether to **speak**; upstream prints a yellow warning
here. Permissions and a broken symlink are the two ways in, and what it costs is that the agent
works without instructions the person wrote and nobody ever finds out.

Two things about the shape of the fix:

- **`load()` keeps its signature and `loadWithWarnings()` is the new one**, which is upside down
  from its three siblings — `Skills::load()`, `HookLoader::load()` and `CustomToolLoader::load()`
  all return `[things, problems]`. The reason is not a design preference: `Prompt\SystemPrompt`
  calls `load()` and is a file the developer owns, so the tuple cannot be pushed into it from
  here. Aligning it is a one-line change the next person to touch that file can make.
- **An unreadable `AGENTS.md` no longer hides the `CLAUDE.md` beside it.** The fall-through to the
  second name is upstream's behaviour and the useful one, since the second name exists precisely
  for a project that has one and not the other.

**And the mutation check caught the missing end again.** Dropping the warnings where
`CodingAgent::session()` collects them broke no test — `ContextFilesTest` proves the loader and says
nothing about who reads it. That is the third time in four batches, which is worth saying plainly:
**after wiring anything through, mutate each end separately, not the middle.** Both ends have tests
now; they skip as root, so they run where the suite is actually run rather than in the container.

## Two loaders each had their own worse copy of `Paths::resolve()`

`HookLoader` and `CustomToolLoader` both take extra file paths from the settings, and both had
their own private `resolve()` — the same fourteen lines, pasted, doing a worse job than the
function three directories away that exists for this:

```php
// what both had
if (str_starts_with($path, '~')) {
    $home = getenv('HOME');
    $home = $home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/');
    return $home . substr($path, 1);
}
```

Three things wrong with it, in order of how much they cost:

- **It does not normalise Unicode spaces**, which is the whole reason `Paths::expand()` exists:
  the space in a path copied out of Finder is U+202F. So a hook that is right there was reported
  as `not a readable file`, naming a path that looks identical to the one on disk. Reproduced.
- **A missing `HOME` becomes `/tmp`**, which is a silent fallback of exactly the kind the
  conventions forbid — `~/.pig/agent/hooks/x.php` resolves to a file under the temp directory and the
  complaint names a path nobody wrote. `Paths::expand()` leaves the path alone.
- **It does not collapse `..`**, which `Paths::resolve()` has done since the `grep` prefix fix.

Both now call `Paths::resolve()`. **The lesson is the one this file keeps arriving at from the
other direction:** the question is not "is this fourteen lines correct" but "who else answers
this question, and do they agree" — and the two copies could not have diverged from `Paths`
without diverging from each other too, which is why neither looked wrong on its own.

**And there was a third copy, which this sweep could not see: `CombinedAutocompleteProvider`, one
package over.** A grep across `coding-agent` finds the two loaders and stops there, so the rule to
take from this entry is that the sweep is **every package** — the same fourteen lines, the same half
missing, the same silence. See the `@` picker entry above.

Found in the same read and *not* changed, because checking came first: both loaders use
`glob('*.php')`, which skips a leading dot where upstream's `readdirSync` does not — and here
that is protective rather than a gap. A hook directory is a directory somebody edits, and Emacs
writes a `.#name.php` symlink beside the open file; upstream picks that up and reports a load
error for as long as the editor is open. `Migrations` scans instead, for the opposite reason:
missing a session file there loses a conversation. Both now say so where the code is.

Regression tests: `HookLoaderTest::testAConfiguredPathCopiedOutOfAFileManagerStillResolves` and
its twin in `CustomToolsTest`.

## A fixed unpack directory plus a glob installs last week's binary

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

## The override for pi's directory was a variable name nothing sets

Twenty-first, and the whole of it is one string. Upstream does not write its environment variable
down: `ENV_AGENT_DIR = \`${APP_NAME.toUpperCase()}_CODING_AGENT_DIR\`` with `APP_NAME` read from
`package.json`, so the name is **`PI_CODING_AGENT_DIR`** and the only place it appears literally is a
comment ("e.g., PI_CODING_AGENT_DIR or TAU_CODING_AGENT_DIR") and the help text. Reading
`getAgentDir()` alone suggests `PI_AGENT_DIR`, and that is what `Config::piHome()` looked for — a
name nothing sets, with a docblock calling it "upstream's own override".

So somebody who had moved pi's directory the documented way was read from `~/.pi/agent` anyway, and
finding pi's files is the promise everything else about interop rests on. `PI_CODING_AGENT_DIR` is
read first now, `PI_HOME` stays as pig's own, and `PI_AGENT_DIR` is gone rather than kept as a third
spelling of one thing.

**`Config` had no test at all** — 60 lines of path arithmetic, which is exactly the size that goes
unchecked. `ConfigTest` states all six answers, including that the invented name is not read any
more.

## The model had seven tools where upstream gives it four, and no way to say otherwise

Eighteenth, and the one the developer decided rather than the code: `CodingAgent::session()` started
with `ToolSet::ALL`, so every run handed the model `read, bash, edit, write, grep, find, ls`.
Upstream's default is `codingTools` — the first four — and its `--tools read,grep,find,ls` names the
set when something else is wanted. **pig had the wider default and not the flag.**

Both are fixed the same way round: the default is upstream's four, and `--tools` is ported, with a
name that matches nothing refused by name. `--read-only` still *swaps* the set for the read-only
four rather than narrowing whatever was asked for, which is what it always did.

**Then pi 1.0.3's shape of the flag was taken too**: an entry is a name or a `*` pattern
(`Tools\ToolSelection`, upstream's `createToolNameMatcher()`), `--exclude-tools` takes away after
`--tools`, and `--no-mcp` leaves the MCP extension unloaded. Three decisions in it:

- **An MCP tool is kept unless an entry starts with `mcp__`.** `--tools read,codemode` means those
  two and must not also disconnect every server codemode reaches through; upstream fixed exactly
  that in 1.0.3. `--exclude-tools` has no exception, because a denylist means what it names.
  `allows()` and `matches()` are the two questions, on purpose two methods.
- **The typo check moved from `bin/pig` into `CodingAgent::session()`**, because which names
  exist is only known once the custom tools have loaded, and the refusal now lists them too. The
  list is read *before* the filter is applied, or it would be whatever the typo left — the first
  draft got that backwards and `testAToolsEntryThatNamesNothingIsRefusedByName` said so.
- **The filter lives on `CustomToolSet` (`keep()`), not in the `onChange()` listener.** An MCP
  server's tools are adopted after startup, and a filter the listener had to remember to apply is
  the "wired at one end only" shape; mutating `adopt()` to skip it turns one test red.

**The reason is the rule at the top of this file, in the developer's own words: more tools is more
confusion.** A model with seven spends part of every turn choosing between them, and searching
through `bash` with `rg` is what the system prompt already asks for — `grep`, `find` and `ls` stay
ported, tested and one flag away. The test that asserted the old behaviour was called
`testTheDefaultIsEveryTool`; it now asserts the four and is called
`testTheDefaultIsUpstreamsFourAndNotEveryToolPigHas`, with a sibling for each of `--tools` and
`--read-only` so the three answers cannot drift apart.

`--tools` had to join `Arguments::TAKES_A_VALUE`, which is the trap that list exists for.

## A flag that takes no value eats the argument after it

`bin/pig`'s parser decided whether an option took a value by looking at the next argument: if
it did not start with `--`, it was the value. That worked for exactly as long as nothing but
options was ever passed. The moment positional messages meant something,
`bin/pig --read-only "fix the bug"` set `read-only` to `fix the bug` and had no prompt left,
with nothing said about it.

`Cli\Arguments::TAKES_A_VALUE` and `FLAGS` are the lists, as upstream's `args.ts` spells each
option out for the same reason. **A CLI cannot afford a guess about what the next word means**, and
the failure mode of guessing is silent. What is on neither list follows upstream's `parseArgs()`: an
unknown short option is an error (`Unknown option: -x`, exit 1); an unknown long option is kept as
`unknownFlags` and refused once the extensions have loaded unless one of them registered it
(`--name=value`, or `--name value` when the next word starts with neither `-` nor `@`). Two options
are deliberately neither: `-r` takes no value (upstream's), and `--list-models` takes a search only
when one follows. `-p` takes the next word as its message unless it is a file or an option.

## A short option is not automatically a flag

`SHORT` maps `-p` to `print`, and the first version of the branch that handled it did
`$options[$short] = ''` and moved on — right for `-p`, and wrong for every short option whose
long form takes a value. `-t read,bash` would have set `tools` to the empty string and left
`read,bash` in the message list to be sent to the model as a prompt.

The same mistake as the flag that ate the prompt, one level down: **two spellings of one
option must not each carry their own idea of whether it takes a value.** So the short branch
resolves to the long name and falls through into the same code, and a test asserts that every
pair in `SHORT` parses identically.

## `~/.pig` became `~/.pig/agent`, and the downloaded binaries left `tools/`

Two moves, one of them only cosmetic and the other a collision that had been quietly working.

`Config::home()` is `~/.pig/agent` now, the same shape `piHome()` has always had — upstream's
`getAgentDir()` is `~/.pi/agent`, and pig kept everything a level up, so every path comparison
across the two tools had a step in it that was pure accident. Everything else follows for free:
sessions, `settings.json`, `auth.json`, hooks, skills and commands are all built off that one
method, and exactly two tests had the old path written out.

**No migration and no fallback.** `PIG_CODING_AGENT_DIR` still wins, and an old `~/.pig` is not searched. The
developer asked for the move rather than for compatibility, and a fallback that reads the old
directory forever is how somebody ends up with two half-populated homes and no way to tell which
one a given file came from. `mv ~/.pig/* ~/.pig/agent/` is the whole upgrade.

The second move is the one worth remembering. `ToolInstaller::directory()` was
`Config::home() . '/tools'` — **the same directory `CustomTools\CustomToolLoader` scans for
folders with an `index.php` in them.** A downloaded `fd` binary sat in the list of the person's own
tools, and the only reason nothing ever broke is a rule written for a different purpose: the
loader wants directories and a binary is a file. That is a coincidence holding two features apart,
not a design. They are `bin/` and `tools/` now, which also happens to be upstream's name for the
first. `CustomToolsTest::testADownloadedBinaryIsNotMistakenForOneOfThePersonsTools` is what
notices if they are ever merged back.

**A blanket `~/.pig/` → `~/.pig/agent/` pass over the docs is not safe**, and the obvious way to
do it damaged the docblock that had just been written to explain the change — `~/.pig/agent`
became `~/.pig/agent/agent`. The give-away is `grep -rn "agent/agent"`, which is worth running
after any such rename. Project-local `.pig/hooks` and `.pig/tools` have no tilde and are
deliberately untouched: those are per-project directories and did not move.

## A backgrounded command (`&`) leaves its pipes with the grandchild and `Run::wait()` hangs forever

**Phenomenon**:
When the model or the user ran a background job in the `bash` tool (`nohup ... &`, `(cmd) &`, starting a daemon or a service), the foreground parent exited at once with its output, but `Run::wait()` blocked forever and the session was dead until the process was killed from outside.

**Cause**:
On Unix, a process spawned by `proc_open` / `fork` inherits every open descriptor of its parent that is not `O_CLOEXEC`, including the write ends of the stdout/stderr pipes. The parent shell finishes its `echo ...` and exits, but the background grandchild still holds the write end. `Run.php` detected completion with `feof($pipe)` alone, and the OS only reports EOF once *every* write end is closed; `Run::wait()` had no default timeout either, so the Fiber waited for an EOF that never came.

**Countermeasure**:
`Run::wait()` polls the process state on `Loop::delay(0.1)`: as soon as the main command's `proc_get_status()['running'] !== true` (the shell has left its lifecycle), it gives the event loop a 50ms grace period to read and drain what is left in the buffers, then ends the `$this->finished` wait, closes the pipes and returns — a background grandchild can no longer hold the session.

## The bash timer (Elapsed / Took) and batching `test/lint.php`

**Phenomenon**:
1. A long command in the TUI (a user `!command` or the model's `bash` tool) showed no elapsed time in its block — no way to see that it had taken `Elapsed 30.3s` or `Took 53.1s`.
2. `php test/lint.php` took 53.1 seconds, slowing down every pre-commit check.

**Cause**:
1. Upstream's `packages/coding-agent/src/core/tools/renderers/bash.ts` records `startedAt` and `endedAt`, shows `Elapsed ${formatDuration}` while running and `Took ${formatDuration}` when done; pig's `ToolExecutionComponent` had not ported that timer.
2. `test/lint.php` ran `exec(PHP_BINARY . ' -l ' . $file)` inside `foreach ($files as $file)` — 637 files, 637 PHP processes started one after another, and the process startup cost was the 53 seconds.

**Countermeasure**:
1. `ToolExecutionComponent` and `InteractiveMode` carry upstream's `formatDuration()` (one decimal in seconds `30.3s`, `3m 42s`, `1h 15m 30s`) and render a muted `Elapsed <duration>` while running and `Took <duration>` when done.
2. `InteractiveMode::executeBash` schedules a refresh every 500ms so a command with no stdout still shows a moving timer.
3. `test/lint.php` runs `php -l` on `array_chunk($files, 100)` batches and falls back to one file at a time only when a batch reports a syntax error; a full lint went from **53.1s to 0.9s (59×)**.

## An empty tool result was described as "see attached image"

**Symptom**: when a command printed nothing (or the tool result had one empty text block), OpenAI-family models received `(see attached image)` as the tool result and went looking for an image that did not exist.

**Root cause**: upstream decides `hasText` by the length of the joined text; with no text it says `(see attached image)` only when there is an image and `(no tool output)` otherwise (Completions, Responses); Google gets `""` without an image. pig checked "is there a text block" and wrote `(see attached image)` whether or not there was an image.

**Trap rules**:
- `implode("\n", $text)` first, then test for empty; the placeholder follows each provider literally: Completions/Responses `(no tool output)`, Google `""`, Anthropic sends the joined text as is when there is no image.
- Tests: `OpenAiCompletionsTest::testAToolResultWithNothingInItSaysSoRatherThanPointingAtAnImage`, `OpenAiResponsesTest::testAToolResultWithNothingInItSaysSoRatherThanPointingAtAnImage`, `GoogleTest::testAnImageResultWithOnlyEmptyTextStillSaysThereIsAnImage`.

## Built-in tool argument conversion differed from upstream

**Symptom**: a built-in tool receiving `"path": null` got `""` in pig where upstream gives `"null"`; `"offset": ""` was an error in pig and 0 upstream; likewise `"TRUE"`, `"1"` as booleans, a single value for an array parameter, and so on.

**Root cause**: upstream's built-in tools are TypeBox schemas; `validateToolArguments()` runs `Value.Convert` first (converting only TypeBox-built nodes), then `coerceWithJsonSchema()` (whose TypeBox check uses `Symbol.for("TypeBox.Kind")`, which typebox 1.x no longer sets, so every schema goes through it). pig had no `Value.Convert`.

**Trap rules**:
- Built-in tools' `Tool` carries `typeBox: true`, and `ToolArguments::valueConvert()` runs only for them; plain schemas from extensions and MCP do not go through it.
- Expected values are what typebox 1.3.27 (the version upstream pins) actually does, not what one remembers.
- Tests: `ToolArgumentsTest::testABuiltInToolsArgumentsAreConvertedTheWayTypeBoxConvertsThem`, `StrictToolSamplingTest::testEveryBuiltInToolIsConvertedTheWayUpstreamsTypeBoxSchemasAre`.
