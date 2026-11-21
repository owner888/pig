# Web UI Interface

In addition to its fast and responsive terminal TUI, `pig` features a modern, lightweight **Web UI mode** built right in.

Whether you prefer a graphical chat workspace in your desktop browser or need remote collaboration from mobile devices and tablets, Web mode provides a native-grade interactive experience.

---

## Key Features

- **Zero External Runtime Dependencies**: Built entirely on native PHP networking protocols (RFC 6455 WebSocket and HTTP/1.1), ready to run out of the box without any third-party application servers or external frameworks.
- **Native Interactive PTY Terminal Drawer**: Zero-C-extension pseudo-terminal engine integrated with `xterm.js`, accessible anytime via <code>Ctrl+`</code> or <code>Cmd+`</code>, supporting full ncurses tools (`vim`, `nvim`, `nano`, `htop`, `tmux`, `less`).
- **SSH Node Workbench & SFTP Remote File Explorer**: Server inventory, SHA-256 host key fingerprint detection and trust prompts, OpenSSH `~/.ssh/config` discovery, remote PTY terminal streaming (`ssh -tt`), and SFTP remote directory browsing / online file editing (up to 512 KiB).
- **Process-Isolated Model (`SessionPool`)**: Every browser conversation tab runs as an isolated `pig --mode rpc` child subprocess. Multi-turn tasks execute concurrently without blocking each other, and single-session failures never affect other tabs.
- **Single Physical WebSocket Multiplexing**: All tabs in the browser share one physical WebSocket connection tagged with `tabId`, avoiding browser 6-socket connection limits and reverse proxy connection storms.
- **Background Daemon Support (`pig web start -d`)**: Detaches cleanly via double-forking and `posix_setsid()`, keeping the web interface accessible even after closing the terminal or logging out of SSH.
- **Responsive & Mobile Ready**: Seamlessly adapts to desktop monitors, tablets, and phones, automatically switching to a native slide-out hamburger drawer on mobile viewports.
- **Native Bilingual Multi-language Support (中 / EN)**: Built-in internationalization architecture with automatic browser language detection and instant zero-refresh language toggling in the top header.
- **Full Terminal Feature Parity**: Supports built-in slash commands (`/model`, `/tree`, `/name`, `/compact`, `/doctor`, etc.), model thinking block folding, image attachments, and thumbnail previews.

---

## Launching the Web UI

`pig` offers two distinct ways to start the Web UI:

### Option 1: Persistent Background Daemon (Recommended)

When you want the Web UI running continuously in the background as a local or remote service that survives terminal closures, use the daemon management commands:

```bash
# 1. Start in the background as a daemon (default port: 8088 on 127.0.0.1)
pig web start -d

# Custom port and host (e.g. for LAN access)
pig web start -d --port=9000 --host=0.0.0.0

# 2. Check running status, PID, port, and active child processes
pig web status

# 3. Gracefully restart the web service
pig web restart -d

# 4. Stop the daemon and terminate all child session processes
pig web stop

# 5. Set or change an account's password (with no account yet, make the admin)
pig web password <name>

# 6. macOS exclusive: Configure as a login service (launchd LaunchAgent)
pig web install               # Write ~/Library/LaunchAgents/dev.pigagent.web.plist and bootstrap
pig web uninstall             # Unload and remove the login service
```

> **Note**: The daemon mode decouples from the controlling terminal via `pcntl_fork()` and `posix_setsid()`. Closing your terminal window or disconnecting SSH will not interrupt the service. `pig web install` takes this further on macOS by registering a launchd login service that restarts if it dies and survives reboots. While installed, `start`, `stop`, `status`, and `restart` automatically route through `launchctl`.

### Authentication & Cross-Origin Protection

Because the Web UI can launch terminals (PTY) and execute commands on your machine, it is protected by default:
- **Accounts**: You log in with a name and a password. On the first visit, while there is no account, the page asks you to make the admin account (or run `pig web password <name>` on the server). A password set before accounts existed becomes the account `admin`'s.
- **Persistent Sessions**: Login sessions last 30 days and survive daemon restarts; a logout button is accessible from the header and mobile menu.
- **Cross-Origin & CSRF Defense**: Requests from foreign origins are blocked, and WebSocket connections enforce strict Origin verification to prevent rogue websites from secretly dispatching local commands.

