# Changelog

What changed, newest first. `pig` shows the entries newer than the version you last saw, once,
under the conversation; `/changelog` shows the whole file.

A `##` heading with a version in it starts an entry and everything until the next `##` belongs to
it — so an `## Unreleased` heading is a section nobody has versioned yet, and the notes under it
are deliberately *not* shown as part of the release above. See `Pig\CodingAgent\Changelog`.

**Releasing:** the version lives in the **git tag** and nowhere else. `composer.json` has no
`version` field on purpose — Packagist derives one from the tag and says a field "should be omitted",
because a field and a tag are the two copies that can disagree. `Pig\CodingAgent\Version` asks
Composer what it installed, `pig --version` prints that, and the update check compares it against
Packagist. So a release is: add the heading below, commit, `git tag v0.2.0`, push the tag. There is
no number to bump anywhere else, and a build with no tag reachable reports
`1.0.0+no-version-set` — Composer's own words — and asks Packagist nothing.

### Changelog Entry Format (pi-style / Keep a Changelog)

Every release entry strictly follows upstream pi's format with version date and four standard sections:

```markdown
## [x.y.z] - YYYY-MM-DD

### New Features

- Feature title — Description of the major highlight, model capability, or core workflow.

### Added

- Added specific new options, tools, commands, extension APIs, or platform integrations.

### Changed

- Changed defaults, adjusted behaviors, refactored internal mechanisms, or optimized performance.

### Fixed

- Fixed specific bugs, crash conditions, encoding issues, or protocol mismatches.
```

- **Version header**: `## [x.y.z] - YYYY-MM-DD` (e.g. `## [0.2.0] - 2026-03-30`). Brackets around version are standard; date is ISO `YYYY-MM-DD`.
- **Sections**: Only include sections that have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`).
- **Items**: Each bullet starts with a verb or clear subject (`Added ...`, `Changed ...`, `Fixed ...`), describing both the symptom and the resolution.

## [0.3.19] - 2026-10-04

### Added

- **Startup asynchronous extension update check and notifications matching upstream pi**:
  - Implemented `PackageUpdateCheck::checkForUpdates()` to asynchronously scan user extensions against core package releases and git updates.
  - Implemented `InteractiveMode::sayPackageUpdates()` rendering the official `Package Updates Available` banner with instructions to run `pig update --extensions`.
  - Wired into `bin/pig` startup flow, alerting users seamlessly without delaying the initial frame.

## [0.3.18] - 2026-10-04

### Added

- **Extension updates in `pig update` & `pig update --extensions` matching upstream pi**:
  - `pig update` now updates both pig itself and all installed extensions by default.
  - Added `--extensions` option to update installed extensions across `~/.pig/agent/extensions/` (runs `git pull` for git repos, `composer update` for composer packages, and pure PHP recursive增量同步 for core built-in extensions `pig-antigravity`, `pig-mcp`, `pig-web-search`, `pig-codemode`, `pig-computer`).
  - Self-healing autoloader in `scripts/generate-models.php` for global and local environments.

## [0.3.17] - 2026-10-04

### Changed

- **Brand title refined to `pig — PHP AI Agent`**: Aligned the title with the core acronym behind pig (**P**HP **A**I a**G**ent) across English and Chinese README documentation.

## [0.3.16] - 2026-10-04

### Changed

- **Comprehensive README and documentation overhaul in English and Chinese**:
  - Synchronized `README.md` and `README.zh-CN.md` with official documentation at [pigagent.dev](https://pigagent.dev).
  - Added modern installation guides (`curl -fsSL https://pigagent.dev/install.sh | sh` and `composer global require pigagent/pig`).
  - Added dedicated sections for persistent Web UI mode (`pig web start -d`), sub-500ms startup architecture, built-in pure PHP extensions (`pig-antigravity`, `pig-web-search`, `pig-computer`, `pig-codemode`, `pig-mcp`), unified logger (`Pig\Logger`), and troubleshooting diagnostics (`/doctor`).

## [0.3.15] - 2026-10-04

### Added

- **Unified zero-dependency `Pig\Logger` (aligned with smart-book `OmniPHP\Logger` specification)**:
  - **Five-tier logging API with ANSI terminal coloring**: Added `Logger::verbose` (blue), `debug` (cyan), `info` (green), `warning` (yellow), and `error` (red), prioritizing `ERROR(4) > WARNING(3) > INFO(2) > DEBUG(1) > VERBOSE(0)`.
  - **Dynamic level filtering**: Controlled via `PIG_LOG_LEVEL` or `LOG_LEVEL` environment variable (defaults to `INFO`).
  - **Daily file rotation and retention**: Automatically persists logs to `~/.pig/agent/logs/pig-YYYY-MM-DD.log` with automatic cleanup of files older than 5 days (`PIG_LOG_KEEP_DAYS`).
  - **TUI-safe console toggling**: Added `setConsoleOutput(false)` during interactive TUI execution to prevent background log writes from polluting raw terminal rendering, while keeping persistent disk logs active.
  - **Performance and debugging helpers**: Included `Logger::dump()`, `Logger::time()`, and `Logger::timeEnd()`. Accessible via both `Pig\CodingAgent\Logger` and global alias `Pig\Logger`.

## [0.3.14] - 2026-10-04

### Added

- **Workerman-inspired structural fault isolation boundaries**:
  - **`TcpConnection` & `HttpServer` protocol protection**: Added `$onError` connection callback and wrapped framing/dispatching (`decode` & `onMessage`) in structured error guards. Malformed client payloads or handler errors return standard 500 JSON or WebSocket error frames without crashing the web daemon. Registered `Loop::setErrorHandler` in `HttpServer::start()` to guard background timers and event loop callbacks.
  - **`RpcMode` command boundary guard**: Wrapped `line()` command execution to guarantee uncaught exceptions are safely returned as structured JSON-RPC error responses (`{"id": ..., "type": "response", "success": false, "error": ...}`) rather than aborting child agent processes. Safe write protection on `RpcMode::send()`.

## [0.3.13] - 2026-10-04

### Fixed

- **Robust SSL socket write resilience, zero-write spin loop guard, and auto-retry coverage**:
  - **Zero-write loop & EOF protection in `Socket::write()`**: Added pre-write `feof()` validation and consecutive zero-byte write circuit breaker (`>10` attempts) to prevent rapid CPU spin loops when an OpenSSL stream is disconnected by the peer.
  - **Suppressed `fclose()` SSL close_notify warnings**: Wrapped `fclose($stream)` in `capturingWarnings()` within `Socket::close()`, silencing raw PHP warnings triggered when attempting TLS shutdown over a broken connection.
  - **Comprehensive auto-retry pattern matching**: Expanded `Retry::WORDS` to recognize native PHP transport errors (`ssl.*operation failed`, `tls.*handshake.*failed`, `write failed`, `read failed`, `cannot connect`, `network.*unreachable`, `host.*unreachable`, `socket.*closed`), ensuring transient network blips and SSL drops trigger automatic retry rather than aborting turns.

## [0.3.12] - 2026-10-04

### Changed

- **Sub-500ms startup for `pig -c` via `SessionManager::latestPathFor()`**: Added `latestPathFor()` matching upstream pi's `findMostRecentSession()` to discover the newest session file path by mtime and single-line header verification. Eliminates redundant full-file `describe()` passes prior to `SessionManager::open()`, cutting discovery latency from 206ms down to 1.6ms (128x speedup) and reducing overall `pig -c` time-to-first-frame from 630ms to 489ms.

## [0.3.11] - 2026-10-04

### Added

- **Sidebar session rename and deletion with hover actions in Web UI**:
  - **Inline session renaming**: Added an edit icon (`✏️`) on hover (and touch-persistent on mobile) to rename sessions directly from the sidebar. Saves via `POST /api/sessions/rename` and immediately synchronizes the active tab and header titles.
  - **Session deletion with confirmation**: Added a delete icon (`🗑️`) to remove `.jsonl` files from disk via `POST /api/sessions/delete` with strict path validation inside session roots. Automatically closes open tabs and terminates associated child processes in `SessionPool`.
  - **Mobile touch optimization**: Actions are rendered persistently with comfortable touch targets on touchscreens (<768px), ensuring full mobile parity.

## [0.3.10] - 2026-10-04

### Fixed

- **New session workspace resolution in Web UI**: Fixed an issue where clicking `+ New Session` inside a selected workspace drawer (e.g. `GCamAGC`) created the session in the previously active tab's project (`pig`) instead of the selected workspace. `startNewSession()` now prioritizes `selectedWorkspace.path`, safely handles `MouseEvent` listener bindings, and automatically closes the mobile drawer upon session creation.

## [0.3.9] - 2026-10-04

### Changed

- **Startup acceleration for `pig -c` on massive sessions (from 2.28s down to 0.63s)**:
  - **Eliminated O(N^2) memory reallocation in `SessionManager::pathTo()`**: Replaced loop-time `array_unshift` with O(1) appends followed by a single `array_reverse`, cutting traversal on large 28k-node session trees from 718ms to 3ms (230x speedup).
  - **Instant reverse search in `SessionManager::settings()`**: Replaced forward whole-tree scans with leaf-to-parent reverse lookups that terminate immediately once the latest model and thinking level are found, dropping execution time from 725ms to 0.6ms (1200x speedup).
  - **Zero-allocation array append in `SessionManager::resolve()`**: Replaced loop-time array unpacking (`[...$messages, ...]`) with direct element appending.
  - **Single-file inspection in `SessionManager::latestFor()`**: Updated `latestFor()` to inspect only the newest matching session file rather than parsing up to 30 history files on disk, cutting session discovery latency by nearly 50%.
  - **Deferred timestamp parsing in `SessionEntries::decode()`**: Skipped expensive `strtotime` and regex extraction on message entries, saving over 50ms during large JSONL file loads.

## [0.3.8] - 2026-10-04

### Added

