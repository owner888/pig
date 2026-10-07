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

## [0.4.7] - 2026-10-07

### Fixed

- **Fixed Vim and interactive full-screen TUI applications in Web PTY Terminals**:
  - Added `COLORFGBG=15;0` to the PTY environment so macOS and Linux Vim correctly recognize dark backgrounds, rendering clear white text and bright tildes instead of invisible black-on-black text.
  - Centralized `PtyManager` in `HttpServer` with dynamic terminal-to-connection routing, ensuring active PTY processes and Vim sessions survive WebSocket reconnects rather than being killed when a connection drops.
  - Added container click-to-focus listeners in `WebTerminal.js` and `NodeWorkbench.js` so clicking anywhere in the terminal drawer immediately focuses xterm.js input.
  - Initialized terminal instances with visible display before fitting so `FitAddon` measures real pixel dimensions (`cols` and `rows`) rather than defaulting to 80x24.

## [0.4.6] - 2026-10-07

### Changed

- Streamlined Web UI header action buttons for visual consistency:
  - Renamed "账号与配额" to concise "🔑 账号" (in Chinese) and "🔑 Accounts" (in English) with dedicated key icon.
  - Added clean 6px gap spacing to `.ctrl-btn` between `.nodes-btn-icon` SVG and "SSH 节点" label, matching all other header buttons.

## [0.4.5] - 2026-10-07

### New Features

- **Fullscreen Chat Viewport with Fixed Bottom Dock & Scroll-to-End Indicator (`ChatViewport`, `ScrollView`, `TuiAltScreen`)**:
  - Aligned with upstream pi 0.85+ ~ 1.0 architecture: splits the terminal into an upper scrollable transcript viewport and a fixed bottom dock containing `Pending Queue`, `Status`, `Overlay`, `Editor`, and `Footer`.
  - Bottom dock remains firmly pinned to the bottom of the terminal window, preventing the editor prompt and telemetry status lines from drifting off-screen during long conversations.
  - Smart follow-end behavior: automatically scrolls to latest output; when scrolled up via `PageUp`, `Shift+Up`, or mouse wheel, detaches from the end and displays a centered floating indicator pill: ` ↓ Jump to latest message · Ctrl+End `.
  - Seamless jump-to-bottom: supports clicking the floating indicator with mouse left-click, pressing `Ctrl+End` (or `End` / `Fn + →` on MacBook keyboard without Mission Control conflicts), or scrolling back to the bottom to instantly resume end-following and dismiss the pill.

### Added

- Added `tui.mode` configuration setting in `Settings.php` (default: `fullscreen`, with `regular` fallback support).
- Added `tui.altScreen.top` (`ctrl+home`), `tui.altScreen.bottom` (`ctrl+end`), `tui.altScreen.pageUp` (`pageup`), and `tui.altScreen.pageDown` (`pagedown`) keybindings in `Keybindings.php`.

### Changed

- **System Prompt File Operations Alignment**:
  - Corrected guideline in `SystemPrompt.php` from `Use bash for file operations like ls, grep, find` to `Use bash for file operations like ls, rg, find`, 100% matching upstream pi's guideline.
  - Guides LLMs like Gemini to prioritize fast parallel `rg` over slow unindexed POSIX `grep -rn` across large build caches and binary directories.
- **Tool Execution Component Execution Timer (`Elapsed` / `Took`)**:
  - Ported upstream pi's duration formatter and timer from `core/tools/renderers/bash.ts` into `ToolExecutionComponent`.
  - Renders real-time `Elapsed X.Xs` / `Xm Ys` during bash command execution, and clean muted `Took X.Xs` / `Xm Ys` upon completion.
- **Web UI Language Switcher Redesign**:
  - Replaced the static header button label `"中 / EN"` with an internationalized `"🌐 语言"` (in Chinese) / `"🌐 Language"` (in English) prefixed with the global icon.
  - Aligns with pig's full multi-language architecture, providing clear and elegant visual language indicators matching the theme and other action buttons.

### Fixed

- **59x Speedup for `test/lint.php` via Chunked Batch Execution**:
  - Refactored `php -l` execution from serial per-file `exec()` subprocess launches (637 individual process starts) to chunked batches (`array_chunk($files, 100)`).
  - Execution time dropped from **53.1s to 0.8s** while preserving granular syntax error reporting and failure line locations.
- **SGR Mouse Protocol Click Parsing**:
  - Implemented SGR mouse sequence decoding (`\x1b[<button;x;y[Mm]`) in `InteractiveMode`, enabling direct mouse left-click navigation on the `↓ Jump to latest message` indicator.
- **Dock Top Spacing in Chat Viewport**:
  - Restored `Spacer(1)` padding in `ChatViewport` above the editor dock, ensuring a clean vertical separation between the transcript tool output boxes and the `── ⠋ Working... ──` top border.

## [0.4.4] - 2026-10-07

### Fixed

- Fixed `PHP Warning: Undefined array key` in `PtyManager::input()` and `PtyManager::resize()`:
  - Accessing `$this->terminals[$id]?->...` triggered PHP undefined array key warnings when frontend xterm.js resize listeners or lingering keystrokes arrived after a terminal session had exited and been unset.
  - Replaced with null-coalescing `$this->terminals[$id] ?? null` access to safely ignore input and resize operations on terminated or unknown terminal sessions without leaking warnings.

## [0.4.3] - 2026-10-07

### Changed

- Streamlined TUI working status indicator by removing redundant `(esc to interrupt)` hint:
  - Aligned with upstream pi's minimal `defaultWorkingMessage = "Working"` and project UI minimalism principles.
  - Eliminates visual noise in the editor's top border: cleanly renders `⠋ Working...` initially, and `⠋ Working... · 16s` (or `· 1m15s`) as time elapses.

## [0.4.2] - 2026-10-07

### Added

- Added dynamic elapsed timer to TUI working, compaction, and branch summary status indicators:
  - `Loader::withTimer()` automatically calculates and formats elapsed duration in the top border: `⠋ Working... · 16s (esc to interrupt)` (or `· 1m15s` after 60s).
  - Provides clear real-time feedback that long-running operations (deep model reasoning, large codebase searches, slow network API calls, and context compactions) are actively executing rather than frozen.
  - Enabled during turns (`Working...`), manual `/compact`, tree branch summarization, and auto-compaction, while preserving retry countdowns untouched.

## [0.4.1] - 2026-10-07

### Added

- Added automatic browser launch on in-session `/web` command:
  - Running `/web` or `/web [port]` in the interactive TUI starts the web interface and automatically opens the user's default system browser at `http://127.0.0.1:<port>`.
  - Re-running `/web` while the server is already running opens the browser at the existing server URL.
  - Background daemon and status commands (`/web start`, `/web stop`, `/web status`, `/web restart`) operate strictly in the terminal without opening unwanted browser windows.

## [0.4.0] - 2026-10-07

### New Features

- **Interactive Local PTY Terminals & Web Terminal Drawer (`PtyProcess`, `PtyManager`, `WebTerminal.js`)**:
  - Native Unix pseudo-terminal execution using PHP's `proc_open` with `['pty']` descriptor on macOS/Linux with zero external C-extensions.
  - Non-blocking I/O integrated into `Pig\Async\Loop`, 16ms output micro-batching to prevent WebSocket frame storms, and slave PTY device detection (`lsof` on Darwin, `/proc/$pid/fd/0` on Linux) with window resizing via `stty` + `SIGWINCH`.
  - Frontend integration with `xterm.js` and `FitAddon` multi-tab terminal drawer (accessible via `Ctrl+\`` / `Cmd+\`` or the top navigation terminal icon), with 200KB scrollback buffering and full support for interactive TUI tools (`vim`, `nvim`, `nano`, `htop`, `tmux`, `less`).
