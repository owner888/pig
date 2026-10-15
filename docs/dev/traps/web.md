# Known traps: pig web

The page, the WebSocket, tabs, login, the terminal drawer, the service. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [A failed LLM send in Web mode showed no error at all (the error was swallowed)](#a-failed-llm-send-in-web-mode-showed-no-error-at-all-the-error-was-swallowed)
- [Web history replay must not have a second, narrower set of wire rules](#web-history-replay-must-not-have-a-second-narrower-set-of-wire-rules)
- [`PtyManager::input()` / `resize()` on an exited terminal id raised an Undefined array key warning](#ptymanagerinput--resize-on-an-exited-terminal-id-raised-an-undefined-array-key-warning)
- [vim unusable in the Web terminal (no first screen, no response to input, Ctrl+C ignored)](#vim-unusable-in-the-web-terminal-no-first-screen-no-response-to-input-ctrlc-ignored)
- [The web sidebar read two variables nothing declared, and an ES module throws on that](#the-web-sidebar-read-two-variables-nothing-declared-and-an-es-module-throws-on-that)
- [A web string in a loop over tabs called the tab, and a component's `data-i18n` was never applied](#a-web-string-in-a-loop-over-tabs-called-the-tab-and-a-components-data-i18n-was-never-applied)
- [Answering an extension's question in the page never reached the child](#answering-an-extensions-question-in-the-page-never-reached-the-child)
- [The web chat stopped short of the bottom when a new block came in](#the-web-chat-stopped-short-of-the-bottom-when-a-new-block-came-in)
- [The web page jumped on an iPad with a hardware keyboard](#the-web-page-jumped-on-an-ipad-with-a-hardware-keyboard)
- [`pig web` over Tailscale: a long conversation reconnected every few seconds](#pig-web-over-tailscale-a-long-conversation-reconnected-every-few-seconds)
- [A command the server sends itself is answered to its caller, not to the pool](#a-command-the-server-sends-itself-is-answered-to-its-caller-not-to-the-pool)
- [`pig web install` is a launchd LaunchAgent, and the service owns start/stop/status/restart](#pig-web-install-is-a-launchd-launchagent-and-the-service-owns-startstopstatusrestart)
- [A copy button over plain http threw and did nothing](#a-copy-button-over-plain-http-threw-and-did-nothing)
- [Many tabs in the web UI squeezed to nothing instead of scrolling](#many-tabs-in-the-web-ui-squeezed-to-nothing-instead-of-scrolling)
- [A screenshot from the web UI never reached the agent: WebSocket fragments were read as messages](#a-screenshot-from-the-web-ui-never-reached-the-agent-websocket-fragments-were-read-as-messages)
- [The web sidebar follows the window width](#the-web-sidebar-follows-the-window-width)
- [An extension rewrote `Origin` and the login page's own stylesheet was refused](#an-extension-rewrote-origin-and-the-login-pages-own-stylesheet-was-refused)

## A failed LLM send in Web mode showed no error at all (the error was swallowed)

**Phenomenon**:
Every so often a question asked in the Web UI produced no reply: the spinner stopped, the view went back to blank with no red error, and the input had been cleared — the user assumed the request was lost or stuck.

**Cause**:
1. `RpcEvents.php`'s `agent_end` did not extract the failed message's `errorMessage`; the event was `{"type":"agent_end","messages":[...]}` with no top-level `error`, and the front end's `onEvent(evt)` only checked `evt.error`, so a failed turn passed straight through.
2. The streaming `ErrorEvent` in `RpcEvents::delta()` serialized only `stopReason`, not `$event->error->errorMessage`.
3. When `retry_end` reported all retries spent, the front end only hid the banner and refreshed state; it never rendered `evt.error` in the chat.
4. When `RpcMode::prompt()` threw asynchronously, the error response carried no `$id`; the front end's `onRpcEvent()` found no pending promise and dropped it, neither notifying the send nor rendering anything.
5. The front end cleared `promptInput.value` before the connection was ready or when the send failed, destroying the user's text on error.

**Countermeasure**:
1. `RpcEvents::encode(AgentEndEvent)` gained a `lastError` extractor that serializes the failed message's `errorMessage` into `agent_end.error`; the `ErrorEvent` delta includes `error`.
2. The front end's `onEvent(evt)` falls back to `evt.error || lastErrorMessage(evt.messages)`, so a failed `agent_end` always prints a red `msg-error` in the chat.
3. A failed `retry_end` (`!evt.succeeded`) prints the actual retry error.
4. `onRpcEvent` catches failed responses with no id or no match, ends the running state and calls `appendErrorMessage`.
5. `submitMessage` restores `text` and `pendingImages` to the input when the send or the connection fails.

## Web history replay must not have a second, narrower set of wire rules

**Phenomenon**: after the Web UI reopened a session or auto-compacted, several transcript blocks differed from the TUI or were missing outright: compaction summaries kept the old wording and ignored `tokensBefore`; the auto-compaction completion event carried a summary but appended no block; hook messages were not shown at all; historical tool results lost `details.diff`, the truncation notice and the full-output path; a replayed `!command` had no block of its own.

**Cause**: Web had two forks. The back end's `SessionCodec` encoded only the three app messages compaction / branch / bash and skipped `HookMessage`, so hook messages became `null` on the RPC/Web event chain; the front end's history replay had its own hand-written tool cards and summary/hook HTML, read `result.diff` instead of the real `result.details.diff`, and dropped the tool result's `details`. That is the TUI, the session file, the RPC wire and the Web each answering the same question on their own.

**Rule**: Web replay consumes the full shape of `SessionCodec::encode()`; `ToolCard` is the one place that decides how a result is drawn, with no second `formatToolResultBody()` in `app.js`; compaction, branch summaries and hook messages all use the same theme-token block rather than a hard-coded purple or old wording. `compaction_end.result.summary` and the `message_end` that follows may be the same summary, so the front end deduplicates by `role:timestamp`.

**Countermeasure**: `SessionCodec` encodes `HookMessage` as `role: "custom"`; Web replay wraps a tool result as `{content, details}` for `ToolCard`, `edit` prefers `details.diff`, `write` shows what was written, `bashExecution` takes the bash card; compaction / branch / hook use `.compaction-box` + theme tokens, hook messages preview five lines and expand on click; `compaction_end` (any reason) appends `result.summary` and deduplicates against `message_end`; a bash result's full-output path is `details.fullOutputPath`. The regression test generates the JSON from real PHP messages and runs the Web renderer under Node, so the test cannot invent a wire of its own.

## `PtyManager::input()` / `resize()` on an exited terminal id raised an Undefined array key warning

**Phenomenon**:
After a Web terminal had exited or its tab was closed, a late `terminal_resize` / `terminal_input` from xterm.js (a resize listener, or quick repeated clicks) produced
`PHP Warning: Undefined array key "term-..." in PtyManager.php on line 106` on the server.

**Cause**:
The nullsafe operator in `$this->terminals[$id]?->resize()` is only safe when the left side evaluates to `null` or an object; when `$id` is not in the array, evaluating `$this->terminals[$id]` itself raises the undefined-key warning first.

**Countermeasure**:
`($this->terminals[$id] ?? null)?->input($data)` and `($this->terminals[$id] ?? null)?->resize($cols, $rows)` — the null coalescing (`?? null`) reads the element safely, and an exited or unknown terminal is ignored without a warning.

## vim unusable in the Web terminal (no first screen, no response to input, Ctrl+C ignored)
**Symptom**: `vim file` in the terminal drawer of `pig web` left the screen on the command line (or a few scattered blocks); keys pressed afterwards reached vim (`:wq` saved) but nothing repainted; Ctrl+C could not stop `sleep`; bash printed "cannot set terminal process group … no job control" at startup; after `pig web` stopped, a leftover shell still held the port.
**Root cause** (three stacked):
- The vendored `xterm.js` was not the official build but someone's repackaging of 5.5 plus FitAddon into one ES module (exports minified to `D` / `o`). That repackaging broke `requestMode()` (DECRQM, the answer to `CSI ? Ps $ p`), assigning an enum into an undeclared `i`; the module is strict mode, so the first mode query threw a ReferenceError inside the parser and the rest of that write was lost. vim queries a mode on startup (`?12$p`, cursor blink), so the first screen and every later repaint vanished. Official 5.5.0 and 6.0.0 have no such code; it is now the official 6.0.0.
- pty output was sent as JSON text frames, and `json_encode()` returns false for the whole frame on any non-UTF-8 byte (`(string) false` is an empty frame). A pty is a byte stream: a read can split `你` in half, and vim's startup probing writes bytes that are not characters — the whole frame, vim's first screen included, disappeared.
- `proc_open` only pointed fds 0–2 at the pty; the child stayed in pig's session with no controlling terminal: ^C/^Z were plain bytes, resize sent no SIGWINCH, bash had no job control, `/dev/tty` either did not exist (daemon) or was the terminal that started `pig web`; closing the master sent no SIGHUP, and an interactive bash ignores SIGTERM. Worse, PHP's `proc_open` leaked the original master/slave fds from `openpty()` to the child (closing only its dup), along with the server's sockets that had no CLOEXEC: the shell held the master itself, so closing the terminal never produced a hangup and the shell kept the listening port after `pig web` stopped; the old `PtyTest` running to its full 300s timeout was the same thing (a leftover shell holding phpunit's output pipe). And on Linux, reading the master after the shell exits is EIO (`fread` returns false); the old code only recognized EOF, so the watcher spun at 100% CPU and never reported exit.
**Trap rules**:
- The shell is always started through `PtyProcess::launcher()` (setsid → open the slave to acquire the controlling terminal → `stty` the initial size → exec); do not go back to running `proc_open($shell)` directly. When it cannot create a session it says so on the terminal's first line, not silently.
- Terminal output sent to the browser must be complete UTF-8: `flushOutput()` holds back an unfinished tail and `mb_scrub`s to U+FFFD; `Websocket::encode()` uses `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR` — no more empty frames.
- Reading the master, `false` and EOF both mean the end, and both `cleanup()`.
- The initial size is set on the slave by the launcher, which reports the slave name on fd 3 (it is the only process that knows fd 0 is already the slave; looking at `/proc/$pid/fd/0` / `lsof` from the parent right after fork may show the server's own terminal, and `stty` would resize the user's own window). A resize arriving before the report is remembered and applied again once it arrives, or the launcher's initial `stty` overwrites it.
- Before exec'ing the shell the launcher must close every inherited fd other than 0–2 (PHP cannot close raw fd numbers, so it goes through `/bin/bash -c 'exec N<&- …; exec "$0" "$@"'`; POSIX sh only knows up to 9).
- The xterm files under `vendor/` are copied verbatim from the official npm packages (`@xterm/xterm`'s `lib/xterm.mjs`, `css/xterm.css`, `@xterm/addon-fit`'s `lib/addon-fit.mjs`), never edited or repackaged; an upgrade swaps the files and updates the sha256 in `VendoredXtermTest`.
- Run `PtyProcessTest` (^C, foreground process group, initial size, half a character, invalid bytes, inherited fds, EIO exit) and `PtyTest` before touching the pty.

## The web sidebar read two variables nothing declared, and an ES module throws on that

**Symptom**: in `pig --mode web`, opening the sidebar showed nothing, "+ New Session" did nothing,
and every `agent_end` stopped half way through its handler — no error anywhere but the browser console.

**Cause**: `app.js` is `<script type="module">`, which is strict mode, and `currentView` /
`selectedWorkspace` were assigned and read but never declared: every read was a `ReferenceError`.
`node --check` passes it — an undeclared name is a runtime error, not a syntax one.

**Rule**: after touching `assets/js`, grep each new bare name for its `let`/`const`/`function`; the
sidebar is now `refreshSidebar()` with no view state at all.

## A web string in a loop over tabs called the tab, and a component's `data-i18n` was never applied

**Symptom**: the tab bar and the terminal's tab list drew nothing (`TypeError: t is not a function`);
the SSH node workbench showed Chinese in an English page.

**Cause**: `for (const t of tabs)` shadows the imported `t()`, so a `t("close_tab")` inside the loop
calls a tab. And `applyI18n()` runs once at startup, before `NodeWorkbench` builds its markup, so its
`data-i18n` elements kept their Chinese defaults.

**Rule**: inside a loop or callback whose variable is `t`, translate with `__t()`; a component that
builds `data-i18n` markup calls `applyI18n(itsElement)` after appending it. `WebI18nTest` checks
that every key used is in both languages — not shadowing, which only the browser shows.

## Answering an extension's question in the page never reached the child

**Symptom**: in `pig --mode web`, a hook's or tool's `confirm` / `input` / `editor` dialog closed on
Yes, and the tool stayed "Running…" for good — first seen as a `schedule` create that never saved.

**Cause**: `replyTo()` sent the answer with `tab?.send(…)`, and `Tab` has no `send` (it has `rpc()`)
since the move to ES modules: the click closed the dialog, then threw `TypeError`, and nothing was
sent. `tab.rpc()` would not do either — it puts its own id on the command, and the answer's id must be
the question's.

**Rule**: an `extension_ui_response` goes out as
`network.send({ type: "rpc_command", tabId, command: { type: "extension_ui_response", id: <question id>, … } })`.
After touching a dialog, answer one in a browser (the fake agent's `ask:` prompt) — no PHP test covers the page's side.

## The web chat stopped short of the bottom when a new block came in

**Symptom**: in `pig --mode web`, a new block (a tool card, a long reply, an image) often left the
view some way above the bottom, and it stayed there for the rest of the turn.

**Cause**: `.chat-scroll` had `scroll-behavior: smooth`, so `scrollTop = scrollHeight` became an
animation, and the tab's scroll listener read its first frames — more than 80px from the bottom — as
the user scrolling up and set `userScrolledUp`, which every later scroll then obeyed. Blocks that grow
after they are added (an image decoding) had no scroll at all.

**Rule**: no `scroll-behavior` on the chat scroller; only a scroll that moves up sets
`userScrolledUp`, and each tab's `ResizeObserver` on its blocks follows growth.

## The web page jumped on an iPad with a hardware keyboard

**Symptom**: on iPad Safari with a keyboard attached, focusing the prompt lifted it (later: jolted
the whole page up and back), and typing shook the page.

**Cause**: the floating input bar is ~68px. `viewport.js` shrank the page to `visualViewport.height`
for it, which is the lift. With the page at full height instead, Safari still panned: it keeps a
focused field **40px** clear of what covers the screen, and for one frame as the bar comes up it
reports `innerHeight` shrunk to the visible height with the visible part panned by the bar
(`inner=770 vv=770@68`, then `inner=838 vv=770@0`). That pan is not `scrollY`, so `scrollTo` cannot
undo it and a compensating transform arrives a frame late. Found with a temporary `?vvdebug` overlay
logging `innerHeight`, `visualViewport` height/offset, `scrollY` and the prompt's bottom per event
and per frame, then `?lift=N` until it stopped (55 panned, 56 did not: prompt bottom 730, visible 770).

**Rule**: the selectors row sits under the prompt, and under a keyboard's height (`KEYBOARD`, 150)
the page keeps the screen's height, `max(innerHeight, vv.height + vv.offsetTop)`. The prompt is raised
(`--bar-lift`) until its bottom is `MARGIN` (40) above the visible bottom, measured the first time
the bar is up and kept in `localStorage` (`pig_bar_lift`), so only that first focus moves anything.
Do not lower `MARGIN` or let the lift drop back to 0 on blur.

## `pig web` over Tailscale: a long conversation reconnected every few seconds

**Symptom**: a page on another machine (`http://100.x.x.x:8088`) with a long conversation open
reconnected its WebSocket every ~5 s for good; small conversations were fine. Along the way:
`get_messages: no answer in 30s`, `Future::await() must be called inside a coroutine`, and once
`Allowed memory size exhausted` in `TcpConnection::sendRaw()`.

**Cause**: macOS answers a `send()` a tunnel interface (`utun`) cannot queue yet with `ENOBUFS`
(errno 55). PHP counts only `EAGAIN` as "try again", so `fwrite()` returned false and
`TcpConnection` closed the socket — whenever megabytes went out, i.e. every load of a long
conversation, which the page asked for again on reconnect. The other errors were knock-ons: the
reconnects piled `get_messages` answers up (the OOM), and `RpcClient::relay()` waited for a full
child pipe outside a fiber (the await error). Three wrong guesses (send-buffer limit, memory,
payload size) came first; the session file was only 7.7 MB.

**Rule**: in `TcpConnection::flush()`, `ENOBUFS` (55 macOS, 105 Linux) is a full queue — retry
after `NO_BUFFER_RETRY` — and writes go out `WRITE_CHUNK` at a time. A close the server chooses
logs its reason (`[web] closing a connection: …`): read that before guessing at a reconnect loop.
`RpcClient::write()` queues and never awaits.

The log then showed the next one: `send buffer past 160 MB`, and an OOM in `RpcClient::flush()`
copying a 230 MB queue. A running command reported its whole tail (`Run::$onUpdate`, ≤50 KB) on
every read — megabytes a second for a build log. Updates are at most `Run::UPDATE_EVERY` apart (the
last always sent), a page that is behind is sent only the newest update per running command
(`HttpServer::$held`, flushed on `TcpConnection::onDrain()`), background tabs load their history
when opened, both write queues drain from an offset (a `substr()` of the rest after each small
write is quadratic), and the limits (8/32 MB to a page, 8 MB to a child) are brakes, not room.
Any queue that can grow without bound gets a limit and an offset, not a copy — and the producer
gets slowed or coalesced, not the limit raised.

## A command the server sends itself is answered to its caller, not to the pool

**Symptom**: a scheduled run's conversation opened from the page started a *second* child, and a
hook's question the run was parked on was never shown.

**Cause**: `SessionPool::observe()` learns a child's session file from a `get_state` response, but
`RpcClient::readOut()` hands a response to the `send()` waiting on its id and **not** to the event
listeners. A browser's `get_state` is relayed and reaches `observe()`; one the server sends with
`RpcClient::getState()` never does, so the child was never registered under its file.

**Rule**: whoever sends a command through `RpcClient::send()` and learns the session file says so
with `SessionPool::remember()`.

```php
$file = $client->getState()['sessionFile'] ?? null;
if (is_string($file) && $file !== '') { $this->pool->remember($managed, $file); }
```

Regression test: `WebModeTest::testAQuestionAScheduledRunAskedIsAskedAgainOfWhoeverOpensIt`.

## `pig web install` is a launchd LaunchAgent, and the service owns start/stop/status/restart

macOS only; the developer asked for the web UI to come back after a restart. `WebDaemon::install()`
writes `~/Library/LaunchAgents/dev.pigagent.web.plist` and runs `launchctl bootstrap gui/<uid>`;
`uninstall` is `bootout` and deleting the plist. Other systems are refused by name.

- **launchd runs `php pig --mode web … --no-browser` (`serverArgv()`), never `pig web start -d`.**
  launchd is the supervisor and `KeepAlive` restarts a job that exits; a double-forked daemon looks
  to it like a job that exited at once. `--no-browser` because `--mode web` opens a tab by default,
  which under launchd is a tab at every login and every restart.
- **The plist is a snapshot of the installing shell**: absolute `PHP_BINARY` and `pig`, `PATH`,
  the proxy variables pig reads, `PIG_CODING_AGENT_DIR` (always written, so the log, settings and
  web password are this command's), and `WorkingDirectory` = the cwd. launchd's own job has
  `PATH=/usr/bin:/bin:/usr/sbin:/sbin`, no proxy and `/`. **Provider keys are never copied**: a
  plist is world-readable; keys belong in `auth.json`.
- **While installed (`isInstalled()`: macOS and the plist exists), `start|stop|status|restart`
  ask launchctl**, because a launchd job has no pid file and a SIGTERM is a crash `KeepAlive`
  undoes. `stop` = `bootout` (holds until next login or `start`), `start` = `bootstrap` or
  `kickstart`, `restart` = `kickstart -k`, `status` reads `pid = N` from `launchctl print`. A
  `--port`/`--host` for one start is refused; `install` again changes them (it boots the old job
  out before rewriting the plist). A plist launchd refused is deleted again.
- **Tests never reach the machine's launchd or LaunchAgents**: every `WebDaemon` in
  `WebDaemonTest` gets its own LaunchAgents directory and a launchctl stand-in (`Launchd`), the
  plain helper's throwing if called — otherwise the suite's `stop` test would boot out a service
  installed on the Mac running it.

Regression tests: the service cases in `WebDaemonTest`.

## A copy button over plain http threw and did nothing

**Symptom**: the 📋 on a tool card (and a code block's Copy, the terminal drawer's folder badge) in
`pig web` did nothing when the page was opened from another machine — `http://<lan-ip>:8088`, a
Tailscale address.

**Cause**: `navigator.clipboard` exists only in a secure context (https, localhost). Over plain
http it is `undefined`, so `navigator.clipboard.writeText()` threw a `TypeError` inside a click
handler and nothing was shown. The session-file badge already had a fallback; these did not.

**Rule**: copy only through `copyText()` in `assets/js/utils.js` — the clipboard API in a secure
context, the selection and `execCommand('copy')` elsewhere, a rejection (shown as ✗) when the
browser refuses both. Read a card's text with `textContent`: a collapsed card's body is not
rendered.

Regression test: `WebModeTest::testTheCopyButtonsCopyWhereThereIsNoClipboardApi`.

## Many tabs in the web UI squeezed to nothing instead of scrolling

**Symptom**: with a dozen conversations open, every tab in the strip was an ellipsis with its ×
hidden, and the earlier ones could not be closed.

**Cause**: `.session-tabs` had `overflow-x: auto`, but the tabs were flex items free to shrink
(`min-width: 0`), so the strip never overflowed — the tabs shrank instead.

**Rule**: tabs are `flex: 0 0 auto` and the strip scrolls; `+` is `position: sticky` at the right.
`renderTabs()` empties the strip, so it carries `scrollLeft` over and reveals the active tab only
when the active tab changed — the strip is redrawn at every turn's start and end, and someone who
scrolled left to close an old tab must not be thrown back. A vertical wheel over the strip scrolls
it sideways. On a touch screen (`hover: none`) every tab shows its ×.

Verified in headless Chromium (see the CHANGELOG entry); no PHPUnit case — layout needs a browser.

## A screenshot from the web UI never reached the agent: WebSocket fragments were read as messages

**Symptom**: sending an image from `pig web` ended in `prompt: no answer in 30s`, or the page's
connection dropped; text-only prompts worked.

**Cause**: Chrome sends a message over about 128 KB as several frames — the first with its opcode
and FIN unset, then continuation frames (opcode 0), the last with FIN set. `Websocket::decode()`
ignored FIN and handed each frame on as a message, so the server read three pieces of broken JSON
and the prompt never reached the child. Measured: a 150 KB prompt arrived as frames of 130,500,
522 and 21,118 bytes.

**Rule**: `decode()` keeps the message in `TcpConnection::$websocketDataBuffer` (Workerman's name)
until a frame with FIN arrives; control frames may come between fragments; a continuation with
nothing to continue, or a new data frame mid-message, closes the connection (RFC 6455 §5.4).
Unmasking is one string XOR, not a byte loop. A large-message test drives `onReadable()` with
masked client frames as a browser sends them, not only `decode()` on one frame.

**Keep the test's first frame over 65,535 bytes.** It was once shrunk to 2 KB frames during a
release; every frame then fit the 16-bit length form, and breaking the 64-bit branch — the one
Chrome's 130,500-byte frame takes — left the test green. Smaller is not "the same test, faster".
Stream the frames non-blocking and pump `onReadable()` in the same loop: Unix domain sockets on
macOS default to an 8 KB send buffer, so a single blocking `fwrite()` of 70+ KB deadlocks waiting
for the server on the same thread to read.

Regression tests: `WebsocketProtocolTest::testAMessageSplitAcrossFramesArrivesWholeAndOnce`,
`testAContinuationWithNothingToContinueClosesTheConnection`.

## The web sidebar follows the window width

Open at ≥ 1024 px (MacBook, iPad landscape), closed below (phone, iPad portrait, where the
sidebar would cover or squeeze the chat). `sidebarFollowsWidth()` runs at `boot()` and on the
`matchMedia` change, so only crossing the width moves it; a toggle in between stays. It is called
from `boot()` and not where it is defined, because `refreshSidebar()` reads `let sidebarSeq`
further down the file and calling it earlier is a temporal-dead-zone `ReferenceError`.

## An extension rewrote `Origin` and the login page's own stylesheet was refused

**Symptom**: `/web` opened an unstyled login page; `theme.css`, `style.css`, `login.js` and
`favicon.svg` were 403 with `Requests from another site are refused.`

**Cause**: a browser extension set `Origin: http://127.0.0.1` — port gone — on every request,
including a stylesheet Chrome sends with no `Origin` at all (`Sec-Fetch-Mode: no-cors`,
`Sec-Fetch-Site: same-origin`). `SameOriginMiddleware` compared that `Origin` with
`Host: 127.0.0.1:8088` and refused it. Not an install problem: the same page was fine in a browser
without the extension.

**Rule**: `SameOriginMiddleware::allowed()` asks `Sec-Fetch-Site` first — the browser's verdict, which
a page cannot set: `same-origin` and `none` pass, `same-site` and `cross-site` are refused (another
port on 127.0.0.1 is same-site and cross-origin). Only without the header does it compare `Origin`
with `Host`. Debug a 403 there from the request headers, not the response.

Regression tests: `SameOriginMiddlewareTest`, and the two `theme.css` requests in
`WebModeTest::testNothingButTheLoginPageWithoutLoggingIn`.

## A language pack installed with `pig install` was not in the web page's language list

**Symptom**: `pig install git:…/pig-locale-th` succeeded and `pig list` showed its extension, but
**⋯ → Language** in the web UI did not offer Thai; `/api/locales` answered `{}`.

**Cause**: the web shell (`HttpServer::start()`) loaded `settings.extensions` and
`~/.pig/agent/extensions/` only, and `/api/locales` is the shell's endpoint. A package's
extensions were loaded by the children (`CodingAgent` passes `packageExtensions` to
`ExtensionLoader::load()`), where `registerLocale()` filled a registry nobody served.

**Rule**: the shell loads what a child loads. Anything an extension registers *for the page*
(a locale, an HTTP route, a provider for `/api/models`) must come from the shell's own
`ExtensionLoader::load()` call, so a new way of bringing extensions in goes there too.