- **Active session file badge with smart truncation in Web UI**: Added an interactive session file indicator in the top header displaying the active tab's underlying `.jsonl` session file upon switching tabs.
- **Smart filename truncation**: Long 60+ character session filenames are cleanly truncated to date-time prefix plus UUID tail (`2026-10-04T11-03…0b0e.jsonl`), preserving full timestamp and file identification without breaking UI layouts.
- **Click-to-copy & responsive mobile styling**: Clicking the badge copies the full absolute session path to the clipboard with an instant feedback animation (`Copied!`). Styled with responsive constraints (`max-width: calc(100vw - 120px)`) ensuring zero overflow or wrapping on 375px mobile screens.

## [0.3.7] - 2026-10-04

### Fixed

- **Broken pipe Warning capture in `Socket::write()` and `read()`**: Wrapped `fwrite()` and `fread()` in `Socket::capturingWarnings()` to prevent PHP's native OpenSSL warnings (`error:80000020:system library::Broken pipe`) from leaking into stdout/stderr and corrupting TUI/CLI renders when the remote peer disconnects or times out.
- **Accurate socket failure reason and auto-retry triggering**: Included the underlying warning message in `SocketError` (e.g. `Write failed: ... Broken pipe`) and closed the failed stream, allowing `Retry::worthRetrying()` to recognize the `broken pipe` failure and automatically trigger seamless session retries.

## [0.3.6] - 2026-10-04

### Changed

- **Lifecycle guidance on interactive `/web` startup**: Added a clear startup guidance note to `/web` output explaining that the temporary browser server runs for the current terminal session, pointing users to `pig web start -d` when permanent background daemon operation is desired.

### Added

- **Interactive `/web` test coverage**: Added `testSlashWebShowsUrlAndDaemonHint` verifying `/web` startup output, port binding, and clean shutdown via `/web stop`.

## [0.3.5] - 2026-10-04

### Fixed

- **Session resolution for bare `.jsonl` filenames (`SessionManager::find()`)**: Fixed an issue where passing a bare session filename (ending in `.jsonl` without path separators, e.g. `2026-10-04...jsonl`) caused `find()` to check only `./filename.jsonl` and immediately return null without checking the project's session storage directory (`~/.pig/agent/sessions/...` or `~/.pi/agent/sessions/...`). This caused Web UI tabs to fail with `No session found matching '...'` upon reload or resume.
- **Session path pre-resolution in `HttpServer`**: Canonicalized `$sessionFile` to its full absolute path via `SessionManager::find($cwd, $sessionFile)` prior to spawning `RpcClient` subprocesses, ensuring reliable resumption under all environment conditions.

## [0.3.4] - 2026-10-04

### Fixed

- **Missing `Async` import in `HttpServer`**: Added `use Pig\Async\Async;` to `HttpServer.php`. Previously, requesting `/api/accounts/usage` (such as opening the Antigravity Accounts panel in Web UI) threw `Error: Class "Pig\CodingAgent\Web\Async" not found` when attempting to spawn the asynchronous quota check.
- **Accounts endpoint test coverage**: Added unit tests in `WebModeTest` verifying that `/api/accounts/usage` executes `Async::spawn()` cleanly without fatal un-imported class errors.

## [0.3.3] - 2026-10-04

### Fixed

- **Crash on session resume (`bin/pig -c`) with expired OAuth tokens**: Fixed a fatal `Future::await() must be called inside a coroutine` crash when resuming a conversation that used an expired OAuth credential (e.g. Antigravity or Anthropic OAuth). `Auth::fresh()` now prevents attempting async network token renewal outside of a coroutine, and `AgentSession::restoreSettings()` uses non-network peeking (`hasKeyFor()`) to verify model availability safely.
- **Streaming session file parser (`SessionManager::open()`) to prevent 128MB OOM on large files**: Replaced full-file `file()` array reads with incremental line-by-line stream decoding (`fopen` + `fgets`). Opening 50MB+ conversation sessions (tens of thousands of lines) now consumes under 10MB of memory instead of exhausting the PHP default 128MB memory limit.

## [0.3.2] - 2026-10-04

### Changed

- **Single-connection WebSocket multiplexing for Web UI**: Replaced per-tab physical WebSocket connections with a single shared WebSocket connection for the entire browser window. All conversation tabs multiplex their traffic over this single wire using `tabId` tagging:
  - `SessionPool`: Upgraded subscription mapping from 1:1 connection binding to multi-tab subscription keys (`"$connectionId:$tabId"`), tracking client tab subscriptions per managed session and resolving `boundTo($connectionId, $tabId)` on command relay.
  - `HttpServer`: Encoded outgoing RPC events, responses, and termination notices with `tabId`, routing messages strictly to the relevant tab.
  - Frontend Web UI: Introduced `SharedNetwork` singleton with global heartbeat, window-level reconnect with jitter, and automatic bulk resubscription of all active tabs upon connection establishment.
  - Resource optimization: Physical TCP connections remain constant at 1 regardless of tab count (1, 10, or 50 tabs), eliminating connection storms, browser socket limits, and reverse-proxy long-poll quotas while fully preserving full-time background stream rendering across tabs.

## [0.3.1] - 2026-10-04

### Fixed

- **Silent prompt failure in Web UI without error messages**: Fixed a critical issue where failed turn generations (e.g. invalid API keys, 401/403/500 provider errors, rate limit exhaustion, or unselected models) completed with no visible error in the browser:
  - `RpcEvents`: Encoded `error` directly onto `agent_end` events by extracting `lastError` from failing `AssistantMessage` instances, and included `$event->error->errorMessage` in `ErrorEvent` deltas.
  - `RpcMode`: Preserved `$id` on asynchronous prompt failure responses so error rejections correlate properly.
  - Frontend Web UI: Displayed errors upon `retry_end` failure (`succeeded === false`), rendered unmatched or asynchronous failure responses via `appendErrorMessage()`, extracted fallback errors from `agent_end.messages`, and restored input text on connection or prompt dispatch failure instead of losing user typing.

## [0.3.0] - 2026-10-04

> **Milestone Release**: Rebuilt the web architecture on upstream `pi-web`'s multi-session process model, introduced native `pig web` daemon management, and eliminated long-standing background pipe inheritance deadlocks.

### New Features

- **Multi-session Web UI tabs via `pig --mode rpc` process pool**: Rebuilt web mode on upstream `pi-web`'s architecture. The browser shell (`HttpServer`) now coordinates a managed child process pool (`SessionPool`) where each conversation tab is backed by an isolated `pig --mode rpc` process. Multiple tasks run concurrently in parallel tabs without interfering with each other's stream, tool execution, or thinking state.
- **Background daemon management (`pig web start|stop|status|restart [-d]`)**: Native double-fork and `posix_setsid()` process daemonisation with pidfile and log output management under `$PIG_HOME/web.pid` and `web.log`.

### Added

- **Per-tab WebSocket connection & independent state machine**: Each frontend tab maintains its own WebSocket connection, chat scroll area, and RPC request dispatch. Reconnecting or reloading recovers all opened tabs and re-attaches to existing child sessions without restarting turns.
- **RPC slash command parity**: Added `/doctor`, `/diff`, `/bug`, and `export_markdown` commands to `RpcMode`, giving Web and RPC clients full parity with terminal slash command diagnostics.
- **FD leak mitigation in `RpcClient`**: Added `withoutInheritedFds()` to close leaked file descriptors (fds 3-255) prior to spawning child processes, avoiding deadlocks where children inadvertently held server sockets open.

### Fixed

- **Infinite hang in `Run::wait()` when commands spawn background grandchildren**: Fixed a critical hang where running background jobs (such as `(cmd) &` or background daemons) caused `Run::wait()` to block indefinitely waiting for pipe EOF because child processes inherited stdout/stderr descriptors. `Run` now periodically polls `proc_get_status()` and promptly completes after the primary shell exits.
- **Model resolution in `RpcMode::setModel()`**: Fixed a bug where `RpcMode::setModel()` stripped the `provider` parameter and fell back to direct providers via `Models::RESOLD`, causing resold models (such as `antigravity/*` or `github-copilot/*`) to switch to incorrect providers.
- **SessionPool path canonicalization**: Canonicalized directory paths via `realpath()` to resolve differences between symlinked paths (such as `/tmp` vs `/private/tmp` on macOS), preventing duplicate child process spawns on reload.

## [0.2.49] - 2026-10-04

### Added

- **`pig web start|stop|status|restart [-d] [--port N] [--host H]` — the browser UI as a daemon.** `start -d` double-forks, `setsid()`s, redirects the standard streams to `~/.pig/agent/web.log` and execs `pig --mode web`, so a daemonised server runs byte-identical code to a foreground one. The pid file lives under `$PIG_HOME`; `status` trusts it only after `kill -0` and clears a stale one; `stop` is SIGTERM, a bounded wait, then SIGKILL; a second `start` while one runs is refused rather than binding the next port up. `--web-host` and `--web-port` are new `--mode web` options underneath. No framework: this is `pcntl` plus two `posix` calls, and the two files that would have been redundant under Workerman (`HttpServer`, `Protocols\*`) stay as they are. Step ① of three toward pi-web's process model — the decision and the other two steps are written up in CLAUDE.md under "Three ways in".

### Fixed

- **The daemon's exec named the binary relative to a directory it had just left.** `$argv[0]` is `bin/pig` from a checkout and the grandchild `chdir('/')`s before exec, so the first live `start -d` printed ✔, failed on `/bin/pig`, and `status` said "not running" two seconds later. The binary is `realpath()`ed at construction; and the parent now waits 300ms and asks the kernel whether the daemon survived its exec before claiming success, reporting the log's last line when it did not.
- **The daemon's session belonged to `/`.** Same `chdir('/')`; `--cwd` is now pinned to where `pig web start` was typed.

## [0.2.48] - 2026-10-04

### Added

- **Custom dropdown menu for the provider / model / thinking selectors on desktop.** A native `<select>` opens its list wherever the OS decides — macOS anchors it on the *selected* row, so with the sixth of seven providers chosen the menu floated a hundred pixels above the pill with nothing between. The menu is now a DOM element anchored flush above the pill (6px gap, whatever is selected), in the page's own dark theme, with hover/active rows, a `✓` on the current one, and full keyboard support (↑↓ to move, Enter/Space to pick, Esc to close, Tab closes). The `<select>` stays as the source of truth — its options, its value, its `change` event — so the three cascading handlers are untouched. Touch devices keep the native picker: iOS's bottom wheel is the better control there.

