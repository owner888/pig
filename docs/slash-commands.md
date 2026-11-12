# Slash commands

Type `/` in pig's terminal editor to search the commands available in the current session. This page lists the built-in commands in the current pig release.

Extensions, prompt templates, and skills can add commands. The command menu in pig is therefore the exact reference for the resources loaded in your session.

## Models and settings

| Command | Description |
|---|---|
| `/settings` | Open settings |
| `/theme [name]` | Switch theme; without arguments opens a picker that previews each theme, or specify `dark`, `light`, `labra`, or a custom theme name |
| `/model [provider/model]` | Select a model |
| `/thinking [level]` | Set the thinking level |
| `/scoped-models` | Enable or disable models for `Ctrl+P` cycling; `Ctrl+S` saves the selection as `enabledModels` |
| `/login [provider]` | Add provider authentication |
| `/logout` | Remove provider authentication |
| `/skills` | List the skills the model can use and where each came from |
| `/tools` | List the tools the model can call, built-in and loaded |

## Sessions and context

| Command | Description |
|---|---|
| `/new` | Start a new session |
| `/resume` | Switch to another saved session (`Tab` toggles this folder or all, `Ctrl+S` sorts, `Ctrl+N` shows named sessions only, `Ctrl+R` renames) |
| `/name [name]` | Set the session display name, or show the current name when omitted |
| `/session` | Show current session information and statistics |
| `/tree` | Navigate the session tree |
| `/fork` | Create a new session from an earlier user message |
| `/label [name]` | Name the current point so `/tree` can find it; without a name, clears the label |
| `/clone` | Duplicate the current session at its current position |
| `/compact [instructions]` | Compact the current context, optionally with custom instructions |
| `/import <path>` | Import a JSONL session into this project's session directory (after confirmation) and resume it |

## Export and share

| Command | Description |
|---|---|
| `/copy` | Copy the last assistant message |
| `/export [path]` | Export the session as HTML (default) or, with a `.jsonl` path, as JSONL; `/export md` writes Markdown and `/export pr` a pull-request description |
| `/share` | Share the session as HTML: listed credentials redacted if you say so, then kept encrypted on pigagent.dev for 30, 7 or 1 days (the key only in the link), or with `sessionShareDestination: "gist"` a secret GitHub gist via `gh` |
| `/unshare` | Delete one of this machine's shares from pigagent.dev before it expires |
| `/bug [description]` | Prepare a bug report for the pig developers: upload it to `pigagent.dev/api/bug-reports`, or export it as `pig-bug-report-<id>.zip` in the current directory |

Review a session before exporting or sharing it. Sessions can contain prompts, tool arguments, command output, file contents, and credentials exposed during the conversation.

## Runtime and project

| Command | Description |
|---|---|
| `/web [start\|stop\|status\|restart] [port]` | Start, check status, restart, or stop the browser Web UI server & daemon |
| `/doctor` | Inspect system health: PHP runtime, binaries, and auth tokens |
| `/diff [--staged]` | Show git working tree changes |
| `/commit` | Review changes and commit with a Conventional Commits message |
| `/trust` | Save a project trust decision for future pig processes |
| `/reload` | Reload keybindings, extensions, skills, templates, themes, and context files |
| `/help` | Show the keys and commands |
| `/hotkeys` | Show active keyboard shortcuts |
| `/changelog` | Show changelog entries |
| `/quit` | Quit pig (`/exit` also works) |

## Extension Commands

The following commands are registered by extensions. `/mcp` and `/llama` come from built-in extensions; `/vless` is available once `pig update --extensions` has copied `pig-vless` into `~/.pig/agent/extensions/`; the others come from packages, installed with `pig install git:github.com/owner888/<name>`:

| Command | Extension | Description |
|---|---|---|
| `/mcp [login\|logout\|reconnect <server>]` | `builtin:mcp` | Open the MCP server manager, or run one action. See [MCP Servers](/docs/latest/mcp#manage-servers) |
| `/llama` | `builtin:llama.cpp` | Manage models on the configured llama.cpp router |
| `/antigravity.accounts [rotate\|switch <index\|email>\|remove <index\|email>]` | `pig-antigravity` | List, switch, or remove linked Antigravity Google accounts |
| `/antigravity.usage` | `pig-antigravity` | Query account quota and remaining usage |
| `/antigravity.models [all]` | `pig-antigravity` | List Antigravity runtime models and their remaining pool fraction |
| `/antigravity.doctor` | `pig-antigravity` | Check the Antigravity token, project, and accounts |
| `/antigravity.refresh` | `pig-antigravity` | Force refresh and synchronize the remote model catalog |
| `/antigravity.search [--thinking] [--url <url>] <query>` | `pig-antigravity` | Search the web through Google Search grounding |
| `/antigravity.image [--ratio <w:h>] [--model <id>] [--path <file>] <prompt>` | `pig-antigravity` | Generate images (default model `gemini-3.1-flash-image`) and save locally |
| `/gemini-video <path> [prompt]` | `pig-gemini-video` | Have Gemini watch a local video and put its answer into the conversation |
| `/search <query>` | `pig-web-search` | Perform web search via DuckDuckGo |
| `/computer [status\|screenshot\|app <name>]` | `pig-computer` | Inspect and drive the macOS desktop. See [Computer Use](/docs/latest/computer) |
| `/android [status\|connect <ip:port>\|screenshot\|background [off]]` | `pig-android-use` | Inspect and drive an Android device over ADB; `background off` closes the background screen. See [Android Use](/docs/latest/android) |
| `/vless [start\|stop\|status\|restart]` | `pig-vless` | Open, close, restart or inspect the VLESS inbound for a phone; bare `/vless` is `status` with the share link. See [VLESS](/docs/latest/vless) |

## Commands added by resources

- Extensions can register commands with their own arguments and completion behavior.
- Each prompt template is available under its template name.
- Skills are available as `/skill:name` when skill commands are enabled.

Use `/reload` after adding or changing a discovered command resource. See [Extensions](/docs/latest/extensions), [Prompt Templates](/docs/latest/prompt-templates), and [Skills](/docs/latest/skills) for their loading and naming rules.
