# Design: pig web

The page, the WebSocket, tabs, login, the terminal drawer, the service. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [Three ways in](#three-ways-in)
- [The web mode's process model: pi-web's shell over `pig --mode rpc`, not a mirror of the TUI](#the-web-modes-process-model-pi-webs-shell-over-pig---mode-rpc-not-a-mirror-of-the-tui)
- [An extension's `$ctx->ui` in the page](#an-extensions-ctx-ui-in-the-page)
- [An extension's panel in the page](#an-extensions-panel-in-the-page)
- [The folder a new conversation starts in](#the-folder-a-new-conversation-starts-in)
- [Logging in to the web UI](#logging-in-to-the-web-ui)
- [Scheduled tasks: a timer per task over the session pool](#scheduled-tasks-a-timer-per-task-over-the-session-pool)

## Three ways in

`bin/pig` picks one by upstream's rule (`Arguments::appMode()`): **`--mode json|rpc|web|mcp` is that
mode; `-p`, or a standard input or output that is not a terminal, is print mode; anything else is
the TUI** — `--mode text` on a terminal included. So the terminal is what you get when nothing
else was said, and `git diff | pig "review"` prints.

| | What comes out | Ported from |
|---|---|---|
| terminal (the default) | a TUI | `modes/interactive/` |
| `-p`, or no terminal | the last answer, on stdout | `modes/print-mode.ts` |
| `--mode json` | every event as a JSON line | the same file, its `"json"` branch |
| `--mode rpc` | JSON lines out, commands in | `modes/rpc/` |
| `--mode web` / `/web` | a browser chat UI | `Web\WebMode` (Workerman-inspired HTTP/SSE) |
| `--mode mcp` | an MCP server with one tool, `ask`, over Streamable HTTP | `Mcp\McpMode` — pig's own, upstream has none |

## The web mode's process model: pi-web's shell over `pig --mode rpc`, not a mirror of the TUI

**Decided 2026-10-04, after the developer pointed at the seam.** The web UI showed a tab bar of
sessions and `HttpServer` held **one** `AgentSession`: a tab was a bookmark in `localStorage`, and
clicking one called `switchTo()` on the single session — so the TUI sharing it under `/web` moved
with the browser, a turn in flight on tab A was aborted by opening tab B, and the "working" dot
could only ever be lit on one tab. Not a bug in the switch; a UI claiming a capability the process
model did not have.

Upstream never had this problem because pi-web is a different shape: a **web shell process that
spawns one `pi --mode rpc` child per `cwd::sessionFile`** and talks JSON-lines to each
(`server.js`'s `createManagedSession()`, `rpcSessions = new Map()`). It does that for three reasons
worth knowing, because only one of them applies here: pi-web does not import pi at all
(`AGENT_CMD = npx -y @earendil-works/pi-coding-agent@latest` — a black-box command, so the boundary
is a process boundary by construction); pi carries process-level state that two sessions in one
process would fight over (`process.on('SIGINT')`, a module-level theme, jiti's module cache); and a
crashed extension takes down one tab rather than seven. **pig has the third reason and not the
first two** — `HttpServer` constructs `AgentSession` directly, theme is the module-level `Themes::theme()` (upstream's module-level theme, the same
shared-state reason), extensions are `require`d closures — so same-process multi-agent over the shared `Loop` is
*possible* here in a way it is not there. It is still not what was chosen, and the reason is the
seam: the pieces for pi-web's shape already exist and the pieces for same-process do not.

| pi-web needs | pig has |
|---|---|
| a `--mode rpc` child | `RpcMode`, upstream's commands and wire format |
| a client that spawns it and matches responses by id | **`Rpc\RpcClient`** — it is `rpc-client.ts`, and `RpcClientTest` already starts `bin/pig` |
| HTTP + WebSocket | `HttpServer`, `TcpConnection`, `Protocols\Websocket` |
| a pool keyed `cwd::sessionFile` with idle reaping | **the one new piece** |

So the web mode becomes the shell: `HttpServer` holds `array<string, RpcClient>` rather than an
`AgentSession`, each WebSocket connection is bound to one key (pi-web's `socketBindings`), events are
forwarded from a child's stdout to its connections, and `extension_ui_request` is routed per session.
Three consequences that are the point rather than side effects: the TUI and every web session are
separate processes, which is the "TUI handles one session" the developer named; two tabs can run
two turns at once; and because pig's rpc protocol *is* pi's, this shell drives `pi --mode rpc` and
pi-web drives `pig --mode rpc` — both directions were free.

**Workerman is not introduced**, though it was asked about. The lint of the question is right —
`pig web start -d` wants a daemon with `status`/`stop`/`restart` and a child that is restarted
when it dies — and none of it needs a framework: `pcntl_fork()` twice and `posix_setsid()` is the
daemon, a pid file is `status` and `stop`, and the child-exit handler pi-web already has is the
restart. `HttpServer` and `Protocols\*` were written *in Workerman's shape* (their docblocks say
so) precisely so the framework itself would not be needed; bringing it in now would make those
files redundant and break the first rule of this document. The shell is I/O-bound forwarding, so
one process is enough; the work happens in N children, which is multi-core for free. If a concrete
bottleneck ever wants Workerman's multi-worker model, that is the time to argue it with a number.

**Landed 2026-10-04 in four steps** (with `pig web install|uninstall` since added for macOS — see
"`pig web install` is a launchd LaunchAgent" below): **① `pig web start|stop|status|restart [-d]`** — the
subcommand and the daemon (`WebDaemon.php`); **② the session pool** (`SessionPool.php`) and the
`HttpServer` rewrite to route connections to isolated `pig --mode rpc` child processes with 60s
idle reaping; **③ the multi-session tab bar in `index.html`** multiplexed over **a single physical WebSocket**
connection with `tabId` tagging, per-tab chat scroll areas, background streaming indicators, and state recovery on reload;
**④ Telegram-style language pack import/export and ExtensionApi locale registration** (`v0.3.26`–`v0.3.30`):
full bilingual client-side `I18N` dictionary with 101 symmetric keys (defaulting to `zh-CN`), instant zero-refresh language modal (`#lang-btn`),
Telegram-style JSON import/export, `$pig->registerLocale()` on `ExtensionApi` with `/api/locales` aggregation, and in-session
interactive `/web restart`, `/web status`, and `/web stop` controls;
**⑤ Interactive Local PTY Terminals, SSH Node Workbench, and SFTP File Explorer (aligned with `youweichen/pi-web-ui` / `omp-web-ui`)**:
- `Pig\CodingAgent\Web\Pty\PtyProcess` & `PtyManager`: Native `proc_open` with `['pty']` descriptors on macOS/Linux, started through a small PHP launcher (`PtyProcess::launcher()`) that `setsid()`s, opens the slave so the pty becomes the shell's controlling terminal (forkpty / node-pty semantics), `stty`s the initial size, reports the slave's name on fd 3, then execs the shell through bash with every inherited fd past stdio closed. Output is micro-batched for 16ms via `Loop::delay()` and handed on as whole UTF-8 characters (an unfinished tail waits for the next flush, invalid bytes become U+FFFD). Resizes `stty` the reported slave and the kernel sends the foreground job SIGWINCH; a resize before the launcher has reported is applied once it does. 200KB scrollback buffering.
- `Pig\CodingAgent\Web\Node\NodeProfile` & `NodeManager`: SSH node inventory in `~/.pig/agent/nodes.json`, secret persistence in `~/.pig/agent/nodes-secrets.json` (chmod 0600), SHA-256 host key fingerprint detection (`ssh-keyscan` + `ssh-keygen -lf`), OpenSSH `~/.ssh/config` discovery, remote PTY terminal streaming (`ssh -tt`), and SFTP remote directory listing/reading/writing (capped at 512 KiB).
- Frontend: official `@xterm/xterm` 6.0.0 (`assets/js/vendor/xterm.mjs`, `assets/css/vendor/xterm.css`) + `@xterm/addon-fit` 0.11.0 (`assets/js/vendor/addon-fit.mjs`), copied byte for byte from the npm packages, drive the multi-tab terminal drawer in `WebTerminal.js` and the `NodeWorkbench.js` modal with remote terminal tabs and SFTP file explorer/editor.

## An extension's `$ctx->ui` in the page

The child's UI is `RpcUi`, upstream's RPC context, and what it sends the page draws as `TerminalUi`
would: `setWidget` lines above or below the prompt, `setStatus` in the footer line, `setTitle` as the
page title (all per tab, in `assets/js/hooks.js`; widget and status colours drawn by `ansiToHtml()`,
the sixteen terminal colours as the page's theme tokens), `notify` as a transcript
line at its level, a dialog's `timeout` as the heading counting down, and the `/` list from
`get_commands` — the extensions' commands, prompt templates and `skill:` commands after the built-ins.
Two things the page has to do that the terminal does not:

- **The lasting requests are replayed to a page that binds later** (`ManagedSession::$toldUi`, the
  latest per widget, status and title key, a clear included), beside the dialogs `pendingUi` replays,
  so a reload shows what the terminal would still show.
- **A `notify` that arrives before the conversation is drawn is drawn after it**, because drawing the
  conversation clears the chat and a `session_start` handler's notice comes first.
- **The child draws in the page's theme.** `start_session` carries the page's `theme`, the shell
  starts the child with `PIG_WEB_THEME`, and `RpcMode` makes it the global theme (truecolor) before
  `session_start`; the page sends pig's `set_theme` command when a tab binds and whenever its theme
  changes. Once the host has said, `RpcUi` answers `getAllThemes()` / `getTheme()` from the theme files
  (without `system`), and an extension's `setTheme(name)` is pig's own
  `extension_ui_request {method: "setTheme", theme}`, which the page applies to itself — so every
  tab, and through `set_theme` every tab's child, follows. A host that never says gets upstream's
  answers: no themes, `setTheme()` refused. Not saved to `settings.json`: the page keeps its own
  choice (`localStorage`), the terminal its own. A theme only the child has (a custom theme in
  its project or from an extension) is one the page cannot show, so the page ignores it.
- **A prompt whose answer was lost is sent again under the same `requestId`**, and the child answers
  `duplicate` rather than asking twice (`AgentSession::prompt()`, [rpc.md](rpc.md)). The page keeps
  `{text, requestId}` in `tab.unsent` when the answer never came — the connection dropped, the child
  went away — and reuses the id when the same text is sent; a refusal from the child (`err.refused`)
  took nothing, so the next send is a new request. For that to reach the child that took it,
  `start_session` carries `pageId`, one per page load: a tab whose conversation is not saved yet was
  keyed by the connection, so a reconnect started a new child — and a new conversation — while the
  old one went on answering where nobody could see it.

What `RpcUi` does not send — a terminal `Component` (`custom()`, headers, footers, the editor, message
and tool renderers), the working indicator, shortcuts — the page cannot draw either;
an extension that wants a place in the page has a panel (next section). Regression tests:
`WebHookUiTest` (`hooks.js` under Node against `RpcUi`'s real requests, a theme colour included),
`RpcUiThemeTest`, `RpcModeTest`'s `set_theme` / `PIG_WEB_THEME` cases,
`WebModeTest::testATabThatJoinsLaterIsShownWhatTheExtensionsLeftOnScreen` and
`WebModeTest::testAChildIsStartedInThePagesThemeAndAJoiningPageDoesNotRestartIt`.

## An extension's panel in the page

`ExtensionApi::registerWebPanel(id, title, render, action, icon, commands, placement, label)` gives an
extension an entry in the sidebar's Extensions group (hidden while there is none), or with
`placement: 'header'` a header button named by `label`, and a window. Where it goes is the
extension's default; the header's 🧩 button lists every panel to open it or move it to the other
place, which the developer wanted the person to decide — the move is kept in the browser
(`pig_panel_placement`) and wins over the default. Antigravity's accounts panel defaults to the
header as "🔑 账号", where the page's own button was. The developer
chose described panels over extension JavaScript: `render` returns blocks (`WebPanel` lists them —
heading, text, list of items with buttons, meter, card, actions, lazy) and `panels.js` draws them in
the page's own components and theme, so a panel cannot read other tabs, the WebSocket or the
credentials endpoints, and the page's internals are not an API. Every string is text; the page's
locale is in the request and the extension answers in it. A button is an action: `action(name, args,
request, $ui)` returns null or why it failed, and the panel is drawn again either way; a button with
a `confirm` question asks it in the page's own modal first (`openModal`, which lets only the top
modal of a stack answer Escape and Enter). `$ui` is the `HookUi` a hook has as `$ctx->ui`, so an
action asks with the same `confirm()` / `select()` / `input()` / `editor()` the developer's
extensions already use in the terminal and in conversations: the page sends the action over its
WebSocket (`panel_action`), `HttpServer` runs it in a fiber with an `RpcUi` whose questions go to
that page only (`panel_ui`, drawn by `openHookDialog()` with the answer routed back as
`panel_ui_response`), a `notify()` is a line at the top of the panel, and `panel_result` carries the
outcome and the blocks. A page that closes mid-question is a cancel (`RpcUi::withdrawAll()`). Over
plain HTTP (`POST /api/panels/<id>`) there is nobody to ask: `$ui` is `NoUi`. A `lazy` block is
`render` asked again with its `section`, for what needs a network call (the quota). `commands` open
the panel from the prompt (`/accounts`) and join the `/` list.

`/api/panels` (`Web\WebPanels`) answers from the same fiber as `registerHttpRoute()`: the list, a
panel or section (`GET /api/panels/<id>?locale=&section=`), an action (`POST` `{action, args,
locale}`). Panels live in the shell process, like routes, and `/reload` forgets them
(`forgetWebPanels()`). The Antigravity accounts panel is the first (`extensions/pig-antigravity/src/
AccountsPanel.php`, its strings with it); the page has no accounts code, buttons or strings left.
Regression tests: `WebPanelsTest`, `AntigravityPanelTest` (the real blocks through `panels.js`
under Node) and `WebModeTest::testAnExtensionsPanelIsServedFromAFiberInThePagesLanguage`.

## The folder a new conversation starts in

The terminal's `pig` starts where it was run; the page asks. While a tab's conversation is empty
(`Tab.emptyState` — its setter redraws the chip), a 📁 chip by the model pickers shows its folder and
opens `components/CwdPicker.js`: the folders conversations were held in (`/api/folders`), a path
typed with its subfolders completed, and a walk through the tree (`/api/dirs`, `DirectoryListing`:
subfolders only, hidden ones left out, `~` and relative paths read as typed, 500 at most — any
folder this process can read, since the page already has a terminal). Choosing one replaces the
empty tab with a new one there, which is a new child started in that directory; after the first
message the chip is gone, as a conversation's folder does not change under it. The path handling is
`cwd.js`, run under Node by `DirectoryListingTest`. A project's own extensions in the folder load
by `defaultProjectTrust`, as for any web child: nothing in the page asks yet.

## Logging in to the web UI

The developer wanted the page behind a login always, 127.0.0.1 included, because another site's
page in the same browser can otherwise reach `127.0.0.1:8088` — a terminal and agents. Every HTTP
request, WebSocket handshakes included, goes through `HttpServer`'s middlewares (`Web\Middleware`,
smart-book's `handle(ctx, $next)` shape without OmniPHP) before `route()`:

1. `SameOriginMiddleware` — a request whose `Origin` is not the `Host` it was sent to is a 403. No
   `Access-Control-Allow-Origin` anywhere: only the page itself reads the API.
2. `LoginMiddleware` — without a session, a page is a 302 to `/login`, anything else a 401;
   `LoginMiddleware::PUBLIC` is what the login page needs.

`WebLogin` is one password (`password_hash` in `web-login.json`, 0600) and sessions (a random token
in an HttpOnly, SameSite=Strict `pig_session` cookie; only its SHA-256 kept, 30 days, surviving a
restart). The first password is set on the page with a one-time setup code that `pig web start`
(and `-d`, `status`, the TUI's `/web`) prints and `web-setup-code` keeps, so whoever reaches the page
first cannot take it. `pig web password` sets or changes it and logs every browser out; ten tries a
minute per address. Failures carry a `reason` (`WebLogin::REASONS`) the login page words in its
language. A session ended elsewhere takes effect at the page's next connection: the WebSocket
already open stays until it drops, and the page then goes to `/login`. Tests of everything else
build the server with `WebLogin::off()`; regression tests: `WebLoginTest` and
`WebModeTest::testNothingButTheLoginPageWithoutLoggingIn`.

## Translating answers: Telegram's setting, the session's model as the translator

The language settings carry Telegram's "Translate messages" block — **Do not translate** (the
languages the reader has; the display language unless they pick others) and **Translate entire
chats**. Off, every finished answer gets a Translate button that swaps its markdown for the
translation and back; on, the button is pressed as the answer finishes. Kept in the browser
(`localStorage` `pig_web_translate`, in `i18n.js` with the locale), since it is the reader's.

The page tells an answer's language by its script (`needsTranslation()`: mostly Han is Chinese,
kana Japanese, Latin English — the one guess that can be wrong, and the cheap one) and shows no
button for one in a "Do not translate" language. pig has no translation service, so the child's
model is the translator: `translate {text, language, doNotTranslate}` (`Session\Translation`,
through `Compaction::completeSummarization()` as a bug-report summary goes — no cache writes,
the session's retry policy, nothing kept in the conversation) answers the translation, or
`text: null` when the script could not place the answer and it was in one of those languages
after all. Answers and the person's own
questions are translated; tool output and thinking stay as they are.

## Scheduled tasks: a timer per task over the session pool

pig's own, after the Claude app's; upstream has none. The developer asked for it in the web UI and
the decisions were the developer's: a hook's question during a run **waits** for an answer as the
Claude app waits for approval (in a task whose permission is `ask`; see below), the form offers presets **and** a custom cron, and there is **no
minute jitter** — a task runs at the minute it says.

`Web\Schedule\ScheduleManager` arms one `Loop::delay()` per task. **A task has one conversation**,
as the Claude app's does (the developer's choice over one conversation per run):
`ScheduledTask::$sessionFile`, made when the task is saved — named after it and written at once with
`SessionManager::persist()`, since pi writes a file only after the first answer — so it is in the
sidebar with its clock before any run. A run is a prompt in it: `SessionPool::open()` gives the child a
tab already has on that file, or starts one nobody is bound to. A run that finds that child mid-turn is
`skipped`. Moving the task to another folder starts a new conversation there; one deleted from the
sidebar is started again at the next run; no client can name the file.

**A finished run is unread** until its conversation is on screen, as the Claude app's blue dot: each
run carries `seen: false` from its start (a run written before this has none and counts as read), the
sidebar row of the task's conversation and the Scheduled entry show a dot (`ScheduleManager::unread()`,
`scheduledUnread` in `/api/sessions/all`), and the page posts `/api/scheduled/seen` when that
conversation is the active tab of a visible page (`markScheduledSeen()` in `app.js`). Kept in the tasks
file, so it is the same on every device; a question waiting for an answer keeps its own amber dot. `ScheduledTask` stores **a list of triggers**
(`$triggers`, the Claude app's "+" in the form), each a five-field cron (`CronExpression`, hand-written,
Vixie semantics: `7` is Sunday, two restricted day fields OR) or `once`; none is a task that runs only
when asked. The next run is the soonest of them; a `once` trigger is dropped when it fires, and a
task left with no trigger by that is switched off. A task written with one `schedule` / `cron` /
`runAt` (before there could be more) reads as one trigger. All in the **browser's** timezone; the
page shows each in the viewer's own time. Tasks and runs are `~/.pig/agent/scheduled-tasks.json`.

**Permission, per task** — the Claude app's: `auto` (the default for a new task, as there) answers a
run's confirmations yes as they come (`ScheduleManager::observe()` relays the
`extension_ui_response`), so the run does not stop; `ask` leaves them for the person (`needs_input`).
A question that wants a choice or some text waits in both. A task from before the choice existed is
`ask`, which is what runs did then.

**The pages** (`ScheduledTasks.js`), laid out as the Claude app lays its own out at the developer's
request: the list is a page in the main area in place of the conversation (`#page-view`,
`#main-view.page-mode`) — cards with search, filter and sort, and a "New task" menu of *Create with
pig* (a new conversation with the Claude app's sentence in the prompt, unsent) and *Set up
manually*; a task's own conversation in the sidebar opens the **task's page** (history on the left;
instructions, schedule and permission on the right; Run now and the switch at the top; edit, open
conversation and delete under "⋯"); the form is a dialog with the permission and the model in a
bar under the instructions, a row per trigger, and the folder and thinking level under "Advanced".
A `schedule` call's confirmation is a card in the conversation under the call
(`scheduleConfirmCard()`, `drawScheduleCard()` in `app.js`): the arguments as sent, then task,
schedule, where it runs, source and instructions, read from `ScheduleTool::summary()`'s lines; Esc
cancels, ⌘/Ctrl+Enter schedules.

- **Missed runs happen once.** The next occurrence counts from `anchorAt`; after a run the anchor is
  *now*, not the occurrence, so a week asleep is one run. Saving or switching on resets the anchor.
- **The timer wakes at least once a minute** and compares against the wall clock, so sleep and clock
  changes are noticed within a minute.
- **One server runs the schedule**: the first takes `flock` on `scheduled-tasks.lock`; the others
  show the tasks and refuse writes with a 409.
- **`WebMode` starts it, not `HttpServer::start()`**, so a test that serves the page from a real home
  cannot fire that home's tasks.
- A question asked with nobody bound is kept in `ManagedSession::$pendingUi` and replayed on bind;
  an `extension_ui_response` relayed by the page removes it, `agent_settled` clears the rest.
- **A run a stopped server left going is carried on from its conversation.** `start()` opens the
  run's session file (`SessionPool::open()`) and sends pig's own `continue` command
  (`AgentSession::continueRun()`): a last turn that failed or was aborted is dropped (with a
  `context_edit`, as a retry drops it) and the run goes again; a conversation ending on an answer is
  `finished` and the run is DONE; one ending on tool calls without results has them answered and goes
  on — a call that may have half run is run again only if its tool is replay-safe, otherwise answered
  `interrupted` for the model to decide ([Carrying on a run that stopped inside a tool
  call](session.md#carrying-on-a-run-that-stopped-inside-a-tool-call)). A run the child refuses, with
  no session file, or whose task is gone, is an ERROR as before. pi-durable's whole runtime was weighed
  for this and declined — a second store beside pi's session file for one feature; its replay policy
  was taken without it.

Regression tests: `CronExpressionTest`, `ScheduleManagerTest` (both under `test/Web/`), the two
scheduled-task cases in `WebModeTest`, the six `continueRun` cases in `AgentSessionTest` and the
three `continue` cases in `RpcModeTest`.

### Setting one up by asking: the `schedule` tool

The Claude app's way, which the developer asked for: "every morning, see what changed upstream and
whether this code needs it" in a conversation becomes a task. `Web\Schedule\ScheduleTool`
(list, runs, create, update, delete, enable, disable, run), registered by `builtin:schedule`
(`extensions/pig-schedule`) — a built-in rather than core so `-builtin:schedule` switches it off,
the tool itself in the core beside the scheduler. The developer's three decisions:

- **Every change is confirmed** in a `$ctx->ui->confirm()` showing what will be saved (name, when
  with the next run, folder, model, prompt), checked by `ScheduledTask::fromArray()` first so the
  dialog never shows a task that cannot be saved. With `hasUi` false (`-p`, `--mode json`) the tool
  only reads.
- **From the terminal and from the page alike.** Neither is the process that holds the tasks:
  `ScheduleManager` keeps them in memory in the one `pig web` that owns the lock, and a write to
  `scheduled-tasks.json` from anywhere else is undone the next time it saves. So `ScheduleClient`
  asks that server over the page's own `/api/scheduled`. The owner writes its address
  (`HttpServer::localUrl()`, loopback for `0.0.0.0`) into `scheduled-tasks.lock` when it takes it,
  and `ScheduleManager::owner()` believes the address only while the lock is held. The way in is
  `WebLogin::localToken()` (`web-local-token`, 0600) as `Authorization: Bearer`, which
  `LoginMiddleware` accepts for `/api/scheduled…` from this machine (`TcpConnection::isFromThisMachine()`)
  and for nothing else. One path for both, so a conversation in the page does not need an RPC
  message of its own. No server: an error saying to run `pig web start -d`, not a file written for a
  server that might come later. A server that holds the lock and wrote no address is one older than
  the tool, and the error says `pig web restart`.
- **A run reports by default**: the guidelines tell the model to write a run's prompt to stand on its
  own and to ask before pulling, committing or editing. A question in a run already waits
  (`needs_input`).

The timezone is this machine's (`ScheduleTool::localTimezone()`: `TZ`, `/etc/localtime`'s target,
`/etc/timezone`, PHP's), shown in the dialog; `at` is read in it. Tests:
`WebModeTest::testTheScheduleToolAsksTheServerThatRunsTheScheduleWithTheLocalToken()`,
`ScheduleExtensionTest`, and the owner and local-token cases in `ScheduleManagerTest` and `WebLoginTest`.

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

- **It runs before the mode exists**, on a `TuiMainScreen` it starts and stops inside the call, so what
  comes back is an ordinary return value and the session is opened by the same code that
  opens a `--session <path>`.
- **Escape is upstream's "No session selected" and an ordinary exit 0.** pig used to start a new
  session on Escape; upstream's answer is the one a script around pig can predict.
- **Only with a terminal.** Without one there is nobody to ask, so `--resume` is an error naming the
  way that works: `--resume needs a terminal to choose on. Use --session <path|id>.`

**Ctrl+C is the third answer, and it went missing because there were only two.** `ask()` handed
back a `?string`, so the two keys that leave the list with nothing chosen had one value between
them — and `SelectList` answers both of them with its cancel handler, because to a list inside a
running screen ctrl+c is the screen's business. Here the list *is* the screen: pressing ctrl+c
started a brand new session, so somebody who asked to leave got a fresh agent, an empty prompt
and the same key as the only way out of that. Escape is "not this session", ctrl+c is "not pig",
and `SessionChoice` is what carries three answers instead of two — upstream keeps them apart with
a third callback that calls `process.exit(0)`, which a class here cannot do and `bin/pig` does
instead. `SessionList::setQuitHandler()` is that third callback, and a search box with a
half-typed query in it does not hold on to anybody: ctrl+c is the list's whichever way.

Upstream's selector has a delete key too, and it is ported (`app.session.delete`, ctrl+d):
it asks first, refuses the session being talked in, and moves the file to the trash when a `trash`
command is there, `unlink()` otherwise — so a slip of the key can usually be undone.
