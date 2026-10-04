# pig — PHP AI Agent

**English** · [简体中文](README.zh-CN.md) · **Website & Docs:** [pigagent.dev](https://pigagent.dev)

A complete, high-performance PHP port of [pi](https://github.com/earendil-works/pi). Same architecture, same file layout, written for PHP >= 8.3 with **zero runtime dependencies** — no Guzzle, no ReactPHP, no amphp, no ncurses, no Node.js. Just the PHP standard library.

> **Status: 100% Production Ready.** `pig` delivers a sub-500ms time-to-first-frame startup, streaming answers, live tool execution, interactive diff inspection, mid-stream interruption via Escape, follow-up message queues, and bidirectional compatibility with upstream `pi` session formats (`.jsonl`), credentials (`auth.json`), custom models (`models.json`), project trusts (`trust.json`), and settings (`settings.json`).

---

## Highlights

- **Zero Runtime Dependencies**: Pure PHP utilizing `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pcntl`, and `ext-pcre`.
- **Sub-500ms Fast Startup (`pig -c`)**: Optimized O(1) session tree traversal and lightweight mtime header discovery; resumes massive 70MB+ sessions in under 0.5s.
- **Three Interaction Modes**:
  - **Terminal TUI**: Differential rendering ANSI interface with live spinners, visual diff views, keybindings, and IME-safe caret tracking.
  - **Persistent Web UI (`pig web start -d`)**: Daemonized multi-tab browser interface backed by a managed RPC session pool (`SessionPool`) and native full-duplex WebSocket streaming.
  - **CLI & Automation**: Print mode (`-p`), JSON event streaming (`--mode json`), and long-lived JSON-RPC socket mode (`--mode rpc`).
- **Comprehensive Built-in Extensions**:
  - `pig-antigravity`: Multi-account Google Antigravity quota management, 429 auto-failover, `/antigravity.usage` dashboard, and `generate_image` tool.
  - `pig-web-search`: Real-time web search (`web_search`), readable page extraction (`fetch_web_page`), and headless Chrome DOM rendering (`browse_web_page`).
  - `pig-computer`: Anti-detection headless browser automation with mouse, keyboard, scrolling, clicking, and persistent domain cookies.
  - `pig-codemode`: Batch multi-tool execution inside an isolated PHP sandbox to minimize context window usage.
  - `pig-mcp`: Native Model Context Protocol (MCP) client supporting stdio and streamable HTTP servers with dynamic OAuth.
- **Resilient Network & Logging**: Workerman-inspired fault isolation boundaries, auto-retry on transient SSL/socket drops, and a unified 5-tier colored logger (`Pig\Logger`).

---

## Installation & Quickstart

### Quick Install (Recommended)

```bash
curl -fsSL https://pigagent.dev/install.sh | sh
```

The installer verifies your PHP version and required extensions, installs `pigagent/pig` globally via Composer, and ensures Composer's global bin directory is in your `PATH`.

### Composer Global Install

```bash
composer global require pigagent/pig
echo "export PATH=\"$(composer global config bin-dir --absolute):\$PATH\"" >> ~/.zshrc
exec $SHELL
```

### From Source

```bash
git clone https://github.com/owner888/pig.git && cd pig
composer install
./bin/pig
```

### Self Update

```bash
pig update               # Self-update pig and all installed extensions (default: --self + --extensions)
pig update --extensions  # Update installed extensions only (git pull, composer, and core built-ins sync)
pig update --models      # Refresh and update model catalogs
pig update --self        # Update pig core program only
```

---

## Usage

### 1. Terminal Interactive Mode (Default)

Launch `pig` in your project directory:

```bash
pig
```

- **Set API Key**: Run with `ANTHROPIC_API_KEY=sk-... pig` or use `--api-key <key>` for a single run without persisting.
- **Subscription Login**: Run `/login` inside pig to sign in with Claude Pro/Max, GitHub Copilot, or Google Antigravity. Tokens are saved in `~/.pig/agent/auth.json` (or shared with `~/.pi/agent/auth.json`).
- **Resume Sessions**:
  - `pig -c` / `pig --continue`: Instantly resume the most recently modified session in the current directory.
  - `pig -r` / `pig --resume`: Open the interactive session picker with fuzzy search.
  - `pig --session <id>`: Open a specific session by ID or file path directly.
- **Model Selection**:
  - `pig --model sonnet` or `pig --model antigravity/gemini-3.8-flash`
  - `pig --model sonnet:high` (sets model and thinking level simultaneously)
  - Press `Ctrl+L` to open the in-session model picker; press `Ctrl+P` / `Shift+Ctrl+P` to cycle models on the fly.
  - `pig --list-models [query]`: View available models with context limits and pricing.
- **Interactive Shortcuts & Mid-Turn Controls**:
  - **Hold `⌘` (Command) for ~0.5s**: Pops up an interactive **Blink Shell / iPadOS style** shortcuts cheat sheet (HUD) in the center of the screen, automatically dismissing when released (supported in both TUI and Web UI).
  - Press **Shift+Enter**: Insert a **newline** in the editor (draft multiline prompts or paste code safely without accidental sending).
  - Press **Enter** or **Command+Enter** (`⌘+Enter` / `Ctrl+Enter`) when idle: Submit message.
  - Press **Command+Enter** (or **Alt+Enter**) while the model is answering: **Queue a follow-up** for after the turn ends.
  - Press **Enter** while the model is answering: **Steer** (interrupts right after current tool).
  - Press **Alt+Up**: Restore all queued messages back to the editor.
  - Press **Escape**: Interrupt the active turn or cancel retries.
  - Press **Ctrl+G**: Edit complex prompts in your external editor (`$VISUAL` or `$EDITOR`).
- **Context & Session Commands**:
  - `/name <new-name>`: View or change the active session title (reflected in footer and Web UI).
  - `/label <name>`: Bookmark the current point in the session tree.
  - `/tree`: Visualize conversation branches as an interactive tree and jump between forks.
  - `/compact`: Manually trigger conversation summarization.
  - `/export [file.html]`: Export the session as a standalone offline HTML document with syntax highlighting.
  - `/reload`: Hot-reload extensions, skills, tools, and context files without restarting `pig`.
  - `/doctor`: Run system diagnostic checks on PHP extensions, tools, permissions, and network endpoints.

### 2. Web UI Interface (`pig web`)

`pig` includes a native web chat interface matching `pi-web` with workspace management, multi-tab execution, inline session rename/delete, and real-time streaming:

```bash
# Foreground ephemeral server (stops when terminal exits)
pig --mode web
# or type /web from inside any interactive terminal session

# Persistent background daemon (recommended)
pig web start -d              # Start daemon on 127.0.0.1:8080 (or specify --port / --host)
pig web status                # Check status and PID
pig web restart               # Restart daemon
pig web stop                  # Stop daemon gracefully
```

Open `http://localhost:8080` in your browser or mobile phone:
- **Multiplexed Multi-Tab Execution**: Switch between workspaces and tabs without interrupting active runs.
- **Sidebar Session Actions**: Hover (or tap on mobile) to rename (`✏️`) or delete (`🗑️`) sessions safely.
- **Touchscreen & Mobile Parity**: Responsive layout optimized for smartphones and tablets.
- **Antigravity Account Drawer**: Manage Google accounts, token expiration, and view quota meters.

### 3. Non-Interactive CLI & Pipes

```bash
# Print mode: output final answer directly to stdout and exit
pig -p "summarize the architecture of this repo" | pbcopy
pig -p @error.log "what caused this crash?"

# JSON event stream: emit each turn event as a JSON line
pig --mode json -p "explain index.php"

# Long-lived JSON-RPC server over stdio
pig --mode rpc
```

---

## Built-in Extension Ecosystem

All extensions in `pig` are **100% pure native PHP** with zero external npm or composer dependencies:

| Extension | Namespace / Location | Capabilities |
| :--- | :--- | :--- |
| **`pig-antigravity`** | `extensions/pig-antigravity/` | Multi-account Google Antigravity management, token refresh, auto 429 failover, `/antigravity.usage`, `/antigravity.accounts`, and `generate_image` tool. |
| **`pig-web-search`** | `extensions/pig-web-search/` | Real-time web search (`web_search`), readable article extraction (`fetch_web_page`), headless Chrome DOM rendering (`browse_web_page`), `/search <query>`. |
| **`pig-computer`** | `extensions/pig-computer/` | Anti-detection browser automation (mouse move, click, scroll, typing, screenshots, persistent cookies). |
| **`pig-codemode`** | `extensions/pig-codemode/` | Fast multi-tool execution in a sandboxed child PHP process (`open_basedir`, `disable_functions`). |
| **`pig-mcp`** | `extensions/pig-mcp/` | Model Context Protocol client for stdio & streamable HTTP servers with dynamic OAuth (`mcp.json`). |

---

## Unified Logging (`Pig\Logger`)

`pig` includes an enterprise-grade static logger aligned with the `OmniPHP\Logger` standard:

```php
use Pig\Logger;

Logger::info("Session initialized", ['id' => $sessionId]);
Logger::debug("Executing tool call", ['tool' => 'bash']);
Logger::warning("Socket interrupted, scheduling retry...");
Logger::error("API request failed", ['error' => $e->getMessage()]);

// Performance profiling
Logger::time('benchmark');
// ... do work ...
Logger::timeEnd('benchmark');
```

- **5 Standard Levels**: `VERBOSE` (blue), `DEBUG` (cyan), `INFO` (green), `WARNING` (yellow), `ERROR` (red).
- **Environment Controlled**: Filter via `PIG_LOG_LEVEL=debug` or `LOG_LEVEL=info`.
- **Daily Rotation**: Persisted to `~/.pig/agent/logs/pig-YYYY-MM-DD.log` with automatic 5-day retention.
- **TUI Screen Safety**: Automatically mutes console output in TUI raw mode (`Logger::setConsoleOutput(false)`) to protect rendering while keeping disk logs active.

---

## Configuration & Compatibility

`pig` shares configuration and session structures seamlessly with upstream `pi`:

| Path | Purpose |
| :--- | :--- |
| `~/.pig/agent/settings.json` | Global preferences (theme, model, thinking, auto-compact, auto-retry). |
| `~/.pig/agent/auth.json` | Provider API keys and OAuth tokens (shared with `~/.pi/agent/auth.json`). |
| `~/.pig/agent/models.json` | Custom OpenAI-compatible endpoints, local models (llama.cpp, vLLM). |
| `~/.pig/agent/mcp.json` | MCP server configurations (stdio & HTTP). |
| `~/.pig/agent/trust.json` | Project resource authorization records. |
| `~/.pig/agent/keybindings.json` | Custom keyboard shortcut mappings. |
| `~/.pig/agent/sessions/` | Saved session logs in standard `.jsonl` format. |

---

## Packages Architecture

`pig` is organized as clean decoupled namespaces under `packages/`:

- `packages/async/` (`Pig\Async\`): Coroutine runtime, non-blocking TLS Socket, Futures, Deferreds, and event loop.
- `packages/ai/` (`Pig\Ai\`): Unified LLM protocol adapters (Anthropic, OpenAI Completions, OpenAI Responses, Gemini, Antigravity).
- `packages/agent-core/` (`Pig\Agent\`): Agent loop, tool lifecycle, and JSON schema validation.
- `packages/tui/` (`Pig\Tui\`): Differential terminal rendering engine, ANSI styling, and key parser.
- `packages/coding-agent/` (`Pig\CodingAgent\`): CLI harness, session tree, auto-compaction, Web daemon, and tools.

---

## Requirements & Development

- **PHP >= 8.3**
- Required PHP extensions: `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pcntl`, `ext-pcre`.
- Optional: `ext-posix` (required for daemonizing `pig web start -d`).
- External binaries: `stty`. (`fd` and `rg` downloaded automatically into `~/.pig/agent/bin/` if absent).

```bash
# Run unit test suite
vendor/bin/phpunit

# Run syntax lint across all packages
php test/lint.php

# Run live provider validation (requires API keys)
php test/live.php
```

---

## Documentation

Full guides, configuration specifications, and SDK manuals are available on the official website:
- **Documentation**: [https://pigagent.dev/docs](https://pigagent.dev/docs)
- **Model Catalog**: [https://pigagent.dev/models](https://pigagent.dev/models)
- **Extension Packages**: [https://pigagent.dev/packages](https://pigagent.dev/packages)
- **Changelog**: [https://pigagent.dev/changelog](https://pigagent.dev/changelog)

---

## License

MIT © [owner888](https://github.com/owner888)
