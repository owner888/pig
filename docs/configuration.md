# Configuration

pig supports user-level and project configuration. User-level configuration lives in the agent directory, which defaults to `~/.pig/agent`. Project configuration lives in `.pig` under the working directory and loads after [project trust](/docs/latest/security#understand-project-trust) is granted. The only exception is `sessionDir`, which pig reads before resolving trust so it can locate sessions.

In interactive mode, use `/settings` to change common preferences. For other options, ask pig to update the configuration or edit the relevant files directly. Run `/reload` after manually changing settings, keybindings, instructions, or resources.

## Agent directory

The agent directory is shown as `<agent-dir>` below. Set its location with the `PIG_CODING_AGENT_DIR` environment variable (a leading `~` is expanded). In the [SDK](/docs/latest/sdk), the same variable applies; `Settings::load()` also takes a `home` argument.

| Path | Responsibility |
|---|---|
| `<agent-dir>/settings.json` | User-level [settings](/docs/latest/settings), including preferences, defaults, resource paths, and pig package declarations. |
| `<agent-dir>/keybindings.json` | Custom terminal UI and application [keybindings](/docs/latest/keybindings). |
| `<agent-dir>/mcp.json` | [MCP servers](/docs/latest/mcp) available in every project. |
| `<agent-dir>/models.json` | [Compatible endpoints, models, and model overrides](/docs/latest/models#configure-a-compatible-endpoint). |
| `<agent-dir>/auth.json` | Saved API keys and OAuth credentials. When pi already has `~/.pi/agent/auth.json`, pig uses that file instead, so both tools share rotating tokens. |
| `<agent-dir>/mcp-auth.json` | OAuth credentials of MCP servers. |
| `<agent-dir>/trust.json` | Saved [project trust](/docs/latest/security#understand-project-trust) decisions. |
| `<agent-dir>/sessions/` | Saved sessions, one directory per working directory. pig also lists pi's sessions under `~/.pi/agent/sessions/`. |
| `<agent-dir>/models-store.json` | Model lists fetched from providers that can list their own models, kept for offline startup. |
| `<agent-dir>/git/` | Git [packages](/docs/latest/packages) installed with `pig install`. |
| `<agent-dir>/AGENTS.override.md`, `AGENTS.md`, `AGENTS.MD`, `CLAUDE.md`, or `CLAUDE.MD` | User instructions applied across working directories. |
| `<agent-dir>/SYSTEM.md` | Replaces pig’s default system prompt. |
| `<agent-dir>/APPEND_SYSTEM.md` | Adds instructions to pig’s system prompt. |
| `<agent-dir>/extensions/` | User [extensions](/docs/latest/extensions). |
| `<agent-dir>/skills/` | User [skills](/docs/latest/skills) and supporting files. `~/.agents/skills/` is also read. |
| `<agent-dir>/prompts/` | User [prompt templates](/docs/latest/prompt-templates) exposed as slash commands. |
| `<agent-dir>/themes/` | User [theme](/docs/latest/themes) files. |

## Project `.pig` directory

| Path | Responsibility |
|---|---|
| `.pig/settings.json` | Project-level [settings](/docs/latest/settings), resource paths, and pig package declarations. |
| `.pig/mcp.json` | Project [MCP servers](/docs/latest/mcp). |
| `.pig/SYSTEM.md` | Replaces the system prompt for the project. |
| `.pig/APPEND_SYSTEM.md` | Adds project-specific instructions to the system prompt. |
| `.pig/extensions/` | Project extensions. A top-level `extensions/` folder in the working directory is also loaded. |
| `.pig/skills/` | Project skills and supporting files. |
| `.pig/prompts/` | Project prompt templates exposed as slash commands. |
| `.pig/themes/` | Project theme files. |
| `.pig/git/` | Git packages installed with `pig install -l`. |

Project skills are also read from `.agents/skills/` in the working directory and its ancestors up to the repository root.

For `SYSTEM.md` and `APPEND_SYSTEM.md`, the trusted project file takes precedence over the corresponding agent-directory file. Files with the same name are not combined. A missing or empty file leaves the default in place. `--system-prompt` and `--append-system-prompt` override both; a value that names an existing file is read as that file.

## Context files

Context files are separate from project `.pig` configuration. pig loads them from the agent directory, the working directory, and its parent directories. In each directory it reads one file, the first that exists of `AGENTS.override.md`, `AGENTS.md`, `AGENTS.MD`, `CLAUDE.md`, and `CLAUDE.MD`. A context file applies whenever pig runs in its directory or anywhere below it. In a linked git worktree nested inside the main checkout, the main checkout's files are not loaded.

An `AGENTS.override.md` therefore replaces `AGENTS.md` or `CLAUDE.md` only in the same directory. It does not suppress context files from the agent directory or other directories.

Context-file discovery does not require project trust. `--no-context-files` (`-nc`) turns it off.
