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
  - **Persistent Web UI & PTY Workbench (`pig web start -d` / `/web`)**: Daemonized multi-tab browser interface with embedded xterm.js PTY terminal drawer, SSH node workbench, SFTP remote file explorer, and full-duplex WebSocket streaming.
  - **CLI & Automation**: Print mode (`-p`), JSON event streaming (`--mode json`), long-lived JSON-RPC socket mode (`--mode rpc`), and MCP server mode (`--mode mcp`) so another agent can call pig as a tool.
- **Comprehensive Built-in Extensions**:
  - `pig-antigravity`: Multi-account Google Antigravity quota management, 429 auto-failover, `/antigravity.usage` dashboard, and `generate_image` tool.
  - `pig-web-search`: Real-time web search (`web_search`), readable page extraction (`fetch_web_page`), and headless Chrome DOM rendering (`browse_web_page`).
  - `pig-computer`: Anti-detection headless browser automation with mouse, keyboard, scrolling, clicking, and persistent domain cookies.
  - `pig-vless`: A minimal VLESS inbound (TCP, optional TLS) so a phone can proxy through the machine pig runs on.
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
pig update               # Update pig only (packages are skipped, and it says so)
pig update --extensions  # Update installed packages, and the bundled extensions' copies
pig update --all         # Update pig and all packages
pig update <source>      # Update one package
pig update --models      # Refresh and update model catalogs
```

### Packages

Extensions, skills, prompt templates and themes travel together as a **package** — a git
repository or a local directory, as in pi, minus npm:

```bash
pig install git:github.com/user/pig-tools        # cloned under ~/.pig/agent/git/github.com/user/pig-tools
pig install git:github.com/user/pig-tools@v1     # pinned: updates reconcile to v1 and never past it
pig install https://github.com/user/pig-tools    # a URL is a git source
pig install ./my-tools                           # loaded from where it is
pig install git:github.com/user/pig-tools -l     # into the project's .pig/settings.json (trust required)
pig list                                         # what is configured, per scope, and where it is installed
pig remove git:github.com/user/pig-tools
pig config                                       # switch single resources on and off (Tab: project overrides)
pig -e git:github.com/user/pig-tools             # try a package for one run, nothing written to settings
```

A package is a directory with any of `extensions/` (`.php` files, or folders with `index.php`),
`skills/`, `prompts/` and `themes/`, or a `composer.json` naming them explicitly:

```json
{
  "name": "user/pig-tools",
  "keywords": ["pig-package"],
  "extra": {
    "pig": {
      "extensions": ["./src/Extension.php", "src/more/*.php", "!src/more/legacy.php"],
      "skills": ["./resources/skills"],
      "prompts": ["./prompts/*.md"],
      "themes": ["./themes/*.json"]
    }
  }
}
```

#### Publishing a package

The gallery at [pigagent.dev/packages](https://pigagent.dev/packages) is pi's `pi-package` npm keyword, for git:
every GitHub repository with the topic `pig-package` is listed, with no registration and no review.

1. Put the package in its own GitHub repository — the conventional directories above, or a `composer.json`
   with `extra.pig`. A package that needs libraries commits its own `vendor/`.
2. Check it installs: `pig install git:github.com/user/repo`, then `pig list` shows its resources.
3. Add the topic `pig-package` on the repository page (About → Topics). The list refreshes hourly; the card
   shows the repository description, stars and last push, so the description is the card's text.

Optional `extra.pig.image` / `extra.pig.video` in `composer.json` (a path in the repository or an `https://`
URL) give the card a preview; pig itself ignores them. Removing the topic removes the package. Only GitHub is
indexed — packages on other hosts install fine but are not listed.

The settings entry can narrow what loads, with pi's syntax — omit a type to load all of it, `[]`
for none, `!glob` to exclude, `+path` / `-path` for one exact file:

```json
{ "packages": [{ "source": "git:github.com/user/pig-tools", "extensions": ["!extensions/legacy.php"], "skills": [] }] }
```

pig runs no package manager inside a package: a package that needs libraries ships its own
`vendor/` (pig requires `vendor/autoload.php` when it is there), and `Pig\*` comes from the host —
do not `require` `pigagent/pig` in a package. Packages are PHP-only; `npm:` and `composer:`
sources are refused by name.