### Option 2: Interactive Terminal Control & Preview

While working inside an interactive TUI session, you can manage and inspect the Web UI directly from the prompt:

```text
/web [port]                         # Start in foreground and automatically open default browser
/web restart [--port=8088]          # Restart the Web UI server (smooth reload)
/web status                         # Inspect Web UI running status, PID, and port
/web stop                           # Stop the running Web UI server
```

Running `/web` in your terminal automatically launches your default web browser once the port binds, eliminating the need to copy-paste URLs! Background operations (`/web start -d`, `/web status`, `/web restart`) operate quietly in the terminal without opening unwanted browser windows.

---

## Interface Layout & Usage

```
┌──────────────────────────────────────────────────────────┐
│  [+] [Tab 1: Optimize Query (running)] [Tab 2: Fix Bug]    │ ── Multi-session Tab Bar
├────────────────────────────┬─────────────────────────────┤
│ 📁 Workspace Sessions      │  👤 User: Refactor this class│
│                            │                             │
│ 2026-10-04 Initial setup   │  🤖 Assistant: Sure, here...│ ── Chat Transcript
│ 2026-10-04 Fix injection   │                             │
│                            │  🛠️ bash: git status         │
├────────────────────────────┴─────────────────────────────┤
│ [ 📎 Attach ] [ Type your message, press Enter to send.. ] │ ── Prompt Input
└──────────────────────────────────────────────────────────┘
```

### 1. Multi-Tab Concurrency
- Click **`+`** to open a new conversation tab.
- A new conversation's welcome lists what it loaded, as the terminal does under its header: context files (by path), skills, prompt templates, extensions, and the tools active in it. A built-in extension's tool such as `codemode` appears under tools only when it is active.
- Each tab is an independent `pig --mode rpc` child process. Run extensive code generation in Tab 1 while discussing another task in Tab 2 simultaneously.
- Click **`×`** on any tab to close the conversation and terminate or reap its idle subprocess.

### 2. Session History & Resume
- The left sidebar lists all past sessions for the workspace (loaded from `~/.pig/agent/sessions/` and `~/.pi/agent/sessions/`).
- Clicking any session streams and reconstructs the message tree and token usage in milliseconds.

### 3. Folder Selection & Model Configuration
- **Start in Any Directory**: On an unstarted conversation tab, a folder chip appears above the prompt input (e.g. `📁 /path/to/project`). Clicking it opens a picker with previously used folders, an auto-completing path input, and an interactive folder browser. The session launches in that directory.
- **Model & Thinking Level Switching**: The header displays the active model and reasoning level (e.g. `(antigravity) gemini-3.8-flash • medium`). Click the selector dropdown to switch across any configured provider (Anthropic, Google, Copilot, Antigravity, or custom endpoints).

### 4. Keyboard Shortcuts & Input Experience (100% Aligned with Terminal TUI)
- **`Enter`**: Submit and send the message directly, fully matching terminal TUI behavior (with IME composition guards so candidate confirmation never accidentally submits).
- **`Shift+Enter`**: Insert a newline in the multiline input box with dynamic height expansion for composing code or long prompts.
- **`Command+Enter` (`⌘+Enter`) or `Ctrl+Enter`**: Also submits and sends the message.
- **Hold `⌘` for Shortcuts HUD**: Similar to Blink Shell / iPadOS, holding the `⌘` (Command) key on its own for ~0.85s fades in a centered frosted-glass shortcut cheat sheet, which disappears the moment `⌘` is released.

### 5. Slash Commands
Type `/` in the input field to trigger command autocompletion:
- `/model [name]`: Switch the model
- `/name [title]`: Rename the session
- `/compact`: Compact long context
- `/doctor`: Run system diagnostics

### 6. Internationalization & Language Pack Import/Export (Telegram-Style)
Click the **`[中 / EN]`** button in the top-right header to bring up the centered frosted-glass Language Settings modal:
- **Instant Display Language Switching**: Click any language row to toggle between Simplified Chinese (default), English, or imported/extension locales with zero page reload.
- **📤 Export Current Language Pack (.json)**: Download the active language as a flat JSON file, such as `pig-locale-en.json` (186 keys for the built-in `en` dictionary, plus `label`, `author`, `version`, and `exported_at` metadata), to serve as a translation template or offline backup.
- **📥 Import Custom Language Pack (.json)**: Choose or drag-and-drop any Telegram-style JSON file; the frontend validates and persists it to `localStorage`, applying the new language immediately.
- **Extension Integration**: Any language packs registered by pure PHP extensions via `$pig->registerLocale()` are automatically listed with an `[Extension]` badge.