- **SSH Node Workbench & SFTP Remote File Explorer/Editor (`NodeWorkbench.js`, `NodeManager`, `NodeProfile`)**:
  - Persistent node inventory in `~/.pig/agent/nodes.json` and credentials in `~/.pig/agent/nodes-secrets.json` (chmod 0600).
  - SHA-256 host key fingerprint detection (`ssh-keyscan` + `ssh-keygen -lf`) with verification and trust prompts.
  - Automatic OpenSSH `~/.ssh/config` host discovery and one-click import.
  - Remote interactive SSH terminal streaming via PTY (`ssh -tt`) and SFTP remote directory listing, navigation, and file reading/writing (capped at 512 KiB).
- **Multi-Session Tab Multiplexing Architecture & Web Daemon**:
  - Multiplexed multi-session tabs over a single physical WebSocket connection with per-tab chat scroll areas, background streaming indicators, and state recovery on reload.
  - Isolated `pig --mode rpc` child processes per `cwd::sessionFile` with 60s idle reaping and zero cross-session interference.
  - Native zero-dependency daemon management via `pig web start|stop|status|restart [-d]` and in-session `/web restart`, `/web status`, `/web stop` controls.
- **Telegram-style Language Packs & Client-Side i18n Engine**:
  - Full bilingual client-side `I18N` dictionary with 101 symmetric keys (defaulting to `zh-CN`), instant zero-refresh language modal (`#lang-btn`), Telegram-style JSON import/export, and `$pig->registerLocale()` on `ExtensionApi` with `/api/locales` aggregation.

### Added

- Added `externalEditor` setting and intelligent fallback for `Ctrl+G` external prompt editing (aligned with upstream pi `getExternalEditorCommand`): precedence follows `settings.externalEditor` > `$VISUAL` > `$EDITOR` > platform fallback (`notepad` on Windows; `nano` / `vim` / `vi` on POSIX).
- Added `ExtensionApi` Phase 2 parity: EventBus, lifecycle hooks, tool loadout, context/session control, UI capabilities, and cross-handler state sharing via `$ctx->set()`, `$ctx->get()`, and `$ctx->has()`.
- Added provider traffic hooks (`before_provider_request`, `after_provider_response`, `before_retry`) and dynamic provider registration (`registerProvider`), cleanly decoupling Google Antigravity into a standalone pure PHP extension.
- Added session title telemetry and speed meter integration in `FooterComponent` (`pwd (branch) • <session-name>`), `session_info` JSONL persistence, and `/name` slash command.
- Added model selection persistence control: session switches default to `persistAsDefault: false` (matching upstream pi), and `Ctrl+S` (`app.models.save`) explicitly saves the chosen model as global default.

### Changed

- Enhanced `SessionManager::open()` and `SessionManager::describe()` to use streaming line-by-line reading (`fopen` + `fgets`), reducing memory consumption on 70MB+ session files from >150MB to <10MB and preventing 128MB OOM crashes.
- Optimized session tree backtrace (`SessionManager::pathTo()`) by replacing O(N) `array_unshift` with O(1) append + single `array_reverse`, speeding up 28,000-node tree traversal from 718ms to 3ms (230x speedup).
- Optimized `SessionManager::latestPathFor()` with lightweight first-line sniffing, reducing `pig -c` session resume latency from 2275ms to 489ms.
- Optimized LRU cache eviction in `Width::visible()` from O(N) `array_shift` to O(1) hash eviction (`unset`), speeding up cache updates by 5.0x with capacity expanded to 2048.
- Aligned `TextWrap::tokenize()` with upstream pi's `cjkBreakRegex` for clean hyphenation and natural wrapping in mixed Chinese/English sentences.
- Updated `Chars::isPunctuation()` with Unicode punctuation matching (`^[\p{P}\p{S}]\z/u`) to recognize CJK punctuation as word boundaries for `Ctrl+W` / `Alt+Backspace`.

### Fixed

- Fixed background fibers and subprocesses continuing to run on `/quit` while working: `AgentSession::dispose()` now cleanly aborts in-flight LLM streams, retries, compactions, and bash commands.
- Fixed Broken pipe and SSL zero-write loops on non-blocking OpenSSL transport failures: expanded `Retry::WORDS` regex to catch native SSL, handshake, and connection errors, and encapsulated `fwrite`/`fread` in warning-capturing error handlers.
- Fixed background process (`&`) pipe inheritance deadlock: added process state polling in `Run::wait()` to exit gracefully once the parent shell process terminates.
- Fixed silent LLM turn failures in Web mode: serialized `lastError` in `AgentEndEvent` and enhanced client-side error rendering with automatic input restoration.
- Fixed long quota reset errors displaying raw provider text: normalized to pi-style action guidance messages.
- Fixed top-level symbol redeclaration crashes on `/reload` and guarded extensions with tokenized symbol checks.

## [0.3.65] - 2026-10-06

### Added

- Added `externalEditor` setting and intelligent fallback for `Ctrl+G` external prompt editing (aligned with upstream pi `getExternalEditorCommand`):
  - Added `Settings::externalEditor()` and `setExternalEditor(?string)` to support `"externalEditor": "vim"` (or `"nvim"`, `"code --wait"`) in `~/.pig/agent/settings.json`.
  - Resolution precedence: `settings.externalEditor` > `$VISUAL` > `$EDITOR` > platform fallback (`notepad` on Windows; `nano` / `vim` / `vi` on POSIX).
  - Eliminates the previous hard failure `Warning: No editor configured. Set $VISUAL or $EDITOR.` when environment variables were not exported.

## [0.3.64] - 2026-10-06

### New Features

- Ported Interactive Local PTY Terminals, SSH Node Workbench, and SFTP File Explorer (aligned with `youweichen/pi-web-ui` / `omp-web-ui`):
  - **Local PTY Terminal Engine (`PtyProcess` & `PtyManager`)**: Native pseudo-terminal execution using PHP's `proc_open` with `['pty']` descriptor on macOS/Linux with zero external C-extensions. Non-blocking I/O integrated into `Pig\Async\Loop`, 16ms output micro-batching to prevent WebSocket frame storms, slave PTY device detection with window resizing via `stty` + `SIGWINCH`, and 200KB scrollback buffering.
  - **SSH Node Workbench (`NodeProfile` & `NodeManager`)**: Persistent inventory in `~/.pig/agent/nodes.json` and credentials in `~/.pig/agent/nodes-secrets.json` (chmod 0600), SHA-256 host key fingerprint detection (`ssh-keyscan` + `ssh-keygen -lf`) with verification and trust prompts, OpenSSH `~/.ssh/config` discovery, remote PTY terminal streaming (`ssh -tt`), and SFTP remote directory listing/reading/writing (capped at 512 KiB).
  - **Web UI Integration**: Embedded `xterm.js` and `FitAddon` multi-tab terminal drawer (`WebTerminal.js`), `#nodes-btn` in header and mobile drawer, full-featured `NodeWorkbench.js` modal with remote terminal tabs and SFTP file explorer/editor, and bilingual i18n support.

## [0.3.63] - 2026-10-06

### Fixed

- Fixed startup model resolution ignoring extension-provided default providers: `CodingAgent::session()` resolved the startup model before loading extensions, so when `settings.json` configured an extension provider as `defaultProvider` (e.g. `antigravity`), resolution failed to find the unregistered provider and fell back to direct built-ins (e.g. `google/gemini-3.8-flash`). Extensions and custom providers are now loaded before model resolution runs.

