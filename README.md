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
  - `pig-antigravity`: Multi-account Google Antigravity quota management, 429 auto-failover, `/antigravity.usage` dashboard, and `generate_image` / `google_search` tools.
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

The installer verifies your PHP version (>= 8.3) and required extensions (prompting to install PHP via Homebrew on macOS if missing), automatically bootstraps and installs Composer via pure PHP if not found on your PATH, installs `pigagent/pig` globally via Composer, and ensures Composer's global bin directory is in your `PATH`.

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

Extensions, skills, prompt templates and themes travel together as a **package** — a Composer
package, a git repository or a local directory, as in pi, with Composer where pi has npm:

```bash
pig install composer:user/pig-tools              # composer require into ~/.pig/agent/composer
pig install composer:user/pig-tools@^1.2         # a constraint; an exact version (@1.2.3) is pinned
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
4. Optionally submit it to [Packagist](https://packagist.org) as well, so it installs with
   `pig install composer:user/repo` and its version constraints — pi's `npm publish`. A package published
   this way declares its libraries in `require` and does not commit `vendor/`.

Optional `extra.pig.image` / `extra.pig.video` in `composer.json` (a path in the repository or an `https://`
URL) give the card a preview; pig itself ignores them. Removing the topic removes the package. Only GitHub is
indexed — packages on other hosts install fine but are not listed.

The settings entry can narrow what loads, with pi's syntax — omit a type to load all of it, `[]`
for none, `!glob` to exclude, `+path` / `-path` for one exact file:

```json
{ "packages": [{ "source": "git:github.com/user/pig-tools", "extensions": ["!extensions/legacy.php"], "skills": [] }] }
```

A `composer:` package is installed with Composer (it has to be on the PATH) into one shared
project per scope — `~/.pig/agent/composer`, or `.pig/composer` with `-l` — whose `composer.json`
replaces `pigagent/pig`, so a package that requires pig gets the running pig rather than a second
copy, and blocks Composer plugins. Its libraries come from that project's `vendor/`; two packages
that need incompatible versions of one library are refused at install, by Composer. `pig update`
requires an unpinned package again — within its constraint, or the newest release without one.

pig runs no package manager inside a git or local package: one that needs libraries ships its own
`vendor/` (pig requires `vendor/autoload.php` when it is there), and `Pig\*` comes from the host —
do not `require` `pigagent/pig` in such a package. Packages are PHP-only; `npm:` sources are
refused by name.

---

## Usage

### 1. Terminal Interactive Mode (Default)

Launch `pig` in your project directory:

```bash
pig
```