### 7. Interactive Local PTY Terminal Drawer (`WebTerminal`)
Click the top-right **`Terminal`** button or press <code>Ctrl+`</code> (or <code>Cmd+`</code> on macOS) anywhere on the page to slide out a full-featured multi-tab terminal drawer:
- **True Unix PTY Support**: Driven by PHP's native pseudo-terminal engine with non-blocking micro-batching, providing full support for `vim`, `nvim`, `nano`, `htop`, `tmux`, and `less`.
- **Concurrent Terminals**: Click the **`+`** button on the tab bar to open multiple concurrent terminal tabs with individual renaming and closing.
- **Native Terminal Feel**: Automatic copy-on-select, right-click/hotkey paste, and dynamic resize adaptation (`SIGWINCH`).

### 8. SSH Remote Node Workbench & SFTP File Explorer (`NodeWorkbench`)
Click the top-right **`🖥️ Nodes`** button to open the full-featured SSH and SFTP workbench modal:
- **Node Asset Inventory**: Grouped tree view on the left for managing servers, with search, add, edit, and delete capabilities; passwords and passphrases are stored in encrypted isolation.
- **One-Click `~/.ssh/config` Discovery**: Automatically scans local OpenSSH configurations to import existing production servers and bastion hosts.
- **Host Key Fingerprint Verification (SHA-256)**: Prompts for trust on unknown host keys or key changes to prevent man-in-the-middle (MITM) attacks.
- **Remote SSH Terminal**: Direct interactive terminal streaming via `ssh -tt` in a backend PTY process for remote shell tasks.
- **Online SFTP File Explorer & Editor**: Visual file tree navigation with directory traversal, file viewing, and online editing/saving (capped at 512 KiB per file).

### 9. Scheduled Tasks
Click the **`Scheduled`** button at the top of the left sidebar to open the scheduled task manager:
- **Conversational Task Management (`schedule` tool)**: Beyond the visual panel, you can also manage schedules directly through conversation in the terminal or Web UI (e.g. "every morning at 9, check for upstream changes"). The built-in `schedule` tool (`builtin:schedule`) supports listing, creating, updating, pausing, resuming, and running tasks; any modification prompts for confirmation before taking effect.
- **Dedicated Task Conversations (Claude-style)**: Each scheduled task has its own persistent conversation, appearing in the sidebar with a clock icon the moment it is created. Subsequent runs continue within the same conversation, allowing the model to inspect previous outputs; finished runs display a blue unread dot (cleared upon opening); runs due while you are actively speaking in the conversation are skipped.
- **Flexible Scheduling**: Supports manual-only, hourly, daily, weekdays, weekly, once, or custom 5-field `cron` expressions in the browser's timezone.
- **Human-in-the-Loop Interaction**: If a run encounters a hook question or confirmation prompt, it pauses waiting for input and illuminates a status dot in the sidebar; clicking the conversation allows seamless inspection and answering.
- **Sleep Catch-Up Recovery**: Runs missed while the machine slept or the web daemon was stopped execute once upon waking, preventing burst floods.

---

## Several Accounts, Docker & SSH