## [0.3.62] - 2026-10-06

### Fixed

- Fixed model and thinking level switches in a session clobbering the user's persistent default model: `setModel()` and `setThinkingLevel()` previously defaulted `persistAsDefault` to `true`, so typing `/model <name>`, selecting a model with Enter, or cycling with `Ctrl+P`/`Shift+Tab` silently overwrote `defaultModel` and `defaultProvider` in `~/.pig/agent/settings.json`, causing the next new session to open on the switched provider instead of restoring the configured default. Session switches now default to `persistAsDefault: false` (matching upstream pi). The model picker supports `Ctrl+S` (`app.models.save`) to explicitly set the selected model as the persistent default, and `/settings` continues to persist changes.

## [0.3.61] - 2026-10-06

### Fixed

- Fixed working status border lines and editor borders to match upstream pi's thinking level colors: `paintBorder()` was not called on startup, leaving the editor's top and bottom border rules and the dashes surrounding the working indicator (`── ⠋ Working... ───`) stuck on `borderMuted` (gray `#768186`) instead of matching the active thinking level (e.g. `thinkingMedium` `#6185cc`). Startup, `showLoader()`, and `hideLoader()` now consistently synchronize the editor border to `borderColour()`.

## [0.3.60] - 2026-10-06

### Added

- **Inter-extension event bus** — `$pi->events()`, upstream's `pi.events`: pub/sub channels (`on()` returning an unsubscribe closure, `emit()`, `clear()`) shared across all extensions of a load, with error isolation so one handler throwing does not break others.
- **Full lifecycle hook events** — `tool_execution_start`, `tool_execution_update` (streaming tool deltas), `tool_execution_end`, `session_compact_failed` (with reason, abort flag, and error message), `user_bash` (intercepting typed `!command`), and `input` (intercepting user input before skill/template expansion).
- **Input transform & interception** — `InputEvent` & `InputEventResult` (`continue`, `transform`, `handled`), fired before prompt expansion and during queue steering/follow-up, chaining text/image modifications across extensions.
- **User bash command interception** — `UserBashEvent` & `UserBashEventResult`: extensions can execute commands on remote hosts/containers and return `BashExecution` without falling back to local execution.
- **Active tool loadout management** — `ToolLoadout`, unifying tool filtering across session creation, `/reload`, and runtime; `$pi->setActiveTools()`, `$pi->getActiveTools()`, and `$pi->getAllTools()`.
- **Extension tool execution** — `$ctx->executeTool($name, $args)`: runs tools on an extension's behalf through schema validation and `tool_call` permission guards.
- **Context usage and session control on `$ctx`** — `$ctx->getContextUsage()` (`ContextUsage` with tokens, contextWindow, and percent), `$ctx->getSystemPrompt()`, `$ctx->isProjectTrusted()`, `$ctx->mode()`, `$ctx->thinkingLevel()`, `$ctx->compact()`, and `$ctx->shutdown()`.
- **Rich extension UI capabilities on `$ctx->ui`** — `pasteToEditor()`, `setTitle()`, `setWorkingMessage()`, `setWorkingVisible()`, `setHiddenThinkingLabel()`, `getToolsExpanded()`, `setToolsExpanded()`, `setWidget()`, `setHeader()`, `setFooter()`, `getAllThemes()`, `getTheme()`, `setTheme()`, and `onTerminalInput()`.
- **Extension keyboard shortcuts** — `$pi->registerShortcut($key, $handler, $desc)`: custom shortcuts bound on the editor, executed in a fiber with context, and displayed in `/help`.
- **Markdown transformers** — `$pi->registerMarkdownTransformer($transformer)`: transforms user and assistant Markdown before rendering in transcript.
- **Tool renderers** — `$pi->registerToolRenderer($resolver)`: onion-middleware chain allowing extensions to customize call and result rendering for both built-in and custom tools.
- **Custom entry renderers** — `$pi->registerEntryRenderer($customType, $renderer)`: draws custom session tree entries in the transcript.

### Fixed

- Fixed `/reload` silently dropping the `--tools` / `--exclude-tools` filter: reload now preserves active tool loadout states through `ToolLoadout`.
- Fixed permission gate false positives: anchored dangerous system commands to command position and blanked non-shell quoted strings.

## [0.3.59] - 2026-10-06

### Fixed

- Fixed socket writes to a peer that accepts a connection but stops reading: the internal one-second writable wait slice was treated as the whole write timeout, so a stalled write failed after about one second even when the caller allowed longer. The write loop now waits only for the remaining overall timeout and has a regression test for the non-reading peer case.
- Fixed TUI compaction, branch-summary and hook-message blocks to use the same padded transcript shape, theme tokens and folding behaviour: compactions show `[compaction]` plus `Compacted from <tokens> tokens`, branch summaries and hook messages share the same custom-message background, and expanded text stays inside the block.
- Fixed Web transcript replay for app messages and tool details: hook messages now cross `SessionCodec` as `role: "custom"`, automatic compaction summaries render in the chat and de-duplicate against the following `message_end`, historical `!command` executions draw as bash cards, and tool results keep `details.diff`, truncation notices and full-output paths.
- Fixed long quota resets to match pi's actionable error: a model quota that resets hours later now says `Quota reached. Please wait … Next: switch models or try again after reset.` instead of leaving the provider's raw 429/JSON in the transcript. Antigravity's `Resets in …` wording is recognised too, and the TUI no longer prints the same failed assistant turn twice.

## [0.3.58] - 2026-10-06

### New Features

- **Extensions can bring a provider** — `ExtensionApi::registerProvider(new Provider(...))`, pi's `registerProvider()`: models, a wire protocol (`Pig\Ai\Extension\StreamApi`), a sign-in (`OauthFlow`), the environment variables that carry its key, and whether it resells other providers' ids. The models land in the registry, the protocol behind `Api::Extension`, the sign-in in `/login`, `pig-ai list` and `/doctor`. `unregisterProvider()` and `/reload` take it back.
- **Antigravity is an extension, not a core provider.** Everything about it — `AntigravityApi`, `AntigravityOauth`, `Routing`, the model table, `Accounts`, `Catalog`, `QuotaClient`, `ImageGenerator` — lives under `extensions/pig-antigravity/src/` and registers itself; nothing Antigravity-shaped is in `packages/` any more, which is the move pi made in 0.71 and the community's `pi-antigravity` extension answered. The row in `/login` is there only while the extension is loaded.
- **Provider-traffic hooks**: `before_provider_request` (rewrite headers or body; chained), `after_provider_response` (status and headers before the body is read), and `before_retry` — pig's own — which lets an extension change the wait, reset the attempt count or cancel. The Antigravity 429 account failover is a `before_retry` handler now rather than a special case inside `AgentSession`.
- **More of pi's `ExtensionAPI`**: `registerFlag()`/`getFlag()` (`--my-flag` declared by an extension), `getSettings()`, `getModel()`/`setModel()`, `getThinkingLevel()`/`setThinkingLevel()`, `sendUserMessage(text, 'steer'|'followUp')`, `setLabel()`, `getCommands()`, and the `model_select` / `thinking_level_select` events.
- **`registerHttpRoute()`** — an extension answers `/api/<prefix>/...` in `pig web` from a fiber; the Antigravity accounts panel is served this way instead of from `HttpServer`.
- **`Auth::useSecondStore()`** — a provider that keeps several accounts beside `auth.json` tells `Auth` where to read when the file has nothing and where to write a renewal.