- **Set API Key**: Run with `ANTHROPIC_API_KEY=sk-... pig` (`ANTHROPIC_AUTH_TOKEN` is sent as a bearer token), or use `--api-key <key>` together with `--model` for a single run without persisting.
- **Google Vertex AI and Amazon Bedrock**: no key needed when the cloud's own credentials are there, as in pi. Vertex takes `GOOGLE_CLOUD_API_KEY`, or Application Default Credentials (`gcloud auth application-default login`, a service-account file in `GOOGLE_APPLICATION_CREDENTIALS`, or the metadata server) with `GOOGLE_CLOUD_PROJECT` and `GOOGLE_CLOUD_LOCATION`. Bedrock takes `AWS_BEARER_TOKEN_BEDROCK`, or the AWS SDK's credential chain — `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, `AWS_PROFILE` and `~/.aws` (assumed roles, `credential_process`, SSO), web identity, ECS/EKS and EC2 roles — with the region from `AWS_REGION` or the profile. Pick a model as `google-vertex/gemini-2.5-pro` or `amazon-bedrock/us.anthropic.claude-sonnet-4-5-20250929-v1:0`.
- **Azure OpenAI and Radius**: as in pi. Azure takes `AZURE_OPENAI_API_KEY` and its endpoint from `AZURE_OPENAI_BASE_URL` or `AZURE_OPENAI_RESOURCE_NAME` (`https://<name>.openai.azure.com/openai/v1`), the API version from `AZURE_OPENAI_API_VERSION` (default `v1`), and deployments named differently from the model in `AZURE_OPENAI_DEPLOYMENT_NAME_MAP` (`gpt-5.4=prod,o3=reasoning`); pick a model as `azure/gpt-5.4`. The Radius gateway takes `RADIUS_API_KEY`, with models such as `radius/balanced`.
- **The other providers pi ships on these APIs**: each with pi's key variable, and a model picked as `<provider>/<id>` (a bare id is the maker's own provider). DeepSeek `DEEPSEEK_API_KEY`, OpenRouter `OPENROUTER_API_KEY` (`openrouter/moonshotai/kimi-k2.6`), Vercel AI Gateway `AI_GATEWAY_API_KEY`, Together `TOGETHER_API_KEY`, Fireworks `FIREWORKS_API_KEY`, Baseten `BASETEN_API_KEY`, Hugging Face `HF_TOKEN`, NVIDIA `NVIDIA_API_KEY`, MiniMax `MINIMAX_API_KEY` / `MINIMAX_CN_API_KEY` (`minimax-cn`), Moonshot `MOONSHOT_API_KEY` (`moonshotai`, `moonshotai-cn`), Kimi For Coding `KIMI_API_KEY` (`kimi-coding`), Meta `META_API_KEY`, OpenCode Zen and Go `OPENCODE_API_KEY` (`opencode`, `opencode-go`), Xiaomi MiMo `XIAOMI_API_KEY` and its Token Plans `XIAOMI_TOKEN_PLAN_CN_API_KEY` / `_AMS_` / `_SGP_` (`xiaomi-token-plan-cn` …), Qwen's Token Plans `QWEN_TOKEN_PLAN_API_KEY` (`qwen-token-plan`, `qwen-token-plan-individual`) and `QWEN_TOKEN_PLAN_CN_API_KEY` (`qwen-token-plan-cn`), Z.ai's China Coding Plan `ZAI_CODING_CN_API_KEY` (`zai-coding-cn`), Ant Ling `ANT_LING_API_KEY`. The OpenRouter, Kimi, Meta and xAI sign-ins are not here; their keys are. xAI's models (`XAI_API_KEY`) speak its Responses API, as in pi.
- **Cloudflare Workers AI and AI Gateway**: `CLOUDFLARE_API_KEY` with `CLOUDFLARE_ACCOUNT_ID`, and for the gateway `CLOUDFLARE_GATEWAY_ID` too (pi's sign-in, which stores the ids, is not here); pick a model as `cloudflare-workers-ai/@cf/openai/gpt-oss-120b` or `cloudflare-ai-gateway/claude-sonnet-4-6`.
- **Classifiers and image models** (`Pig\Ai\Models::classify()`, `Models::generateImages()`, for code built on `pig/ai`): TypeSafe's System One `TYPESAFE_API_KEY` (`typesafe/jev-latest`, and the Jev and decision models of OpenRouter, Vercel AI Gateway, OpenCode Zen and Workers AI), a chat model on llama.cpp's `llama-server` read by its next-token probabilities (`llama-cpp-classify`), and OpenRouter's image models. They are not chat models, so `/model` does not list them.
- **Subscription Login**: Run `/login` inside pig to sign in with Claude Pro/Max, GitHub Copilot, ChatGPT Plus/Pro (for the `openai-codex` models, e.g. `openai-codex/gpt-6.1-sol` — a browser that comes back to `localhost:1455`, or a device code for a headless machine), or Google Antigravity. Claude offers two ways in, as pi 1.0 does: a browser that comes back to `localhost:53692` (the default), or copying the code off Anthropic's page for a headless machine. Tokens are saved in `~/.pig/agent/auth.json` (or shared with `~/.pi/agent/auth.json`).
- **Resume Sessions**:
  - `pig -c` / `pig --continue`: Instantly resume the most recently modified session in the current directory.
  - `pig -r` / `pig --resume`: Open the interactive session picker — fuzzy words, `"phrases"` or `re:<pattern>`; Tab switches between this folder and all projects, `Ctrl+S` cycles threaded / recent / fuzzy order, `Ctrl+N` shows named sessions only, `Ctrl+D` deletes.
  - `pig --session <id|path>`: Open a specific session by ID prefix or file path; an ID found only in another project is offered as a fork into this one. A resumed session carries on in its own working directory.
  - `pig --fork <id|path>` copies a session into a new one here; `pig --session-id <id>` opens or creates a session with that exact id; `pig --no-session` runs without saving the conversation.
- **Model Selection**:
  - `pig --model sonnet`, `pig --model antigravity/gemini-3.8-flash`, or `pig --provider openai --model gpt-5.5`
  - `pig --model sonnet:high` (sets model and thinking level simultaneously)
  - Press `Ctrl+L` to open the in-session model picker; press `Ctrl+P` / `Shift+Ctrl+P` to cycle models on the fly.
  - `pig --list-models [query]`: View available models with context limits and pricing.
- **Interactive Shortcuts & Mid-Turn Controls**:
  - **Hold `⌘` (Command) for ~0.85s**: Pops up an interactive **Blink Shell / iPadOS style** shortcuts cheat sheet (HUD) in the center of the screen, automatically dismissing when released (supported in both TUI and Web UI).
  - Press **Shift+Enter**: Insert a **newline** in the editor (draft multiline prompts or paste code safely without accidental sending).
  - Press **Enter** or **Command+Enter** (`⌘+Enter` / `Ctrl+Enter`) when idle: Submit message.
  - Press **Command+Enter** (or **Ctrl+Enter**) while the model is answering: **Queue a follow-up** for after the turn ends.
  - Press **Enter** while the model is answering: **Steer** (interrupts right after current tool).
  - Press **Alt+Up**: Restore all queued messages back to the editor.
  - Press **Escape**: Interrupt the active turn or cancel retries.
  - Press **Ctrl+G**: Edit complex prompts in your external editor (supports `"externalEditor": "vim"` in `settings.json`, `$VISUAL` or `$EDITOR`, or auto-fallback to system-available editors).
  - The editor has upstream's editing keys — undo, a kill ring with yank / yank-pop, delete word forward, jump to character — and every key, editing keys included, can be rebound in `~/.pig/agent/keybindings.json` under pi's action ids.
- **Context & Session Commands**:
  - `/name <new-name>`: View or change the active session title (reflected in footer and Web UI).
  - `/label <name>`: Bookmark the current point in the session tree.
  - `/tree`: Visualize conversation branches as an interactive tree and jump between forks.
  - `/fork`: Pick one of your messages and fork the conversation up to before it into a new session file, with that message back in the prompt; `/clone` copies the conversation as it stands into a new file. The new file names the old one as its `parentSession`.
  - `/compact`: Manually trigger conversation summarization.
  - `/continue`: Carry on a run that stopped when pig did (crashed, killed, the machine slept). A conversation opened mid-run says so under it and nothing runs until you ask; a tool call it stopped in is run again only if the tool only reads, otherwise the model is told it was interrupted, with what it had printed. The Web UI shows the same line with a Continue button.
  - `/export [file.html|file.jsonl]`: Export the session as a standalone offline HTML document with syntax highlighting, or the current branch as a session file.
  - `/import <file.jsonl>`: Copy a session file into this project and resume it. `/share`: Upload the HTML export as a secret GitHub gist (needs `gh`).
  - `/scoped-models`: Choose which models `Ctrl+P` cycles through, and save the choice as `enabledModels`.
  - `/reload`: Hot-reload extensions, skills, prompt templates, themes, keybindings, tools, and context files without restarting `pig`. An extension that declares PHP classes keeps running the version loaded at startup (PHP cannot unload a class) and the reload says so when its files changed — restart `pig` to pick that up.
  - `/doctor`: Run system diagnostic checks on PHP extensions, tools, permissions, and network endpoints.

### 2. Web UI Interface (`pig web`)

`pig` includes a native web chat interface matching `pi-web` with workspace management, multi-tab execution, inline session rename/delete, and real-time streaming:

```bash
# Foreground ephemeral server (starts server and opens your default browser)
pig --mode web
# or type /web from inside any interactive terminal session to start and auto-launch browser

# Persistent background daemon (recommended)
pig web start -d              # Start daemon on 127.0.0.1:8088 (or specify --port / --host)
pig web status                # Check status and PID
pig web restart               # Restart daemon
pig web stop                  # Stop daemon gracefully
pig web password              # Set or change the web UI's password

# macOS: run it as a login service, so it is back after a restart
pig web install               # launchd LaunchAgent (--port / --host as above)
pig web uninstall             # remove it
```

`pig web install` writes `~/Library/LaunchAgents/dev.pigagent.web.plist` and loads it: launchd starts the server when you log in, restarts it if it dies, and runs it in the folder you typed `install` in, with that shell's `PATH` and proxy settings. API keys exported in your shell are not copied into the plist — sign in with `/login` or keep them in `auth.json`. While it is installed, `start`, `stop`, `status` and `restart` go through `launchctl`; `stop` lasts until the next login or `pig web start`, and `pig web install --port N` again changes the port.

Open `http://localhost:8088` in your browser or mobile phone:
- **Login**: the web UI runs a terminal and agents on your machine, so it always asks for a password. The first time, `pig web start` prints a one-time setup code; enter it on the page and choose a password (or set one with `pig web password`). Requests from other websites are refused.
- **Interactive Local PTY Terminals**: Press <code>Ctrl+`</code> or <code>Cmd+`</code> (or click the terminal icon) to slide out a full multi-tab terminal drawer powered by `xterm.js` and a native Unix PTY engine, with full support for `vim`, `nvim`, `htop`, `tmux`, and `nano`.
- **SSH Node Workbench & SFTP File Explorer**: Click `🖥️ Nodes` to manage server inventory, verify SHA-256 host key fingerprints, import from `~/.ssh/config`, open remote interactive SSH terminals, and view/edit remote files via SFTP (up to 512 KiB).
- **Auto Browser Launch**: Running `/web` inside an interactive TUI session starts the server and automatically opens your default system browser.
- **Multiplexed Multi-Tab Execution**: Switch between workspaces and tabs without interrupting active runs; a new conversation can start in any folder you pick from its 📁 chip (recent folders, path completion, or folder browser).
- **Native Bilingual Multi-Language Support (中 / EN)**: Click the header `[中 / EN]` button for instant zero-refresh language toggling, auto-adapting to browser language.
- **Translate messages**, as Telegram has it, in the language settings: every answer gets a *Translate* button that shows it in the display language (and *Show original* back), or *Translate entire chats* does it as each answer arrives. Answers already in a *Do not translate* language (the display language unless you pick others) get no button. The session's own model translates, so a translation costs one extra request; questions get the button too; tool output and thinking are left as they are.
- **Claude-style Sidebar**: New session, Search (`⌘K`) and Scheduled at the top; every project's conversations below, grouped by Today / Yesterday / date with their folder beside them; "View all" and Search open one panel over names and contents. Hover (or tap on mobile) to rename (`✏️`) or delete (`🗑️`).
- **Scheduled Tasks**: Laid out like the Claude app's. Click Scheduled in the sidebar for the tasks as cards (search, filter, sort), then *New task* → *Create with pig* (a conversation that asks you what, how often and when) or *Set up manually*: a prompt with one or more triggers — hourly, daily, on weekdays, weekly, once, or a custom cron expression — a permission (*Auto* answers a run's confirmations so it never stops for approval; *Manual* waits for you), a model, and under *Advanced* the folder and thinking level. Each task has a conversation of its own, in the sidebar with a clock from the moment the task is saved — clicking it opens the task's page (history, instructions, schedule, permission, Run now and the on/off switch) — and every run carries on in it — so a run sees what the last one found (busy with your own turn when it comes due, it skips that one); a run that has finished puts a blue dot on its conversation until you open it; a run of a *Manual* task that stops on a hook's question waits for your answer and the sidebar shows a dot. Tasks run while the web server is up — use `pig web start -d` — and a run missed while the machine slept happens once when it wakes, and a run the daemon was in the middle of when it stopped is carried on from its conversation when it starts again (a tool call it stopped in is run again only if the tool only reads — `read`, `grep`, `find`, `ls`; any other is reported to the model as interrupted, with what it had printed, rather than run twice). Stored in `~/.pig/agent/scheduled-tasks.json`. You can also just ask — "every morning at 9, check what changed in owner/repo and tell me whether this checkout needs it" — in the terminal or the page: the `schedule` tool (`builtin:schedule`) shows you the task to confirm — in the page, as a card in the conversation with Cancel (Esc) and Schedule (⌘/Ctrl+Enter) — and asks the running `pig web` to save it.
- **Touchscreen & Mobile Parity**: Responsive layout optimized for smartphones and tablets (iPadOS visualViewport dynamically keeps header and prompt in view).
- **Extension Web Panels & Antigravity Accounts**: Manage Google accounts, token expiration, and view quota meters right from the header (🔑 Accounts); custom extensions can add their own panels via `registerWebPanel()` and users can toggle them between header and sidebar via the 🧩 Extensions menu.

