# Quickstart

pig runs in your terminal or web browser and works directly with files on your machine. To use it, you need access to a model through a supported provider (subscription, API key, or local model).

For native Windows setup, read [Windows Setup](/docs/latest/windows). For Android, read [Termux Setup](/docs/latest/termux).

---

## 1. System Requirements

- **PHP >= 8.3** (macOS users with Homebrew will be prompted to run `brew install php` automatically if missing; Linux users can install via system package managers such as `apt` or `dnf`)
- Standard PHP extensions: `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pcntl`, `ext-pcre` (optional `ext-posix` for background daemons)
- Operating Systems: macOS, Linux, or Windows (WSL / Git Bash)
- Optional external tools: `fd` and `ripgrep` for high-throughput code search (automatically detected and fetched on demand unless `PIG_OFFLINE` is set)
- Composer (automatically bootstrapped and installed via pure PHP by the quick installer script if not already present on your PATH)

---

## 2. Install pig

### Quick Install (macOS / Linux, Recommended)

Run the official installer script:

```bash
curl -fsSL https://pigagent.dev/install.sh | sh
```

The installer handles the entire setup automatically:
1. **Preflight Environment Checks**: Verifies PHP >= 8.3 and required extensions; on macOS with Homebrew installed, it prompts to install PHP via `brew install php` if missing.
2. **Automatic Composer Bootstrapping**: If `composer` is not found on your PATH, it automatically downloads and configures the latest Composer binary to your local user directory (e.g. `~/.local/bin` or `/usr/local/bin`) via pure PHP.
3. **Global Install & PATH Setup**: Installs `pigagent/pig` globally via Composer and prompts to append Composer's global bin directory to your shell configuration (`~/.zshrc`, `~/.bashrc`, or `config.fish`).

### Or Install via Composer

If you already have a complete PHP and Composer setup, install directly:

```bash
composer global require pigagent/pig
```

Ensure Composer's global bin folder is on your `$PATH`:

```bash
export PATH="$PATH:$HOME/.composer/vendor/bin"
```

Verify your installation:

```bash
pig --version
# Output: pig <version>
```

Keep pig up-to-date anytime by running:

```bash
pig update
```

---

## 3. Start pig

Change to your project folder, then start pig:

```bash
cd /path/to/folder
pig
```

The working folder helps pig discover context files (`CLAUDE.md` / `AGENTS.md`), project Skills, custom tools, and git state.

---

## 4. Choose a Model & Provider

In pig, run:

```text
/login
```

Choose your provider:
- **Anthropic (Claude Pro/Max), GitHub Copilot**: sign in with your subscription through the browser or device flow.
- **Anthropic / OpenAI / Groq and others**: Provide your API key, or export it in your shell environment (e.g. `export ANTHROPIC_API_KEY="sk-..."`).
- **Antigravity**: available once the `pig-antigravity` package is installed (`pig install git:github.com/owner888/pig-antigravity`) and its OAuth client is configured; see [Provider Authentication](/docs/latest/providers#sign-in-with-a-subscription).

Switch active models anytime with `/model`.

---

## 5. Web UI Mode

If you prefer a browser chat interface with concurrent multi-session tabs:

```bash
# Start as a persistent background daemon
pig web start -d

# Open in your browser
open http://127.0.0.1:8088
```

Or type `/web` inside any active terminal session to launch a temporary browser UI.

---

## 6. Run Tasks

pig streams actions, file reads, diff edits, searches, and bash executions in real-time:

```text
Summarize @notes.txt and save the action items to action-items.md.
```

```text
Explain how this project is structured and run its unit tests.
```

---

## Common Commands & Shortcuts

- `Esc`: Abort current running turn or tool call.
- `Ctrl+O`: Fold or expand tool executions and verbose diffs.
- `Ctrl+T`: Toggle thinking blocks visibility.
- `/compact`: Compact session context into a summary.
- `/tree`: Browse and restore conversation branch tree.
- `/web`: Launch the multi-session Web UI.
- `/doctor`: Inspect the PHP runtime, external binaries, and auth tokens.
- `/help`: Show full command and hotkey list.
- `/quit` or `/exit`: Exit pig cleanly.
