# Known traps: The other modes

`--mode rpc`, print and JSON mode, `--mode mcp`. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [`-p` handed a custom tool a context with no session in it](#-p-handed-a-custom-tool-a-context-with-no-session-in-it)
- [RPC's session switch and new-session skipped the three checks the terminal's have](#rpcs-session-switch-and-new-session-skipped-the-three-checks-the-terminals-have)
- [`--mode rpc` could not be started, by either route](#--mode-rpc-could-not-be-started-by-either-route)
- [A host could read the queue mode and never set it](#a-host-could-read-the-queue-mode-and-never-set-it)
- [Validating inside the fiber answers a command twice](#validating-inside-the-fiber-answers-a-command-twice)

## `-p` handed a custom tool a context with no session in it

`CustomToolSet` needs two things once a mode is running: the UI and the session context.
`InteractiveMode` and `RpcMode` wire both. `PrintMode` wired the UI, under a comment reading
*"the same wiring as the other two modes, with the one difference that matters: no UI"* — which
was counting one difference too few.

What a custom tool got in `-p` and `--mode json` was therefore `CustomToolSet::contextFn()`'s own
stub: no model, no conversation to reconstruct its state from, an `abort()` that does nothing,
and a **working directory of `.`** — so a tool resolving a path against `$ctx->cwd` read a
different file there than the same tool in the terminal, which is the kind of difference somebody
debugs for an hour.

Three things about it are worth keeping:

- **The stub is why it went unnoticed.** It exists so a tool asked something legitimately early
  gets an answer rather than a `TypeError`, and nothing distinguishes that from a mode that
  forgot to wire. It says so on itself now.
- **The `?? new HookContext(…)` fallbacks in the modes are unreachable from `bin/pig`**, which
  builds a `HookRunner` unconditionally — `--no-hooks` gives an *empty* runner, not a null one. So
  the only live path was the one `PrintMode` did not take.
- **`AgentSession::cwd()` is why the fix needed no new constructor parameter.** The session
  already holds the project directory, and a mode holding the session being handed it a second
  time is the two-copies shape on a value that cannot differ.

Found in the same read and corrected rather than annotated: `CustomToolSet`'s own docblock said
`notify()` is called "from the interactive mode", which two other modes had already made false —
the fourth shape from the index, a docblock asserting something about the rest of the repository.

Regression test: `PrintModeTest::testACustomToolGetsTheRealSessionHereToo`.

## RPC's session switch and new-session skipped the three checks the terminal's have

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
`restoreSettings()`, the `session_shutdown` and `session_start` emits), the way `goTo()` already held its own guards inside.
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

## `--mode rpc` could not be started, by either route

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

The fix lives in `Arguments::appMode($stdinIsTty, $stdoutIsTty)`, upstream's mode decision, tested
directly: `--mode rpc|json|web|mcp` is that mode whatever else was typed; `-p` or a stream that is not
a terminal is print mode; anything else opens the TUI (`--mode text` on a terminal included). Print
mode with nothing to say exits 0 silently, as upstream's does, and RPC refuses an `@file` on its own
command line. Inline in `bin/pig`, the next person to touch it would have had nothing to run.

The general rule: **a mode that nothing starts the way a person starts it is a mode that can be
broken by a line somewhere else entirely.** `RpcClientTest` is now the one test in the suite that
spawns `bin/pig`, and that is the point of it — it also caught `RpcClient` sending `path` where the
wire wants `sessionPath`, which no amount of reading would have.

**One test in it failed three times in a full run and never on its own, and the third time is what
fixed it.** Each time it was `testAgentThatCannotStartSaysWhatItWroteToStandardError`, each time on
the 8.3 pass, and each time the message was `The agent closed its output` with **no trailer** where it
should carry `No model matches 'no-such-model-anywhere'` — the sentence whose whole job is to name the
reason, naming none.

The reading, which was right: the child's two pipes become readable in one `stream_select()`, the
stdout watcher is registered first, so `readOut()` sees EOF and words the failure with
`$this->stderr` still empty — and `fail()` then cancels the watcher that would have filled it in.
`trailer()` **reads the pipe itself** now, before it words anything. Every one of its six callers is
already reporting a failure, so a non-blocking read nobody is waiting on is free, and it does not
depend on which watcher the loop happened to reach first.

**The part worth keeping is how long the wrong test held the fix up.**
`testTheReasonSurvivesWhenBothPipesEndInTheSameBreath` was written for exactly this and *passes
either way*: a stand-in that writes its reason and exits immediately gets its stderr drained on some
other path, so for three appearances the evidence said "the obvious construction is already handled
somewhere" and the fix stayed unapplied. What reproduces it every time is one line different — the
stand-in closes standard output and **stays alive** (`fclose(STDOUT); usleep(400000);`), so the poll
that finds stdout at EOF finds standard error readable and unread, and the registration order decides
the rest. *A test built from the shape a race is guessed to have is not a test of the race.*

Regression test: `RpcClientTest::testTheReasonIsReadOffThePipeRatherThanHopedFor`, which fails with
the drain removed. The older one stays as the non-regression guard for the shape it does cover.

## A host could read the queue mode and never set it

`rpc-mode.ts` is 469 lines and almost all of it lines up: pig's field names are upstream's wherever
they overlap — `message`, `images`, `modelId`, `provider`, `level`, `mode`, `customInstructions`,
`enabled`, `command`, `outputPath`, `sessionPath`, `entryId` — and its response shapes are upstream's
keys with pig's own extras beside them (`sessionFile` and `messageCount` on a switch, `thinkingLevel`
beside a cycled model). Two divergences in pig's favour while reading it: an unknown command comes
back **with the id it was sent with**, where upstream answers `error(undefined, …)` so a host cannot
match the failure to the command; and a thrown argument error is a `success: false` response rather
than the process dying.

One command was missing, and the interesting part is that **this file's own table explained it away**.
`set_queue_mode` sat on the row about the anchor commit's queue split, beside `queue_message` — but
that split is about *queuing a message*, which `steer` and `follow_up` replace, and it says nothing
about the *mode*. So the mode had `AgentSession::setQueueMode()`, a row in `/settings`, and a field in
`get_state` — everything except the way a host changes it. *A reason that covers one thing does not
cover the thing filed next to it.*

`new_session` takes `parentSession`, written on the new file's header as `fork()` writes it.

## Validating inside the fiber answers a command twice

`RpcMode::prompt()` read its `message` field inside the `Async::spawn()` that runs the turn.
A command with no `message` therefore threw in the *fiber*, where the only thing to catch it
was the fiber's own handler — which sent an id-less failure, **after** `dispatch()` had already
returned null and the success response had gone out. A host was told both that its prompt was
accepted and that it was not, in that order, and the second line named no command it had sent.

Whatever a command needs is read before the spawn. The rule generalises: anything spawned
answers separately from the command that spawned it, so everything that can fail as *the host's
mistake* has to fail on the near side of the spawn, and only the work itself belongs on the far
side.