### Changed

- The default model is `claude-sonnet-4-5` (`CodingAgent::DEFAULT_MODEL`), as both READMEs have said; it had been `antigravity/gemini-3.8-flash`, a provider that only exists once an extension has loaded.
- `bin/pig` loads the extensions before `--list-models`, so a provider an extension brings is listed, and hands the one load to the session rather than loading twice.
- `Auth::credentials()`, `setCredentials()` and `freshCredentials()` take a provider name as well as the built-in enum.
- The two network-reaching tests no longer touch a provider: `SocketTest`'s TLS check goes to `example.com`, and `AnthropicTest::testAgainstTheRealApi` wants `PIG_LIVE_ANTHROPIC=1` on top of `PIG_NETWORK_TESTS=1`, so turning network tests on for the proxy does not spend a request on somebody's Anthropic account.

### Removed

- `Api::Antigravity`, `Models::ANTIGRAVITY_MODELS`, `Oauth\Provider::Antigravity`, `Providers\Antigravity`, `Oauth\Antigravity`, `Ai\Antigravity\Routing`, `CodingAgent\Antigravity\*`, `Auth::accounts()/rotateAntigravityAccount()/activateAntigravityAccount()/removeAntigravityAccount()/antigravityClient()`, and `HttpServer`'s `/api/accounts` handlers — all moved into the extension.

## [0.3.57] - 2026-10-06

### Fixed

- Fixed every transcript entry carrying an extra blank row under its last line. `Markdown::block()` added a block's trailing blank line after the **last** block too, where upstream guards each one with `nextTokenType && …` — so a one-line user message sat on two empty rows of background where pi draws one, and every assistant message and tool result was one row taller than its content. The last block leaves no blank now; the gap after a message belongs to whoever placed it (`Spacer`, or the component's own `paddingY`).

## [0.3.56] - 2026-10-06

### New Features

- **Anthropic sign-in brought up to pi 1.0.3** — `/login` with Claude Pro/Max now asks which way in: a browser that comes back to a loopback on `localhost:53692/callback` (the default), with the paste box open beside it for a browser on another machine; or copying the code off Anthropic's page for a headless setup. The endpoints are `platform.claude.com` (the anchor's `console.anthropic.com` callback page is a 301 to it), the scopes are pi's six, and a token goes out as Claude Code's: both betas (`claude-code-20250219,oauth-2025-04-20`), `user-agent: claude-cli/2.1.280`, `x-app: cli`, and tool names spelled Claude Code's way (`read` → `Read`) on the way out and mapped back on the way in (`Pig\Ai\Providers\ClaudeCode`).
- **`--tools` takes `*` patterns and `--exclude-tools` takes them away** — `--tools read,codemode,'mcp__gh__*'`, as pi does. An MCP tool is kept unless an entry starts with `mcp__`, so `--tools read` does not silently disconnect every server; `--exclude-tools` has no such exception. A typo (`--tools raed`) is refused by name with the whole list, custom tools included, which is why the check moved from `bin/pig` into `CodingAgent::session()`. The filter is kept on `CustomToolSet` so a server connecting after startup is filtered too (`CustomToolSet::keep()`, `Tools\ToolSelection`).
- **`--no-mcp`** — start without loading the MCP extension at all (`ExtensionLoader::load(disabled:)`, by directory name).

### Changed

- The token-speed meter (`⚡ 90.8 tok/s · TTFT 8ms`) is drawn on the footer's top line after the session name — `~/pig (main) • <name> • ⚡ …` — rather than as a third footer line that appeared and vanished with every turn. `FooterComponent` treats the `token-speed` status key as inline; every other key still gets the hooks' own line.
- `/login` and `/logout` label a provider as `not configured` or `subscription configured`, pi 1.0's wording (`Oauth\Provider::isSubscription()`).
- A pasted authorization code is accepted in every shape pi accepts: the whole redirect URL, a `code=…&state=…` query, `code#state`, or a bare code; and a pasted state that is not the verifier is refused (`Anthropic::parseAuthorizationInput()`).
- `Oauth\CallbackServer` names the provider it listens for in its two messages, since Anthropic's flow uses it as well as Google's.

### Fixed