### 3. Non-Interactive CLI & Pipes

```bash
# Print mode: output final answer directly to stdout and exit
pig -p "summarize the architecture of this repo" | pbcopy
pig -p @error.log "what caused this crash?"
git diff | pig -p "review this"            # piped stdin comes first in the message

# Check a provider's credentials without starting a session
pig auth check --provider anthropic

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
| **`pig-antigravity`** | `extensions/pig-antigravity/` | **The whole Antigravity provider** — models, wire protocol, Google sign-in, model routing — registered through `registerProvider()`, as pi's community `pi-antigravity` does since pi 0.71 dropped it from the core. Plus multi-account management, 429 failover to the next account inside the request (as pi-antigravity does), `/antigravity.usage`, `/antigravity.accounts`, the `generate_image` tool, and the Google Search Grounding `google_search` tool (`/antigravity.search`). |
| **`pig-web-search`** | `extensions/pig-web-search/` | Real-time web search (`web_search`), readable article extraction (`fetch_web_page`), headless Chrome DOM rendering (`browse_web_page`), `/search <query>`. |
| **`pig-computer`** | `extensions/pig-computer/` | Anti-detection browser automation (mouse move, click, scroll, typing, screenshots, persistent cookies). |
| **`pig-llama`** · `builtin:llama.cpp` | `extensions/pig-llama/` | The `llama.cpp` provider: models from a running `llama-server` router, `/login llama.cpp` for its URL and key, `/llama` to load, unload and download (Hugging Face search) models. |
| **`pig-codemode`** · `builtin:codemode` | `extensions/pig-codemode/` | Fast multi-tool execution in a sandboxed child PHP process (`open_basedir`, `disable_functions`). |
| **`pig-tool-search`** · `builtin:tool-search` | `extensions/pig-tool-search/` | `tool_search`: BM25 over the tools an extension deferred (MCP's `deferred` exposure), loading the matches. |
| **`pig-mcp`** · `builtin:mcp` | `extensions/pig-mcp/` | Model Context Protocol client for stdio & streamable HTTP servers with dynamic OAuth (`mcp.json`). |
| **`pig-schedule`** · `builtin:schedule` | `extensions/pig-schedule/` | The `schedule` tool: scheduled tasks set up by asking, each change confirmed first, saved by the running `pig web`. pig's own. |
| **`pig-vless`** | `extensions/pig-vless/` | A VLESS inbound beside the agent so a phone (Shadowrocket, v2rayNG) can go through this machine. TCP only, one UUID, TLS when a cert is given. Loading it writes a UUID and listen address into `settings.json`; nothing listens until `/vless start` (`stop`, `restart`, `status`), and `/vless` prints the `vless://` link. |