### Fixed

- **Switching model from the Web UI ignored the provider and switched to the direct one.** `/api/model` and the WebSocket `set_model` built `"provider/id"` and handed it to `Models::get()`, which compares *bare* ids — so the lookup missed, the `?? Models::get($modelId)` fallback resolved the bare id to the direct provider by `Models::RESOLD`, and a pick of `antigravity / gemini-2.5-flash` switched the session to **Google's public** `gemini-2.5-flash` on a different bill and quota, with `ok: true`. Both paths go through `resolveModel()` now, which uses the keyed `Models::find()` when a provider is named and also accepts a combined `provider/id` string.
- **A model switch that did not happen answered `ok: true`.** The HTTP handler swallowed every failure — no such model, no API key — and returned 200 with the old model in `state`, so the dropdowns redrew as though the pick had taken. It answers 400 with the reason now, and the page shows it.
- **Every model pick ran `setModel()` twice.** `sendModelChange()` sent the switch over the WebSocket *and* then POSTed it, which wrote two `model_change` lines into the session file per pick. One request, over HTTP, because its answer carries the state the dropdowns redraw from.

## [0.2.47] - 2026-10-04

### Fixed

- **Added missing `TcpConnection::isClosed()` getter.** In `HttpServer::armHeartbeat()`, WebSocket ping heartbeat check invoked `$wsClient->isClosed()`. `TcpConnection` had `protected bool $isClosed` but lacked the public `isClosed(): bool` getter method, causing a fatal error (`Call to undefined method Pig\CodingAgent\Web\Connection::isClosed()`) when the 25-second heartbeat timer fired. Added public getter to `TcpConnection`.

## [0.2.46] - 2026-10-04

### Added

- **Official pig mascot SVG favicon for Web UI (`/favicon.ico` & `/favicon.svg`).** Browsers requesting `/favicon.ico` previously returned a 404 error. `HttpServer` now directly serves the official cute pink piglet vector icon (`HttpServer::FAVICON_SVG`), and `index.html` registers both `<link rel="icon" type="image/svg+xml" href="/favicon.svg">` and `/favicon.ico` fallback with 24-hour client caching (`Cache-Control: public, max-age=86400`).

## [0.2.45] - 2026-10-04

### Changed

- **Custom SVG dropdown chevron for model & provider selectors (`.select-pill`).** Replaced clumsy native browser dropdown arrows (which rendered with inconsistent offsets, uneven line thickness, and oversized glyphs across WebKit and Blink) with a clean, centered vector chevron (`stroke-width: 1.5`, `#94a3b8`) via `appearance: none` and SVG background. Added balanced right padding (`padding-right: 24px` on desktop, `20px` on mobile) so text never collides with the arrow.

## [0.2.44] - 2026-10-04

### Added

- **Mobile responsive layout & touch UX adaptation (< 768px).** Comprehensive optimization for iOS Safari and mobile browsers:
  - **Dynamic Viewport Height (`100dvh`) & Safe Areas**: Switched main layout height to `100dvh` and added `env(safe-area-inset-bottom)` padding to prevent mobile bottom bars and home indicators from obscuring input controls.
  - **Slide-out Drawer with Blur Backdrop**: Converted sidebar into an overlay drawer covering up to 85vw on mobile, complete with backdrop blur tap-to-close (`.sidebar-backdrop`) and auto-closing on session selection.
  - **iOS Safari Auto-Zoom Prevention**: Set prompt input font size to `16px` on mobile viewports so tapping the textarea does not trigger iOS Safari's default page zoom.
  - **Touch Target Sizing**: Enlarged action buttons (`#send-btn`, `#attach-btn`, `#stop-btn`) to minimum `44px` touch targets conforming to Apple HIG.
  - **Adaptive Selectors & Header**: Wrapped model/provider/thinking selectors and status telemetry into flexible responsive rows, ensuring no layout breaks or text clipping on narrow screens.

## [0.2.43] - 2026-10-04

### Added

- **RFC 6455 Ping/Pong standard protocol keep-alive.** Implemented `Websocket::ping()` and added periodic server-initiated Ping heartbeats (every 25 seconds) in `HttpServer` across all active WebSocket clients. Handled client Pong responses quietly in `Websocket::decode()`, ensuring standard protocol keep-alive without payload overhead.

### Changed

- **Telegram-style WebSocket auto-reconnect with exponential backoff & fresh connection isolation.** Web UI frontend (`index.html`) now implements robust reconnection:
  - Completely closes, detaches listeners, and discards previous dead connection instances before spawning a fresh `new WebSocket(wsUrl)` connection, matching Telegram's clean connection handover.
  - Implements exponential backoff with jitter (1s base up to 10s maximum) on network drops.
  - Displays non-intrusive status banner ("Connecting to server...") while disconnected, automatically dismissed on reconnection.
  - Listens to `window.online` and `document.visibilitychange` to trigger immediate reconnect without waiting for retry timers when the user switches tabs or regains network connectivity.

## [0.2.42] - 2026-10-04

### Fixed

- **Suppressed `fwrite()` Broken pipe / Connection reset warnings in `TcpConnection`.** In `TcpConnection::sendRaw()` and the writable loop callback, writing to a client socket that abruptly closed or refreshed caused PHP to emit notices (`fwrite(): Send failed with errno=32 Broken pipe` / `errno=54 Connection reset by peer`). Wrapped both `fwrite` invocations in scoped `set_error_handler` / `restore_error_handler` blocks following project conventions (zero `@` error suppression), ensuring client disconnects close cleanly without spewing warnings.

## [0.2.41] - 2026-10-04

### Fixed

- **Web UI tool cards squished into 2px hairline strips by flexbox (`flex-shrink: 0`).** In `index.html`, `#chat-scroll` uses `display: flex; flex-direction: column`. Because default flexbox items have `flex-shrink: 1`, when a conversation accumulates dozens or hundreds of tool calls, Chrome and WebKit aggressively shrink all non-text children down to their borders (`offsetHeight: 2px`). Added `flex-shrink: 0` to `.tool-card`, `.msg-block`, `.thinking-box`, and `.compaction-box`, allowing all tool cards to retain their full height, headers, and click-to-expand capabilities inside the scroll container.

## [0.2.40] - 2026-10-04

### Added

- **Web UI compaction and branch summary support.** `HttpServer::getMessagesPayload()` now exports `CompactionSummary` (`role: "compaction"`), `BranchSummary` (`role: "branch_summary"`), and `HookMessage` (`role: "hook_message"`). Web UI renders clean collapsible summary cards (`⊙ Compacted · N earlier messages summarised` and `⑂ Branch summarised`), enabling inspection of conversation summaries without breaking turn layout.

### Changed

- **Upgraded Web UI tool cards with type-colored borders, icons, and status indicators.** Aligned `.tool-card` styling with terminal TUI standards:
  - Color-coded left accents: sky blue for `bash`, emerald green for `read`, purple for `edit`, orange for `write`, yellow for `grep`/`find`, blue for web tools, rose for `computer`.
  - Added explicit interactive expand/collapse chevrons (`▶ / ▼`) and status icons (`⏳ Running...`, `✔ Done`, `✖ Failed`).
  - Auto-expands failed tool calls for instant debugging visibility.

### Fixed

- **Web UI tool cards appearing as blank/flattened strips.** Resolved CSS layout issue where tool card headers and empty pre-execution bodies compressed into indistinguishable borders during high-frequency sequential tool invocations.

## [0.2.39] - 2026-10-03

### Fixed

- **Web UI tool calls not rendering on history replay (camelCase vs snake_case mismatch).** In Web UI `loadMessages()`, history entries from `.jsonl` session files store `type: "toolCall"` and `role: "toolResult"`. The frontend only checked snake_case `c.type === "tool_call"` and `m.role === "tool_result"`, which caused all tool call cards and execution diffs to be completely skipped when loading or refreshing conversations. Added support for both `toolCall` / `tool_call` and `toolResult` / `tool_result`.

## [0.2.38] - 2026-10-03

### Added

- **Complete tool execution UI coverage for Web UI.** Aligned `createToolCard` and `updateToolCard` across all core and extension tools (`write`, `edit`, `read`, `bash`, `find`, `grep`, `ls`, `web_search`, `fetch_web_page`, `browse_web_page`, `computer`):
  - `write`: Renders formatted file write preview cards (first 10 lines + line count indicator) matching terminal TUI behavior.
  - `read`: Displays accurate line offset/limit ranges (`read <path>:start-end`) and outputs formatted file contents.
  - `edit`: Auto-renders unified or inline diffs with color-coded additions/deletions even when inspecting raw argument payloads.
  - `bash`: Full status metadata formatting (cancelled indicator, exit codes, output truncation warnings).
  - Multi-modal support: Automatically parses and embeds tool result inline images (`ImageContent` / screenshots) directly inside execution cards with click-to-zoom.
  - History replay: Fixed `loadMessages()` to correctly extract and render structured `content` blocks for past tool executions from `.jsonl` session files.

## [0.2.37] - 2026-10-03

### Changed

- **Standardized Web UI English UI copy to professional title case.** Corrected lowercase buttons and labels in `packages/coding-agent/src/Web/assets/index.html`:
  - `accounts` button → `Accounts` (Title: `Accounts & Quota`)
  - `directories` subheader → `Directories`
  - Input placeholder `send a message or paste images...` → `Send a message or paste images...`
  - Account dialog headers and action buttons: `Antigravity accounts` → `Antigravity Accounts`, `Quota — active account` → `Quota — Active Account`, `rotate` → `Rotate`, `refresh` → `Refresh`, `use` → `Use`, `remove` → `Remove`.

## [0.2.36] - 2026-10-03

### Changed

- **Web UI dark theme contrast and typography readability enhancements.** In `packages/coding-agent/src/Web/assets/index.html`:
  - Upgraded `--text-muted` from `#94a3b8` to `#cbd5e1` and `--text-dim` from `#64748b` to `#94a3b8` to dramatically improve legibility on deep dark backgrounds.
  - Brightened tool execution headers, terminal outputs (`#f1f5f9`), thinking block summaries, and code block language tags.
  - Adjusted assistant message background (`#1e293b`) and border contrast (`#233144`), resolving eye strain and low-contrast grey text in dark mode.