With sessions kept in a database (`"storage": "sqlite:pig.db"` or a `mysql:` DSN, see [Keep sessions in a database](/docs/latest/sessions#keep-sessions-in-a-database)), one `pig web` serves several people:

- **Accounts**: the first account is the admin. The admin adds the others under ⋯ → **Accounts**, and can set an account's password, make it an admin, or remove it.
- **Each to their own**: every account sees and opens only its own conversations, scheduled tasks and terminals — the admin cannot see anybody else's either. Conversations from before accounts are the admin's.
- **The server's things are the admin's**: the API keys (everybody's requests are billed to them), the SSH nodes, and the panels and endpoints extensions add to the page (the Antigravity sign-ins, say).
- **Host, Docker or SSH**: each account runs on the host, as the server's own system user, or — the default for new accounts — in a long-lived Docker container of its own, or (2.0.1) as a system user reached over SSH (below). A Docker account's tools, `!commands`, scheduled runs and web terminal all run in its container, in its own directory (`<workspaces>/<name>-<id>`, mounted at the same path inside). Nothing of its projects' `.pig/` is loaded, and the host's files and keys are out of its reach.
- **Folders of the server** (2.0.1): the admin can give a Docker account folders that are already on the server — ⋯ → **Accounts** → **Folders** on its row, each read-write or read-only. They are mounted in its container at the same path, listed in its folder picker beside its own directory, and it can start conversations, run commands and schedule tasks in them. pig's home (`~/.pig/agent`), the accounts' directories and the system's folders (`/etc`, `/usr`, …) cannot be given. After a change the account's container is made again the next time it is needed, which stops whatever was running in it. Files it writes there belong to the server's own system user. On macOS, Docker Desktop must be allowed to share the folder (Settings → Resources → File sharing).

Build the image once on the server:

```bash
docker build -t pig-sandbox "$(composer global config home)/vendor/pigagent/pig/docker/sandbox"
```

and, optionally, say where things go in `~/.pig/agent/settings.json`:

```json
{
  "storage": "sqlite:pig.db",
  "sandbox": { "image": "pig-sandbox", "workspaces": "/srv/pig", "docker": "docker" }
}
```

pig never pulls or builds the image itself. After rebuilding it, remove an account's container (`docker rm -f pig-<id>`) and its next conversation makes it again from the new image; its files stay in its directory.

> Docker keeps people who share a server apart; it shares the host's kernel, so it is not a defence against somebody determined to break out.

### Extensions for Docker and SSH accounts

An extension runs on the server, as its own system user, whichever account the conversation belongs to — its tools, its commands, the MCP servers `pig-mcp` starts. So the conversations of Docker and SSH accounts load only the extensions listed in `sandbox.extensions` in `~/.pig/agent/settings.json`, by name as in `disabledExtensions`. By default that is `pig-schedule` and `pig-tool-search`, which touch nothing but pig's own things:

```json
{
  "sandbox": { "extensions": ["pig-schedule", "pig-tool-search", "pig-antigravity"] }
}
```

Add one only if you are happy for those accounts to use everything it offers: `pig-antigravity` lets them use the Antigravity models (and its commands), `pig-mcp` the MCP servers running on the server, `pig-codemode` PHP scripts run on the server in a hardened `php`.

### SSH accounts

An SSH account (2.0.1) works as a system user reached over SSH — another user on the same server (`127.0.0.1`), whose own permissions then keep people apart without Docker, or another machine, such as a build or GPU box. Its tools, `!commands`, `/diff`, `AGENTS.md`, folder picker, file previews, uploads, scheduled tasks and web terminal all work there, as that user; its conversations, the models and the API keys stay on the pig server.

Under ⋯ → **Accounts**, set the account to **SSH** and open **Connection**:

- **Host** and **Port**, and the **User** there.
- **Private key path**: a key on the pig server (for example `/home/pig/.ssh/bob_ed25519`). Leave it empty to use the server user's own `~/.ssh` and agent. Put its public key in that user's `~/.ssh/authorized_keys` there.
- **Workspace**: the folder there, by its whole path, that its conversations start in. It is made if it does not exist.

**Save and test** connects and makes the workspace, or says what `ssh` said. pig keeps its own `~/.pig/agent/known_hosts`: a machine's host key is learnt the first time and a changed one is refused after (remove its line there if the machine was reinstalled). One connection per machine is shared, so each file the agent reads does not cost a new handshake.

That side needs a POSIX login shell, `bash`, coreutils, util-linux's `setsid`, and `rg` and `fd` (on Debian/Ubuntu: `apt install ripgrep fd-find && ln -s $(which fdfind) ~/.local/bin/fd`).

---

## Production Deployment & Reverse Proxy (Nginx)

When hosting `pig` on a remote server behind an Nginx reverse proxy with SSL, use this configuration:

```nginx
server {
    listen 443 ssl http2;
    server_name pig.your-domain.com;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8088;
        proxy_http_version 1.1;

        # WebSocket upgrade support
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Disable buffering for real-time SSE & WebSocket streaming
        proxy_buffering off;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
```