---

## Usage

### 1. Terminal Interactive Mode (Default)

Launch `pig` in your project directory:

```bash
pig
```

- **Set API Key**: Run with `ANTHROPIC_API_KEY=sk-... pig` or use `--api-key <key>` for a single run without persisting.
- **Google Vertex AI and Amazon Bedrock**: no key needed when the cloud's own credentials are there, as in pi. Vertex takes `GOOGLE_CLOUD_API_KEY`, or Application Default Credentials (`gcloud auth application-default login`, a service-account file in `GOOGLE_APPLICATION_CREDENTIALS`, or the metadata server) with `GOOGLE_CLOUD_PROJECT` and `GOOGLE_CLOUD_LOCATION`. Bedrock takes `AWS_BEARER_TOKEN_BEDROCK`, or the AWS SDK's credential chain — `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, `AWS_PROFILE` and `~/.aws` (assumed roles, `credential_process`, SSO), web identity, ECS/EKS and EC2 roles — with the region from `AWS_REGION` or the profile. Pick a model as `google-vertex/gemini-2.5-pro` or `amazon-bedrock/us.anthropic.claude-sonnet-4-5-20250929-v1:0`.
- **Azure OpenAI and Radius**: as in pi. Azure takes `AZURE_OPENAI_API_KEY` and its endpoint from `AZURE_OPENAI_BASE_URL` or `AZURE_OPENAI_RESOURCE_NAME` (`https://<name>.openai.azure.com/openai/v1`), the API version from `AZURE_OPENAI_API_VERSION` (default `v1`), and deployments named differently from the model in `AZURE_OPENAI_DEPLOYMENT_NAME_MAP` (`gpt-5.4=prod,o3=reasoning`); pick a model as `azure/gpt-5.4`. The Radius gateway takes `RADIUS_API_KEY`, with models such as `radius/balanced`.
- **The other providers pi ships on these APIs**: each with pi's key variable, and a model picked as `<provider>/<id>` (a bare id is the maker's own provider). DeepSeek `DEEPSEEK_API_KEY`, OpenRouter `OPENROUTER_API_KEY` (`openrouter/moonshotai/kimi-k2.6`), Vercel AI Gateway `AI_GATEWAY_API_KEY`, Together `TOGETHER_API_KEY`, Fireworks `FIREWORKS_API_KEY`, Baseten `BASETEN_API_KEY`, Hugging Face `HF_TOKEN`, NVIDIA `NVIDIA_API_KEY`, MiniMax `MINIMAX_API_KEY` / `MINIMAX_CN_API_KEY` (`minimax-cn`), Moonshot `MOONSHOT_API_KEY` (`moonshotai`, `moonshotai-cn`), Kimi For Coding `KIMI_API_KEY` (`kimi-coding`), Meta `META_API_KEY`, OpenCode Zen and Go `OPENCODE_API_KEY` (`opencode`, `opencode-go`), Xiaomi MiMo `XIAOMI_API_KEY` and its Token Plans `XIAOMI_TOKEN_PLAN_CN_API_KEY` / `_AMS_` / `_SGP_` (`xiaomi-token-plan-cn` …), Qwen's Token Plans `QWEN_TOKEN_PLAN_API_KEY` (`qwen-token-plan`, `qwen-token-plan-individual`) and `QWEN_TOKEN_PLAN_CN_API_KEY` (`qwen-token-plan-cn`), Z.ai's China Coding Plan `ZAI_CODING_CN_API_KEY` (`zai-coding-cn`), Ant Ling `ANT_LING_API_KEY`. The OpenRouter, Kimi, Meta and xAI sign-ins are not here; their keys are. xAI's models (`XAI_API_KEY`) speak its Responses API, as in pi.
- **Cloudflare Workers AI and AI Gateway**: `CLOUDFLARE_API_KEY` with `CLOUDFLARE_ACCOUNT_ID`, and for the gateway `CLOUDFLARE_GATEWAY_ID` too (pi's sign-in, which stores the ids, is not here); pick a model as `cloudflare-workers-ai/@cf/openai/gpt-oss-120b` or `cloudflare-ai-gateway/claude-sonnet-4-6`.
- **Classifiers and image models** (`Pig\Ai\Models::classify()`, `Models::generateImages()`, for code built on `pig/ai`): TypeSafe's System One `TYPESAFE_API_KEY` (`typesafe/jev-latest`, and the Jev and decision models of OpenRouter, Vercel AI Gateway, OpenCode Zen and Workers AI), a chat model on llama.cpp's `llama-server` read by its next-token probabilities (`llama-cpp-classify`), and OpenRouter's image models. They are not chat models, so `/model` does not list them.
- **Subscription Login**: Run `/login` inside pig to sign in with Claude Pro/Max, GitHub Copilot, ChatGPT Plus/Pro (for the `openai-codex` models, e.g. `openai-codex/gpt-6.1-sol` — a browser that comes back to `localhost:1455`, or a device code for a headless machine), or Google Antigravity. Claude offers two ways in, as pi 1.0 does: a browser that comes back to `localhost:53692` (the default), or copying the code off Anthropic's page for a headless machine. Tokens are saved in `~/.pig/agent/auth.json` (or shared with `~/.pi/agent/auth.json`).
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
  - Press **Ctrl+G**: Edit complex prompts in your external editor (supports `"externalEditor": "vim"` in `settings.json`, `$VISUAL` or `$EDITOR`, or auto-fallback to system-available editors).
- **Context & Session Commands**:
  - `/name <new-name>`: View or change the active session title (reflected in footer and Web UI).
  - `/label <name>`: Bookmark the current point in the session tree.
  - `/tree`: Visualize conversation branches as an interactive tree and jump between forks.
  - `/fork`: Pick one of your messages and fork the conversation up to before it into a new session file, with that message back in the prompt; `/clone` copies the conversation as it stands into a new file. The new file names the old one as its `parentSession`.
  - `/compact`: Manually trigger conversation summarization.
  - `/export [file.html]`: Export the session as a standalone offline HTML document with syntax highlighting.
  - `/reload`: Hot-reload extensions, skills, tools, and context files without restarting `pig`. An extension that declares PHP classes keeps running the version loaded at startup (PHP cannot unload a class) and the reload says so when its files changed — restart `pig` to pick that up.
  - `/doctor`: Run system diagnostic checks on PHP extensions, tools, permissions, and network endpoints.

### 2. Web UI Interface (`pig web`)

`pig` includes a native web chat interface matching `pi-web` with workspace management, multi-tab execution, inline session rename/delete, and real-time streaming:

```bash
# Foreground ephemeral server (starts server and opens your default browser)
pig --mode web
# or type /web from inside any interactive terminal session to start and auto-launch browser

# Persistent background daemon (recommended)
pig web start -d              # Start daemon on 127.0.0.1:8080 (or specify --port / --host)
pig web status                # Check status and PID
pig web restart               # Restart daemon
pig web stop                  # Stop daemon gracefully
```

Open `http://localhost:8080` in your browser or mobile phone:
- **Interactive Local PTY Terminals**: Press <code>Ctrl+`</code> or <code>Cmd+`</code> (or click the terminal icon) to slide out a full multi-tab terminal drawer powered by `xterm.js` and a native Unix PTY engine, with full support for `vim`, `nvim`, `htop`, `tmux`, and `nano`.
- **SSH Node Workbench & SFTP File Explorer**: Click `🖥️ Nodes` to manage server inventory, verify SHA-256 host key fingerprints, import from `~/.ssh/config`, open remote interactive SSH terminals, and view/edit remote files via SFTP (up to 512 KiB).
- **Auto Browser Launch**: Running `/web` inside an interactive TUI session starts the server and automatically opens your default system browser.
- **Multiplexed Multi-Tab Execution**: Switch between workspaces and tabs without interrupting active runs.
- **Native Bilingual Multi-Language Support (中 / EN)**: Click the header `[中 / EN]` button for instant zero-refresh language toggling, auto-adapting to browser language.
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

# Serve this conversation to another agent as an MCP server (one tool: `ask`)
pig --mode mcp                            # Streamable HTTP at http://127.0.0.1:8089/mcp
pig --mode mcp --mcp-host 0.0.0.0 --mcp-port 9000
pig --mode mcp --mcp-stdio                # JSON-RPC lines on stdin/stdout, for a host that spawns it
pig --mode mcp --session <id>             # over an existing conversation (`-c` for the latest)
```

Register it where the other agent reads its MCP servers — for Claude Code,
`claude mcp add --transport http pig http://127.0.0.1:8089/mcp`, or
`claude mcp add pig -- pig --mode mcp --mcp-stdio` — and it gets an `ask` tool whose calls are turns
in one pig conversation, with pig's own tools, hooks and extensions behind it. Asks made while one
is running wait their turn rather than failing. Details: [Serve pig as an MCP Server](https://pigagent.dev/docs/latest/mcp-server).

### 4. A VLESS Inbound for Your Phone (`pig-vless`)

The built-in `pig-vless` extension opens a [VLESS](https://xtls.github.io/en/development/protocols/vless.html) inbound beside the agent, so a phone running Shadowrocket or v2rayNG can route through the machine pig is on. TCP only, one UUID, TLS when you give it a certificate — the smallest version a phone can use, not an Xray.

```text
/vless start      # open the port (nothing listens until you ask)
/vless status     # configuration, state, and the vless:// link for the phone
/vless stop       # close it; the session ending does this too
/vless restart
```

The first session writes `vless.listen` (`0.0.0.0:10086`) and a generated `vless.uuid` into `settings.json`; add `vless.cert` / `vless.key` (PEM) for TLS. Details: [Proxy a Phone Through pig](https://pigagent.dev/docs/latest/vless).

---

## Built-in Extension Ecosystem

All extensions in `pig` are **100% pure native PHP** with zero external npm or composer dependencies:

| Extension | Namespace / Location | Capabilities |
| :--- | :--- | :--- |
| **`pig-antigravity`** | `extensions/pig-antigravity/` | **The whole Antigravity provider** — models, wire protocol, Google sign-in, model routing — registered through `registerProvider()`, as pi's community `pi-antigravity` does since pi 0.71 dropped it from the core. Plus multi-account management, 429 failover to the next account inside the request (as pi-antigravity does), `/antigravity.usage`, `/antigravity.accounts`, and the `generate_image` tool. |
| **`pig-web-search`** | `extensions/pig-web-search/` | Real-time web search (`web_search`), readable article extraction (`fetch_web_page`), headless Chrome DOM rendering (`browse_web_page`), `/search <query>`. |
| **`pig-computer`** | `extensions/pig-computer/` | Anti-detection browser automation (mouse move, click, scroll, typing, screenshots, persistent cookies). |
| **`pig-codemode`** | `extensions/pig-codemode/` | Fast multi-tool execution in a sandboxed child PHP process (`open_basedir`, `disable_functions`). |
| **`pig-mcp`** | `extensions/pig-mcp/` | Model Context Protocol client for stdio & streamable HTTP servers with dynamic OAuth (`mcp.json`). |
| **`pig-vless`** | `extensions/pig-vless/` | A VLESS inbound beside the agent so a phone (Shadowrocket, v2rayNG) can go through this machine. TCP only, one UUID, TLS when a cert is given. Loading it writes a UUID and listen address into `settings.json`; nothing listens until `/vless start` (`stop`, `restart`, `status`), and `/vless` prints the `vless://` link. |

### What an extension can do

An extension is a PHP file (or a folder with `index.php`) returning `function (ExtensionApi $pi)`. The API tracks pi's `ExtensionAPI`:

| | |
| :--- | :--- |
| **Bring a provider** | `registerProvider(new Provider(id, name, models, api: StreamApi, oauth: OauthFlow, envKeys, resold))` — the models go into the registry, the protocol behind `Api::Extension`, the sign-in into `/login`. `unregisterProvider()` takes it back. |
| **Tools, commands, renderers** | `registerTool()`, `removeTools()`, `registerCommand()`, `registerMessageRenderer()`, `registerLocale()`. |
| **Events** | `on('…')` for the session lifecycle, the agent loop, tool calls and results, `context`, and upstream's provider events — `before_provider_request` (replace the payload), `before_provider_headers` (edit the headers in place), `after_provider_response` (status and headers), `provider_stream_event` (each raw stream event) — plus `model_select`, `thinking_level_select`. |
| **Flags** | `registerFlag('name', 'boolean'|'string', description, default)` declares `--name`; `getFlag()` reads it from a handler. |
| **The session** | `getSettings()`, `getModel()`, `setModel()`, `getThinkingLevel()`, `setThinkingLevel()`, `sendUserMessage(text, 'steer'|'followUp')`, `sendMessage()`, `setLabel()`, `getCommands()`, `exec()`. |
| **The web UI** | `registerHttpRoute('/api/prefix', fn (path, req) => ['status', 'body'])` answers requests in `pig web` — how the Antigravity accounts panel is served. |
| **A second credential store** | `Auth::useSecondStore(provider, read, renewed)` for a provider that keeps several accounts beside `auth.json`. |

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
| `~/.pig/agent/themes/` | Custom JSON themes directory (built-in `dark`, `light`, `labra`, plus any user-defined theme). |
| `~/.pig/agent/sessions/` | Saved session logs in standard `.jsonl` format. |

**Prompt cache warming.** While a long tool runs past the provider's cache lifetime (five minutes on Anthropic), pig re-sends the last request with a one-token cap just before the cache entry expires — only when the expected saving is at least $0.05 — so the next request reads the prompt from the cache instead of paying to write it again. Each refresh is in the session file and in `/session`'s cost. `cacheWarming` in the global `settings.json` (or the *Cache warming* row in `/settings`) is `streaming` by default (while the agent runs), `idle` (between runs too, for up to 30 minutes) or `off`. An extension can overrule each refresh with the `cache_warming_decision` event. `-p` never warms.

### Signing in with a subscription

`/login` inside pig, or `pig-ai login <provider>` from a shell with no terminal UI. Four providers, each the way pi does it:

| Provider | How | Notes |
| :--- | :--- | :--- |
| **Anthropic (Claude Pro/Max)** | **Browser** (default): pig listens on `http://localhost:53692/callback`, opens `claude.ai`, and the code comes back by itself. A paste box stays open beside it — if the browser is on another machine, paste the final redirect URL there. **Copy code** (headless): the browser lands on Anthropic's own page showing `code#state`; paste that. | The token goes out as Claude Code's — `claude-cli` user agent, both betas, tool names spelled `Read`/`Bash`/`Edit`/`Write` — because that is the identity Anthropic issued it to. Port 53692 is registered with Anthropic and cannot be changed. |
| **GitHub Copilot** | Device flow: pig shows a code, you type it at `github.com/login/device`, pig polls until it is accepted. Blank at the Enterprise prompt means `github.com`. | Claude's and Grok's models are switched on for the account after signing in. |
| **OpenAI (ChatGPT Plus/Pro)** | **Browser** (default): pig listens on `http://localhost:1455/auth/callback` (on `PI_OAUTH_CALLBACK_HOST` when set), opens `auth.openai.com`, and the code comes back by itself; a paste box stays open beside it for the redirect URL or the code. When port 1455 is taken (the Codex CLI shares it), the paste box alone finishes the sign-in. **Device code** (headless): pig shows a code to type at `auth.openai.com/codex/device` and polls until it is accepted. | For the `openai-codex` models only — ChatGPT's Codex backend, with the account id read off the token. |
| **Antigravity** (extension) | Browser callback on `localhost:51121/oauth-callback`. The row is there only while `pig-antigravity` is loaded — it is the extension's provider, not the core's. | Needs `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET` in the environment or `antigravity.clientId` / `antigravity.clientSecret` in `settings.json`; pig does not ship them. |

Tokens are renewed automatically when they expire; the file is the same one pi reads, so a sign-in in either tool is a sign-in in both. `/logout` forgets one.

---

## Packages Architecture

`pig` is organized as clean decoupled namespaces under `packages/`:

- `packages/async/` (`Pig\Async\`): Coroutine runtime, non-blocking TLS Socket, Futures, Deferreds, and event loop.
- `packages/ai/` (`Pig\Ai\`): Unified LLM protocol adapters (Anthropic, OpenAI Completions, OpenAI Responses, Gemini, Vertex AI, Mistral, Amazon Bedrock ConverseStream — with SigV4 and the AWS credential chain in plain PHP — Azure OpenAI Responses, the ChatGPT Codex backend, and pi's own `pi-messages` protocol), and `Pig\Ai\Extension\` — the `Provider`/`StreamApi`/`OauthFlow` an extension implements to bring a provider of its own.
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