## [0.2.35] - 2026-10-03

### Fixed

- **Web UI input composition handling (IME Chinese typing).** In Web UI (`packages/coding-agent/src/Web/assets/index.html`), pressing Enter to confirm Chinese pinyin candidates in the prompt input or hook dialogs previously triggered immediate message submission. Added `compositionstart` / `compositionend` event listeners and `e.isComposing` / `keyCode 229` guards so Enter only confirms candidate selection while composing text.

## [0.2.34] - 2026-10-03

### Fixed

- **Crash on undefined method `SystemClipboard::default()`.** When executing `/bug` or exporting PR descriptions, `InteractiveMode` called `SystemClipboard::default()->write(...)` which threw `Call to undefined method Pig\Tui\Clipboard\SystemClipboard::default()`. Fixed by using injected `$this->clipboard->write(...)` in `InteractiveMode` and adding `SystemClipboard::default()` convenience factory method.

## [0.2.33] - 2026-10-03

### Changed

- **Theme palette aligned with upstream pi OKHSL color tokens.** Updated `DARK` and `LIGHT` palettes in `Palette.php` to match upstream's perceptual OKHSL design space. Calibrated `dim` (`#7e888e`), `muted` (`#9da5a9`), `accent` (`#a798d7`), `border` (`#5fa8cc`), and `thinking*` gradients, ensuring identical contrast, status output, and code block highlight colors between pig and pi.

### Fixed

