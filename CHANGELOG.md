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