The five marked `builtin:` are **built in**, as pi's are (`schedule` is pig's own): they load from pig itself on every run, after every other extension, and `pig update` does not copy them anywhere. `-builtin:mcp` (or `!builtin:*`) in the `extensions` setting turns one off, a project's `+`/`-` entry decides over yours, `pig config` lists them under *Built-in*, `-e builtin:<name>` loads one under `--no-extensions`, and `--no-mcp` leaves out `mcp`. `codemode`, `tool-search` and `mcp` step aside, with a warning, for another extension that registers one of their tools or commands. A leftover copy of one of them in `~/.pig/agent/extensions` is not loaded — pig says so; delete it.

### What an extension can do

An extension is a PHP file (or a folder with `index.php`) returning `function (ExtensionApi $pi)`. The API tracks pi's `ExtensionAPI`:

| | |
| :--- | :--- |
| **Bring a provider** | `registerProvider(new Provider(id, name, models, api: StreamApi, oauth: OauthFlow, envKeys, resold, apiKeyAuth: ApiKeyAuth, classifiers))` — the models go into the registry, the protocol behind `Api::Extension`, the sign-in (OAuth or an api-key flow) into `/login`. `unregisterProvider()` takes it back. |
| **Tools, commands, renderers** | `registerTool()`, `removeTools()`, `registerCommand()`, `registerMessageRenderer()`, `registerLocale()`. |
| **Events** | `on('…')` for the session lifecycle, the agent loop, tool calls and results, `context`, and upstream's provider events — `before_provider_request` (replace the payload), `before_provider_headers` (edit the headers in place), `after_provider_response` (status and headers), `provider_stream_event` (each raw stream event) — plus `model_select`, `thinking_level_select`. |
| **Flags** | `registerFlag('name', 'boolean'|'string', description, default)` declares `--name`; `getFlag()` reads it from a handler. |
| **The session** | `getSettings()`, `getModel()`, `setModel()`, `getThinkingLevel()`, `setThinkingLevel()`, `sendUserMessage(text, 'steer'|'followUp')`, `sendMessage()`, `setLabel()`, `getCommands()`, `exec()`. |
| **The web UI** | `registerWebPanel(id, title, render, action, icon, commands, placement, label)` puts a panel in `pig web`'s sidebar or header (the person can move it): `render` returns blocks (text, lists with buttons, meters, cards, lazy sections) and the page draws them in its own theme; a button's `action` gets a `$ui` that asks the person like `$ctx->ui` (`confirm`, `select`, `input`, `notify`) — the Antigravity accounts panel is one. `registerHttpRoute('/api/prefix', fn (path, req) => ['status', 'body'])` answers requests of your own. |
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