- The Claude browser sign-in no longer crashes pig once the tokens have arrived: a browser's speculative second connection, never written to, was closed under an armed reader (`Reader r505 watches a closed stream`). `CallbackServer::close()` cancels every reader before closing, and a reader at EOF lets its connection go.
- `/antigravity.usage`, `/antigravity.models`, `/antigravity.image` and the rest of the Antigravity extension's commands answered `401 Request had invalid authentication credentials` from the second hour after signing in: they read the stored access token raw, and only `Auth::apiKey()` knew to renew an expired one. `Auth::freshCredentials()` is that renewal for a caller that needs the token and the project apart, and the extension goes through it.
- `Selected model is at capacity` is retried rather than ending the turn (pi 1.0.1, #10278).
- z.ai's CN endpoint's `Prompt exceeds max length` is recognised as a context overflow and compacted rather than retried (pi 0.99.2, #10208).
- The file a truncated command's output is spilled to is readable by the user alone (`0600`), as pi 1.0.3 makes its output files.
- Shift+Enter in Apple Terminal sent the prompt instead of breaking the line. That terminal sends `\e\r` for Shift+Enter (measured), which `Keys::isAltEnter()` also accepted as Alt+Enter's legacy form — so `app.message.followUp` claimed the key before the editor saw it. `\e\r` is a new line now; Alt+Enter is its kitty form `\e[13;3u` only, and `command+enter` is bound to the same follow-up action.
- The screen no longer scrolls up by the number of rows the frame shrank by at the end of every turn, which left blank rows under the footer. The erase of vanished rows moves the cursor down with `\e[B` rather than `\r\n` — a newline on the terminal's last row scrolls. Measured on a real pty through a VT emulator (`PIG_TUI_TRACE=<file>` records every byte written to the terminal, for exactly this).

## [0.3.55] - 2026-10-05

### Added

- **`test/lint.php` sweeps closures for uncaptured variables** (`uncapturedClosureReads()`): a variable read inside a closure body that is not a parameter, not in its `use` list, not `$this` (for a non-static closure), not a superglobal and not assigned in the body is reported with its line. This is the class of bug behind `Undefined variable $pi` in an extension handler — `php -l` cannot see it; PHP's own tokens can. Zero hits across 618 files; putting the bug back turns it red.
- **`lint.php` takes extra directories as arguments** (`php test/lint.php ~/.pig/agent/extensions`), and now sweeps the repository's `extensions/` by default.

### Fixed

- Three `@` error suppressions that had survived in `extensions/` because that directory was never linted (`pig-computer` health probe, `pig-web-search` profile cleanup) and one new in this session (`HttpServer` tab completion) — each replaced with a scoped error handler or a precondition check.
- `HeadlessBrowser::remove()` now covers `scandir()` as well as `rmdir()` with its handler: a browser still writing into its profile warned from the first and only the second was guarded.

## [0.3.54] - 2026-10-05

### Fixed

- **"Cannot reach pig: the conversation: limit is not defined"** when opening any session containing a ranged `read` (`offset`/`limit`). `ToolCard` built the `read a.php:10-39` title with `Number(limit)` instead of `Number(args.limit)` — a free variable that parsed fine and threw on first use, taking the whole conversation replay down with it.
- **Regression guard**: `WebModeTest::testToolCardRendersEveryToolItKnowsWithoutThrowing` runs `ToolCard` and `ThinkingBlock` under Node against a minimal DOM over 17 argument shapes a real session replays. `node --check` cannot see an undefined name; executing the component can — putting the typo back fails the test with the exact message the user saw.

## [0.3.53] - 2026-10-05

### Added

- **Web UI `!command` / `!!command` now run exactly as in the TUI** — pig executes them directly, deterministically, with zero model tokens, and `!` output joins the conversation as a `BashExecution` for the model to read next turn (`!!` runs and remembers nothing). Before this the Web prompt sent `!cmd` to the model as plain text and left it to decide whether to call the `bash` tool.
- **`bash_output` RPC event**: `RpcMode::bash()` now streams a command's output as it arrives, each event carrying the command's `id` so a host can find the card. A `flutter run` or `npm run dev` shows its log live instead of nothing until it is stopped.
- **Stop works for `!command` in the Web UI**: the Stop button sends `abort_bash` while a bash card is in flight (`abort` otherwise), so a resident process can be ended from the browser — verified to leave no stray process behind.

## [0.3.52] - 2026-10-05

### Fixed

- **Web UI stuck on "Loading session…" after v0.3.50**: a stray `});` left behind by the Command HUD edit made `app.js` fail to parse, so the module never ran and the page never bound to a session. Removed.
- **Regression guard**: `WebModeTest::testEveryServedJsAssetParses` now runs `node --check` over every JS module under `Web/assets/js/`, so a syntax error in any served script fails the suite instead of shipping.

## [0.3.51] - 2026-10-05

### Fixed

- **TUI Command HUD Solo-Modifier Validation (Resolving `Shift + Command` False Triggers)**:
  - Fixed an issue in the terminal (TUI) where holding `Shift + Command` (e.g. macOS `Shift + Command + 4` screenshot shortcut) still triggered the Command Shortcuts HUD overlay.
  - **Root Cause**: The native macOS CoreGraphics event monitor (`CGEventSourceFlagsState`) previously only checked for the `kCGEventFlagMaskCommand` (0x00100000) bit, ignoring co-pressed modifiers.
  - **Strict Solo Command Detection**: Introduced `InteractiveMode::isSoloCommand()`, which validates the Command bit is set while ensuring the `Shift` (0x00020000), `Control` (0x00040000), and `Option/Alt` (0x00080000) bits are all unset.
  - **Threshold Alignment**: Increased the TUI long-press hold threshold from 480ms to 850ms, matching the Web UI and iPadOS/Blink Shell standards.

## [0.3.50] - 2026-10-05

### Fixed

- **Command Key Long-Press HUD Conflict with macOS Screenshots (Blink Shell / iPadOS Solo-Modifier Standard)**:
  - Fixed an issue where taking a screenshot on macOS (`Shift + Command + 4`) prematurely triggered the Shortcuts HUD, blocking the screen capture area.
  - **Strict Solo-Modifier Validation**:
    - Enforced `!e.shiftKey && !e.altKey && !e.ctrlKey` on `Meta` keydown; if Shift is already pressed (typical screenshot posture), the HUD timer is never started.
  - **Threshold Adjustment**: Increased the hold duration threshold from an aggressive 450ms to the industry-standard 850ms, safely bypassing the human keystroke hesitation window.
  - **Combination Abort & System Blur Guard**: Any subsequent keypress immediately marks the sequence as a combo and cancels the HUD timer, and window blur (when `screencapture` takes system focus) unconditionally closes the modal.

## [0.3.49] - 2026-10-05

### Added

- **Web Terminal Tab Intelligent Auto-Completion (Bash/Zsh Parity for Commands & Paths)**:
  - **Full Tab Completion Engine**: Pressing `Tab` inside the Web Terminal provides an authentic shell experience for completing both command names and filesystem paths.
  - **Command Name Completion**: When completing the first token, matches common CLI utilities (`git`, `composer`, `php`, `docker`, `npm`, `grep`, `find`, `pig`, etc.) and executables.
  - **Filesystem Path & Directory Traversal**:
    - Automatically autocompletes relative paths, absolute paths, and subdirectories (e.g. `cd pac` + Tab autocompletes to `cd packages/` with trailing slash);
    - Supports deep multi-level path completion and hidden dotfiles when prefixed with `.`;
  - **Common Prefix Expansion & Candidate Grid**:
    - Expands input directly when a single match is found (adding space for commands/files or slash for directories);
    - Expands up to the longest common prefix when multiple matches exist, while echoing the candidate list into the terminal output area with directory color differentiation matching real shell behavior.
  - **High-Performance Non-Blocking Endpoint (`/api/terminal/complete`)**: Direct filesystem resolution in `HttpServer.php` with sub-millisecond response times.

## [0.3.48] - 2026-10-05

### Fixed

- **Chinese IME Composition Enter Protection (No More Accidental Message/Command Submissions)**:
  - Fixed an issue where pressing `Enter` to confirm Chinese Pinyin composition or select a candidate word prematurely triggered prompt submission or terminal command execution.
  - Implemented an industrial-standard 60ms cooldown window on `compositionend` alongside `isComposing` and `keyCode === 229` guards across the main chat prompt input, the Web Terminal interactive input, and the Slash Autocomplete popup.
- **Mobile Web Terminal Prompt Auto-Compactness & Pixel-Perfect Cursor Alignment**:
  - Fixed an issue where long hostnames and deep directory paths caused the terminal prompt prefix to break across multiple lines on mobile screens, resulting in detached, vertically misaligned floating cursors.
  - **Responsive Prompt Compactness**: On narrow mobile screens (<= 640px), the lengthy hostname (e.g. `@kakadeMacBook-Air.local`) is automatically hidden and long paths are abbreviated to `~/.../folder` without breaking.
  - **Baseline Lock & Wrap Prevention**: Enforced `white-space: nowrap; flex-shrink: 0;` and locked `align-items: baseline` with matched 20px line heights, ensuring the input caret is permanently glued directly after the `$` prompt symbol across all mobile screen sizes.

## [0.3.47] - 2026-10-05

### Added

- **Web UI Embedded Web Terminal Component (`components/WebTerminal.js` & `/api/terminal/exec`)**:
  - **Top-Right Terminal Entry**:
    - Added a modern `>_ Terminal` icon button in the top navigation bar and mobile dropdown;
    - Supports click or global shortcut `Ctrl + \`` to toggle terminal drawer instantly.
  - **Active CWD & Directory Tracking**:
    - Automatically initializes in the active conversation's working directory (`active.cwd`);
    - Displays interactive path capsule (`~/path`), with click-to-copy;
    - Built-in `cd` tracking that dynamically updates the terminal's working directory and prompts across sessions.
  - **Rich Interactive Console**:
    - Authentic command line prompt (`user@hostname:path $ `) with monospace JetBrains typography;
    - Command history navigation with `ArrowUp` / `ArrowDown`;
    - Fast ANSI color code parser rendering `git status`, `diff`, `ls`, and test suites in full terminal fidelity;
    - Quick-action chips (`git status`, `ls -la`), clear screen (`🧹` / `Ctrl+L`), and full-screen drawer toggling (`⛶`).
  - **Zero-Dependency Non-Blocking Execution Engine**:
    - Powered by `Process::runAsync` in `HttpServer.php`, executing bash commands with sub-millisecond dispatch and 120s timeout without blocking the event loop.

## [0.3.46] - 2026-10-05

### Added

- **Web UI Slash Command Autocomplete Component (`components/SlashAutocomplete.js`)**:
  - Implemented an ambient floating popup that automatically triggers when typing `/` into the prompt input;
  - **Smart Command Filtering & Curation**:
    - Safely excluded pure TUI-specific or destructive commands (`web`, `hotkeys`, `exit`, `quit`, `tree`, `label`, `resume`, `copy`, `login`, `logout`) that are either redundant in a browser or risk killing backend child processes;
    - Retained and curated all 21 high-value commands with bilingual descriptions (`/compact`, `/export`, `/diff`, `/commit`, `/model`, `/thinking`, `/name`, `/session`, `/doctor`, `/accounts`, `/antigravity.*`, etc.);
  - **Fluid Keyboard & Mouse Navigation**:
    - Real-time fuzzy filtering as the user types (e.g. `/co` filters down to `/compact`, `/commit`, `/accounts`);
    - Full keyboard control with `ArrowUp` / `ArrowDown` navigation, `Enter` / `Tab` completion, and `Escape` dismissal;
    - Complete theme styling across Dark, Light, and Labra themes.

## [0.3.45] - 2026-10-05

### Added

- **Web UI Native ES Components Suite (TUI Parity for Thinking, Tools & Empty States)**:
  - **`ThinkingBlock` Component (`components/ThinkingBlock.js`)**:
    - Live pulsing purple indicator during thought generation;
    - Real-time elapsed duration and character count telemetry (`1.8s · 420 words`);
    - Smart auto-collapse on thought completion to keep final assistant response immediately visible, with seamless click-to-expand.
  - **`ToolCard` Component (`components/ToolCard.js`)**:
    - Dedicated visual identities, icons, and accent border colors for `bash` (⚡ sky blue), `read` (📄 emerald), `edit` (✏️ purple with unified diffs), `write` (💾 orange), `computer` (🖱️ rose), and `web_search` (🌐 blue);
    - Built-in one-click output copy button (`📋`);
    - Enhanced status pills showing execution time, exit codes, and truncation notes;
    - Smooth collapsible body toggling.
  - **`EmptyState` Component (`components/EmptyState.js`)**:
    - Centered welcome card featuring the official pink Piglet mascot vector logo;
    - 4 quick-action starter chips (code review, test suite, git status, doctor check) that populate the prompt on click;
    - Automatically cleans up as soon as a turn begins.
  - **`ImageLightbox` Component (`components/ImageLightbox.js`)**:
    - Ambient fullscreen modal with backdrop blur for tool screenshots and attachments, dismissing via background click or `Escape` key.

## [0.3.44] - 2026-10-05

### Added

- **Web UI Markdown Tables & Typography Parity with TUI (GFM Table & Rich Formatting Support)**:
  - Fixed an issue where the Web UI failed to parse Markdown tables, causing tabular data to collapse into a single chaotic line of text.
  - **GFM Table Parsing & Alignment**:
    - Full support for GFM table syntax including header rows, separator rows with column alignment (`:---`, `:---:`, `---:`), and multi-row datasets.
    - Wrapped tables in responsive `.table-wrapper` containers with smooth horizontal touch-scrolling on mobile devices to prevent layout breakage.
  - **3-Theme Visual Design**:
    - **Dark Theme**: Subtle translucent border, elevated table header, and row hover transitions.
    - **Light Theme**: Pure white card background (`#ffffff`), fine `#e2e8f0` grid lines, and high-contrast typography.
    - **Labra Theme**: Cyberpunk deep dark-green backdrop with signature pink/accent highlights.
  - **Complete Typography System**:
    - Blockquotes (`<blockquote>`) with left accent border and dim text;
    - Unordered (`<ul>`) and ordered (`<ol>`) lists with tidy indentations;
    - Clean horizontal rules (`<hr>`);
    - Secure external links (filtering `javascript:`, `data:`, and `vbscript:` protocols);
    - Full heading hierarchy (`<h1>` through `<h6>`).

## [0.3.43] - 2026-10-05

### Changed

- **Web UI Native ES Modules Architecture Refactoring (Zero-Build Frontend Modularization)**:
  - Eliminated the monolithic ~5,000-line `index.html` in favor of a modern, clean, browser-native ES6 Module architecture with **zero build tools, zero bundlers, and zero external dependencies**.
  - **Modular Component Separation**:
    - `index.html`: Streamlined from ~5,000 lines down to a pristine ~120-line HTML skeleton.
    - `css/style.css`: Extracted all dark, light, and labra theme styles, responsive layouts, and animations.
    - `js/utils.js`: Reusable modal dialogs (`openModal`), HTML sanitization, and title/filename formatters.
    - `js/i18n.js`: Telegram-style dictionary engine, dynamic language pack loader, and modal.
    - `js/theme.js`: Complete theme switcher with swatch preview modals and system persistence.
    - `js/network.js`: Multiplexed shared WebSocket transport, heartbeat, and exponential reconnect.
    - `js/markdown.js`: Fast native Markdown parser, code block copying, and Git unified diff view.
    - `js/accounts.js`: Antigravity account management and Claude iOS style quota card renderer.
    - `js/app.js`: Master coordinator managing multi-session tabs, streaming chat cards, and drawer views.
  - **Native Asset Serving**: `HttpServer.php` now serves `/assets/` with strict path traversal verification and accurate MIME types while preserving instantaneous local live-reloading.

## [0.3.42] - 2026-10-05

### Changed

- **Web UI Mobile More Menu Minimalist Copy Refinement**:
  - Streamlined dropdown menu labels from verbose titles to clean, iconic single-word items: `Theme`, `Language`, `Account` (and `主题`, `语言`, `账号` in Chinese).
  - Compacted dropdown popover width to a sleek 140px, giving mobile views a refined, native-app grade elegance.

## [0.3.41] - 2026-10-05

### Changed

- **Web UI Prompt Input Minimalist Placeholder & Design Discipline**:
  - Eliminated bloated shortcut instructions from the main textarea placeholder, streamlining it from `Ask pig a question or paste images... (Enter to send, Shift+Enter for new line)` to concise `Ask pig a question` (English) and `向 pig 提问...` (Chinese).
  - Codified the UI Placeholder Minimalist Convention in `CLAUDE.md`: strictly prohibiting dumping functional instructions and keybindings into primary interactive controls to maintain a clean, distraction-free aesthetic.

## [0.3.40] - 2026-10-05

### Fixed

- **Web UI Mobile "More Options" Icon Pixel-Perfect Vertical Centering**:
  - Replaced the baseline-sensitive Unicode `⋯` character and bottom padding with a native 16x16 vector SVG icon (`cy="8"` geometric center).
  - Ensured mathematical and visual vertical/horizontal centering for the three dots across all device densities, system fonts, and zoom levels, aligning seamlessly with the adjacent sidebar toggle icon.

## [0.3.39] - 2026-10-05

### Added

- **Web UI Mobile Responsive "More Options" Overflow Menu**:
  - Solved top-header layout congestion on mobile screens where packing 4 wide buttons (`Theme`, `Language`, `Accounts`, `Toggle Sidebar`) caused button text wrapping, vertical collisions, and severely squished the session title and badge.
  - **Responsive Overflow System**:
    - **Mobile Viewport (<= 768px)**: Seamlessly collapsed secondary actions into an elegant `⋯` ("More options") pill button, leaving only `[ ⋯ ]` and `[ ‹ ]` on the right and reclaiming 170px+ of horizontal space for directory and session title displays.
    - **Desktop Viewport (> 768px)**: Retained direct desktop header action buttons for quick one-click access.
  - **Frosted Dropdown Interaction**: Clicking `⋯` opens a native-feeling floating action menu with icons (`🎨 Theme`, `🌐 Language`, `🔑 Accounts`), featuring single-line non-wrapping text, outside-click auto-close, and seamless integration with existing modal dialogs.

## [0.3.38] - 2026-10-05

### Changed

- **Web UI & RPC Delayed Persistence State & Real-time Auto-naming Alignment**:
  - Aligned Web UI with TUI delayed persistence lifecycle: a new session does not write any empty `.jsonl` file to disk until the first assistant message arrives.
  - Added `isPersisted` boolean flag to `RpcMode::state()`. Web UI displays `📄 （内存暂存）` / `(in-memory)` for draft sessions, eliminating misleading ghost filenames before the first prompt.
  - Supported real-time `session_info_changed` broadcasting over RPC: `SessionInfoChangedEvent` now implements `AgentEvent` and is announced to RPC subscribers; `RpcEvents::encode` translates it so Web UI automatically updates top bar title, session tab label, and sidebar list when `smart-session` generates a title.

## [0.3.37] - 2026-10-05

### Fixed

- **Web UI Light & Labra Theme Button Contrast & Legibility**:
  - Fixed hardcoded `#182232` dark background on `.mini-btn`, which caused `Rotate`, `Refresh`, `Remove`, and dialog action buttons to appear as pitch-black illegible blocks in Light theme.
  - Aligned theme overrides with `[data-theme="light"]` and `[data-theme="labra"]` selectors:
    - **Light Theme**: Transformed buttons to modern soft-gray pills (`#f1f5f9` bg, `#cbd5e1` border, `#334155` text) with clear contrast and subtle elevation; dangerous actions (`Remove`) styled with soft-rose background and crimson text.
    - **Labra Theme**: Seamlessly integrated with deep cyberpunk olive and hot-pink hover states.

## [0.3.36] - 2026-10-05

### Changed

- **Web UI Quota Cards Claude iOS Layout Alignment**:
  - Replaced the cramped 3-column horizontal table with Claude's official iOS Usage vertical card design for Antigravity quota display.
  - **Header Row**: Model and limit name on the left with prominent weight, remaining percentage right-aligned.
  - **Full-Width Progress Bar**: 100% full-width rounded pill progress bar with dynamic status colors (normal cyan/blue, warning amber when <20%, alert red when empty).
  - **Dedicated Reset Row**: Reset timer (e.g. `44 小时 7 分后重置` / `resets in ...`) now occupies its own dedicated row below the progress bar, completely eliminating mobile line wrapping and layout tearing.

## [0.3.35] - 2026-10-05

### Added

- **100% Upstream Theme Tokens Parity (56/56 Tokens)**:
  - Augmented built-in palettes (`DARK`, `LIGHT`, and `LABRA`) with all 7 remaining theme tokens: `scrollbarTrack`, `scrollbarThumb`, `searchMatchBg`, `searchMatchText`, `syntaxOperator`, `syntaxPunctuation`, and `thinkingMax`.
  - Verified 100% byte-for-byte hex color parity with upstream `@earendil-works/pi-coding-agent`'s `getResolvedThemeColors("dark")` and `getResolvedThemeColors("light")` with 0 diffs.
  - Expanded `PaletteTest::NAMES` to validate all 56 color tokens across all built-in themes.

## [0.3.34] - 2026-10-05

### New Features

- **Frosted Glass Theme Settings Modal (Web UI)**:
  - Upgraded theme selection in Web UI to a centered frosted-glass Theme Settings modal dialog matching the Language Settings modal architecture.
  - Displays each palette (`Dark`, `Labra`, `Light`) with 4-color swatch preview pills, clear descriptive subtitles, and active checkmarks with instant zero-refresh switching and `localStorage` persistence.

### Fixed

- **Comprehensive Light Theme Visual Overhaul (Web UI)**:
  - Eliminated jarring black/white contrast tears where tool execution cards, prompt input wrappers, dropdown selectors, and action buttons remained pitch black in light mode.
  - Abstracted all hardcoded component colors into theme-aware CSS variables (`--card-bg`, `--card-header-bg`, `--tool-body-bg`, `--input-bg`, `--select-bg`, `--dropdown-bg`, `--thinking-bg`, `--btn-send`, `--btn-send-text`).
  - Redesigned Light mode into an elegant, high-contrast palette inspired by modern GitHub/Linear themes: soft daylight gray-white background (`#f8fafc`), pure white cards with soft shadow elevation, deep slate text (`#0f172a`), and crystal blue send buttons (`#0284c7`).

## [0.3.33] - 2026-10-05

### Fixed

- **Interactive TUI Full-Interface Theme Repainting & Prompt Helper Visibility**:
  - Fixed `/theme` command only changing the editor border by triggering a full interface replay (`$this->chat->clear(); $this->replay()`), updating `$this->banner`, updating `$this->footer->setPalette()`, and repainting borders with `$this->paintBorder()`. All past messages, diffs, tool outputs, and status lines now instantly repaint in the new theme colors.
  - Updated `/theme` description in `COMMANDS` table from legacy `"Switch between dark and light"` to `"Switch themes (dark, light, labra, or custom) (/theme [name])"`, ensuring autocomplete and `/help` accurately surface the Labra theme and custom theme capabilities.
  - Enhanced theme switch confirmation notice to list all currently available themes: `(Available themes: dark, light, labra...)`.

## [0.3.32] - 2026-10-05

### Changed

- **Web UI Enter Key Direct Send Aligned with Terminal TUI**:
  - Aligned Web UI keyboard behavior with terminal TUI: pressing `Enter` directly submits and sends the prompt (with robust IME composition guards preventing accidental sends during candidate word selection).
  - `Shift+Enter` naturally inserts a newline with smooth auto-expanding textarea height.
  - Maintained `⌘+Enter` and `Ctrl+Enter` as secondary send shortcuts for user convenience.
  - Updated input placeholder hints, send button titles, and HUD shortcut cheatsheet to reflect `Enter` as the primary submit key.

## [0.3.31] - 2026-10-05

### New Features

- **Cyberpunk Dark Olive & Hot Pink Built-in Theme `labra`**:
  - Added official built-in `labra` theme faithfully matching `HANCORE-linux/omarchy-labra-theme` color philosophy (deep green-black background `#040704`, linen white foreground `#d3d7b5`, hot pink accent `#e33d84`, and dark olive gold borders `#89974a`).
- **100% Upstream Theme Parity & Custom JSON Theme Discovery**:
  - `Palette::customThemes()` now automatically discovers custom theme JSON files from `~/.pig/agent/themes/*.json`, `~/.pi/agent/themes/*.json`, and `<cwd>/.pig/themes/*.json`.
  - Parsed theme objects safely merge with default `dark` tokens so incomplete theme definitions will never crash the renderer.
- **Web UI Instant Theme Toggling**:
  - Added `🎨 Theme` button in Web UI header providing instant zero-refresh toggling across `Dark`, `Labra`, and `Light` palettes with `localStorage` persistence.

### Added

- **Enhanced `/theme [name]` Slash Command**:
  - `/theme` without arguments cycles smoothly across all installed themes (`dark -> light -> labra -> ...`).
  - `/theme <name>` directly switches to the specified theme, with clear validation and available theme suggestions on typos.
  - `/settings` dynamically adapts its `Theme` row to cycle through all discovered themes.

## [0.3.30] - 2026-10-04

### Fixed

- **Web UI Non-Existent Session Auto-Recovery & Strict Port Binding**:
  - Fixed `HttpServer` passing `--session` for nonexistent, deleted, or missing session files, gracefully falling back to a clean new session and preventing child RPC processes from crashing with `exit(1)` and causing endless `no active session` reconnect loops.
  - Eliminated silent port jumping (`port + 1`) in `WebMode.php`, ensuring the daemon strictly binds to the requested port without port discrepancy between printed URL and listening socket.
  - Guarded `pigUnreachable` in web frontend to avoid showing misleading reconnection banners when the child process has already signaled exit.
  - Added unit test `testDefaultSpawnDoesNotPassSessionForNonExistentFile` verifying graceful fallback.

## [0.3.29] - 2026-10-04

### Added

- **In-Session `/web` Command Suite Enhancement (`/web restart`, `/web status`, `/web stop`)**:
  - Unified `/web` slash command inside interactive TUI sessions to manage both in-process listeners and background `WebDaemon` processes.
  - Added support for `/web restart [--port=N]`, allowing developers to restart and reload the web server without leaving their current terminal conversation.
  - Added `/web status` command to view web server running status, PID, and listening URL directly inside the terminal.
  - Enhanced `/web stop` to seamlessly stop background web daemons as well as foreground listeners.
  - Added unit test `testSlashWebStatusAndRestartCommand` verifying in-session web lifecycle controls.

## [0.3.28] - 2026-10-04

### Changed

- **Antigravity Quota & Reset Countdown Full Localization**:
  - Localized Antigravity model group names (`Gemini Models` -> `Gemini 模型`, `Claude and GPT models` -> `Claude 与 GPT 模型`).
  - Localized quota bucket limits (`Weekly Limit Remaining` -> `周限额剩余`, `Five Hour Limit Remaining` -> `5 小时限额剩余`, `Daily Limit Remaining` -> `日限额剩余`, `Hourly Limit Remaining` -> `小时限额剩余`).
  - Localized reset countdown labels (`resets in 46h 26m` -> `46 小时 26 分后重置`, `resets in 7d` -> `7 天后重置`, `resets now` -> `立即重置`).
  - Added localized quota loading, unavailable, and empty status messages.

## [0.3.27] - 2026-10-04

### New Features

- **Telegram-style Language Pack Import/Export & Extension Registration**:
  - Implemented Telegram-inspired language pack management for Web UI with instant JSON import and template export.
  - Added `ExtensionApi::registerLocale()` allowing extensions to register custom language translations (e.g. `ja`, `es`, `zh-TW`).
  - Added `/api/locales` endpoint in `HttpServer` to serve extension-provided locales.
  - Added centered Language Settings modal (`#lang-btn`) with one-click language selection, custom language deletion, file drag-and-drop import, and zero-refresh tab/badge re-rendering.
  - Resolved local variable shadowing on translation helper `__t()`.

## [0.3.26] - 2026-10-04

### New Features

- **Web UI full bilingual multi-language architecture**:
  - Implemented client-side `I18N` dictionary framework inspired by smart-book's `Lang` architecture with fallback resolution and `{param}` interpolation.
  - Added seamless Chinese (`zh-CN`) and English (`en`) support with automatic locale detection via `navigator.language` and persistence in `localStorage`.
  - Added header language toggle button (`[中 / EN]`) enabling instant zero-refresh UI language switching.
  - Fully localized all static elements and dynamic dialogs: sidebar workspaces, session drawers, rename/delete modals, accounts & quota popup, shortcut HUD, tool execution status cards, and live telemetry badges.

## [0.3.25] - 2026-10-04

### Fixed

- **Socket write delay synchronization safety**:
  - Guarded adaptive write-delay in `Socket::write()` to check `Fiber::getCurrent() !== null`, falling back to `usleep(10000)` outside coroutines to prevent `Future::await()` exceptions in non-coroutine contexts.

## [0.3.24] - 2026-10-04

### Fixed

- **TLS non-blocking socket stall false alarm**:
  - Replaced the brittle 10-iteration loop limit in `Socket::write()` with wall-clock timeout stall detection, preventing normal non-blocking zero-byte writes from prematurely killing connections on large payloads.
  - Sliced outgoing data in standard 16KB TLS-record chunks (`substr($data, $offset, 16384)`), complying with RFC 8446 to prevent OpenSSL buffer rejections.
  - Added slight adaptive delay (`Async::delay(0.01)`) after consecutive zero writes to allow the OS network stack to flush buffers and process incoming TCP ACKs.
- **Session title telemetry pollution & manual name protection**:
  - Fixed `smart-session` extension persisting transient speed metrics (` • ⚡ ...`) into `session_info` titles. Session titles are now strictly clean business labels (e.g. `京东加购人体工学椅`), with speed telemetry rendered purely in UI runtime status.
  - Added `SessionManager::cleanSessionName()` to automatically sanitize historical dirty titles across session loading, describing, and editing.

### Added

- **Unified mobile-friendly Modal system for Web UI**:
  - Extracted common `openModal()` dialog architecture matching the Account popup design, with centered positioning, frosted backdrop blur, responsive touch button layouts, and Esc/Enter keyboard handling.
  - Refactored sidebar session renaming into a centered modal dialog with pre-filled clean session titles, eliminating awkward inline input clipping on mobile devices.
  - Replaced native `window.confirm()` with a unified modal confirmation for session deletions.

## [0.3.23] - 2026-10-04

### Changed

- **Eliminated unwanted automatic browser popups**:
  - Removed automatic `open $url` invocations from `/bug` command in `InteractiveMode`; bug reports are saved locally, copied to clipboard, and display a clickable prefilled GitHub issue URL in terminal without unexpectedly popping up the OS browser.
  - Removed automatic browser launching from `/web` command and disabled `openBrowser` default in `WebMode`, preventing `phpunit` test runs and CLI executions from intrusively opening browser tabs.

## [0.3.22] - 2026-10-04

### New Features

- **Blink Shell / iPadOS style Command key shortcuts HUD**:
  - In TUI mode: holding the `Command` (⌘) key for ~480ms on macOS automatically pops up a sleek `CommandHudComponent` cheat sheet card in the overlay area, displaying all core keyboard shortcuts. Releasing Command immediately dismisses it, matching the native iPadOS and Blink Shell experience with zero CPU overhead (0.5µs native FFI checks).
  - In Web UI: holding the `Command` / `Meta` key for ~450ms pops up a centered frosted-glass blur HUD modal card (`#command-hud-modal`), automatically fading out on key release.

## [0.3.21] - 2026-10-04

### Changed

- **TUI keybinding & modifier architecture alignment**:
  - Restored `Shift+Enter` strictly to newline insertion in TUI editor across all states (both idle and during streaming), eliminating accidental message submission or queueing.
  - Added macOS native modifier fallback in `ProcessTerminal` via `CGEventSourceFlagsState` FFI (0.5µs zero-latency), accurately resolving bare `\r` sent by `Apple_Terminal` (Terminal.app) to `Shift+Enter` (`\x1b[13;2u`) or `Command+Enter` (`\x1b[13;9u`).
  - Added `SUPER = 8` modifier support to `Keys.php` and `Keybindings.php`, recognizing `command+enter`, `cmd+enter`, and `super+enter`.
  - Rebound queueing messages into the processing queue (`app.message.followUp`) to `command+enter` (with `alt+enter` as fallback).
  - Supported `Command+Enter` to submit messages directly when idle.

## [0.3.20] - 2026-10-04

### Changed

- **Web UI keybinding refinement**:
  - Rebound message submission in Web UI to `Command+Enter` (macOS) / `Ctrl+Enter` (Windows/Linux) to prevent accidental sends while drafting complex prompts or code.
  - Restored `Shift+Enter` and plain `Enter` to natural newline insertion inside the textarea with auto-resizing height.
  - Updated send button title and placeholder hints.

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