- **Context token double counting in Footer and Web UI.** In Google / Gemini / Antigravity usage accounting where `input` already subsumes prompt cache hits, `FooterComponent` and `HttpServer` previously added `cacheRead` back onto `input`, erroneously reporting context percentages exceeding 100% (e.g. 145.6% vs pi's 72.6%). Unified calculation to use `$session->contextTokens()`, which prioritizes provider `totalTokens` and matches compaction triggers.
- **Suppress browser launching during PHPUnit test runs.** In `McpExtensionTest`, OAuth authorization URL generation previously invoked system `open` / `xdg-open` subprocesses, causing unwanted browser tabs to pop up during test suites. Added `PIG_TESTING` guard in `extensions/pig-mcp/index.php` and `McpCli.php` to keep tests completely headless.

## [0.2.32] - 2026-10-03

### Added

- **Domain-specific Cookie files support (`~/.pig/agent/<domain>.cookies.json`).** `pig-computer` and the browser Docker container now support isolating cookies per domain (e.g. `jd.com.cookies.json`, `taobao.com.cookies.json`) in addition to the unified `cookies.json`. 
  - Each site's cookies can be cleanly exported, saved, and updated independently without risking accidental cross-site overwrites.
  - Priority fallback: `~/.pig/agent/<domain>.cookies.json` is checked first, falling back to `~/.pig/agent/cookies.json`.
  - Updated `/computer cookies` to list and report all discovered domain cookie files and expiration status.

## [0.2.31] - 2026-10-03

### Added

- **Pre-flight authentication & Cookie validation in `pig-computer` for e-commerce platforms.** When navigating to domains requiring login sessions (such as `jd.com`, `taobao.com`, `tmall.com`, `douyin.com`, `amazon.com`), `pig-computer` now pre-checks `~/.pig/agent/cookies.json` before sending requests. If the file is missing, invalid, lacks domain cookies, or has expired key session cookies (e.g. `pt_key`, `_m_h5_tk`, `sessionid`), it terminates the turn immediately with detailed guidance on how to export and save cookies, avoiding blind loops or dead-end redirect cycles.
- Added `/computer cookies` slash command to inspect loaded domains and cookie counts from `~/.pig/agent/cookies.json`.

## [0.2.30] - 2026-10-03

### Fixed

- **Crash when rendering fallback label for `ImageContent` with inverted parameters.** In `pig-computer`, `new ImageContent('image/png', $base64)` passed arguments in the inverted order (`$data, $mimeType`). On terminals with image drawing disabled or falling back, `TerminalImage::fallback()` received the 900KB raw base64 string as the `$mimeType`, generating a single line over 919,383 columns wide that crashed `Tui::checkWidth()`. Corrected parameter order to `new ImageContent($base64, 'image/png')` and added defensive length bounding in `TerminalImage::fallback()`.

## [0.2.29] - 2026-10-03

### Fixed

- **PHP 8.5 `curl_close()` deprecation warning in `pig-computer` extension.** In PHP 8.0+, `curl_init()` returns a `CurlHandle` object that is automatically closed on destruction; in PHP 8.5, calling `curl_close()` is deprecated and emitted a runtime deprecation warning mid-stream during tool execution. Replaced with `unset($ch)` across `pig-computer`.

## [0.2.28] - 2026-10-03

### New Features

- **Computer Use & Anti-Detection Browser Automation Suite.** Added `docker/browser/` and `extensions/pig-computer/` for true OS-level / browser-level automation. 
  - Docker container based on `puppeteer-real-browser` + `Xvfb` (1920x1080 virtual display) with anti-detection fingerprint injection to bypass Cloudflare Turnstile, Baidu, and Google anti-bot challenges.
  - Automatically loads and mounts `~/.pig/agent/cookies.json` and persistent browser profile, enabling automated authenticated actions on e-commerce sites (e.g. JD.com, Taobao) without re-login.
  - Registers the native `computer` tool for multimodal agents (Claude 3.7 / Gemini 3.8 / GPT-4o) supporting actions: `navigate`, `screenshot` (base64 ImageContent), `click` (human-like smooth Bezier curve movement), `mouse_move`, `type`, `key`, `scroll`, and `text`.
  - Added `/computer status` and `/computer screenshot` slash commands.

## [0.2.27] - 2026-10-03

### Added

- **Git-modified files priority ranking in `@` file picker.** When typing `@` in the prompt, files modified or untracked according to `git status --porcelain -u` are automatically scored higher and prioritized at the top of the autocomplete list with a `modified · ` indicator badge, greatly accelerating navigation during iterative bug fixing and code reviews.
- **Argument completions for `/theme`.** Typing `/theme <tab>` now automatically autocompletes available built-in themes (`dark`, `light`) with descriptions instead of falling through to the filesystem picker.

## [0.2.26] - 2026-10-03

### Fixed

- **Pig mascot logo split vertically on narrow terminals.** Previously, the 2-line pig logo placed the 84-column summary text (`escape interrupt · ctrl+c/ctrl+d clear/exit · / commands · ! bash · ctrl+o more`) directly to the right of the bottom logo line (`$bottomLogo $summaryStr`). In standard terminals narrower than 85 columns (such as 80-column splits), the summary text wrapped onto a new line, inserting an extra row between the logo's top half and bottom half and breaking the mascot. Now, the 4-cell mascot logo sits compactly beside the version label, with the summary text rendered cleanly on its own dedicated line below.

## [0.2.25] - 2026-10-03

### Added

- **/bug server upload integration.** `/bug` in interactive mode now automatically uploads the diagnostic report to `https://pigagent.dev/api/bug-reports`, prints the permanent online report URL (e.g. `https://pigagent.dev/bug-report/{id}`), and continues to copy the report to the clipboard and open the GitHub prefilled issue URL.
- Added `BugReport::upload()` to submit diagnostic bundles via non-blocking `HttpClient`.

## [0.2.24] - 2026-10-03

### Changed

- **Default model is now `(antigravity) gemini-3.8-flash • medium`.** When no model is typed, set in the environment, remembered from last time or chosen in the settings, pig opens on Antigravity's Gemini 3.8 Flash at `medium` reasoning instead of Anthropic's Claude Sonnet 4.5 at `off`. Explicit `--model`, `PIG_MODEL`, settings and resumed sessions are untouched.

## [0.2.23] - 2026-10-02

### Fixed

- **`/bug` failed the moment it was typed** with `Undefined property: InteractiveMode::$terminalUi` — the field is `$ui`, and the one command that exists for reporting a fault was the one command nothing drove through the screen. `/commit`'s confirm read the same name. Both read `$ui` now, `/bug` has a test that types it, and `test/lint.php` sweeps every class for a `$this->name` with no property called `name` — which is how a misspelled field gets past `php -l`, and which found exactly this one.

## [0.2.22] - 2026-10-02

### Fixed

- **A `trust.json` with a `null` in it stopped pig from starting.** pi's trust store takes `true`, `false` or `null` — `null` is what its "forget this path" writes — and pig's reader refused anything but a boolean, so a file pi had written could crash pig before any screen existed. A null is walked past to the nearest real decision above it, as upstream's `findNearestTrustEntry()` walks past it. A value that is none of the three is still refused, and the message now says what to do about it.

## [0.2.21] - 2026-10-02

### Added

- **A tool result with pictures in it reaches a codemode script as image blocks.** `read` on a PNG or `generate_image` used to answer the script its text alone — `Saved image to …` — and the script had to `read` the file again to show it. It answers `['text' => …, 'images' => [['type' => 'image', 'data' => <base64>, 'mimeType' => …], …]]` now, MCP-shaped, so `image($r['images'][0])` shows it as it is. The same shape upstream's `models.generateImages()` hands back, reached through the tools pig already has rather than a `models` API over a provider pig does not carry.

### Fixed

- **`agent_settled` fired once per run and not once per prompt**, so a hook that notifies on it — the desktop notification extension — said "task complete" on every failed attempt a retry was about to repeat, and on the run an auto-compaction summarised, while the task was still going. It was emitted beside `agent_end`, which is the end of a *run*; upstream emits it once, after the retry loop, when pi will not continue on its own. pig emits it from the one place that means "nothing more is coming" now, whichever ending the prompt had. `system-notify.php` listens on `agent_settled` as pi's does; a notification hook on `agent_end` will keep firing per run, which is what that event means.

## [0.2.20] - 2026-10-02

### Changed

- **codemode costs ~60% fewer prompt tokens.** The tool's description was a full reference — every helper spelled out, ~830 tokens on every request before a single nested tool was listed. It is upstream 1.0's shape now: the intro, one line per global, and the path of `extensions/pig-codemode/CODEMODE.md`, the reference the model reads when it needs a detail (~300 tokens; with two MCP tools listed 929 → 395). The system prompt's snippet and guideline are upstream's shorter ones, and a namespace heading says `(some tools not listed)` rather than counting.
- **codemode errors say how to recover**, upstream 1.0's `guard()`: `$tools->readTextFile()` is answered with `does not exist. Did you mean $tools->read_text_file()?` — names compared with case and punctuation removed, then by containment, then the whole list when it is short — instead of `Unknown tool`. An oversized `store()` value says what the store is for and where large data goes instead.
- **MCP OAuth credentials are stored per server name and URL**, so two servers at one URL can be two accounts (upstream's #10252). An entry stored by URL alone is taken over by the first server that loads it.

### Added

- `oauth.authServerMetadataUrl` for an MCP server that advertises the wrong authorization server or none (upstream's #10172): the document decides, and it is read every time so a changed URL applies at once. `pig mcp add --oauth-auth-server-metadata-url`.

### Fixed

- **An MCP OAuth code whose `iss` names another authorization server was exchanged anyway.** The callback read `iss` and nothing checked it. Refused before the exchange now (RFC 9207), and a server whose metadata promises `iss` on every response is not believed without one.
- **A server asking for more scope asked for ever.** `insufficient_scope` may name only the missing scopes, and the new sign-in requested only those, so the new token lost what the old one had and the server asked again. The step-up asks for the granted scope plus the missing one; a token response without `scope` is recorded as having granted what was requested (RFC 6749 §5.1) so there is something to keep.
- An MCP token response with `"scope": ""` — or any empty optional field — was refused as invalid, throwing away a sign-in that had worked (upstream's #10266).
- `store()` past its size limit threw `Class "RangeError" not found`: PHP has no such class, so the limit had never been reachable. `LengthException`.

## [0.2.19] - 2026-10-02

### Added

- **Alt+Enter queues a follow-up and Alt+Up takes the queue back**, upstream's `app.message.followUp` and `app.message.dequeue`. Typing while the model works had one door: Enter, which steers — the message goes in after the tool that is running. Now there are upstream's two: Enter steers and Alt+Enter (Option+Enter on a Mac) waits for the turn to end, which is the key for "and after that, do this" as against "no, the other file". What is waiting is drawn above the prompt as `Steering:` or `Follow-up:` rather than one `Queued:` for both, with `↳ Option+Up to edit all queued messages` under it — the way back out, named, since a queued line that cannot be taken back is one nobody dares to queue. Alt+Up pulls everything queued into the editor in front of whatever is half typed and leaves the turn running; escape still does the same on its way to stopping it. From an idle prompt Alt+Enter is Enter, as upstream has it. Both keys move in `keybindings.json` like the rest, and `AgentSession::queuedByKind()` is what the screen reads. A file command typed mid-turn steers too, where it used to follow up — the one place the two queues were still swapped.

## [0.2.18] - 2026-10-02

### Fixed

- **Widening the window left its right-hand side blank for most of a second** on a long session. Measured on a resumed 1,144-message conversation at 169 columns against pi on the same file: pi's redraw began 125ms after the signal, pig's 823ms. Four things, all in the frame and none in the terminal. `BashOutputComponent` wrapped the **whole** of a command's output to keep its last five rows — a 50KB build log collapsed to five rows cost a full wrap at every new width, 280 of them in that session, 460ms of the frame; it wraps from the end now and stops once the rows are in hand, with `TextWrap::rows()` counting what the dropped head would have made. `Width::visible()` sent every *styled* line of printable ASCII — which is every line of a transcript — through grapheme segmentation, because the escape in it failed the plain-ASCII fast path; 12,800 lines measured before writing were 236ms, and a styled ASCII line is one column a byte once the codes are gone, as upstream's `asciiVisibleWidth` has it. `TextWrap::breakWord()` cut a 46KB minified line grapheme by grapheme (10ms a line, at every width); a word of printable ASCII is cut by bytes now, held byte-identical to the grapheme walk over 3,000 random styled words. And `ProcessTerminal::write()` handed `fwrite()` the whole remainder of the frame each time the tty took a kilobyte of it — 2,400 copies of 2.5MB, 72ms of `memcpy`; it hands over 64KB slices. First byte of the redraw is 194ms after the signal now, against pi's 125, and the whole frame is on screen in 227ms against pi's 146 — what is left is 2.5MB against pi's 2.2MB going through the tty.
- The editor's two horizontal rules were styled per character — 280 escape sequences and 3,360 bytes per 140-column rule, two rules a frame — and are one styled string now. The whole-frame clear is `\e[2J\e[H\e[3J`, screen first and scrollback last, which is upstream's order.

## [0.2.17] - 2026-10-02

### Changed

- **A confirm dialog has Yes first**, as upstream's `showExtensionConfirm()` has it (`["Yes", "No"]`): Enter alone confirms, and Escape is still the no. pig had No first and called it the safe direction; the safe answer has not moved — it is one key further from the cursor.

### Fixed

- **Dragging the window stuttered**, three ways at once. The SIGWINCH handler ran `stty size` — a 4ms fork — on every signal, and a drag delivers one per pixel; it marks the size stale now and the next frame measures once. Every signal asked for a frame, and a signal that landed on the same cell size redrew the whole screen with the scrollback cleared for nothing; a resize-only request that changed neither dimension draws nothing. Frames are at most 16ms apart (upstream's `MIN_RENDER_INTERVAL_MS`), so a burst becomes one frame. And a SIGWINCH arriving while a frame was half written interrupted the `select()` in `ProcessTerminal::write()` with EINTR, which PHP reports as a **warning** — printed straight into the raw terminal mid-frame as `stream_select(): Unable to select [4]: Interrupted system call`. Measured on a 60Hz drag over 30 widths: 30 frames either way, but the first one 37ms after the first signal rather than 51, and no warnings in the output.
- `HOME` unset by a test's `tearDown` blinded every later test that looks under `~` (`fd is not installed`, fourteen skips); it is restored now.

## [0.2.16] - 2026-10-02

### New Features

- **codemode, in PHP** — upstream's `extensions/codemode` and `pi-codemode`, as `Pig\Codemode` (`packages/codemode/`) and `extensions/pig-codemode/`. The model writes a PHP script; the script calls nested tools as `$tools->name([...])`, fans independent calls out with `parallel([...])` / `parallel_settled([...])` (each arm a `Fiber`, every call on the pipe before any answer, so three slow tools cost one), chains dependent ones, filters what came back and returns only what it needs — the half of the MCP story `deferred` did not cover: the tool results go through the script, not the model. The sandbox is a child `php -n` with `disable_functions` naming every process, file, stream, network and host-reading function, `open_basedir` pointing at nothing, a 256MB `memory_limit`, no `php.ini` and an empty `PATH`, speaking one JSON line per message over the pipe; upstream's is QuickJS in wasm, and the difference is stated on `Sandbox`: a blacklist for a model's mistakes, not a whitelist for an adversary's. `text()`, `image()`, `exit_script()`, `store()`/`load()` (persisted as upstream's `codemode-store` entry in the session file), `ALL_TOOLS`, `search_tools()` (BM25), `describe_tool()`, `// @options: {"max_output_tokens", "timeout_ms"}`, the middle-cut output budget, the `Script completed / Wall time / Output:` header, and the renderer that shows the script, each nested call with ✓/✗/⊘ and its duration, and the output. Nested calls to the agent's own tools go through the hooked tool, so the permission gate reaches `bash` from a script exactly as from the model.
- **MCP's `codemode` exposure is codemode now.** Upstream's default exposure: a server with no `exposure` has its tools **not** declared to the model but put in `Pig\Codemode\Registry`, where the codemode tool lists them (grouped by server, within a 3000-token catalog budget, OpenCode's round-robin selection) and scripts call them; registering one is what activates `codemode`, as upstream's `ensureDiscoveryActive()` does. `codemode-deferred` leaves them out of the catalog for `search_tools()` to find. `/mcp` describes all five exposures in upstream's words. `codemode.enabled: true` in the settings turns the tool on with no server; `codemode.inlineBudget` sizes the catalog.
- **A tool can speak in the system prompt** — upstream's `promptSnippet` and `promptGuidelines`, on `CustomTool`, collected by `CustomToolSet::promptContributions()` and written into "Available tools" and "Guidelines" by `SystemPrompt::build()`, re-built when the tool set changes. codemode's line and its guideline ("Use codemode to batch or chain several tool calls…") are the first users; without the guideline a model knows the tool exists and does not reach for it.

### Added

- `AgentLoopConfig`-level: `AgentError` carries `$details`, so a tool that failed half way still shows the UI the half that ran (codemode's nested calls on a failed script).
- `ToolSearch` moved from `extensions/pig-mcp` to `Pig\Codemode`, since `tool_search` and `search_tools()` share it.

### Fixed

- `HookRunner::setSession()` existed and nothing called it, so `$ctx->session` was null in every handler — a documented `HookContext` field wired at one end only. `AgentSession` attaches itself now, in its constructor and in `setHooks()`.

## [0.2.15] - 2026-10-02

### Added

- **MCP resources** — upstream's `extensions/mcp/resources.js`: the three tools Codex and opencode use, `list_mcp_resources`, `list_mcp_resource_templates` and `read_mcp_resource`, registered once a connected server offers resources, with the widest exposure among those servers (declared when one is `direct`, behind `tool_search` otherwise, gone when none has any). Listings are Codex's JSON shape, one page with a `cursor` for one server or every page of every server; MCP App resources (`ui://`, `profile=mcp-app`) and icons are left out; a read resource reaches the model as text or an image, a binary one as a saved file, several contents labelled by URI. A resource link in a tool result names `read_mcp_resource` only while those tools are on the model. `pig mcp list` reports `resources: N, URI templates: M`. Verified live against `@modelcontextprotocol/server-everything`.
- **The MCP server log** — upstream's `log.js`: what a server says with `notifications/message` is appended to `~/.pig/agent/mcp.log` (`<time> [server] <level> <logger>: <text>`, continuation lines indented), rotated to `mcp.log.1` past 5MB. Best effort: a log that cannot be written never fails a tool.

## [0.2.14] - 2026-10-02

### Added

- **OAuth sign-in for remote MCP servers** — upstream's `pi-mcp/oauth` (as `Pig\Mcp\Oauth`) and `extensions/mcp/oauth.js` (as `McpOauth`): RFC 9728 / RFC 8414 discovery, dynamic client registration, the authorization code flow with PKCE against a loopback callback on a free port, token refresh before expiry and after a 401, `invalid_grant` → tokens dropped and the browser flow again, `insufficient_scope` → a new sign-in with the scope merged in. An HTTP server with no `Authorization` header of its own uses it: a 401 with nothing to refresh is the state `needs-auth` (not `failed`), the startup report says `run /mcp login <server>`, the manager offers **Sign in** (with a box for the pasted redirect URL when the browser runs on another machine) and **Sign out**, and `/mcp login [server]`, `/mcp logout [server]`, `pig mcp login <server> [--timeout s]`, `pig mcp logout <server>` do the same from the prompt and the shell. Credentials live in `~/.pig/agent/mcp-auth.json`, keyed by server URL, `0600`. `pig mcp add --url … [--oauth-client-id --oauth-client-secret --oauth-callback-port]` for a pre-registered client. Not ported: the cross-process refresh lock (`proper-lockfile`) — two pigs refreshing one rotating token in the same instant lose the grant, which is the cost a lost lock has upstream too.
- `HookUi::input()` takes an optional `AbortSignal`, upstream's `{ signal }`: a prompt raced against something that may answer first is closed from outside with null. `TerminalUi` closes the dialog, `RpcUi` tells the host the question is withdrawn.

### Fixed

- `FakeHttpMcpServer` (test helper) speaks OAuth, so the flow is tested end to end against the same server the transport is.

## [0.2.13] - 2026-10-02

### Added

- **`/mcp` is a manager now, where there is a terminal** — upstream's `McpManagerView`: a list of servers that redraws by itself as they connect (the ones needing attention first), and per server its transport, state and error, the tools it offers with their exposure, Reconnect, Exposure (saved to the `mcp.json` it came from; switching to `deferred` moves the declared tools behind `tool_search` at once) and Enable/Disable (saved too, and the connection closed or opened). Without a terminal it prints the status as before.
- **`pig mcp add | remove | list`** — upstream's `extensions/mcp/cli.js`: `add <server> [--env K=V] [--cwd dir] [--exposure mode] -- <command> [args]`, `add <server> --url <url> [--header K=V] [--bearer-token-env-var NAME]`, `-l` for the project file (which says so when the project is not yet trusted), `remove` naming the other scope when the server is defined there, and `list [--json]` that connects to every enabled server and exits 1 if one failed. Verified against GitHub's streamable-HTTP endpoint (46 tools) and `server-filesystem`.

### Fixed

- A server whose exposure changed from `direct` to `deferred` kept its already-declared tools declared: `registerTools()` compared the new list against the names it *owned* rather than the names it had *declared*, so nothing was taken away.

## [0.2.12] - 2026-10-02

### New Features

- **MCP servers** — upstream's built-in `mcp` extension, as `extensions/pig-mcp/`. Servers declared in `~/.pig/agent/mcp.json` or `<project>/.pig/mcp.json` (upstream's file and keys, so a `mcp.json` written for pi works unchanged; the project file is gated by `/trust` like every other `.pig/` resource) are connected when a session starts, in the background, and their tools reach the model as `mcp__<server>__<tool>` through the same pipeline as `bash` — so `tool_call` hooks and the permission gate apply to them too. Stdio and streamable HTTP, `${VAR}` and `!command` in `env`/`headers`, `toolExposure` patterns with `hidden`, lazy reconnect on a dropped connection, a 20KB middle truncation with the whole result saved to a file, `/mcp` for status and `/mcp reconnect <server>`. The first prompt waits up to ten seconds for the startup connections. Verified end to end against `@modelcontextprotocol/server-filesystem`. **`codemode` exposure is not ported** — it is a JavaScript sandbox — and a server asking for it is told so once and declared directly; A `deferred` tool is not declared to the model until **`tool_search`** finds it — upstream's BM25 ranker over tool names, descriptions, schemas and the server's instructions, ported arithmetic for arithmetic; the tool appears as soon as a connected server has a deferred tool, lists the servers it can search, and a loaded tool reaches the model on its **next call of the same run**. `/mcp` says how many are waiting. OAuth, resources and `pig mcp add/remove/list` are the steps after this one.

### Added

- `AgentLoopConfig::$getTools`: the loop asks for the tool list before every model call instead of keeping the snapshot it started with, so a tool registered from inside a tool call — `tool_search` loading one — is declared on the next request rather than the next prompt. Upstream refreshes `context.tools` from `agent.state.tools` in `prepareRequest` for the same reason.
- `ExtensionApi::registerTool()` works after load, and `removeTools()` beside it: the loader used to copy an extension's tools once at startup, which is right for a tool that exists at startup and wrong for one that arrives when a server connects. `CustomToolSet::adopt()` follows an extension's list, and the agent's tool set is rebuilt when it changes.
- `Pig\Mcp` (`packages/mcp/`), the first half of MCP support — upstream's `pi-mcp` package ported file for file: JSON-RPC 2.0 shapes and error codes, `McpClient` (handshake, paginated `tools/list` / resources, `tools/call`, progress notifications that reset the request timeout, cancellation sent to the server, server-initiated `ping` and `roots/list`), and three transports — in-memory (for tests), stdio (a child on the loop, the spec's three-step shutdown with `Process::killTree()` where upstream uses a process group) and streamable HTTP (JSON or SSE replies, `Mcp-Session-Id`, a standing GET stream reconnected with backoff, `Last-Event-ID` resumption of a cut reply stream, a 401 handed to an `AuthProvider` once). Nothing uses it yet: the extension that reads `mcp.json` is the next step.

### Fixed

- Gemini refused every request with `Unknown name "$schema"` the moment an MCP server was connected: its `parameters` is an OpenAPI 3.0 schema and the TypeScript MCP SDK puts `$schema` and `$defs` on every tool. Upstream's `sanitizeForOpenApi()` is ported into `GoogleShared` — no built-in tool carries a meta-declaration, which is how it went unported.
- `Loop::runQueue()`: a throw out of one deferred callback dropped the callbacks queued behind it in the same tick. Found through `McpClient` — the root fiber failing on a timeout and the in-memory transport's deferred `notifications/cancelled` shared a snapshot, and the server never heard the cancellation.
- `Process::killTree()` moved from `coding-agent`'s `Shell` to `pig/tui` beside the other subprocess primitives, taking the signal as an argument; `Shell::killTree()` delegates.

## [0.2.11] - 2026-10-01

### Fixed

- A `tool_call` hook can now **change** a call rather than only refuse it: `ToolCallEvent::$input` is writable and the tool runs with what the hooks left in it (upstream: "to modify arguments, mutate `event.input` in place"). The event was `readonly`, so the permission gate's `rm` → `trash` rewrite edited a copy and `rm` ran as typed.
- A dialog taller than the terminal no longer makes the screen flash: a change in the scrollback above the window (the working spinner, pushed up by the overlay, ticking twelve times a second) is left alone instead of forcing a full redraw with the scrollback cleared on every tick. A change the new frame brings back into the window is still redrawn, as upstream does.

## [0.2.10] - 2026-10-01

### Fixed

- `/reload` no longer warns `extension <cwd>/copy.php (load): not a readable file` for every extension: it was handing the loader the banner's display labels as if they were `--extension` paths. The reload also applies the project-trust gate the startup applies, so an untrusted project's `.pig/` cannot come in through `/reload`.
- Typing `/` and deleting it no longer pops the whole directory listing under an empty prompt: an empty line is not a path context (upstream's own rule — "Empty text should not trigger file suggestions"), while Tab on an empty line still lists files.

## [0.2.9] - 2026-10-01

### Added

- pi's session format v3: `context_edit` entries are read (the latest edit per target on the active branch omits or replaces what the model is shown, leaving the file, tree and totals untouched — pi's `buildSessionProjection()`) and written (a retry now records that it took the failed turn off the context, so `--resume` no longer puts it back). pig writes `version: 3`; a v2 file is brought to v3 the way pi's `migrateV2ToV3()` does, without re-id'ing anything.

## [0.2.8] - 2026-10-01

### Added

- `~/.pig/agent/keybindings.json` (upstream's `keybindings.ts`, the `app.*` half): the eleven keys the terminal takes can be moved under pi's action names and key spelling (`"app.model.select": "ctrl+m"`, lists allowed, `[]` unbinds). `Keys::matchesName()` is upstream's `matchesKey()`; `CustomEditor` claims whatever the bindings say and binds by action, so a moved key frees the old one for the text field or tmux; `/help` shows the keys in force; a wrong key or action is named on the shell at startup and a line that was only typos leaves the default standing.
- Hooks and custom tools are wired in `--mode web`: a hook's `confirm`/`select`/`input`/`editor` opens a dialog in the page over the RPC `hook_ui_request` line, `notify` and `setStatus` reach the banner and the telemetry bar, `hook_error`/`tool_error` are shown. Standalone web mode previously gave hooks `NoUi`, so a `tool_call` guard refused every tool call without a word.

### Fixed

- A hook's question id is unique per process (`ui-<epoch>-<n>`): a browser tab left over from an earlier pig that reconnected could hold a dialog with the same `ui-1` as the new server's first question and cancel it, refusing a tool call nobody was asked about (one run in two, measured). The page also ignores a repeat of the same question id and closes its SSE fallback once the WebSocket is up, so an event never arrives twice.
- The Web UI says when it cannot reach pig (`Cannot reach pig for the session state: … — retrying…`) instead of showing `Loading session...` for ever; every loader used to swallow its failure.
- The trust prompt now says *what* it found (`It has: extensions/pig-antigravity/index.php, …`) — a project with no visible `.pig/` but PHP under `extensions/` was being asked with no reason on screen — and its options are no longer cut at 30 columns, so "Trust parent folder (…)" shows the whole path.

## [0.2.7] - 2026-10-01

### Added

- Web UI session tabs: every conversation opened in the browser gets a tab above the chat (labelled by its `/name` or first line, live one marked, `×` to close, `+` for new), kept in `localStorage` per server. `/api/state` now carries `sessionPath`, `opening` and `cwdPath`; the server broadcasts `session_switch` and `session_info_changed` so the page follows a switch made in the terminal.

- Web UI accounts panel (`accounts` button, `/accounts`): the Antigravity sign-ins with token expiry, use / remove / rotate, and the live account's quota pools with reset times. `GET /api/accounts`, `GET /api/accounts/usage`, `POST /api/accounts/{activate,remove,rotate}`; `Auth::activateAntigravityAccount()` / `removeAntigravityAccount()` are the one implementation the panel and `/antigravity.accounts` share.

- `browse_web_page` in `pig-web-search`: renders a page in the locally installed Chrome (headless, `--remote-debugging-port=0`) through a pure PHP DevTools Protocol client over a masked-frame WebSocket on pig's own `Socket`, waits for the load event, strips nav/header/footer/scripts and returns the live DOM's text — for single-page apps that `fetch_web_page` returns as an empty shell. Chrome is optional; its absence is named. ~2.7s per page, profile cleaned up after.

### Fixed

- `/api/session/switch` and `/api/session/new` answered before the switch had happened (and `new` reported the *old* session's state); both now answer from inside the switch, a missing file is a 404, and a hook declining to leave is a 409.

## [0.2.6] - 2026-10-01

### Fixed

- Gemini 3.x on Google's public endpoint: every 3.x model is now asked for a thinking *level* (upstream's `3-pro`/`3-flash` check matched only `gemini-3-flash-preview`, so 3.1/3.5/3.8 silently ignored `--thinking`), and the levels a model refuses — measured per model — live in its registry row, so thinking *off* on `gemini-3.1-pro-preview` / `gemini-3.5-flash-lite` no longer fails every turn with `Budget 0 is invalid`, and MINIMAL is never sent to a model that answers 400 to it. Model catalogue regenerated from models.dev.

### Added

- Crash log, ported from upstream pi's `crash-log.ts`: an uncaught throw and a throw the event loop caught are both written to `~/.pig/agent/crashes.json` (last five), the next start says so once, and `/bug` attaches them — then clears the file once the report is written.
- Project trust (`/trust`), ported from upstream pi's `trust-manager.ts`/`project-trust.ts`: a project's own `.pig/` (settings, hooks, tools, extensions, skills, commands) and `extensions/` are not loaded until the person says so. The first time pig opens such a project it asks — Trust / Trust parent folder / this session only / Do not trust — and remembers the answer in `~/.pig/agent/trust.json`, nearest ancestor winning. An untrusted project says so on screen and in `-p`'s stderr; with no terminal to ask on, an undecided project is untrusted, so a script run in a stranger's repository cannot run that repository's hooks. Projects with nothing under `.pig/` are never asked.

## [0.2.5] - 2026-10-01

### Added

- Bug reporting (`/bug [what went wrong]`) — Ported from upstream pi's `/bug`, adapted for an open-source project with no upload server: writes a Markdown report (pig/PHP/OS versions, model and provider without keys, the last provider error, `/doctor` findings, and an opt-in transcript) to `~/.pig/agent/bug-reports/`, copies it to the clipboard, and opens a GitHub issue with it prefilled. Available in TUI and Web UI (`/api/bug`).
- After a non-retryable, non-aborted provider error the TUI now says once per session: `If this looks like a pig bug, /bug writes a report and opens a GitHub issue for it.` A 429 quota wall is retryable and deliberately does not trigger it.

## [0.2.4] - 2026-10-01

### New Features

- Pull Request description generator (`/export pr`) — Automatically extracts session context, file changes, and git diff statistics to generate structured, professional GitHub PR descriptions formatted in GitHub Markdown (Summary, Key Changes, Modified Files, Verification), with automatic clipboard copying and file saving.
- Pure Markdown conversation exporter (`/export md [file]`) — Cleanly exports the complete conversation with user turns, assistant responses, thinking blocks, and tool results formatted as GitHub Markdown.
- System health and environment diagnostics (`/doctor`) — Added built-in diagnostic inspector checking PHP runtime, required extensions, external tooling (`stty`, `git`, `fd`, `rg`, clipboard), provider credentials with OAuth expirations, proxy connectivity, and active session health. Accessible via `/doctor` in TUI and Web UI (`/api/doctor`), or CLI.
- Git development workflow commands (`/diff` and `/commit [message]`) — Added `/diff` to inspect working tree changes with colorized diffs in both TUI and Web UI, and `/commit [message]` with automatic Conventional Commits message generation from diffs and interactive confirmation.

### Added

- Added `+ New Session` button in Web UI sidebar and `/api/session/new` endpoint for instant standalone session creation.
- Added direct `/diff` inspection rendering in Web UI with unified diff cards.
- Code block copy buttons (`Copy` / `Copied! ✓`) with language tags across all Markdown code outputs in Web UI.
- Smart auto-scroll and reading-pinning in Web UI: preserves scroll position when user scrolls up to read history without jarring forced auto-scroll, with floating `↓ New messages` return button.
- Antigravity multi-account automatic 429 failover — Automatically rotates to the next available account in `antigravity-accounts.json` when encountering 429 Rate Limit / Quota Exceeded errors, seamlessly continuing active turns with zero manual intervention.
- Added `/antigravity.accounts rotate` slash command to manually cycle to the next linked Google account.

### Changed

- Enforced strict release tagging rule in `CLAUDE.md`: every release strictly increments SemVer tag without force-pushing over existing tags.

## [0.2.3] - 2026-10-01

### New Features

- Workerman-inspired protocol decoupling and RFC 6455 pure PHP WebSocket engine — Decoupled network I/O from wire protocols with `ProtocolInterface` (`input`, `decode`, `encode`). Implemented pure PHP `Websocket` protocol supporting handshake, masking/unmasking, ping/pong keepalive, and full-duplex RPC streaming for Web UI (`--mode web` & `/web`).
- Built-in Web UI mode (`--mode web` & `/web`) — 1:1 pixel-level TUI replica matching `pi-web` with two-level workspace directory and session drawer, telemetry status, thinking blocks, and tool execution views.
- Pure PHP web search extension (`extensions/pig-web-search/`) — Added standalone, zero-dependency web search extension providing `web_search` (DuckDuckGo HTML scraping), `fetch_web_page` (clean documentation text extraction), and `/search <query>` slash command. Keeps core tools 1:1 pure with upstream pi while enabling real-time web retrieval.

### Added

- 1:1 aligned 3-level cascading selectors in Web UI (`[provider ˅]` ➔ `[model ˅]` ➔ `[thinking ˅]`), dynamically fetching authorized models and constraining thinking levels per model.
- 1:1 aligned Web UI event dispatch with TUI: added streaming tool execution updates (`ToolExecutionUpdateEvent`), retry countdowns (`RetryStartEvent` & `RetryEndEvent`), and compaction alerts (`AutoCompactionStartEvent` & `AutoCompactionEndEvent`) with real-time console scrolling and top notification banners.
- Render standalone tool execution cards in Web UI matching `pi-web`: display `$ command` headers and terminal console boxes for bash commands, and git-style unified diffs (`+` green, `-` red) for edit operations.
- Added multimodal image support to Web UI: clipboard paste (`Ctrl+V` / `Cmd+V`), drag-and-drop, attachment button (`📎`), thumbnail preview with deletion, and rendering of sent images.
- Added two-level workspace browser and per-directory session navigation in Web UI matching `pi-web`, allowing users to explore all projects with session histories and switch sessions across workspaces.
- Added pure PHP RFC 6455 WebSocket protocol (`Pig\CodingAgent\Web\Protocols\Websocket`) and HTTP/1.1 framing protocol (`Pig\CodingAgent\Web\Protocols\Http`).
- Added adorable pig mascot icon (`Pig\CodingAgent\Interactive\PigLogo`) to startup banner, matching upstream pi's 4-cell half-block header icon with a custom pink piglet.

### Changed

- Switching models and thinking levels in Web UI now exclusively affects the active session without overwriting global `settings.json` defaults, matching upstream pi behavior.
- Decoupled thinking level from model switching in Web UI: user thinking preference (e.g. `medium` / `high`) is preserved across reasoning models and never reset to `off`.
- Web UI communication upgraded to full-duplex WebSocket RPC streaming with automatic SSE/HTTP fallback.
- Aligned mid-run prompt behavior with upstream pi: submitting via Enter while the agent is streaming now steers immediately (`$session->steer()`) instead of waiting in the follow-up queue until the entire turn finishes.

### Fixed

- Supported Anthropic Adaptive Thinking protocol for new Claude models (Fable 5, Fable 5.1, Opus 4.7+, Sonnet 5): sending `thinking.type=adaptive` and `output_config.effort` to prevent HTTP 400 rejection (`"thinking.type.enabled" is not supported for this model`).
- Fixed Web UI model switching failure caused by passing raw model ID string instead of `Pig\Ai\Model` instance, and added `userIsInteracting` lock to eliminate state revert race conditions.
- Fixed error hiding in Web UI: broadcast `agent_end` error state and serialize `errorMessage` in session history, rendering prominent red error blocks instead of silently failing.
- Fixed fatal `Allowed memory size of 134217728 bytes exhausted` in `SessionManager::describe()` when reading 10MB+ session files by replacing `file()` with O(1) memory streaming (`fopen`/`fgets`), reducing peak memory consumption by over 93%.
- Aligned escape abort message with upstream pi from `Aborted` to `Operation aborted`.

## [0.2.2] - 2026-09-30

### Added

- Added CJK break support in `TextWrap::tokenize()`, matching upstream pi's `cjkBreakRegex` to naturally wrap Chinese, Japanese, and Korean glyphs without forcibly severing subsequent English words mid-token.

### Changed

- Standardized extensions on pure PHP (`extensions/pig-antigravity`), eliminating all npm package, npm registry, and Node.js dependencies to preserve pig's zero-dependency core.
- Optimized `Width::visible()` LRU cache eviction using `unset(self::$cache[array_key_first(self::$cache)])` instead of O(N) `array_shift`, delivering a 5x speedup and expanding cache capacity to 2048.
- Optimized `Editor` layout by caching visual line calculations per frame, eliminating 2-3 duplicate `Graphemes::split` regular expression passes per keystroke.
- Added bypass in `Markdown.php` to skip wrapping and padding inline image protocol lines (Kitty / iTerm2), avoiding unnecessary parsing overhead on large base64 payloads.

### Fixed

- Fixed `ExtensionLoader` duplicate extension warning by deduplicating extensions by name, ensuring project-level extensions cleanly override global ones.
- Fixed `Chars::isPunctuation()` misclassifying multibyte CJK punctuation as word characters, resolving the bug where `Ctrl+W` / `Alt+Backspace` deleted entire sentences across Chinese commas and periods.

## [0.2.1] - 2026-09-30

### New Features

- One-command self-update (`pig update`) — Built-in CLI updater matching upstream pi's `pi update` to upgrade pig via Composer global update or git pull, plus `--models` to refresh model catalogs.
- Native `pig-antigravity` extension — Ported pure PHP implementation of the Antigravity extension (`extensions/pig-antigravity`), eliminating all Node.js and npm package dependencies.

### Added

- Added session display name to the footer top line (`pwd (branch) • <session-name>`), matching upstream pi's layout and accommodating telemetry extensions like `smart-session`.
- Added `SessionInfoEntry` (`session_info` entry type) for persisting custom and auto-summarized session titles in `.jsonl` files and displaying them in `/resume`.
- Added `/name` slash command to view or update the current session name on the fly.
- Added `message_start`, `message_update`, `message_end`, `session_info_changed`, and `agent_settled` hook events to `HookApi::EVENTS`.
- Added `$ctx->complete($prompt)` and `$ctx->apiKey($model)` to `HookContext` for background extensions to execute LLM completions using active session credentials.
- Added `AgentSession::onSessionNameChanged()` to immediately trigger TUI footer re-renders when a session name is updated.

### Changed

- Aligned update available notification in `InteractiveMode::sayNewVersion()` with upstream pi, recommending `pig update` and pointing to the changelog URL.
- Updated `FooterComponent::where()` to append ` • {$sessionName}` beside the git branch, with responsive column-aware truncation.

### Fixed

- Fixed Antigravity token refresh failure in long-running sessions by automatically falling back to official desktop client credentials.
- Fixed silent turn failures where unstreamed error responses (e.g. 400 Bad Request or token renewal errors) exited quietly without showing error text, ensuring `Error: <message>` is explicitly rendered in red.
- Fixed `copy` extension (`/cc`) missing code blocks indented inside Markdown lists by updating regex and adding automatic indentation stripping (dedent).
- Fixed `smart-session` real-time token speed meter and auto-naming failure caused by checking non-existent `$message->role` property instead of `instanceof AssistantMessage`.
- Fixed background title summarization token truncation on reasoning models (e.g. Gemini 3.8 Flash) by raising completion budget to 1000 tokens.
- Fixed background title summarization failures on OAuth-backed providers (e.g. Antigravity) by passing active session credentials through `HookContext`.

## [0.2.0] - 2026-09-30

### New Features

- Antigravity provider & dynamic model routing — Native support for the Antigravity subscription provider (`antigravity`), featuring multi-tier model routing (`gemini-3.8-flash` + thinking level to runtime model to endpoint enum), OAuth token support via `auth.json`, and dynamic thinking budgets.
- Extension system (`Pig\CodingAgent\Extensions`) — Unified extension mechanism porting upstream pi's extension architecture, enabling extensions to bundle slash commands, tools, event hooks, and message renderers in a single directory or file.
- Built-in `antigravity` extension — Ported pi-antigravity providing quota monitoring (`/antigravity.usage`), model catalogue management (`/antigravity.models`, `/antigravity.refresh`), multi-account management (`/antigravity.accounts`), environment diagnostics (`/antigravity.doctor`), and Imagen image generation (`/antigravity.image` & `generate_image` tool).

### Added

- Added `/reload` slash command to live-reload extensions, skills, custom commands, tools, settings, and context files without restarting the agent.
- Added `/thinking` slash command to view or switch reasoning levels on models supporting thinking budgets.
- Added `/hotkeys` slash command to display global and inline editing hotkeys aligned with upstream pi.
- Added `--session <path-or-name>` CLI option to resume or create a named session directly from the command line, and print resume instructions on interactive exit.
- Added `HookState` container to `HookContext` (`$ctx->get()`, `$ctx->set()`, `$ctx->has()`) for sharing middleware state across handlers.
- Added AST validation (`PhpToken::tokenize`) in `HookLoader` and `ExtensionLoader` to block top-level function and class declarations that would crash on reload.
- Added prompt cache hit rate indicator (`CH: <percent>%`) in the TUI footer to monitor prompt caching.
- Added loaded custom tools to the startup banner alongside `[Context]`, `[Skills]`, and `[Extensions]`.
- Added interactive fuzzy filtering to `SelectList` components, allowing keyboard typing to filter model and session selectors.

### Changed

- Changed TUI differential rendering in `Tui::changedLines()` to update only lines that actually changed, avoiding erasing down to the bottom of the frame on spinner ticks and reducing update bytes from ~1885 to ~95 bytes/tick.
- Optimized `BashOutputComponent` with line, text, and width caching to eliminate terminal input lag after long command executions.
- Optimized `AssistantMessageComponent` with slot-based component reuse to leverage markdown render caching during streaming.
- Consolidated duplicate `Fuzzy` implementations into `Pig\Tui\Fuzzy`, standardizing on character-based matching with astral and CJK full-width support.
- Retired legacy Gemini CLI provider and unified client OAuth handling under `Antigravity`.
- Changed default provider resolution in `CodingAgent` to respect the stored `defaultProvider`, preventing Antigravity models from falling back to public Google endpoints.
- Reorganized home directory structure from `~/.pig` to `~/.pig/agent` and separated downloaded binaries into `bin/` instead of `tools/`.

### Fixed

- Fixed `/quit` leaving in-flight LLM streams, background bash commands, retries, and compactions running by ensuring `AgentSession::dispose()` aborts all background work and clears queues.
- Fixed `FooterComponent` multi-byte truncation crashing macOS Terminal.app by replacing byte-based `substr()` with grapheme-aware `Width::truncate()`.
- Fixed Google Cloud Code RPC 400 errors by serializing empty quota request bodies as `{}` instead of `[]`.
- Fixed 403 errors on personal quota endpoints by omitting the `X-Goog-User-Project` header on non-project calls.
- Fixed startup thinking level defaulting to `off` on models with a `thinkingLevelMap` that disallow `off` (e.g. Antigravity models).
- Fixed image generator parameter ordering for `ImageContent` constructor to avoid fatal argument errors.

## 0.1.1

Nothing about how pig runs changed — no file under `packages/*/src` is touched. What changed is
getting it onto a machine, and what the test suite can see.

- **`install.sh`**, so `curl -fsSL https://pigagent.dev/install.sh | sh` is the one line. It runs
  `composer global require pigagent/pig` — the one way pig is distributed, so there is still a
  single answer to "which version am I running" — and adds the two things Composer will not do. It
  names **every** missing PHP extension at once *before* anything is installed, where a missing
  `ext-pcntl` otherwise surfaces half way through as a platform error that says nothing about what
  to install. And it offers to put Composer's global bin directory on your PATH, which is the step
  that turns a successful install into `command not found`. Every function is defined before the
  last line calls one, so a download cut off half way does nothing at all rather than half an
  install.
- **Both READMEs say how to install it**, with that PATH step as a prerequisite rather than a
  footnote: `composer global require` on its own really does end in `command not found`, so the
  instructions did not work as written.
- **`bin/pig` and `bin/pig-ai` are executable in git.** Composer sets the bit when it installs, so
  this one is for whoever clones the repository.
- **A mutation sweep over the eight files whose mistakes are not undoable** — the four tools that
  change your files or kill your processes, the arithmetic that decides what the model is shown of
  them, and the three classes that write the session file pi also reads. 831 mutations, and the
  tests it asked for are what is in this release. `Truncate` had no test of its own at all; Escape
  arriving between the model asking for a write and the write happening was pinned in none of the
  tools; two write-failure checks could never have fired; a compaction's kept range was not held to
  ending at the compaction; and every default the session decoders apply to a field pi leaves out
  was untested. **None of it was a behaviour change** — each guard was already doing the right
  thing, and nothing was holding it there.

## 0.1.0

First numbered version, and the first one that can tell you there is a newer one.

- **A file-by-file port of [pi](https://github.com/earendil-works/pi) against commit `d0a4c37`**,
  with zero runtime dependencies beyond PHP itself: the agent loop, a unified LLM API over five
  hand-written provider protocols, a terminal UI, and the coding agent CLI.
- **Five providers.** Anthropic, both OpenAI shapes, Gemini and Google Code Assist, plus everything
  that speaks an OpenAI-compatible endpoint — Groq, Cerebras, xAI, z.ai, Mistral, OpenRouter and
  GitHub Copilot. A model your build has never heard of is reachable by declaring it in
  `~/.pig/models.json`.
- **Four ways to sign in** rather than paste a key: Anthropic, GitHub Copilot, Gemini CLI and
  Antigravity. The credentials file is pi's own, so signing in once means signing in once.
- **pi's session file, field for field**, in pi's directory layout — a conversation started in
  either tool opens in the other, `--resume` lists both, and pi's v1 sessions are upgraded the way
  pi itself would.
- **Four modes**: a terminal UI, `-p` for one answer on stdout, `--mode json` for every event as a
  JSON line, and `--mode rpc` for a host driving it.
- **Hooks and custom tools**, loaded from PHP files under `~/.pig/hooks` and `~/.pig/tools`, with
  the whole of pi's hook API including the dialogs a hook can open mid-turn.
- **A proxy that is not optional where pig was written**: HTTP CONNECT and SOCKS5, with TLS
  starting only after the tunnel is open so the proxy carries pig's bytes and can read none of
  them.
- Reaching a provider through a gateway instead (`Agent\StreamProxy`), HTML and Markdown export,
  compaction, retries that read the provider's own stated wait, and `/tree` for going back to an
  earlier point in the conversation and taking it somewhere else.