`pig` shares configuration and session structures seamlessly with upstream `pi`. The agent directory is `~/.pig/agent`, or `PIG_CODING_AGENT_DIR` when set (pi's `PI_CODING_AGENT_DIR`); environment variables are always the `PIG_*` spelling of pi's `PI_*` ones:

| Path | Purpose |
| :--- | :--- |
| `~/.pig/agent/settings.json` | Global preferences (theme, model, thinking, auto-compact, auto-retry). |
| `~/.pig/agent/auth.json` | Provider API keys and OAuth tokens (shared with `~/.pi/agent/auth.json`). |
| `~/.pig/agent/models.json` | Read after pi's `~/.pi/agent/models.json`, so providers declared for pi work here too; a provider named in both is taken whole from pig's file. Custom OpenAI-compatible endpoints, local models (llama.cpp, vLLM), and overrides for built-in providers (`modelOverrides`). `apiKey` and headers take `$NAME` / `${NAME}` for an environment variable or `!command` for a command's output; anything else, a bare name included, is the literal value. |
| `~/.pig/agent/mcp.json` | MCP server configurations (stdio & HTTP). |
| `~/.pig/agent/trust.json` | Project resource authorization records. |
| `~/.pig/agent/keybindings.json` | Custom keyboard shortcut mappings. |
| `~/.pig/agent/themes/` | Custom JSON themes directory (built-in `dark`, `light`, `labra`, plus any user-defined theme). |
| `~/.pig/agent/prompts/` | Prompt templates: `review.md` becomes `/review`, with `$1`, `$@`, `${1:-default}` and `${@:2}` filled in. |
| `~/.pig/agent/skills/`, `~/.agents/skills/` | Skills (folders with a `SKILL.md`); a trusted project's `.pig/skills` and `.agents/skills` up to the repository root are read too. |
| `~/.pig/agent/SYSTEM.md`, `APPEND_SYSTEM.md` | Replace or extend the system prompt (a trusted project's `.pig/` copy wins); `--system-prompt` / `--append-system-prompt` do it for one run. |
| `~/.pig/agent/sessions/` | Saved session logs in standard `.jsonl` format. |

**Prompt cache warming.** While a long tool runs past the provider's cache lifetime (five minutes on Anthropic), pig re-sends the last request with a one-token cap just before the cache entry expires — only when the expected saving is at least $0.05 — so the next request reads the prompt from the cache instead of paying to write it again. Each refresh is in the session file and in `/session`'s cost. `cacheWarming` in the global `settings.json` (or the *Cache warming* row in `/settings`) is `streaming` by default (while the agent runs), `idle` (between runs too, for up to 30 minutes) or `off`. An extension can overrule each refresh with the `cache_warming_decision` event. `-p` never warms.

**What the cache cost you.** `showCacheMissNotices: true` adds a line when a prompt that should have been read from the cache was billed again (20,000 tokens or ten cents and up), when a refresh warmed it, and when a compaction or branch summary was billed; `/session` shows the total re-billed. Other display settings under pi's names: `quietStartup` (`true` or `header`), `autocompleteMaxVisible`, `doubleEscapeAction` (`tree`, `fork`, `none`), `treeFilterMode`, `markdown.codeBlockIndent`, `terminal.imageWidthCells`, `terminal.showTerminalProgress` (progress in the terminal tab) and `terminal.images` / `trueColor` / `hyperlinks` to override what pig detects.

**Per model, per tool.** `modelThinkingLevels` remembers a thinking level per `provider/id` (also a `/settings` row), `thinkingBudgets` replaces the token budget per level, `enabledModels` is the `--models` scope when none is typed, and `defaultTools` sets the built-in tools (`["read", "bash"]`, or modifiers like `["+grep", "-write"]`; a `-name` also removes a custom tool). `/skill:name args` sends a skill as the message (`enableSkillCommands`). `compaction.modelOverrides` sets the compaction budget per model, `branchSummary.reserveTokens` and `branchSummary.skipPrompt` the branch summary's, and `warnings.anthropicExtraUsage: false` silences the Claude-subscription warning.

**Images, the shell, sessions.** Pictures from `read`, `@file`, a prompt or any tool are fitted inside the provider's limits on the way in (2000×2000, or the model's own), which needs PHP's `gd` extension for anything that has to be shrunk; `images.autoResize: false` sends them as they are and `images.blockImages: true` sends none. `shellCommandPrefix` runs a line before every command (`shopt -s expand_aliases`, say), and `sessionDir`, `PIG_CODING_AGENT_SESSION_DIR` or `--session-dir <dir>` keeps every project's sessions in one directory. `defaultProjectTrust` (`ask`, `always`, `never`) decides a project nothing has decided about yet (and `-a`/`--approve` or `-na`/`--no-approve` decides for one run without saving anything), `collapseChangelog: true` shows one line after an upgrade instead of the release notes, `steeringMode` / `followUpMode` (`one-at-a-time` or `all`) say how queued messages are handed over, `httpProxy` is the proxy when neither `--proxy` nor the environment names one, and `transport` (`auto`, `websocket`, `websocket-cached`, `sse`) says how ChatGPT's Codex backend is reached — over a WebSocket kept for the session by default, SSE when that fails — with `websocketConnectTimeoutMs` for its handshake.

**Diagrams, flags, attribution.** A ```mermaid block in a message is drawn as a Unicode diagram (flowcharts, state, class, ER and sequence diagrams — a pure-PHP port of the renderer pi uses); `markdown.mermaid` is `streaming` (the default), `final` or `off`. Every one of pi's short options works: `-t`/`-xt` choose tools, `-nt`/`--no-tools` starts with none at all and `-nbt`/`--no-builtin-tools` without the built-ins, `-ns`, `-np`, `-nc` and `-ne` leave out skills, prompt templates, `AGENTS.md`/`CLAUDE.md` and extensions, and `-n`/`--name` names the session. `--skill`, `--prompt-template` and `--theme` load a file or directory for one run (each repeatable, as `-e` now is, and `-e` still loads under `-ne`); the theme to start on is `--use-theme <name>`, and `--no-themes` looks for none but the built-ins and `--theme`'s. Skipping the tool files in `~/.pig/agent/tools` and `.pig/tools` is now `--no-tool-files`. Also from pi: `--provider`, `--system-prompt`, `--append-system-prompt`, `--tui-mode regular|fullscreen`, `--verbose`, `--offline` (no startup network activity, as `PIG_OFFLINE=1`) and `--update-check`. `bash` commands see the session as `PIG_SESSION_ID`, `PIG_SESSION_FILE`, `PIG_PROVIDER`, `PIG_MODEL` and `PIG_REASONING_LEVEL`, and on Windows a `powershell` tool can be turned on with `--tools` or `defaultTools`. OpenRouter, NVIDIA and Cloudflare are told pig is calling them; `enableInstallTelemetry: false` or `PIG_TELEMETRY=0` stops that, and pig sends no install report anywhere.

### Signing in

`/login` asks how, as pi's does: **Sign in with an account**, **Sign in with an API key**, or **Sign in with Radius**. The API-key list is every built-in provider (Anthropic, OpenAI, Gemini, DeepSeek, OpenRouter, Groq, Moonshot, Z.AI, …) and every extension's, searchable, each with whether it is configured and from where (`✓ env: OPENAI_API_KEY`, `✓ configured`); the key is kept in `auth.json`. Custom OpenAI-compatible endpoints can be added on the fly via `/login <name>` or `+ Add custom provider...`, which prompts for the base URL and API key and automatically discovers models. `/login <provider>` goes straight to that provider. With no model chosen yet, signing in switches to the provider's default model. Amazon Bedrock, Google Vertex AI and the Cloudflare providers are configured from the environment only.

The account sign-ins — `/login`, or `pig-ai login <provider>` from a shell with no terminal UI — each the way pi does it:

| Provider | How | Notes |
| :--- | :--- | :--- |
| **Anthropic (Claude Pro/Max)** | **Browser** (default): pig listens on `http://localhost:53692/callback`, opens `claude.ai`, and the code comes back by itself. A paste box stays open beside it — if the browser is on another machine, paste the final redirect URL there. **Copy code** (headless): the browser lands on Anthropic's own page showing `code#state`; paste that. | The token goes out as Claude Code's — `claude-cli` user agent, both betas, tool names spelled `Read`/`Bash`/`Edit`/`Write` — because that is the identity Anthropic issued it to. Port 53692 is registered with Anthropic and cannot be changed. |
| **GitHub Copilot** | Device flow: pig shows a code, you type it at `github.com/login/device`, pig polls until it is accepted. Blank at the Enterprise prompt means `github.com`. | Claude's and Grok's models are switched on for the account after signing in. |
| **OpenAI (ChatGPT Plus/Pro)** | **Browser** (default): pig listens on `http://localhost:1455/auth/callback` (on `PIG_OAUTH_CALLBACK_HOST` when set), opens `auth.openai.com`, and the code comes back by itself; a paste box stays open beside it for the redirect URL or the code. When port 1455 is taken (the Codex CLI shares it), the paste box alone finishes the sign-in. **Device code** (headless): pig shows a code to type at `auth.openai.com/codex/device` and polls until it is accepted. | For the `openai-codex` models only — ChatGPT's Codex backend, with the account id read off the token. |
| **Radius** | **Browser** (default): pig listens on `http://127.0.0.1:1456/oauth/callback` and opens the gateway's authorize page. **Device code**: a code to type on another device, polled until it is accepted. | The Radius gateway's public model list; upstream also fetches the account's own list after signing in, which pig does not yet. |
| **Antigravity** (extension) | Browser callback on `localhost:51121/oauth-callback`. The row is there only while `pig-antigravity` is loaded — it is the extension's provider, not the core's. | Needs `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET` in the environment or `antigravity.clientId` / `antigravity.clientSecret` in `settings.json`; pig does not ship them. |

Tokens are renewed automatically when they expire; the file is the same one pi reads, so a sign-in in either tool is a sign-in in both. `/logout` removes a stored sign-in or API key; environment variables and `models.json` are left as they are.

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
