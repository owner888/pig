# Known traps: PHP and the async runtime

Fiber, Loop, Future, sockets, and where PHP is not JavaScript. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [`stream_socket_enable_crypto()` returns `0`, not just `true`/`false`](#stream_socket_enable_crypto-returns-0-not-just-truefalse)
- [`getenv()` answers `''` for a variable exported without a value, and `!== false` calls that present](#getenv-answers--for-a-variable-exported-without-a-value-and--false-calls-that-present)
- [A JavaScript string offset carried across as a byte offset lands inside a character](#a-javascript-string-offset-carried-across-as-a-byte-offset-lands-inside-a-character)
- [PCRE's `$` matches before a trailing newline, so `^…$` is not "the whole string"](#pcres--matches-before-a-trailing-newline-so--is-not-the-whole-string)
- [A signal makes `stream_select()` return `false`](#a-signal-makes-stream_select-return-false)
- [A tick polls after its callbacks, even when they ended the program](#a-tick-polls-after-its-callbacks-even-when-they-ended-the-program)
- [Closing a stream without cancelling its watcher](#closing-a-stream-without-cancelling-its-watcher)
- [Resuming a Fiber that has not suspended yet](#resuming-a-fiber-that-has-not-suspended-yet)
- [`proc_close()` reports the signal number where every shell reports 128 plus it](#proc_close-reports-the-signal-number-where-every-shell-reports-128-plus-it)
- [A command run with `usleep()` freezes everything a person can see](#a-command-run-with-usleep-freezes-everything-a-person-can-see)
- [`packages/async` read against workerman, which is the reference it does have](#packagesasync-read-against-workerman-which-is-the-reference-it-does-have)
- [478 mutations over `packages/async`, and a wait that was a spin](#478-mutations-over-packagesasync-and-a-wait-that-was-a-spin)
- [A JavaScript index past the end is a field that disappears, and PHP has no such thing](#a-javascript-index-past-the-end-is-a-field-that-disappears-and-php-has-no-such-thing)
- [An arrow function whose body returns nothing is a fatal error](#an-arrow-function-whose-body-returns-nothing-is-a-fatal-error)
- [`stream_socket_pair()` with a dropped peer (tests)](#stream_socket_pair-with-a-dropped-peer-tests)
- [A missing `use Throwable` makes every catch in the file a no-op](#a-missing-use-throwable-makes-every-catch-in-the-file-a-no-op)
- [`tick()` waits out the timer it is polling for (tests)](#tick-waits-out-the-timer-it-is-polling-for-tests)
- [A provider that throws hangs the agent, and swallows the reason](#a-provider-that-throws-hangs-the-agent-and-swallows-the-reason)
- [A hex id used as an array key becomes an integer](#a-hex-id-used-as-an-array-key-becomes-an-integer)
- [A loop with one readable watcher blocks forever on a zero-timeout tick (tests)](#a-loop-with-one-readable-watcher-blocks-forever-on-a-zero-timeout-tick-tests)
- [PHP 8.5's `curl_close()` deprecation and the `CurlHandle` lifetime](#php-85s-curl_close-deprecation-and-the-curlhandle-lifetime)
- [`Socket::write()` and `read()` on a peer-closed connection leaked Broken pipe warnings onto the terminal and broke retries](#socketwrite-and-read-on-a-peer-closed-connection-leaked-broken-pipe-warnings-onto-the-terminal-and-broke-retries)
- [`fwrite(): SSL operation failed` zero-write spin, `fclose` close_notify warning leak, and stronger network retries](#fwrite-ssl-operation-failed-zero-write-spin-fclose-close_notify-warning-leak-and-stronger-network-retries)

## `stream_socket_enable_crypto()` returns `0`, not just `true`/`false`

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

## `getenv()` answers `''` for a variable exported without a value, and `!== false` calls that present

`Capabilities::detect()` decides whether this terminal can draw a picture, and four of its seven
tests are presence tests: is `KITTY_WINDOW_ID` / `GHOSTTY_RESOURCES_DIR` / `WEZTERM_PANE` /
`ITERM_SESSION_ID` there at all. Upstream reads each through JavaScript's truthiness, where `''` is
falsy; `getenv()` answers `false` for absent and `''` for set-but-empty, so `!== false` said yes to
an empty one.

```php
getenv('ITERM_SESSION_ID') !== false        // true  for `export ITERM_SESSION_ID`
(string) getenv('ITERM_SESSION_ID') !== ''  // false, which is what upstream reads
```

A bare `export ITERM_SESSION_ID`, a `docker run -e ITERM_SESSION_ID`, or an ssh or tmux environment
forwarding the name without a value therefore made pig send iTerm2 inline-image sequences to a
terminal that cannot draw them — **not a missing picture, tens of kilobytes of base64 printed into
the transcript**, plus a `CSI 16 t` whose answer never comes.

**`detect()` had no test at all** — twenty-five lines of environment arithmetic, which is the size
that goes unchecked, exactly as `Config`'s sixty lines of path arithmetic were. `CapabilitiesTest`
states all twelve terminals as well as the four empty-variable cases, and the twelve passing first
time is what says the rest of the detection is upstream's.

**`Tui\Env::isSet()` is the one implementation, and getting there took two goes.** The first fix put
it in `Images\Capabilities` as a private method and wrote the same comparison out again in
`Theme\Colour::truecolor()` — two copies with a comment between them — and left **four more sites
reading `!== false`** in `Clipboard\SystemClipboard`: `WAYLAND_DISPLAY`, `TERMUX_VERSION`,
`WSL_DISTRO_NAME`, `WSL_INTEROP`. Those are pig's own, with no upstream counterpart to be faithful
to, and what they cost is milder by a lot — the wrong clipboard command is chosen,
`Process::capture()` answers null for it, and the result is no clipboard rather than a screen full
of base64. They were left for a decision about public surface in `pig/tui`, which the developer has
since made: one `Env`, and a rule with six readers has one answer rather than two right ones, one
written-out one and four wrong ones. `EnvTest` states the rule and reaches three of the clipboard's
four through the methods that read them; the fourth is `linuxWrite()`'s command order, which has no
seam that does not involve running `wl-copy` for real.

`Env::home()` is the other half of that file and answers **null** where `Config::homeDirectory()`
answers the temp directory. That split is the point: pig's own files have to go *somewhere*, while
`Paths::expand()` leaves a `~` alone and the footer leaves a path unshortened — so a
`sys_get_temp_dir()` underneath all three would resolve `~/notes` into `/tmp`, which is exactly what
two hook loaders were caught doing. It also trims the trailing slash, which none of the four copies
did: with `HOME=/Users/dev/`, `~/notes` was `/Users/dev//notes` and the footer compared a path
against a prefix one character too long.

## A JavaScript string offset carried across as a byte offset lands inside a character

Four keystrokes killed the session, and all four of them are ordinary in a Chinese prompt: type
`你`, Shift+Enter, `ab`, Up. The next frame threw `Grapheme split failed: Malformed UTF-8` — out of
`Editor::render()`, which runs inside the loop's own input callback, so there is nothing above it to
catch. Typing anything before the frame was worse: it wedged the letter between 你's first and
second byte and left two invalid bytes in the buffer.

`Editor::moveVertically()` is a literal port of upstream's `moveCursor(deltaLine)`: take
`cursorCol - startCol`, clamp it, add it to the target row's `startCol`. **Upstream's offsets are
UTF-16 code units**, so for everything either project edits that arithmetic is a character count
and lands on a character. `substr()` counts bytes, so the same arithmetic aimed two bytes into a
three-byte character:

```php
// cursor two characters along `ab`, moving up onto `你` (e4 bd a0)
$this->cursorCol = $target->startCol + min($column, $target->length);   // 2 → between e4 and bd
$this->cursorCol = $target->startCol + self::lengthOfFirst($row, $offset);   // 2 graphemes → 3
```

**The rule: when upstream indexes a string, port the *unit* and not the expression.** A JS
`length`, `slice`, `indexOf` or `charCodeAt` offset is a code-unit count; `strlen`, `substr` and
`$s[$i]` are bytes, and the two agree only while the text is ASCII — which is exactly how long the
bug stays invisible. Where the offset is a cursor, counting graphemes is the same answer one step
safer than counting codepoints, because it also refuses to separate a combining mark or one person
of a family emoji from the rest.

This is the third shape from the index above — *PHP's value model is not JavaScript's* — in its
fourth guise, and the first one that crashes rather than misreporting: numbers split finer, arrays
split coarser, UTF-8 counts bytes where UTF-16 counts units. Anywhere a number crosses from one
string into another, ask what it is counting.

Found by a differential run against `editor.ts` — 1308 keystroke sequences — which crashed pig on
case 1258 before it could compare anything. With it fixed, the two agree exactly on text, lines,
cursor and submitted value, and every one of the 9990 drawn lines is exactly the terminal's width.
Regression tests: `EditorTest::testUpFromALongerLineLandsOnACharacterAndNotInsideOne`,
`testACursorMovedUpOntoAWideCharacterCanStillBeDrawn`,
`testTypingAfterMovingUpDoesNotSplitTheCharacterUnderTheCursor`,
`testUpAndDownKeepTheSameCharacterOffsetAcrossWideLines`.

## PCRE's `$` matches before a trailing newline, so `^…$` is not "the whole string"

`Width::visible()` starts with a fast path — almost every line is printable ASCII, where one byte is
one column — and it was guarded by `/^[\x20-\x7e]*$/`. That pattern **accepts `"abc\n"`**, because
`$` matches at the end *or* just before a final newline, so the newline went through `strlen()` and
the string measured four columns for three. The slow path disagreed: a newline is `\p{Cc}`, which is
zero, so the same characters measured 3 inside a longer string and 4 at the end of one. Anything that
pads a line to a width — `Width::background()`, and every box and selected row through it — was a
space short whenever the line ended in a newline.

```php
preg_match('/^[\x20-\x7e]*$/',  "abc\n");   // 1  — "$" forgives the newline
preg_match('/^[\x20-\x7e]*\z/', "abc\n");   // 0  — what was meant
```

**`\z` is the fix and the rule**: in a pattern that means "the whole string is nothing but this", the
end anchor is `\z`, never `$`. (`/D` does the same for the last `$` in a pattern; `\z` says it at the
place it applies, which is what a reader needs.) Found by a differential run against upstream's
`utils.ts`, not by anybody noticing a missing space — `truncateToWidth` disagreed on five multi-line
inputs and the width function underneath it was the reason.

The same shape is elsewhere in the tree and **was left alone**: `HookApi`'s `customType` and tool
names, `Skills`' name check, `Editor`'s "is this word completable" — each is `^…$` against a string
that would have to end in a newline to slip through, and none of them can be reached with one today.
Changing them tightens what is accepted, which is a behaviour change with no reproduced bug behind
it. If one of those inputs ever comes from somewhere new, this is the entry to remember.

## A signal makes `stream_select()` return `false`

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

## A tick polls after its callbacks, even when they ended the program

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

## Closing a stream without cancelling its watcher

`stream_select()` silently drops closed streams from the array, then fails with
`ValueError: No stream arrays were passed` — which names nothing useful. `Loop::poll()` checks
`is_resource()` per watcher and throws naming the watcher id instead. On EOF: `cancel()` first,
then `fclose()`.

## Resuming a Fiber that has not suspended yet

`$fiber->resume()` on a running fiber throws `FiberError: Cannot resume a fiber that is not
suspended`. So `FutureState` never invokes callbacks synchronously — every one goes through
`Loop::defer()`, which also keeps completion callbacks out of the completer's own stack.

## `proc_close()` reports the signal number where every shell reports 128 plus it

A command something killed came back as `Command exited with code 9`. Nine is not a number any shell
ever prints for a SIGKILL — `$?` says **137** — and 137 is the number a model has seen a thousand
times and reads as "something killed this, probably for memory". Told "code 9" it goes looking for an
exit code the program chose, in a program that never got to choose one. An out-of-memory kill during
a build is the common case, and `139` for a segfault is the next one.

**Upstream has the opposite bug and it is worse.** Node reports `code: null` for a signal death, and
`bash.ts` guards with `code !== 0 && code !== null` — so a segfaulting build resolves as a command
that *worked*, with whatever it printed before dying.

The first fix here asked `proc_get_status()` and then `proc_close()`, on the grounds that the status
is the last thing the process resource knows. Both halves of that turned out to be wrong about how
PHP behaves, and **the sentence that was here — "`proc_close()` hands back the signal number" — is
only true when nothing asked for the status first.** What was measured, identically on 8.3 and 8.4,
by `proc_get_status()` in a loop and printing every call:

```
first call that finds it gone   running=false exitcode=-1 signaled=true  termsig=15
second call                     running=false exitcode=-1 signaled=false termsig=0
third call                      running=false exitcode=-1 signaled=false termsig=0
proc_close=-1
```

So the status of a command something killed is **readable exactly once** — the call that finds the
process gone is the call that reaps it, and after that every reader is told the command chose its own
exit code, `proc_close()` included. Two rules come out of that, and `Process::awaitFinish()` is where
both of them live:

- **The answer is kept, not asked for again.** `codeOf()` takes the status that carries it; nothing
  reads `proc_close()`'s return any more. `run()`, `stream()` and `feed()` already had the right
  status in hand from their own loops and now keep it — `stream()`'s loop goes round again after the
  process has gone whenever there is still output on the pipe, so the last status it read was not
  the one that mattered.
- **A command finishes twice, and the second time is the one to wait for**: its pipes close, and
  then it exits. See the entry below.

Regression tests: the three `BashToolTest::testACommandKilledBySignalReportsWhatTheShellWouldHaveSaid`
rows, `ProcessTest::testTheSameKilledCommandIsReportedTheSameWithAFiberAndWithout` — the two doors,
since the translation used to be in `runAsync()` and `Run::close()` and nowhere else, so the same
command reported 137 during a turn and 9 from a hook factory at startup — and
`testAKilledCommandStreamingItsOutputReportsTheSignalToo`, which is the number `grep` puts in front
of the model.

## A command run with `usleep()` freezes everything a person can see

`Process::run()` polls with `usleep()` while it waits. That is right for what it was written
for — a two-second `wl-paste`, a `which` — and wrong for anything a person waits through, because
the one thread is inside the sleep: no keystrokes, no spinner, no redraw, no escape. A hook's
`$pi->exec(['npm','test'])` went through it, so a thirty-second command was thirty seconds of a
terminal that would not answer, and the only thing that ended it was the timeout.

`Process::runAsync()` is the same function with the pipes on the loop — the same `select()` that
waits on the model's socket — parked on a `Deferred` until they close. `HookApi::exec()` and
`CustomToolApi::exec()` use it. `run()` is untouched, because its thirty-odd other callers are
probes where blocking is the honest thing to do and some of them run before there is a loop at all.

Four things in it are load-bearing rather than tidy, and three are rules this file already had:

- **The watcher is cancelled before the pipe is closed**, or `stream_select()` drops a closed
  stream and then fails with an error that names nothing.
- **The timeout timer is cancelled in a `finally`.** A pending timer keeps `Loop::isIdle()` false
  for the rest of its span and `bin/pig` waits for the loop before it exits, so a leak here is a
  program that does not quit. `ProcessTest::testTheLoopHasNothingLeftToWaitForAfterwards` fails on
  exactly that, and it asserts the elapsed time too — otherwise it would pass by waiting.
- **A signal death is 128 + the signal**, and it is `Process::codeOf()` rather than a translation of
  its own. Having two — this one and `Tools\Run::close()`'s — is how `run()` came to report 9 for
  the command `runAsync()` reported 137 for; see the entry on that above.
- **With no fiber to suspend, it is `run()`.** That is not a fallback papering over a failure:
  blocking is correct when there is no loop being kept alive. It is also not speculative — taking
  the branch out turns **five existing tests** red, among them
  `CustomToolsTest::testTheFactoryGetsTheWorkingDirectoryAndCanRunThings`, because a factory runs
  at startup and a factory may run a command.

- **A killed command stops being waited for**, which is not the same as waiting for its pipes to
  close. `proc_terminate()` kills the command and not its children, so `sh -c 'echo; sleep 5'`
  leaves `sleep` holding the pipe open and EOF never comes: the first version of this waited the
  full five seconds after the kill, for both the timeout and the abort. A command that was not
  allowed to finish has finished as far as the caller is concerned, and what the watchers have
  already read is what there is. **Every test of a kill asserts the elapsed time**, because the
  outcome (`STOPPED`, plus whatever it printed) is identical either way — the first draft of those
  tests passed while waiting out the timeout, which is how the defect got written in the first
  place.

- **And then it waits for the process**, which is the one thing the list above was missing and which
  the pipes closing does not tell you — see the entry on a command finishing twice.

`Process` had **no test file at all** before this — 370 lines of process handling, which is the size
that goes unchecked, exactly as `Config`'s sixty lines of path arithmetic were. `ProcessTest` covers
`runAsync` in nineteen cases; the rest of the class is still only covered through its callers.

**Then escape was wired through, and the mutation check caught the missing end.** Removing
`signal:` from `InteractiveMode::initialize()` broke **no test at all** — the runner-level tests
hand the runner a signal directly, so they prove the runner and say nothing about who feeds it.
That is the *wired at one end only* shape from the index, made inside its own fix, for the second
time (the first was the ctrl+o handler that named three classes). What closes it is a test that
presses escape for real:
`InteractiveModeTest::testEscapeStopsACommandAHooksGuardStarted` — a `tool_call` guard runs
`sleep 5`, escape arrives while the guard is parked on it, and the command comes back stopped. Note
*why* the key can arrive at all: the command is not blocking the loop, which is the previous fix
holding the door open for this one.

## `packages/async` read against workerman, which is the reference it does have

This document has said from the start that `Pig\Async` "has no upstream counterpart at all — JS
ships an event loop, PHP does not", and used that to explain why the read-against-upstream
scoreboard has no row for it. **The developer's correction: PHP has mature event loops, and
workerman is the one to read.** So it was read against [workerman](https://github.com/walkor/workerman),
whose `EventInterface` is the comparison surface, and against the select-based loop
[ReactPHP](https://reactphp.org/event-loop/) and workerman share.

Ten of workerman's fifteen methods are here under the same names — `onReadable`, `onWritable`,
`cancel` (its `offReadable`/`offWritable`/`offDelay`), `defer`, `delay`, `run`, `stop`, plus
`isIdle` where it has `getTimerCount`. Three absences are pig's own answers rather than gaps:

| workerman | pig |
|---|---|
| `repeat()` / `offRepeat()` | a callback that re-arms itself — `BorderedLoader`'s spinner and `Process::runAsync`'s poll. A second way to say "again in a moment" is what the rule at the top of this file forbids |
| `onSignal()` / `offSignal()` | `pcntl_async_signals(true)` plus `pcntl_signal()` where the signal is wanted — `ProcessTerminal` for SIGWINCH, `InteractiveMode` for SIGCONT. Two users, neither of which wants the loop to own the table |
| `deleteAllTimer()` | `Loop::reset()`, which tests use and nothing else does |
| the `channel` socket pair | the `usleep()` branch in `poll()` — see below, where the two were measured against each other |

**And the comparison paid for itself somewhere else entirely: `grep` and `find` froze the screen.**
workerman's answer to "wait without blocking" is `Timer::add()` — register a callback and give the
loop back — which pig has as `Loop::delay()` plus a `Deferred`, and as `Process::runAsync()` for a
subprocess. Asking which callers actually use it found that **two of the four search tools do not**.
Measured with a 20ms timer armed and a command that takes half a second:

```
Process::stream   (grep tool)    ran 0.50s, loop ticked  0 times (a free loop would tick ~25)
Process::run      (find tool)    ran 0.50s, loop ticked  0 times
Process::runAsync (hooks)        ran 0.50s, loop ticked 24 times
```

So an `rg` or an `fd` over a large tree stopped the loop for as long as it took: **no keystrokes, no
spinner, no escape** — which is the entry above on a hook's command freezing everything a person can
see, in a tool instead of a hook. And `bash` was never affected, because `Tools\Run` is on the loop
with an `onReadable` per pipe and a `Deferred`: **pig has two subprocess runners and only one of
them was on the loop**, which is the first shape from the index at the worst possible call site,
since searching is what the system prompt tells the model to do instead of giving it more tools.

**Then the obvious question — what does upstream do — and the answer is three different things,
which is why it was worth asking before calling any of this a port defect:**

| | upstream | pig |
|---|---|---|
| `grep` | `spawn(rgPath, …)` — **async** | blocked, through `Process::stream()` |
| `find` | `spawnSync(fdPath, …)` — **blocking** | blocked, now async |
| `bash` | `spawn` | async, `Tools\Run` |
| the `@` picker | `spawnSync` in `autocomplete.ts` | blocking, with pig's own 2s cap |

So `find` is the odd one out in **both** trees, and pig's fix there is a deliberate divergence
rather than a rule it had failed to port — the same standing as `version_compare()` and the
picker's timeout. **`grep` is the other way round: upstream is async and pig is not, so that one is
a genuine port defect**, and the strongest argument for fixing it is that `grep.ts` already says
what the answer looks like.

`FindTool` is fixed by the one-word swap to `Process::runAsync()`, whose no-fiber fallback means a
test calling the tool directly still gets the blocking version and nothing outside a session
changes. **`GrepTool` needed a streaming async runner, which is `Process::streamAsync()`** — the
developer's call, since it is new public surface, and taken once upstream's `grep.ts` had settled
what the answer looks like. Four things about it:

- **`runAsync()` and `streamAsync()` share one `pump()`.** A watcher per pipe, a timeout timer, an
  abort listener and the wait for the *process* rather than for its pipes each have a reason
  written on them, and two copies of that are two things that can come to disagree about what
  killing a command means. Extracted from `runAsync()` with its cases passing unchanged, which is
  the same evidence `GoogleShared`'s extraction rests on.
- **The contract is `stream()`'s**, because a tool moved from one to the other: whole lines only
  with the tail held back, `false` stops the reading there and then, standard error is kept rather
  than drained, and a last line with no newline after it is still a line. All four asserted, because
  a difference in any of them is a difference in what the model is told.
- **The abort goes to the runner, not into the callback.** `GrepTool` used to call
  `$signal?->throwIfAborted()` per line, which was right while the call was blocking and is a hazard
  now: a throw from in there escapes a *loop* callback. The signal is passed to `streamAsync()`
  instead, so escape kills `rg` promptly rather than at its next match, and the caller still sees
  the throw from a `throwIfAborted()` after the call.
- **No fiber means the blocking one**, as `runAsync()` does, so a test calling a tool directly is
  unaffected.

Regression tests at both ends, and the tool end was the one that mattered: `ProcessTest` proves
`streamAsync()` leaves the loop free and keeps `stream()`'s contract, and swapping either tool back
to the blocking runner **broke nothing at all** until
`SearchToolsTest::testASearchDoesNotStopTheLoopWhileItRuns` existed — *wired at one end only*, in
the fix for a bug of exactly that shape. It asserts a 1ms timer ticking during a real search over
this repository: six to ten ticks either tool, against none when blocked.

Two smaller ones found in the same sweep and left alone, because each has a reason to think about
first: the `@` picker's `fd` goes through `Process::run()` from inside an *input callback* with no
fiber to suspend, so making it async means spawning per keystroke rather than swapping a call —
and upstream is `spawnSync` there as well, so pig is already the stricter of the two; and
`openUrl()` blocks for up to two seconds opening a browser, which happens once per `/login`.

**The `channel` is the one to have an argument about, and the argument came out for pig's side.**
workerman's `Select` opens a `stream_socket_pair()` in its constructor and keeps one end
permanently in the read array, for the reason its author gives plainly: *"stream_select does not
allow $read, $write, $e to simultaneously be empty, otherwise it will error."* That is the exact
constraint `poll()`'s `usleep()` branch exists for — PHP 8 raises
`ValueError("No stream arrays were passed")` — so the two are answers to one question, and
workerman's is the asynchronous one: `stream_select()` does the timing, and writing a byte to the
other end interrupts a wait already under way.

pig keeps the sleep, and the reason is that **the branch is only reached when nothing can
interrupt it**. It runs exactly when no stream is armed — so there is no socket that could become
ready, no keystroke that could arrive, and no callback that could run to create a timer, because a
single thread is inside the wait. The only thing that ends it is the timer it was sized to. That is
not an argument from taste; the one case that could refute it was measured:

```
a timer 2.0s out, no streams armed, SIGWINCH delivered at 0.1s from a child
  signal-queued callback ran at 0.103s
  run() returned at        2.000s
```

**A signal does interrupt the sleep** — `usleep()` returns on EINTR and the loop picks the queued
work up on the next tick — so the resize-and-redraw path is already prompt, which was the only
latency the channel would have removed. What is left for it to buy is one code path instead of two,
against three real costs: two file descriptors held for the life of the process, an `isIdle()` that
has to exclude the channel or `bin/pig` never exits, and a `Loop::reset()` that has to close the
pair or 2,500 tests leak 5,000 descriptors. Worth revisiting if a mode ever waits with no watcher
armed *and* something outside the loop has to end that wait; nothing does today.

**And on two things pig is ahead of both**, which is worth stating because it is the same
`version_compare()` rule one package over — where the reference is working around something, the
port is not the workaround:

- **EINTR is matched on the errno, not suppressed.** workerman's React loop is
  `@stream_select($read, $write, $except, …)`, with the `@` there precisely because a signal
  interrupts the call, and React dispatches signals with `pcntl_signal_dispatch()` before every
  select. pig captures the warning with a handler — `@` is forbidden here and the linter enforces
  it — reads `Unable to select [4]` out of it and returns, which is the entry on that trap above.
  The `@` throws away the errno, so it cannot tell EINTR from a real failure.
- **A closed stream is named by its watcher.** `stream_select()` silently drops one and then fails
  with `No stream arrays were passed`, which names nothing; `poll()` checks `is_resource()` per
  watcher first and says which one. Both loops have the hazard and only one of them has the
  message.

**The one real absence is a loop-level error handler.** workerman's interface has
`setErrorHandler(callable)` and every callback goes through a `safeCall()` that routes a throw to
it. pig has nothing: `runQueue()`, `poll()` and `runTimers()` each invoke callbacks bare.
Measured, all three:

```
a deferred callback    => RuntimeException escaped run(): boom
a timer callback       => RuntimeException escaped run(): boom
a readable watcher     => RuntimeException escaped run(): boom
```

**And the traps above are the bill for it.** The sentence "runs inside the loop's own input
callback, so there is nothing above it to catch" appears five times in this file, and each time it
is explaining why something small took the **whole session** down: one byte that is not UTF-8 in a
tool's output, the same in a diff, in a hook's message and in what the person typed; a `/settings`
hint one column too wide on a narrow terminal; a cursor left inside a character by an editor key.
Every one was fixed at its source, correctly. The *class* is still open, and the next throw inside
a `render()` ends `bin/pig` with a stack trace over a half-drawn screen.

**Upstream has no answer to this either, which was checked rather than assumed**: there is no
`process.on("uncaughtException")` or `unhandledRejection` anywhere in the four ported packages —
the only `process.on` handlers in the whole tree are SIGINT and SIGTERM in `mom` and `pods`, both
outside pig's scope. So a throw inside a render ends the process there too, and "upstream does it"
is not available as an argument for. workerman is the only precedent, which made this entirely
pig's own call rather than a port question — **and the developer's call was to take workerman's.**

`Loop::setErrorHandler(?Closure)` is that, with a private `safely()` that every callback the loop
invokes goes through. Three decisions came with it:

- **With no handler set a throw still escapes.** That is what every test in the suite was written
  against, and it is the honest default: a library that swallows by itself is the silent fallback
  this project forbids. Setting one is an application saying it has somewhere to report to.
- **All four sites, not three.** Deferred, timer, readable and writable are one accident four ways,
  and a wrapper on three of them is the shape this document keeps finding. `LoopTest` has a case
  per kind for that reason.
- **The report is deduplicated by message, in the caller.** A `render()` that throws throws again
  on the next tick, so reporting every one fills the transcript with one line per frame and scrolls
  away the thing it is trying to say — the same rule `ToolExecutionComponent`'s renderer fallback
  follows per call. `Loop` hands over every throw, which is its job; `InteractiveMode` decides what
  to draw.

`InteractiveMode::reportLoopFailures()` is the one caller, installed in `start()`: the throw becomes
`Error: …` in the transcript and the session stays usable. That is the whole point and also the
risk, stated plainly because it is the trade that was chosen: a component that cannot draw will keep
not drawing, and what the person sees is a red line rather than a crash.

Regression tests, and each end was mutated separately because *after wiring anything through, mutate
each end, not the middle*: `LoopTest::testWithNoHandlerAThrowStillEscapesTheLoop`,
`testAHandlerTakesAThrowFromAnyCallbackAndTheLoopCarriesOn` (four kinds),
`testTheSameFailureEveryFrameIsOneLineAndNotOnePerFrame`,
`InteractiveModeTest::testAThrowFromInsideTheLoopIsDrawnRatherThanEndingTheSession` — which also
types afterwards, because a session that survived and cannot be used has not survived — and
`testTheSameFailureEveryTickSaysSoOnceAndNotEveryTime`. Six mutations, six kills: unwrapping each of
the four sites, removing the caller, and removing the dedupe.

## 478 mutations over `packages/async`, and a wait that was a spin

Swept in the same batch, two passes as the Oauth entry describes, and with the stray-process count
checked **by the script** before it starts — the guard that entry asks for, which earned itself
immediately by refusing to run beside two leftovers.

300 of the 478 completed before the run was killed, with all ten files represented: **219 killed,
47 detected by hanging, 34 surviving.** Eleven percent, against `Utils/Oauth`'s thirty-six — *the
best-pinned package swept so far*, and the reason is visible in the shape: `AbortSignal` 14 of 14,
`AbortController` 8 of 9, `AbortState` 22 of 26, `Future` 35 of 40, `Deferred` 12 of 15. `Loop.php`
is 60 killed, 29 hanging and 18 surviving, and the hangs are what a broken event loop is: every
test waiting for something that will not arrive.

Two of the 34 were real, and the first is the more interesting kind of bug this instrument finds:

**A wait with no streams in it was a `usleep()` and deleting it changed nothing observable.**
`stream_select()` cannot wait on nothing — PHP 8 raises `ValueError("No stream arrays were
passed")` — so with timers armed and no watchers the wait is a sleep. Take the sleep out and every
callback still fires at the right moment and the wall clock is *identical*: 0.150s either way,
measured. What differs is the CPU — **0.1ms asleep against 134ms spinning**, a thousandfold, which
on a retry countdown or a `Process::runAsync()` poll is a core at 100% for as long as the wait
lasts. No assertion about output could have caught it, which is why `getrusage()` is what the test
asserts on. *A defect with no wrong answer in it still has a cost, and the instrument that finds
one measures something other than the answer.*

**And `onComplete()` has two paths of which only one was reached.** A future that is already
complete defers the callback instead of queueing it; deleting that `defer` changed no test. A
callback that never runs is whatever was waiting on it waiting for ever, and `EventStream` and
`Async::run()` are what register these.

Regression tests: `LoopTest::testWaitingOnATimerWithNoStreamsSleepsRatherThanSpinning` — which
asserts the timer really was waited for as well, so it cannot pass by returning early — and
`FutureTest::testACallbackRegisteredAfterCompletionStillRuns`, which also pins that the callback is
**not** run synchronously, the rule the pending path is written for. Both mutations re-applied
afterwards and both die.

The remaining 32 are the familiar three kinds: `?? null` against `''` in front of a type check,
`$x = []` and `$x = ''` initialisers whose deletion warns (so `failOnWarning` kills them and the
shim does not), and cleanup assignments whose end state is identical. The 178 mutations the killed
run never reached are worth finishing, and the file list above says where they would land.

## A JavaScript index past the end is a field that disappears, and PHP has no such thing

The first find from running against a real API rather than a canned one, and it is the third shape
from the index in a guise the six rows of that table do not cover: **`undefined`**.

A tool call on the Responses API has two ids — `call_id` addresses the result, `id` is the item's own
— so pig carries them joined as `call_id|id`. A call from *another* provider has one id, and
`splitIds()` used it for both, with a sentence on it that was wrong in both halves: *"Reusing it for
both is what upstream does, and OpenAI only ever compares it with itself."* What the live run said:

```
NO  dangling tool call   refused: openai returned 400:
    Invalid 'input[1].id': 'call_abandoned_1'. Expected an ID that begins with 'fc'.
```

OpenAI validates the **shape** of `id`. And upstream does not reuse anything: it writes
`id: toolCall.id.split("|")[1]`, which for an id with no `|` is `undefined` — and `JSON.stringify`
**leaves an `undefined` field out of the object entirely**. So upstream sends no `id` at all and the
item is accepted. PHP has no value that makes a key vanish, so the omission has to be written on
purpose: `splitIds()` answers `null` for the item id and the emission spreads in nothing.

**Which conversations this refused, whole:** `/model` from Anthropic to gpt-5 with a tool call in the
history, and a dangling call `TransformMessages` invented a result for — that is, both of the cases
`TransformMessages` exists to make possible, against the one API that checks. The result item is
unaffected, because it is addressed by `call_id` alone.

**A test was pinning it.** `testACallFromAnotherProviderHasOneIdAndIsUsedTwice` asserted the id
appeared twice, which is what the code did rather than what the caller needs — the `'ripgrep exited'`
shape, and the reason the canned suite was green while the API said no. It is
`testACallFromAnotherProviderSendsNoItemIdAtAll` now, and putting the copied id back turns it red.

The rule to carry: **when porting a JavaScript expression that can produce `undefined`, ask what the
JSON looks like, not what the variable holds.** `split()` past the end, an array index out of range,
a missing property and a function with no return all give `undefined`, and every one of them is an
absent field once it is serialised. PHP's nearest equivalents — `null`, `''`, `false` — are all
present fields with values, and a provider that validates its input can tell the difference.

## An arrow function whose body returns nothing is a fatal error

`fn (): void => $this->say($note)` does not compile: an arrow function always returns its
expression, and returning the result of a `void` method is `A void method must not return a
value` — at parse time, so `php -l` catches it, but only after it has been written. A closure
with a body is the fix. Hit twice in two days, in `Cli\SessionList`'s submit handler and in
`InteractiveMode`'s sign-in progress callback: **a one-line callback that calls a `void` method
has to be a block.**

The same family: `$closure?->($argument)` is not syntax either. Nullsafe applies to `->method()`
and `->property`, never to invoking a variable, so a nullable callback needs an `if`.

## `stream_socket_pair()` with a dropped peer (tests)

`[$a] = stream_socket_pair(...)` garbage-collects the peer, putting `$a` at EOF — permanently
"readable", with `fread()` returning `''`. Keep both ends in scope.

## A missing `use Throwable` makes every catch in the file a no-op

`AgentSession` had no `use Throwable;` and had never needed one. The first three
`catch (Throwable $problem)` blocks written into it were therefore catching
`Pig\CodingAgent\Session\Throwable`, a class that does not exist — so nothing was ever
caught, the exception escaped the `Async::spawn` that was running the work, landed in a
`Deferred` nobody awaits, and **vanished**. What it looked like: the compaction's start event
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
  The `runAgentPrompt()` that `AgentSession::sendHookMessage()` spawns for a hook's `triggerTurn`
  catches a throw and reports it through `emitError()` as a `HookError`, so a bug in there is a
  reported failure rather than a session that stops mid-sentence.

## `tick()` waits out the timer it is polling for (tests)

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

## A provider that throws hangs the agent, and swallows the reason

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

## A hex id used as an array key becomes an integer

An entry id is eight hex characters, and about one in forty-three is all digits. PHP turns an
array key that looks like an integer into one, so `$entries["12345678"]` comes back out of a
`foreach ($entries as $id => …)` as the **int** `12345678` — and a function typed `string $id`
then throws. Intermittently, in about two percent of runs, which is the worst rate there is:
often enough to happen to a user, rare enough to pass every time you look.

Lookups are safe — PHP normalises the subscript the same way — so only iteration is affected,
and the fix is `(string) $id` where the key comes out. It has been latent since ids existed;
pig's twelve-character ids hit it about one run in three hundred.

## A loop with one readable watcher blocks forever on a zero-timeout tick (tests)

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

## PHP 8.5's `curl_close()` deprecation and the `CurlHandle` lifetime

**Phenomenon**:
Under PHP 8.5, a cURL request (`pig-computer` or another extension making an HTTP call) printed
`PHP Deprecated: Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0` to the terminal or the log.

**Cause**:
Since PHP 8.0 `curl_init()` returns a `\CurlHandle` object rather than a resource; the handle is released when the object goes out of scope or is destructed, and PHP 8.5 marks `curl_close()` deprecated.

**Countermeasure**:
No `curl_close($ch)` anywhere in the repository or the extensions; `unset($ch)` explicitly, or let it be destructed at the end of scope.

## `Socket::write()` and `read()` on a peer-closed connection leaked Broken pipe warnings onto the terminal and broke retries

**Phenomenon**:
During a long-lived connection or a streaming request, when the server closed the TCP/SSL connection early (timeout, rate limit, network loss, reset), `Socket::write()` flooded the terminal with PHP's native OpenSSL warnings:
`Warning: fwrite(): SSL operation failed with code 5. OpenSSL Error messages: error:80000020:system library::Broken pipe in Socket.php on line 92`.
And `Socket::write()` threw a hard-coded `SocketError('Write failed')`, losing the real reason.

**Cause**:
`Socket.php` had the `capturingWarnings()` helper for exactly this, but `Socket::write()` and `Socket::read()` called `fwrite()` and `fread()` outside any `set_error_handler`. On EPIPE/Broken pipe, PHP printed the OpenSSL warning to stderr — wrecking the TUI and CLI rendering — and threw the warning text away, so the exception the caller saw never contained `broken pipe`.

**Countermeasure**:
1. `Socket::write()` and `Socket::read()` run `fwrite` and `fread` through `self::capturingWarnings()`; no PHP warning reaches the terminal.
2. When a write or read returns `false`, `$this->close()` releases the dead connection and the captured `$warning` is appended to the exception (`Write failed: ... Broken pipe`).

## `fwrite(): SSL operation failed` zero-write spin, `fclose` close_notify warning leak, and stronger network retries

**Phenomenon**:
In long conversations, on a flaky network, when a proxy closed the connection or when a large payload was sent, `fwrite(): SSL operation failed with code 5` / `SSL operation failed with code 1` / `Broken pipe` appeared now and then, or a raw PHP Warning popped up in the terminal; the session ended in red without an automatic retry.

**Cause**:
1. **Zero-write loop**: on a non-blocking OpenSSL stream, a connection closed by the peer makes `fwrite` return `0`, not always `false`. A closed TCP socket is permanently writable in `stream_select`, so `$this->awaitReady(true)` returned at once and `while ($offset < $length)` spun tens of thousands of times within milliseconds until the timeout — sometimes crashing the OpenSSL state machine. `Socket::write()` also had no immediate `feof($this->stream)` check.
2. **`fclose()` close_notify warning leak**: `fclose($stream)` on a connection already broken makes OpenSSL try to send a TLS `close_notify` alert, and writing that on a Broken pipe connection printed a bare `Warning: fwrite(): SSL operation failed with code 5` to the terminal.
3. **A one-second poll slice mistaken for the whole write timeout**: after consecutive zero writes the code waited for writability with `awaitReady(true, min(1.0, $stallLimit))`. A 30-second or 5-second overall write timeout therefore failed early with `Socket timed out after 1.0s` whenever the peer stopped reading for a second — pig's internal poll slice shown as a user-visible timeout, easiest to hit with large payloads or proxy back-pressure.

**Countermeasure**:
1. `Socket::write()` checks `feof()` first and trips a consecutive-zero-write breaker (`$zeroWrites > 10`), throwing `SocketError` at once on disconnect — no spin, no damaged state machine.
2. `Socket::close()` wraps `fclose($stream)` in `self::capturingWarnings()`, silencing the `close_notify` warning of a dead SSL connection.
3. `Socket::capturingWarnings()` accumulates (`$warning .= ' ' . $message`) so OpenSSL's compound warnings (code 5 plus the Broken pipe detail) are kept whole.
4. Whether such a transport failure is retried by the session depends on the provider reporting it in Node fetch's words (`Connection error.`/`fetch failed` before the response headers, `terminated` mid-body — see `SdkRequest::errorMessage()`), not on PHP's native exception text; upstream's retryable patterns only recognize the former.
5. The wait after a zero write uses what is left of the overall write timeout (`$stallLimit - elapsed`), not a fixed one-second slice; `SocketTest::testAWriteStalledByAPeerThatDoesNotReadGetsTheWholeWriteTimeout` uses a local peer that accepts but never reads to show the old code failing after about 1 second and the new code waiting the full 1.5 seconds.
