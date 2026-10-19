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

## [0.6.86] - 2026-10-18

### Fixed

- **Guaranteed profile persistence in `install.sh`**:
  - Always ensures that `pig` (and bootstrapped Composer if applicable) is permanently appended to the user's shell profile (`.profile` or `.zprofile`), even when symlinking into an existing system bin directory succeeds.
  - Automatically creates `.profile` if none exists, appends clean `$HOME`-relative export lines with dedicated labels (`# pig` and `# composer`), and immediately sources the file for the current session.

## [0.6.85] - 2026-10-18

### Changed

- **Clean `$HOME`-relative PATH export and `~/.zprofile` detection in `install.sh`**:
  - Automatically formats the appended PATH export using portable `$HOME` variables (e.g. `export PATH="$HOME/.composer/vendor/bin:$PATH"` or `$HOME/.config/composer/vendor/bin`) instead of hardcoded absolute paths, matching standard user profile style.
  - Added native detection for `~/.zprofile` under zsh shells.
  - Enhanced duplicate path checking to recognize both absolute and `$HOME`-prefixed lines.

## [0.6.84] - 2026-10-18

### Fixed

- **Unified system bin symlinking for Composer in `install.sh`**:
  - Automatically prioritizes default system PATH directories (such as `/data/data/com.termux/files/usr/bin` in Termux, or `/usr/local/bin`) when bootstrapping Composer.
  - Symmetrically links `composer` into the system PATH via a unified `link_binary_to_path` helper, ensuring `which composer` resolves directly and consistently alongside `which pig`.

## [0.6.83] - 2026-10-18

### Fixed

- **Persistent command resolution after quitting initial session in `install.sh`**:
  - Dynamically searches and resolves the concrete `pig` binary across all Composer vendor paths (`~/.config/composer/vendor/bin`, `~/.composer/vendor/bin`, and Android Termux home paths).
  - Robustly links `pig` directly into default system PATH directories (such as `/data/data/com.termux/files/usr/bin` in Termux, `/usr/local/bin`, or `~/.local/bin`), preventing `No command pig found` after exiting the initial interactive launch (`/quit`).
  - Enhanced `installed_pig_is_first_on_path` to resolve symlink targets against the installed binary.

## [0.6.82] - 2026-10-18

### Fixed

- **Clean single-line profile targeting in `install.sh`**:
  - Respects existing `.profile` files and never creates an unnecessary `.bashrc` when one does not already exist.
  - Appends an independent, cleanly formatted `# pig` export line without touching, modifying, or merging with pre-existing configuration lines.
  - Automatically sources the modified profile file immediately upon appending.

## [0.6.81] - 2026-10-18

### Fixed

- **Instant PATH symlinking and dual profile targeting in `install.sh`**:
  - Automatically symlinks `pig` directly into writable directories already on `PATH` (such as `$PREFIX/bin` in Termux, `/usr/local/bin`, or `~/.local/bin`), enabling immediate `pig` command execution out of the box.
  - For bash environments, profile updates now dual-target both `~/.bashrc` (for interactive non-login terminal windows) and `~/.profile` (for login sessions), properly tagging `# pig & composer global bin` so both `composer` and `pig` are always available.
  - Added robust directory fallback in `composer_bin_dir` across `~/.config/composer/vendor/bin` and `~/.composer/vendor/bin`.

## [0.6.80] - 2026-10-18

### Changed

- **Installer greeting banner in `install.sh`**: Updated the header tagline to `pig — PHP Ai Agent`.

## [0.6.79] - 2026-10-18

### Fixed

- **Termux PATH configuration and default shell profile in `install.sh`**: Fixed an issue where Android Termux defaulted to writing PATH exports to `~/.profile` when `~/.bashrc` did not yet exist, leaving Termux's interactive non-login bash shells unable to find `pig`. The installer now reliably detects Termux environments (`TERMUX_VERSION`, `/data/data/com.termux`, `$PREFIX`) and routes shell profile updates directly to `~/.bashrc`.
- **Immediate session sourcing in `install.sh`**: Automatically sources the target shell configuration file (`. "$config_file"`) and exports PATH variables immediately upon appending, making binaries visible to the current installer process and prompting users with the exact `source <profile>` command for their current terminal window.

## [0.6.78] - 2026-10-18

### New Features

- `/login` signs in the way pi's does: it asks **Sign in with an account**, **Sign in with an API key**, or **Sign in with Radius**, then lists the providers for that kind in a searchable list with each one's status (`✓ configured`, `✓ env: OPENAI_API_KEY`, `• not configured`).
  - **API keys for every built-in provider** — Anthropic, OpenAI, Gemini, DeepSeek, OpenRouter, Groq, Mistral, xAI, Moonshot, Z.AI, MiniMax, Qwen, Xiaomi and the rest — prompted for and kept in `auth.json`, beside the extensions' api-key sign-ins. Amazon Bedrock, Google Vertex AI and the Cloudflare providers say they are configured from the environment.
  - **Radius**: the Radius gateway's OAuth sign-in, by browser (loopback on `127.0.0.1:1456`) or device code.
  - With no model chosen yet, a sign-in switches to the provider's default model (upstream's `defaultModelPerProvider`) and says which.
  - `/login <provider>` starts that provider's sign-in; a cancelled sign-in goes back to the list it was chosen from.
- `/logout` lists what `/login` stored — sign-ins and API keys — and says `Logged out of <name>` or `Removed stored API key for <name>`.

## [0.6.77] - 2026-10-18

### Added

- **Automatic pure PHP Composer bootstrapping in `install.sh`**: When preflight checks confirm PHP >= 8.3 and its required extensions, the installer automatically checks whether `composer` is available on `PATH`. If missing, it downloads and configures the official Composer binary to a writable directory (`/usr/local/bin`, `~/.local/bin`, or `~/bin`) via pure PHP with automatic fallback, requiring zero manual setup.
- **macOS Homebrew PHP installation prompt in `install.sh`**: If PHP is missing or below 8.3 on macOS and Homebrew is detected, the installer interactively prompts via `/dev/tty` to execute `brew install php`, automatically configures `PATH`, and continues the preflight check.
- **Targeted PHP installation guidance for Linux distributions**: Automatically identifies the Linux distro (Ubuntu/Debian, Fedora/RHEL, Arch Linux, Alpine) when PHP is missing or incompatible, printing exact copy-paste installation commands.

### Changed

- **Multi-directory shell PATH configuration in `install.sh`**: When Composer is bootstrapped into a local user directory not yet on `PATH`, the profile prompt batches both the Composer directory and Composer's global bin directory into your shell configuration (`~/.zshrc`, `~/.bashrc`, or `config.fish`).
- **Updated documentation and guides**: Aligned `README.md`, `README.zh-CN.md`, and the official site documentation (`quickstart.md` in English and Simplified Chinese) with the new automated Composer bootstrap and macOS PHP setup workflows.

## [0.6.76] - 2026-10-16

### Changed

- The web page's tool cards, messages and footer now say what the TUI says since 0.6.75, which is what upstream pi says:
  - **Tool card titles**: `[skill] deploy` and `read resource AGENTS.md` for a skill or an AGENTS/CLAUDE file, `read a.php:5-29` for a range; `grep /x/ in src (glob) limit N`, `find x in . (limit N)`, `ls . (limit N)`; a tool with no title of its own shows `name key=value …`, cut to 100 characters.
  - **Warning lines** under a card, in the warning colour and visible with the card collapsed: bash's `[Full output: <path>. Truncated: showing X of Y lines]` (the tool's own footer leaves the output), read's `[Truncated: …]`, and ls/find/grep's `[Truncated: 200 entries limit, …]`. The `(exit N)` / cancelled notes are gone — the output already says them.
  - **write**: no `\r` or trailing blank lines; a failure is shown in red under the content; content that is not a string says `[invalid content arg - expected string]`.
  - **codemode**: its own card — the script, each nested call with its status, time and cost, `Model calls: $…` when more than one was priced, then the output without the `Script completed` header, red when the run failed.
  - **Messages**: `[branch]` / `Branch summary`; bold labels on compaction, branch, skill and hook blocks; a hook's message is shown whole rather than folded; a response cut short by the token limit says `Response was truncated before completion.`
  - **Footer**: the extensions' statuses are sorted by key and joined by a space.

## [0.6.75] - 2026-10-16

### Changed

- The TUI now follows the latest upstream pi rather than a pinned commit (`AGENTS.md`, `docs/dev/upstream.md`), and every place it drew a message or a tool call differently has been brought in line:
  - **Tool calls**: a collapsed `read` is its heading alone — a skill, an `AGENTS.md`/`CLAUDE.md` or one of pig's own docs as one line (`[skill] deploy (ctrl+o to expand)`, `read resource AGENTS.md`); `edit` no longer appends `:line`; `write` trims trailing blank lines and says `N more lines, T total`; `grep` writes ` limit N`; ls, find and grep add `[Truncated: …]` for the limits they hit; a tool with no view of its own shows `name key=value …` collapsed and a `key: value` line each expanded, its result ten lines under it.
  - **Hints**: every `... (N more lines, ctrl+o to expand)` / `(N earlier lines, …)` names the expand key, muted with the key dim.
  - **bash**: ` (timeout 30s)` on the title; the tool's own `[… Full output: …]` footer becomes one warning line, `[Full output: <path>. Truncated: showing X of Y lines]`; the `(exit N)` / cancelled status is gone, since the output already says it; `Took 0.4s` has a blank line above it.
  - **Pictures**: a blank line above each; when they cannot or should not be drawn, the label is a line of the result's text.
  - **Messages**: the compaction and branch summary blocks have a bold label, `Compacted from N tokens (ctrl+o to expand)` / `Branch summary (…)`, and expand to the summary under a bold header; a hook's message has a bold label, is not folded, and one a hook draws itself gets a blank line above it; an expanded skill block has no extra blank line under its label; a run of thinking blocks is one thought, with a blank line under it only when something follows; a response cut short by the token limit says `Response was truncated before completion.`
  - **Status**: the loader says `Working`, with no running timer; footer statuses are sorted by key and joined by a space, and a long path is cut at the end.
  - **codemode**: expanded shows the whole script; each model call shows its cost and more than one adds `Model calls: $…`; a failed run's output is red; collapsed names the full-output file; `Took 1.2s` under the result.
- Custom tool renderers: `renderCall` is given a `RenderOptions` as its third argument, and `RenderOptions` carries `isError` and `timer` (`Elapsed 1.2s` / `Took 1.2s`). A `renderResult` body is now drawn straight under the heading, as upstream adds it — a renderer that wants a blank line above draws one.

## [0.6.74] - 2026-10-15

### Fixed

- The scheduled-task pages hid the header's left half and the tab bar, so the header's buttons slid to the left and the top of the page changed from one view to the next. A page now takes the conversation's place only; the header and the tab bar are the same on every page, and `docs/dev/design/web-ui.md` says so.

## [0.6.73] - 2026-10-15

### Added

- Size tokens for the web page — text, control heights, corners, spacing, icons (`--text-*`, `--control-*`, `--radius-*`, `--space-*`, `--icon-*` in `style.css`), taken from what the page already used most, with the rules in `docs/dev/design/web-ui.md`. Every font size and corner on the page uses them now — the stylesheet's and the scripts' inline styles alike — and `WebStyleTokensTest` fails on one written in px. The odd sizes in between went to the nearest token (9–10.5px labels to 11px, 12.5px to 12px, ×/+ glyphs to 16px, 10–14px corners to 8 or 12px).

### Changed

- The scheduled-task pages, form and confirmation card are redrawn on those tokens: 13px text, 32px buttons, 6–8px corners, as the rest of the page — they had been drawn larger than anything around them.

### Fixed

- `WebMessageBlocksTest` failed with `needsTranslation is not defined` since messages got a Translate button: the fixture runs the page's own message renderer and now hands it the page's own translation helpers.

## [0.6.72] - 2026-10-15

### Added

- Translate messages in the Web UI, as Telegram has it, under the language settings: a *Translate* button on every answer (and *Show original* back), *Translate entire chats* to press it as each answer arrives, and *Do not translate* for the languages you read — an answer whose script says it is in one gets no button. The session's model is the translator (RPC `translate {text, language, doNotTranslate}`, answered `text: null` for one already in your language); questions get the button too; tool output and thinking are not translated.

### Changed

- The web UI's scheduled tasks are laid out as the Claude app's: a page in the main area with the tasks as cards (search, filter, sort) and a *New task* menu — *Create with pig* opens a conversation with the request in the prompt, *Set up manually* opens the form; a task's conversation in the sidebar opens the task's page (history; instructions, schedule and permission; Run now and the on/off switch; edit and delete under "⋯"), with its clock at the end of the row; the form is a dialog with the permission and model in a bar under the instructions, a row per trigger and *Advanced* settings for the folder and thinking level; times are shown in the viewer's timezone.
- A `schedule` call's confirmation in the web UI is a card in the conversation, under the call — "pig wants to create a scheduled task", the arguments, then task, schedule, where it runs, source and instructions — answered with Cancel (Esc) or Schedule (⌘/Ctrl+Enter), rather than a dialog over the page.

### Added

- A scheduled task can have more than one trigger (the form's "+"); it runs at the soonest, and a one-time trigger is dropped once it fires. `triggers` in `/api/scheduled/save`; a task written with one `schedule` reads as one trigger.
- A permission per scheduled task: *Auto* answers a run's confirmations yes so it does not stop for approval, *Manual* waits for you. New tasks — from the form or the `schedule` tool (`permission`) — default to *Auto*, as the Claude app's do; tasks saved before stay *Manual*, which is how they ran.

## [0.6.71] - 2026-10-15

### Added

- Prompt history in the Web UI, as the TUI has it: Up on the first line of the input (empty, caret at its start, or already browsing) brings back the prompts sent before, newest first, and Down on the last line goes forward again and past the newest returns what was being typed. Per tab, 100 kept, filled from the conversation when the tab loads it.

### Fixed

- Up in the TUI brought back nothing after `--resume`, `/resume` or a session switch: the editor's history was only filled by prompts typed in this run. It is filled from the session's user messages when the conversation is drawn, as upstream's `renderInitialMessages()` does.

## [0.6.70] - 2026-10-14

### Added

- RPC `prompt` takes a `requestId`: sent again under an id the conversation already took, it is answered `{disposition: "duplicate"}` and not asked twice — kept as a `pig.request` custom entry, so it holds across a restart. The Web UI sends one, and when a send's answer is lost (a dropped connection, the agent restarting) and you press send again, it is the same request.

### Fixed

- In the Web UI, a new conversation whose connection dropped before it was saved came back as another new conversation, while the first went on answering where it could not be seen; the page now finds its own conversation again after a reconnect.

## [0.6.69] - 2026-10-14

### Added

- `/continue` carries on a run that stopped when pig did. A conversation opened mid-run — with `-c`, `--resume` or `/resume` — says so under the transcript (`This conversation stopped in the middle of a run. /continue carries it on.`), and nothing runs until you ask. The Web UI shows the same line with a Continue button; `get_state` reports it as `isStoppedMidRun`.

### Fixed

- A tool call carried on after a stop is answered in the row the transcript already drew, in the terminal and the Web UI, instead of a second row while the first one kept saying it was running.

## [0.6.68] - 2026-10-14

### Changed

- A tool call a stopped run left half done is answered with what it had printed before the notice that it was interrupted, so the model can see how far a build or a script got. The running tool's output is kept beside the session file as `<session>.running.json` while it runs — the tail a result would show, rewritten at most ten times a second and slower for large output, readable by you alone — and removed when its result is written or the session is deleted.

## [0.6.67] - 2026-10-14

### Changed

- A run that stopped inside a tool call is carried on rather than refused (`continueRun()`, which the web daemon's scheduled runs use): a call that may have been running is run again if its tool only reads (`read`, `grep`, `find`, `ls`), and otherwise answered `Tool <name> was interrupted and may have partially run` for the model to decide — pi-durable's replay policy. The calls after it, which never started, run as they would have. An extension tool opts in with `CustomTool(replay: ToolReplay::Safe)`.

### Fixed

- A run stopped between two of a turn's tool calls was carried on with the rest answered `No result provided`; they are run now.

## [0.6.66] - 2026-10-14

### Changed

- The startup header is upstream's as its terminals without the logo draw it: the pixel pig is gone, the first line reads `Pig vX` with the wordmark in pink, and the key hints below it have the key dim and what it does muted.
- `[Extensions]` no longer names the built-ins (`pig-codemode`, `pig-llama`, `pig-mcp`, `pig-schedule`, `pig-tool-search`) — upstream marks its built-ins `hidden` — and `[Tools]` names only tools loaded from tool folders, not the tools an extension registered, as upstream lists no tools at all.

### Fixed

- pi failed every start with `models.json error: Availability refresh: ENOTDIR: not a directory, rmdir '~/.pi/agent/auth.json.lock'` once pig had renewed a token: pig took its `flock()` on a plain file named `auth.json.lock` in pi's folder, which is the name `proper-lockfile` gives its lock *directory*. pig's lock is `auth.json.flock` now (and `mcp-auth-refresh-<hash>.flock` for MCP tokens), and a `.lock` file an older pig left is removed when pig starts; a `.lock` directory, pi's own, is left alone.

## [0.6.65] - 2026-10-14

### Changed

- `AGENTS.md` carries a hard rule at its top — it stays under 200 lines, everything else goes into `docs/dev/` — and `AgentsFileTest` fails the suite when it does not.

## [0.6.64] - 2026-10-14

### Changed

- The repository's agent instructions are `AGENTS.md` now rather than `CLAUDE.md`: pig and pi read either, and Claude Code reads `AGENTS.md` when there is no `CLAUDE.md`. The comments that named the file, and the headers of `docs/dev/`, say so.

## [0.6.63] - 2026-10-14

### Changed

- `CLAUDE.md` is down from 13,658 lines (about 280k tokens, sent with every request in this repository) to 145. The rest moved, unedited, to `docs/dev/`: `decisions.md`, `upstream.md`, and per area `design/<area>.md` and `traps/<area>.md`, which `CLAUDE.md` indexes. Source comments that pointed at `CLAUDE.md` point at the file the entry is in now.
- A context overflow whose system prompt alone is past the model's window — a project's context files grown beyond a smaller model — is no longer compacted and retried, which could not help. pig says so instead: how large the prompt is, the model's window, the largest context files by size, and that `--no-context-files` (`-nc`) leaves them out.

## [0.6.62] - 2026-10-14

### Fixed

- An Ollama (MLX) model's "input length (N tokens) exceeds the model's maximum context length (M tokens)" is recognised as a context overflow, so the conversation is compacted and the turn retried instead of stopping on the error. The pattern only knew the form without "tokens" inside the parentheses.

## [0.6.61] - 2026-10-14

### Changed

- Web: a message sent while the agent is working waits above the input as `Steering: …` or `Follow-up: …`, as in the TUI, and joins the conversation when it is delivered rather than when it was typed. "↳ Edit queued messages" puts everything queued back into the input without stopping the turn, and Stop does the same before it stops.

## [0.6.60] - 2026-10-14

### Fixed

- `auth.json` is written to a file beside it and renamed into place, so another pig reading it — every web conversation does before each prompt — never finds it half-written. Reading it in that moment emptied every credential in the reader, and the reader's next save could write that back over the file. A symlinked `auth.json` is written where it points and stays a link.

## [0.6.59] - 2026-10-14

### Added

- Web: API keys can be set from the page. ⋯ → API Keys lists every provider that takes a key, shows where each one's key comes from (saved, the environment, or a subscription signed in from the terminal), and stores, replaces or removes a key in `auth.json`. When no model has a key, the page opens it by itself.

### Changed

- `--mode rpc` reads `auth.json` again before each prompt, so a key stored by another process — the web page's key panel, or `/login` in a terminal — is used by a conversation that was already open.

## [0.6.58] - 2026-10-14

### Changed

- Model registry regenerated from models.dev: Gemini 4 Argon across Google, Vertex, Copilot, OpenRouter, Vercel and OpenCode; Claude 5.5 and GPT-6/5.6 rows now come from the catalogue instead of being hand-written; retired OpenRouter and Vercel Qwen 3 models are gone.

## [0.6.57] - 2026-10-14

### Changed

- Antigravity: the shipped model list is regenerated from the deployment's catalogue. Claude Opus 5.5 and Claude Sonnet 5.5 are new; Gemini 3.7 Flash, Gemini 3.6 Flash, Claude Opus 4.6, Claude Sonnet 4.6 and Gemini 3.1 Pro are gone, because the deployment no longer serves them. Eleven models, down from fourteen.
- Antigravity: a 404 on Gemini 3.8 Flash is reported instead of being retried on 3.7, which the deployment no longer serves. The fallback chain is upstream's and comes back by itself when a catalogue names 3.7 again.

### Fixed

- `scripts/fetch-antigravity-models.php` works again. It called a settings method that had been renamed, loaded the installed copy of the extension beside the checkout's one, and printed no model rows for the endpoint's own reply. It now groups that reply the way the extension does at refresh, names each model from its id, and prints the six-column rows `Models.php` holds.

## [0.6.56] - 2026-10-14

### Changed

- Web: a conversation's history no longer carries its images. They come by reference and are fetched one at a time as they scroll into view (`<img loading="lazy">` from `/api/session-image`), so a history of screenshots — computer use, Android use — costs a page only its text and the images it shows, instead of megabytes for every part it loads. Images shown live, as a turn runs, are as before.

### Added

- `--mode rpc`: `get_messages` takes `images: "refs"`, to give each image as `{type: "image", mimeType, ref}` without its data, and `get_image {ref}` answers one as `{mimeType, data}`. `ref` is the message's index and the path to the image in it (`12.content.3`). Without it, images are inline as upstream's.

## [0.6.55] - 2026-10-14

### Fixed

- `pig web` no longer runs out of memory while a command streams a lot of output — a build log, say — to a page across a slow link. A running command told the screen its output's tail (up to 50 KB) on every read, many times a second, megabytes a second of the same text; the page's send buffer passed its limit again and again (`closing a connection: send buffer past 160 MB` in the log), and the queue to a session that had stopped reading grew until the server died (`Allowed memory size … exhausted` in `RpcClient`). The live output is now updated at most ten times a second, its last state always shown. A page that falls behind is sent only the newest of a running command's updates when it catches up, not every one in between. A page now loads a background tab's conversation when the tab is opened, not every tab's at once after a reconnect. Both write queues — to a page and to a session — drain from an offset instead of copying what is left after every write, which was quadratic and pinned the CPU. With that, the limits are brakes rather than room: a page's queue is closed at 8 MB when it has read nothing for 15 s and at 32 MB regardless (were 32 and 160), and a session's input refuses more past 8 MB queued; one message bigger than a limit still goes out whole when nothing is queued before it.

## [0.6.54] - 2026-10-13

### Fixed

- `pig web` over a tunnel (Tailscale and other `utun` interfaces on macOS): a page with a long conversation no longer reconnects every few seconds. macOS answers a write it cannot queue yet with `ENOBUFS`, which PHP does not treat as "try again" as it does `EAGAIN`, so the write failed and the page's socket was closed as soon as megabytes went out — and the page asked for them again on reconnect. A full queue is now waited out (20 ms, then write on), and frames go out 256 KB at a time.
- `pig web` logs why it closed a connection when the choice was its own — write failed (and the system's words), the peer read nothing for 15 s, the send buffer past its limit, no pong for a minute, an unreadable frame — so a page that keeps reconnecting can be explained from the server's log.

## [0.6.53] - 2026-10-13

### Fixed

- `pig web` no longer dies of memory, and the page no longer reconnects over and over, when a very long conversation is open — one with many screenshots from computer or Android use is hundreds of megabytes. The page asked the session for the whole conversation in one `get_messages`, and the server, decoding that line and encoding it again for the page, ran out of memory (`Allowed memory size … exhausted in TcpConnection::sendRaw()`), restarted, and was asked again. The page now asks for the end only — about 180 messages — and for the parts before it as you scroll up.

### Added

- `--mode rpc`: `get_messages` takes an optional `limit`, and `before`, to answer only the end of the conversation, or the part before an index; the part starts at a user message, and the answer says where it sits (`total`, `start`). Without `limit` it answers everything, as upstream's.

## [0.6.52] - 2026-10-13

### Fixed

- `pig web` with a long conversation open: the page no longer reconnects over and over, and no longer shows `Future::await() must be called inside a coroutine` with `get_messages: no answer in 30s`. Two causes, both met by a page whose tabs each load a large conversation at once. The send buffer's 32 MB limit (0.6.44) closed a peer that was reading, just slowly — over a VPN, say — so the page reconnected, asked for the same again and was closed again; it now closes only a peer that has read nothing for 15 seconds, with 160 MB as the line memory draws regardless. And a command relayed to a session's child while the child was busy writing back waited for its input pipe to have room, which needs a fiber the socket handler does not have; it is now queued and sent, in order, as the pipe drains.

## [0.6.51] - 2026-10-13

### Added

- Extensions: `$ctx->ui->systemNotify($title, $body)` tells the person outside the window, and pig picks how. In the terminal, a desktop notification (`osascript`, `notify-send`), or over SSH the terminal's own (OSC 777, shown by Ghostty, iTerm2 and WezTerm) and a bell — the notifier starts without being waited for. Behind `pig web`, the page's: when you are looking elsewhere (another window, another app, a background tab) its title gains `(n) ✓`, its icon a green dot, a short chime plays, all cleared when you come back, and where the browser allows it (`localhost` or https, and you said yes when asked, on your first message) a system notification that a click brings the conversation back from — over plain `http://` on a LAN address browsers offer none. A host over `--mode rpc` gets `extension_ui_request {method: "systemNotify", title, body}`; print, json and mcp modes have nowhere to send it. pig's own, as `registerWebPanel()`; pi's extensions have no such call.
- `examples/extensions/system-notify.php`: copy it to `~/.pig/agent/extensions/` to be told when a piece of work is done — once, when the turn settles, through `systemNotify()`, so the same file works in the terminal and the page.

## [0.6.50] - 2026-10-13

### Fixed

- `test/lint.php` finds every `@` error suppression. It looked for them with a regex after stripping strings and comments, and the nested quotes of a PowerShell command in `SessionList.php` paired up wrongly and hid the `@proc_open` below them. It now reads PHP's own tokens. That `@proc_open` (moving a session to the trash) is a `set_error_handler` around the call, as everywhere else.

## [0.6.49] - 2026-10-13

### Added

- Web: Enter with nothing focused puts the cursor in the prompt, as in Telegram — after reading, clicking in the conversation or switching tabs, the next Enter takes you back to typing. A focused button, link or field keeps its Enter, and nothing happens while a dialog, menu, image or the terminal is open.

## [0.6.48] - 2026-10-13

### Fixed

- Web: picking a command from the `/` list with Enter no longer sends what was typed first — `/di`, then Enter on `/diff`, sent `/di` and left `/diff` in the prompt to be sent again. The list now has its keys before the prompt does, and Enter on a command completes it and sends it in one press, as in the TUI; Tab (or a click) only completes it.

## [0.6.47] - 2026-10-13

### Changed

- `pig web` closes a WebSocket that has been silent for two of its pings (about a minute) — an iPad with its lid shut, a tab iOS has suspended. It pinged every 25 seconds but never checked for the pong, so a gone peer stayed bound to its session, with events piling up behind it, until TCP gave up many minutes later. The page reconnects when it wakes and loads the conversation again.

### Removed

- The socket protocol's own `{"type":"ping"}` / `pong` messages. The page never sent them; keep-alive is the WebSocket protocol's ping and pong, which a browser answers by itself.

## [0.6.46] - 2026-10-13

### Fixed

- Web: underscores in names survive Markdown — `set_error_handler` was drawn as "set", an italic "error" and "handler", underscores gone, even inside a code span. `_` now emphasises only at a word's edge, as CommonMark has it, and code spans are left alone.

## [0.6.45] - 2026-10-13

### Changed

- Web: a long conversation opens as fast as a short one. The last 60 or so messages are drawn at once, about 120 more before them while the page is idle, and the rest only as you scroll up — the next part is drawn whenever you come within two screens of the top, before you reach it, so scrolling stays smooth and a conversation nobody scrolls back through does not cost its whole history in the page. Your place is kept as parts arrive. Parts are cut at a user message, so a tool call and its result are drawn together.

### Fixed

- Web: scrolling a long conversation no longer stutters. The messages had `content-visibility: auto` (0.6.40), so each block — a bash card's output, a highlighted code block — was only drawn as it scrolled into view, and its guessed height was corrected as it was. Removed; switching tabs stays fast, which came from keeping a background tab's layout (`visibility` instead of `display: none`).

## [0.6.44] - 2026-10-13

### Fixed

- `pig web` no longer dies with "Allowed memory size exhausted" in `TcpConnection::sendRaw()` when a browser tab stops reading (an iPad tab in the background, a laptop asleep). Every event for that tab was appended to its send buffer without limit until PHP's memory ran out, taking the server and every other tab down with it. Past 32 MB waiting the connection is closed instead, and the page reconnects and reloads the conversation when it reads again.

## [0.6.43] - 2026-10-13

### Fixed

- Web: on an iPad with a hardware keyboard the page no longer twitches when the prompt is focused or while typing. Safari keeps a focused field 40px clear of the floating input bar: closer, it panned the whole page up as the bar came up and back a frame later, and scrolled it on every keystroke. The prompt is now raised by what that takes, measured the first time the bar comes up and kept in this browser, so after that focusing moves nothing. The page also no longer takes the shrunk `innerHeight` Safari reports for a frame as the bar comes up, nor shrinks for the bar's in-between heights; everything short of an on-screen keyboard keeps the full height.

## [0.6.42] - 2026-10-13

### Changed

- Web: Log out moved into the header's ⋯ menu, out of the top bar where it was easy to press by accident. The ⋯ menu is now always present on desktop, holding Log out alongside the extensions' panels.
- Web: iPad Safari with a hardware keyboard no longer jumps the prompt up on focus. The floating input bar (under 150px) is allowed to overlap the bottom metadata row instead of shrinking the viewport.

## [0.6.41] - 2026-10-12

### Changed

- Web: the header has a ⋯ menu on the desktop too, and the extensions' panels live there — a panel placed in the header (such as Accounts) is an entry in it, with the Extensions list beside it — instead of a button each in the bar. On a phone the menu also holds the bar's buttons, as before, and now the header panels as well, which a phone could not reach before. With no panels the desktop shows no ⋯.
- Web: quiet text stays readable in dark themes such as labra, whose `muted` and `dim` are too dark for text. The header's labelled buttons (theme, language, account, extensions, SSH nodes, terminal) are in the text colour; the folder name, the session file badge, background tabs and the numbers under the prompt use a new `--color-text-subtle`, mixed from the text and the page (6:1 in labra, about the default themes' `muted` elsewhere).
- Web: the sidebar's group headings (Extensions, Today, Yesterday…) are 12px regular instead of 11px bold, closer to the items under them.
- Web: the sidebar's icons (New session, Search, Scheduled, extension panels) are the colour of their labels instead of a dimmer grey.
- Web: the selected conversation in the sidebar is drawn like the "New messages" pill — blue border and text on the raised surface, instead of on a blue-tinted ground that made the text hard to read.
- Follow-up keys: Cmd+Enter and Ctrl+Enter queue a message for after the turn, in the TUI and the web page alike; Alt/Option+Enter is a new line, like Shift+Enter. A terminal without the kitty keyboard protocol sends Option+Return as Shift+Return's bytes or as a bare Return, so Alt+Enter could never be told apart there. On macOS, Ctrl+Return in such a terminal is read from the modifier state, as Cmd+Return already was.

### Fixed

- Web: a message sent while the agent works no longer fails with "Agent is already processing". The page decided between `steer` and `prompt` from the state it read when the tab opened, which never followed a turn; it now always sends `prompt` with a `streamingBehavior` and lets the agent decide. Enter steers and Cmd/Ctrl+Enter queues a follow-up, as in the TUI, and images sent mid-turn are no longer dropped.

## [0.6.40] - 2026-10-12

### Fixed

- Web: switching tabs no longer pauses on a long conversation. A background tab's chat is hidden with `visibility` instead of `display: none`, so it keeps its layout, and messages off screen are skipped with `content-visibility: auto`. The phone layout's tighter chat padding applies again (its rule named `#chat-scroll`, which no longer exists).

## [0.6.39] - 2026-10-12

### Changed

- Android use: tapping the screen is a last resort too — the `android-use` skill and `android_ready` now say to use adb from bash first (`am start`, `input`, `settings`, `install`/`push`/`pull`), and the screen tools only when the person asks for them or adb cannot do the job.

## [0.6.38] - 2026-10-12

### Changed

- Computer use and Android use: the skills (`computer-use`, `android-use`) and the screen viewer pages are in English, like the rest of what pig puts in front of the model.

### Fixed

- Computer use: the model opened Chrome on the desktop to download a file `curl` fetches in one call. The `computer-use` skill and `computer_ready` now call the desktop a last resort: used only when the person asks for computer use or when bash and the other tools cannot do the job; downloads, API calls and reading pages go through `bash` (curl) or `fetch_web_page`.

## [0.6.37] - 2026-10-12

### Fixed

- Installer: answering "Start pig now?" with Enter did nothing. Piped to `sh`, the script's standard input is the download itself, and pig started on it saw no terminal, went into print mode with nothing to say and exited at once. It is started on the terminal now.

## [0.6.36] - 2026-10-12

### Fixed

- Web UI: the thinking-level menu drew the levels of another provider's model with the same id — choosing raccoonx's `gemini-3.8-flash` showed Google's five levels, picked `medium`, and the agent then set `off` because raccoonx's model is declared without reasoning. The model is now looked up by provider and id.

## [0.6.35] - 2026-10-12

### Fixed

- Test suite isolation: added `test/bootstrap.php` to isolate PHPUnit test runs within a temporary `$HOME` directory. Tests that omitted custom home paths or cleared environment variables previously polluted the developer's real `~/.pig/agent` sessions, `nodes.json`, and Trash. The bootstrap provides an isolated environment with a deleting `trash` stand-in, cleans up on shutdown, and preserves downloaded tool binaries.

## [0.6.34] - 2026-10-12

### Fixed

- Shell execution: fixed a race condition between `Run::wait()`'s background process poller and `Process::awaitFinish()`. When a command terminated via signal right as the 100ms background polling loop queried `proc_get_status()`, the first-read signal status (`signaled: true, termsig: N`) was consumed, causing `awaitFinish()`'s subsequent query to erroneously report `Command exited with code -1`. `Run` now immediately cancels background process polling upon pipe completion and passes captured exit statuses directly to `awaitFinish()`.

## [0.6.33] - 2026-10-12

### Changed

- Web UI: the "Load more" button in the All sessions window says how many are left — `加载更多（剩余 N 条）` / `Load more (N left)`, the total less what is already shown.

## [0.6.32] - 2026-10-12

### Fixed

- Custom models: pi's `~/.pi/agent/models.json` was ignored whenever `~/.pig/agent/models.json` existed, so a provider declared for pi disappeared from pig as soon as pig had a file of its own. Both are read now, pig's laid over pi's a provider at a time: a provider named in both is taken whole from pig's file, key included.

## [0.6.31] - 2026-10-12

### Fixed

- Web UI: the login page loaded unstyled in a browser whose extension rewrites the `Origin` header — `theme.css`, `style.css`, `login.js` and the favicon came back 403 because `Origin: http://127.0.0.1` (port gone) did not match `Host`. The same-origin check now believes the browser's own `Sec-Fetch-Site` when it is sent (`same-origin` and `none` pass; `same-site` and `cross-site` are refused, so another port on 127.0.0.1 is still kept out), and compares `Origin` with `Host` only when it is not.

## [0.6.30] - 2026-10-12

### Fixed

- The WebSocket protocol test for large multi-frame messages deadlocked on systems with small default socket buffers (8 KB on macOS). Writing 70+ KB into a blocking Unix domain socket filled the send buffer and hung waiting for the server to read on the same thread; the test now streams chunks non-blocking and pumps the server in the same loop, preserving full test coverage for 64-bit frame headers (> 65,535 bytes) without hanging.

## [0.6.29] - 2026-10-12

### Added

- The web UI's sidebar starts open on a screen at least 1024 px wide — a MacBook, an iPad held sideways — and closed on a phone or an iPad upright. Turning the iPad or resizing the window across that width opens or closes it; a toggle in between stays as you left it.

### Fixed

- A screenshot (or any message over about 128 KB) sent from the web UI never reached the agent, and the page said `prompt: no answer in 30s` — or the connection dropped. The browser sends a large message as several WebSocket frames, and the server handed each frame on as a whole message, so the agent was given broken pieces. The server now puts the frames back together before reading the message; a 6 MB screenshot reaches the agent in about two seconds.

## [0.6.28] - 2026-10-12

### Added

- `pig web install [--port N] [--host H]` and `pig web uninstall` (macOS): the web UI as a launchd login service, so it is running again after the computer restarts. `install` writes `~/Library/LaunchAgents/dev.pigagent.web.plist` — launchd runs `php pig --mode web --no-browser` in the folder `install` was typed in, with that shell's PATH and proxy variables and pig's agent directory, restarts it if it dies, and logs to `web.log` — and loads it. API keys exported in the shell are not copied into the plist; sign in with `/login` or keep them in `auth.json`. While the service is installed, `pig web start`, `stop`, `status` and `restart` (and `/web`) go through `launchctl`: `stop` unloads it until the next login or `start`, and a `--port` or `--host` for one start is refused, since `install` again is what changes them. On other systems `install` says so and `pig web start -d` is what there is.
- A scheduled run the web daemon was in the middle of when it stopped is carried on when it starts again: its conversation is opened and continued from the last thing in it (`continue` over RPC, `AgentSession::continueRun()`). A last turn that failed or was aborted is dropped and asked again; a conversation that had already answered is a finished run; one that stopped inside a tool call is refused and marked failed rather than running a command twice. A run that never wrote a session file still ends as failed.

### Fixed

- The web UI's copy buttons (a tool card's 📋, a code block's Copy, the terminal drawer's folder badge) did nothing when the page was opened over plain http from another machine — a phone on the LAN, a Tailscale address. The browser has no `navigator.clipboard` outside https and localhost, so the click threw; they now copy through the page's selection there, and show ✗ when the browser refuses.
- The web UI's theme button said the theme's id (`dark`) in either language. It now says a short name in the page's language — 暗黑 / 浅色 / 墨绿, Dark / Light / Labra — and changes with the language; a theme with no translation keeps its own name.
- With many conversations open in the web UI, the tab strip squeezed every tab down to an ellipsis with its × hidden, so the earlier ones could not be closed. Tabs now keep their width and the strip scrolls sideways — swipe, trackpad or mouse wheel — with `+` pinned at the right; switching tabs scrolls the active one into view, and on a touch screen every tab shows its ×.

## [0.6.27] - 2026-10-11

### Fixed

- **The Antigravity accounts panel said there were no accounts after `/login antigravity`**: the sign-in was in `auth.json` and the list read only `antigravity-accounts.json`. As pi-antigravity does, the signed-in account is now filed in the store and made active whenever the accounts are listed or chosen — in the Web UI, `/antigravity.accounts` and the 429 failover — and the page's server sees a sign-in made in a terminal after it started.

### Changed

- **A scheduled task has a conversation of its own**, as in the Claude app: it is in the sidebar, with the clock, the moment the task is saved — from the Scheduled page or by asking — and every run carries on in it, so a run sees what the last one found. "Open conversation" on the task's page opens it. A run that comes due while you are mid-turn in that conversation is skipped; moving the task to another folder starts a new conversation there. Tasks saved before get theirs at their next run.
- **A finished scheduled run is marked unread**: a blue dot on its conversation in the sidebar and beside Scheduled, gone once you open the conversation — on any device, since it is kept with the tasks. A run waiting for your answer keeps its amber dot.

## [0.6.26] - 2026-10-11

### Fixed

- **Answering an extension's question in the Web UI did nothing**: Yes, No, OK or a typed answer closed the dialog and was never sent, so the tool that asked — the `schedule` tool's confirmation, a hook's `confirm` — stayed "Running…" for good. Broken since the Web UI moved to ES modules.

### Changed

- **An extension's question dialogs in the Web UI** (confirm, input, editor) answer with the same buttons as the page's other dialogs: full-size, in a footer, with the default answer filled in, instead of two small outlined buttons under the text.

## [0.6.25] - 2026-10-11

### New Features

- **Scheduled tasks by asking** — say "every morning at 9, check what changed upstream and tell me whether this checkout needs it" in the terminal or the Web UI, and the new `schedule` tool (`builtin:schedule`) sets it up: list, create, update, delete, switch on or off, and run now. Every change is shown to you to confirm first; without anyone to confirm (`-p`) the tool only reads. A run reports by default and asks before pulling or editing.

### Added

- The `pig web` that runs the schedule writes where it listens into `scheduled-tasks.lock`, and pig on the same machine reaches `/api/scheduled` with a local token (`~/.pig/agent/web-local-token`, 0600) that opens nothing else. With no `pig web` running, the tool says to start one; an older one still running is told to restart.

## [0.6.24] - 2026-10-11

### Fixed

- **iPad Safari kept the Web UI's header off screen while typing**: focusing the prompt brought up the keyboard or the floating input bar, Safari scrolled the page to keep the field visible, and the header (language, accounts, theme) went off the top. The page now takes its height from what is visible and stays in place; the floating input bar itself is iPadOS's own.

## [0.6.23] - 2026-10-11

### Changed

- **Removed deprecated docker browser service** in favor of native desktop controller.

### Fixed

- **The Web UI is fully translated**: the SSH node workbench (which showed Chinese in English), its forms and dialogs, the web terminal's buttons, an extension's question dialogs (Yes / No / OK / hints), the tab bar, the export, diff, doctor and bug-report messages, and the connection banner now follow the page's language. Both languages have the same keys, and a test keeps every key the page uses in both.
- **The SSH node workbench's own dialogs opened behind it** (add / edit node, host-key fingerprint, SFTP editor) and could not be clicked; they now open on top.

## [0.6.22] - 2026-10-11

### Added

- **The Web UI asks for a password**: always, on 127.0.0.1 too. The first time, `pig web start` prints a one-time setup code to set the password with on the page; `pig web password` sets or changes it. Sessions last 30 days and survive a restart; logging out is in the header and the mobile menu.

### Fixed

- **Other websites can no longer reach the Web UI's API or WebSocket**: requests from another origin are refused and the API no longer sends `Access-Control-Allow-Origin: *` — before, any page open in the same browser could call `127.0.0.1:8088`, terminal included.
- **README's default web port** is 8088, as the server uses, not 8080.

## [0.6.21] - 2026-10-11

### Added

- **The Web UI lets a new conversation start in any folder**: until the first message, a 📁 chip by the model pickers opens a picker with the folders used before, a path field that completes subfolders, and a folder browser — as the terminal starts wherever `pig` is run.

## [0.6.20] - 2026-10-11

### Added

- **A panel's action can ask the person** like a hook does: its handler gets a `$ui` with the same `confirm()`, `select()`, `input()`, `editor()` and `notify()` as `$ctx->ui`, drawn by the page with the same dialogs as a conversation's extension questions.

## [0.6.19] - 2026-10-11

### Added

- **A Web UI panel can live in the header** (`registerWebPanel(..., placement: 'header', label: …)`), and the person decides: the header's new 🧩 Extensions button (and the mobile menu) lists every extension panel, to open it or move it between the header and the sidebar; the choice is kept in the browser.

### Changed

- **The Antigravity accounts panel is back in the header** as "🔑 Accounts", where it was before it moved into the extension.
- **A panel button that asks first uses the page's own dialog** instead of the browser's `confirm()`; with a dialog over a panel, Escape closes only the dialog.

## [0.6.18] - 2026-10-11

### Added

- **Extensions can put a panel in the Web UI's sidebar** (`registerWebPanel()`): the extension returns blocks — text, lists with buttons, meters, cards, sections loaded later — and the page draws them in its own theme and language under a new Extensions group; slash commands can open it. No extension code runs in the browser.

### Changed

- **The Antigravity accounts panel moved into the Antigravity extension**: it is in the sidebar's Extensions group (and `/accounts`) instead of a header button, and only appears when the extension is loaded. Rotate shows only when there is more than one account.

### Fixed

- **The Web UI's empty-conversation "Diagnose" chip runs pig's own `/doctor`** instead of `/antigravity.doctor`, which failed when the Antigravity extension was not loaded.

## [0.6.17] - 2026-10-11

### Added

- **An extension's `setTheme()` switches the Web UI's theme**: the page changes theme (every tab), as the terminal does, and an unknown name is refused with an error. `getAllThemes()` and `getTheme()` answer from the theme files in the Web UI instead of nothing, and `theme()` is the theme the page shows — the page tells each conversation's agent its theme when the tab opens and whenever it changes (pig's `set_theme` RPC command). A plain RPC host that does not say which theme it shows keeps upstream's answers.

### Fixed

- **An extension's `$ctx->ui` in the Web UI now does what it does in the terminal**: `setWidget` lines are drawn above or below the prompt, `setTitle` sets the page title, `notify` warnings and info stay in the transcript as the TUI's `Warning:` and dim lines (including ones sent from `session_start`), a dialog with a `timeout` counts down and closes, and a `setStatus` coloured with the theme no longer shows raw escape codes. Widgets, statuses and the title are replayed to a page that reloads or joins later.
- **Colours in extension widgets and statuses are drawn in the Web UI** in the page's theme colours, instead of being stripped.
- **The Web UI's `/` list offers the conversation's own commands**: extension commands, prompt templates and `skill:` commands from `get_commands`, after the built-ins — and no longer lists Antigravity's commands when that extension is not loaded.

## [0.6.16] - 2026-10-11

### Changed

- **Post-login model switch guidance when active model lacks credentials**:
  - `InteractiveMode` now prompts the user to use `/model` to switch models when logging into a provider while the currently active model has no configured API key, preventing immediate `No API key for provider` failures.

## [0.6.15] - 2026-10-11

### Added

- **Automatic bundled extensions initialization on fresh installs (`SelfUpdate::ensureBundledExtensions`)**:
  - `bin/pig` and `install.sh` now automatically populate bundled core extensions (`pig-antigravity`, `pig-computer`, `pig-web-search`, `pig-android-use`, `pig-vless`) into `~/.pig/agent/extensions/` on initial run.
  - New installations now have Antigravity, computer use, and web search ready out of the box with zero manual installation commands.

## [0.6.14] - 2026-10-11

### Fixed

- **Suppressed full historical changelog dump on fresh installations (aligned with upstream `pi`)**:
  - `bin/pig` now records the active version into `lastChangelogVersion` on first run without dumping the entire multi-year changelog history onto the screen.
  - Startup changelog display is now reserved strictly for incremental upgrades newer than the previously recorded version, keeping initial onboarding clean and distraction-free.

## [0.6.13] - 2026-10-11

### Added

- **Instant interactive launch and shell profile PATH configuration in `install.sh` (aligned with upstream `pi`)**:
  - `install.sh` now automatically prompts via `/dev/tty` to append Composer's global bin directory to the user's shell configuration (`~/.zshrc`, `~/.bashrc`, or `config.fish`).
  - Added `prompt_start_pig` to offer launching `pig` immediately via `exec "$bin_dir/pig"` upon install completion, eliminating `command not found: pig` without requiring manual terminal restarts or manual `export PATH` commands.

## [0.6.12] - 2026-10-11

### Added

- **Google Search grounding tool (`google_search`) and `/antigravity.search` in `pig-antigravity` (aligned with `pi-antigravity` v0.10.0)**:
  - Added native Google Search Grounding over Cloud Code Assist via `gemini-3.5-flash-lite` (fallbacks: `gemini-3.1-flash-lite`, `gemini-3-flash`), returning synthesized evidence briefs (Findings, Gaps, Next checks), source links, and verified UTF-8 byte offset citations.
  - Added `/antigravity.search [--thinking] [--url <url>] <query>` slash command for interactive web search.
  - Added feature toggles `ANTIGRAVITY_NO_SEARCH_TOOL`, `ANTIGRAVITY_NO_IMAGE_TOOL`, and `ANTIGRAVITY_NO_EXTRA_TOOLS` to prevent tool collisions when using dedicated search providers.

### Changed

- **Direct image endpoint with legacy fallback in `pig-antigravity`**:
  - `ImageGenerator` now queries `/v1internal:generateContent` with `requestType: "image_gen"` first, eliminating SSE stream overhead and falling back to `/v1internal:streamGenerateContent?alt=sse` only on HTTP 404.

### Fixed

- **Stable signed-int64 session IDs in `pig-antigravity`**:
  - Derived stable signed 64-bit integer session IDs (`toInt64SessionId`) from conversation seeds / session IDs instead of generating a new random integer every turn, enabling Google's server-side session cache and prompt optimization.
- **Account verification link guidance on 403 `VALIDATION_REQUIRED`**:
  - Extracted `validation_url` from Google 403 error payloads, guiding users with direct verification URLs to unlock accounts without confusing generic permission errors.
- **Premature stream termination detection**:
  - Detected streams closing abruptly without `candidates[0].finishReason` and surfaced clear failure diagnostics instead of truncating responses silently.

## [0.6.11] - 2026-10-11

### New Features

- **Scheduled tasks in the web UI.** The sidebar's Scheduled entry is live: a task is a prompt, a folder, an optional model and thinking level, and a schedule — manual, hourly, daily, weekdays, weekly, once, or a custom cron expression in the browser's timezone. Each run is a fresh `pig --mode rpc` conversation in the session pool, listed in the sidebar with a clock; opening it while it runs shows it live. A hook's question during a run waits for an answer (the run reads "Waiting for your answer" and the sidebar shows a dot), and the question is asked again of whoever opens the conversation. A run missed while the machine slept or the server was down happens once when it is back; an occurrence while the previous run is still going is logged as skipped. Only one web server per home runs the schedule. Tasks and their last 50 runs per task live in `~/.pig/agent/scheduled-tasks.json`.

### Fixed

- **Object serialization for tools without parameters in Anthropic and strict schemas**: Formatted empty tool `properties` definitions as `stdClass` (`{}`) instead of empty PHP lists (`[]`) in `Anthropic::convertTool()` and `ConstrainedSampling::getJsonSchemaToolParameters()`, resolving 400 `tools.N.custom.input_schema.properties: Input should be an object` errors on Anthropic when using no-argument tools like `computer_doctor`.

## [0.6.10] - 2026-10-11

### Fixed

- **Resilient Composer outdated JSON parsing in `PackageManager`**: Excluded stderr output and trimmed non-JSON warning banners when parsing `composer outdated --format=json`, preventing parse failures under experimental PHP runtime environments (e.g. PHP 8.6 dev deprecation notices).
- **Flake-free theme file stamp change detection (`ThemeWatcherTest`)**: Replaced non-deterministic `random_int()` size padding in test theme saves with strictly increasing sequence padding, eliminating 1/50 same-second mtime/size collision flakes on fast multi-core CI runners.

## [0.6.9] - 2026-10-11

### Fixed

- **Web chat auto-scroll pin on incoming blocks and images**:
  - Removed `scroll-behavior: smooth` from `.chat-scroll` to prevent programmatic scroll animations from falsely triggering `userScrolledUp` mid-turn.
  - Directionalized user scroll detection so only upward user scroll halts bottom-following, automatically resuming when within 80px of the bottom.
  - Added `ResizeObserver` tracking to follow blocks that grow after addition (e.g. async image decoding or tool execution expansion).

## [0.6.8] - 2026-10-11

### Changed

- **Refined Web UI divider aesthetics and theme contrast (`--color-divider`)**:
  - Introduced `--color-divider` using low-alpha text color mixing (`color-mix(in srgb, var(--color-text-primary) 10%, transparent)`), ensuring subtle, non-intrusive pane boundaries, modal borders, and table dividers across all active theme backgrounds.

## [0.6.7] - 2026-10-11

### New Features

- **TUI-synchronized theme engine for Web UI (`ThemeStylesheet`, `/theme.css`, `/api/themes`)**:
  - Web UI now sources all themes directly from the TUI's theme files (`packages/coding-agent/src/Theme/*.json`), dynamically generating `/theme.css` with semantic CSS variables (`--color-*`) per theme and serving dynamic theme lists via `/api/themes`.
  - Replaced hard-coded color definitions across Web components (terminal palettes, modal dialogs, input fields, tool cards, language settings, and node workbench) with dynamic theme variables.
  - Implemented `HtmlExport::deriveExportColors()` to automatically compute page, card, and info backgrounds from base user message colors for themes without explicit export blocks.

## [0.6.6] - 2026-10-11

### Fixed

- **Working directory validation and warning suppression in `RpcClient::start()`**: Checked that the target `$cwd` exists before invoking `proc_open()`, throwing a clear `RpcError` instead of letting `proc_open()` fail with a raw PHP `posix_spawn() failed: No such file or directory` warning. Captured potential `proc_open()` warnings using a scoped `set_error_handler` to prevent raw warnings from leaking onto TUI or CLI streams. Ensured `HttpServer` safely falls back to the server root when a restored or client-supplied session tab points to a deleted directory.

## [0.6.5] - 2026-10-11

### Changed

- **Polished Web UI sidebar aesthetics and interaction stability**: Verified and unified sidebar navigation layout, crisp SVG icons, and smooth transitions for session item hover states.

### Fixed

- **Consistent system trash and web session cleanup**: Ensured session deletion across Web UI and CLI interfaces routes through OS-native trash commands (macOS Finder delete / Linux `gio trash` / Windows PowerShell RecycleBin) and verified clean restart workflows.

## [0.6.4] - 2026-10-11

### Fixed

- **System trash/recycle bin integration for session deletion**: Replaced direct hard `unlink()` in Web session deletion (`/api/sessions/delete`) with OS-native trash integration (`SessionList::deleteSessionFile()`). Enhanced trash handling across macOS (`osascript` Finder delete / `trash` CLI), Linux (`gio trash` / `trash-put`), and Windows (PowerShell RecycleBin), safely moving deleted sessions into the operating system's native Recycle Bin with clean fallback to `unlink()`. Corrected dialog prompt text to accurately indicate system trash rather than a nonexistent subfolder.

## [0.6.3] - 2026-10-11

### Changed

- **Modernized sidebar navigation SVG icons**: Replaced tiny and inconsistent Unicode characters (`＋`, `⌕`, `⏱`) on sidebar navigation buttons ("New session", "Search", "Scheduled tasks") with crisp, balanced 16×16 vector SVG icons for a professional, elegant aesthetic.

## [0.6.2] - 2026-10-11

### Added

- **`composer:` package sources**: `pig install composer:vendor/name` (or `@<constraint>`; an exact version is pinned) installs a package with Composer into a shared project per scope — `~/.pig/agent/composer`, `.pig/composer` with `-l`, a temporary one for `-e` — as pi installs `npm:` packages into its npm root. `pig remove`, `pig update` (unpinned only), `pig list`, the startup update check and settings changes of the constraint all work as they do for npm in pi. The shared project replaces `pigagent/pig`, so a package that requires pig never installs a second copy, and blocks Composer plugins. Packages published to Packagist can now be installed this way.
- **Extension provider image generation & classification APIs (`Pig\Ai\Extension\ImageGenerationApi`, `Pig\Ai\Extension\ClassificationApi`)**: Extensions registering custom providers via `registerProvider()` can now implement custom image generation (`images: [...]`) and classifier evaluation (`classifiers: [...]`), fully integrated with `codemode` sandbox `$models->getAvailableOfType()` and `$models->generateImages()`.
- **Claude-style sidebar session actions and dropdown menu**: Redesigned session items in the Web drawer with folder tags right-aligned, seamlessly hidden on hover to reveal a three-dots (`···`) action button. Added a Claude-aligned floating dropdown menu supporting session rename and danger-styled deletion.

### Changed

- **Web sidebar hover aesthetics and height stability**: Locked `.drawer-item` vertical line height and dimensions to ensure zero height jumping between default badge and hover action states. Replaced harsh border styling with pure rounded background blocks on hover.

### Fixed

- **Antigravity image generation model discovery and registration**: Restored Antigravity image models registration during catalog installation and discovery, enabling seamless script access via `codemode`.

## [0.6.1] - 2026-10-11

### Fixed

- **VLESS proxy `localhost` target IPv4 loopback resolution**: Resolved `localhost` targets to `127.0.0.1` in `VlessServer::connect()`, avoiding connection refusal on dual-stack Linux runners where `localhost` resolves to `::1` while target servers only bind IPv4.
- **RPC dialog timeout test polling resilience (`RpcModeTest`)**: Replaced brittle fixed-tick sleep in `testADialogWithATimeoutSaysSoAndAnswersNoWhenItRunsOut` with resilient polling until timeout expiration, preventing CI runner timing flakes.

## [0.6.0] - 2026-10-10

### New Features

- **Upstream's RPC protocol, unchanged (`Rpc\RpcMode`, `RpcEvents`, `RpcUi`, `RpcClient`)**:
  - `pig --mode rpc` now speaks pi's wire exactly: every command, field, response and event name, so a client written for `pi --mode rpc` drives pig. New commands `clear_queue`, `get_available_thinking_levels`, `get_entries`, `get_tree`, `set_session_name`, `get_commands`; `prompt` answers once with `{disposition}` and takes `streamingBehavior`; `steer` / `follow_up` take images; `get_state` and `get_session_stats` have upstream's shapes; `compact` returns the `CompactionResult`; `bash` takes `excludeFromContext` and streams `bash_execution_update`.
  - Events are upstream's: `message_update` carries deltas (`{usage, assistantMessageEvent}`) instead of the whole message, `toolcall_start/delta/end`, `agent_settled`, `queue_update`, `entry_appended`, `thinking_level_changed`, `auto_retry_start/end`, and `compaction_start/end` for manual, threshold and overflow compactions. `--mode json` writes the session header first.
  - Hook dialogs are `extension_ui_request` / `extension_ui_response` with a UUID on every request, `setStatus` / `setWidget` / `setTitle`, and dialog timeouts in every UI.
- **Keybindings registry with upstream's ids (`Keybindings`, `Pig\Tui\KeybindingsManager`, `KillRing`, `UndoStack`)**:
  - Every upstream keybinding id can be rebound in `keybindings.json`, editor and input keys included, with upstream's platform defaults (Windows/WSL, macOS) and old-name migrations; `/reload` reloads keybindings and themes.
  - New editing keys: undo, kill ring with yank / yank-pop, delete word forward, jump to character, page up/down; the tree gets fold/unfold, label edit (`Shift+L`) and label timestamps (`Shift+T`); the session list gets path toggle, delete (confirmed, to the trash when possible) and page keys.
- **Session picker search and sorting (`Cli\SessionList`, `Cli\SessionSearch`)**: Tab switches between this folder and all projects, `Ctrl+S` cycles threaded / recent / fuzzy, `Ctrl+N` shows named sessions only, `Ctrl+R` renames (inside `/resume`), and the search takes `"phrases"` and `re:<pattern>`.
- **Resuming another project's session carries on in its directory (`Session\SessionCwd`, `Cli\StartupSelector`)**: `pig` picks the session before trust, settings and extensions and builds everything for the session's own working directory; `/resume`, `/import` and RPC's `switch_session` rebuild the runtime the same way. A directory that has gone is asked about on a terminal ("continue in current cwd") and is an error otherwise.
- **`SYSTEM.md` and `APPEND_SYSTEM.md` (`Prompt\SystemPromptFiles`)**: replace or extend the system prompt from the trusted project's `.pig/` or the agent directory; `--system-prompt` and `--append-system-prompt` do the same for one run (text or a file path).
- **Structured `bash` results and the `powershell` tool (`Tools\ShellTool`, `PowerShellTool`)**: `bash` returns upstream's `{output, truncated, full_output_path?, exit_code, wall_time_seconds}` to codemode scripts (up to 1 MiB of output); `powershell` is available on native Windows through `--tools` / `defaultTools` (untested on Windows).

### Added

- Added CLI options `--provider`, `--system-prompt`, `--append-system-prompt` (repeatable), `--session-id`, `--fork`, `--tui-mode regular|fullscreen`, `--verbose`, `--offline` and `--update-check`, and the `pig auth check|print-api-key|print-bearer-token` subcommand (`--json`, `--credentials`, `--no-refresh`, `--min-expiry`).
- Added piped standard input to the first message: `git diff | pig -p "review this"` sends the diff, then any `@file` text, then the message.
- Added `/scoped-models` (pick the `--models` scope in a session; save writes `enabledModels`), `/import <path>` (copy a session file into this project and resume it), `/share` (a secret GitHub gist of the HTML export via `gh`, viewed on `PIG_SHARE_VIEWER_URL`) and `/export <file>.jsonl`.
- Added upstream's `/bug` flow: describe the problem, choose whether to include the transcript (a model-written summary otherwise), then Upload Report or Export as Zip (`pig-bug-report-<id>.zip`); uploads are refused under `PIG_OFFLINE`.
- Added prompt-template substitutions `${N:-default}`, `${@:-default}`, `${ARGUMENTS:-default}`, `${@:N}` and `${@:N:L}`, and `argument-hint` in the completion list.
- Added skill discovery from `~/.agents/skills` and, in a trusted project, `.agents/skills` in the working directory and each ancestor up to the repository root; `disable-model-invocation: true` keeps a skill out of the prompt while `/skill:name` still works.
- Added `AGENTS.override.md`, `AGENTS.MD` and `CLAUDE.MD` to the context-file names (one file per directory, upstream's order); a nested linked git worktree no longer loads the main checkout's copy.
- Added the `max` thinking level where a model supports it, with upstream's descriptions in the thinking menus.
- Added `$NAME`, `${NAME}` and `!command` values for `models.json` `apiKey` and headers and for `auth.json` keys; `models.json` entries can now target a built-in or extension provider (`baseUrl`, `headers`, `compat` for all its models), replace a model by id, and use `modelOverrides`; `apiKey` is optional.
- Added `ANTHROPIC_AUTH_TOKEN` (sent as `Authorization: Bearer`, ahead of `ANTHROPIC_OAUTH_TOKEN` and `ANTHROPIC_API_KEY`).
- Added upstream's legacy `registerProvider(name, config)` form for extensions, `$ctx->modelRegistry`, and `(request, ctx)` for virtual model routers.
- Added `PIG_SESSION_ID`, `PIG_SESSION_FILE`, `PIG_PROVIDER`, `PIG_MODEL` and `PIG_REASONING_LEVEL` to the environment of `bash` / `powershell` commands, and `PIG_CODING_AGENT=true` / `AI_AGENT=pig` for every child process.
- Added `PIG_PACKAGE_DIR`, `PIG_TUI_ESC_TIMEOUT` and `PIG_SKIP_VERSION_CHECK`, and Termux clipboard support (`termux-clipboard-get` / `termux-clipboard-set`).
- Added per-model cost lines and a Tools/summaries line to `/session`.
- Added catalog refresh when `/model` or `/scoped-models` opens, and for `/model <pattern>` when nothing cached matches.

### Changed

- `codemode` is always registered but inactive, as upstream's (`CustomTool(defaultActive: false)`): turn it on with `--tools …,codemode`, `--tools +codemode`, `defaultTools` (`["+codemode"]`) or `setActiveTools()`; an MCP server with `codemode` exposure turns it on and it stays on. **Breaking:** `codemode.enabled` is removed — use `"defaultTools": ["+codemode"]`.
- `/share` links to pigagent.dev's own viewer, `https://pigagent.dev/session/#<gist id>` (still overridable with `PIG_SHARE_VIEWER_URL`).

- **Breaking — `PIG_HOME` is now `PIG_CODING_AGENT_DIR`** (upstream's `PI_CODING_AGENT_DIR`, `~` expanded). `PIG_HOME` is not read: rename it in your shell profile.
- **Breaking — `--no-save` is now `--no-session`**, with no alias. A `--no-session` run now reads your settings and `models.json` like any other run; only the conversation is not written. Its session still has an id (`--session-id` sets it).
- **Breaking — a bare `apiKey` in `models.json` (or a key in `auth.json`) is a literal.** `"apiKey": "MY_KEY"` used to name an environment variable; write `"$MY_KEY"` (or `"${MY_KEY}"`) now.
- **Breaking — prompt templates live in `prompts/`, not `commands/`**: move `~/.pig/agent/commands/*.md` to `~/.pig/agent/prompts/` and `.pig/commands/` to `.pig/prompts/`. The conventional directories load their direct children only.
- **Breaking — skills are no longer read from other tools' folders**: `~/.claude/skills`, `.claude/skills`, `~/.codex/skills`, `~/.pi/agent/skills` and `.pi/skills` (and pi's theme directories) are gone. Add any of them to the `skills` setting to keep it. The first skill of a name now wins, with a collision warning for the rest.
- **Breaking — `skills`, `prompts` and `themes` settings are lists of paths** with `!pattern`, `+path` and `-path`. The old `skills` object (`enabled`, `ignoredSkills`, `includeSkills`, the `enable*` switches) is reported and ignored; `enableSkillCommands` is a top-level key.
- **Breaking — RPC wire renames**: `hook_ui_request` / `hook_ui_response` → `extension_ui_request` / `extension_ui_response`; `export` → `export_html`; `go_to` → `navigate_tree` (`{targetId, summarize, customInstructions, replaceInstructions, label}`, answering `{cancelled, …}`); `get_branch` is removed (use `get_entries` / `get_tree`); `auto_compaction_start/end` → `compaction_start/end`; `retry_start/end` → `auto_retry_start/end`; `bash_output` → `bash_execution_update`; `message_update` sends deltas; `hook_error` → `extension_error {extensionPath, event, error}`; `switch_session` / `new_session` return `{cancelled}`; a bad line is `Failed to parse command: …`. `RpcClient` methods use upstream's names (`getState()`, `getAvailableModels()`, `exportHtml()`, …) and wait for `agent_settled`.
- **Breaking — environment variables no longer read**: `PIG_MODEL`, `PI_MODEL`, `PIG_PROVIDER`, `PI_PROVIDER`, `PIG_THINKING`, `PI_REASONING_LEVEL`, `PIG_TUI_MODE`, `PI_TUI_MODE` and `ALL_PROXY`. Use `--model`, `--provider`, `--thinking`, `--tui-mode` or the settings instead.
- **Breaking — `PIG_*` only, no `PI_*` fallback**: `PI_CACHE_RETENTION` → `PIG_CACHE_RETENTION`; `PIG_TUI_TRACE` → `PIG_TUI_WRITE_LOG` (a directory gets `tui-<timestamp>-<pid>.log`); `PIG_CLEAR_ON_SHRINK`, `PIG_HARDWARE_CURSOR`, `PIG_OFFLINE` (`1`/`true`/`yes`), `PIG_PROGRAM_STATUS`, `PIG_HYPERLINKS`, `PIG_IMAGE_PROTOCOL`, `PIG_TRUE_COLOR` and `PIG_OAUTH_CALLBACK_HOST` no longer read their `PI_` names.
- **Breaking — command-line parsing follows upstream's**: `-r` / `--resume` takes no value (use `--session <path|id>`); Escape in the startup picker prints `No session selected` and exits 0; an unknown short option is an error, and an unknown long option is an error unless an extension registers it; `--api-key` requires `--model` / `--models`; `--mode text` on a terminal opens the TUI, while `-p` or a non-terminal stream is print mode; print mode with nothing to send exits 0 quietly; `--list-models` takes a search only when one follows; after `--`, `@file` is still a file.
- Changed `--session <id|path>` for another project's session to ask whether to fork it into this one (on standard error when nothing can answer); `--session-id` opens that exact id or creates a session with it.
- Changed proxy selection to undici's rules: `https://` uses `https_proxy` → `HTTPS_PROXY` → the HTTP proxy; `http://` uses `http_proxy` → `HTTP_PROXY` → direct; `httpProxy` fills an unset `HTTP_PROXY` / `HTTPS_PROXY`; `no_proxy` accepts `*.example.com`; an empty `proxy.bypass` no longer clears `no_proxy`.
- Changed compaction to upstream's implementation and names (`Compaction::calculateContextTokens()`, `prepareCompaction()`, `compact()`, …): a cut inside a turn is summarised as two requests joined under "Turn Context (split turn)", tool results shown to the summariser are cut at 2,000 characters, and "Compacted from N tokens" uses the projected estimate. Threshold compaction now runs in every mode, before each prompt and after each run; an overflow is compacted and retried once.
- Changed branch summaries to upstream's: file lists come only from the part of the branch that fits the budget, with "Additional focus" or full replacement instructions, a label, and "No content to summarize" for an empty branch.
- Changed compaction and tree hook events to upstream's fields (`SessionBeforeCompactEvent`, `SessionCompactEvent`, `SessionBeforeTreeEvent`, `SessionTreeEvent`; `fromHook` → `fromExtension`); `session_before_*` handlers stop at the first cancel, else the last answer wins.
- Changed `!!command` to be recorded in the session (left out of model requests) instead of not being saved; a bash execution's `spillPath` is now `fullOutputPath`.
- Changed `defaultThinkingLevel`'s default to `medium`, clamped to the model; `set_thinking_level` over RPC clamps too.
- Changed `models.json` to be re-read whenever `/model` opens, and to apply after extension providers.
- Changed `shellPath` to expand `~` and `file://`; bash is found as upstream finds it (`shellPath`, then Git Bash / `bash.exe` on Windows, else `/bin/bash`, `bash` on `PATH`, `sh`), so a Homebrew bash needs `shellPath`.
- Changed compaction settings to accept only non-negative safe integers (0 allowed); an invalid value is reported with upstream's message.
- Changed the project trust prompt's triggers to upstream's (`settings.json`, `mcp.json`, extensions, skills, prompts, themes, `SYSTEM.md`, `APPEND_SYSTEM.md`, `.agents/skills`), plus pig's `hooks` and `tools`.
- Changed Ctrl+C to cancel a loader or close the config selector, and Ctrl+D with text in the editor to delete forward (it exits only on an empty editor).
- Changed `pig -r` to open the list even when this project has no sessions, so other projects can be reached with Tab.
- Changed the slash command list to add `/scoped-models`, `/import` and `/share`, with `/quit` as the listed name (`/exit` still works).

## [0.5.31] - 2026-10-10

### New Features

- The web UI's sidebar is laid out like Claude's: New session, Search and Scheduled (a placeholder for now) at the top, then the conversations of every project in one list, grouped by the day they were last written to (Today, Yesterday, a date), each with its folder beside it. "View all" and Search (also ⌘K) open one panel that lists every conversation or searches their names and contents, as `--resume` does.

### Added

- `SessionManager::listAll()` and `searchAll()`, and the `/api/sessions/all` endpoint they answer, across pig's and pi's `sessions/`.
- Providers with a fetched catalog (`Provider(refreshModels:)`, upstream's `refreshModels()`): `ModelRefresh` restores the stored catalog when the provider registers, refreshes every one in the background when the TUI or RPC mode starts (not under `PIG_OFFLINE`), and refreshes the one you signed into after `/login`, warning when that times out or fails.
- `SelectList` layouts (`SelectListLayout`): the label column fits the widest label between upstream's bounds in the theme, thinking, settings, slash-command, per-model and `/llama` pickers.

### Changed

- The web sidebar no longer asks for a folder before showing its conversations, and a new session opens in the active tab's directory.
- Removing an api-key sign-in with `/logout` says upstream's `Removed stored API key for <provider>. Environment variables and models.json config are unchanged.`

### Fixed

- The web sidebar showed nothing and "+ New Session" did nothing: `app.js` read two variables it never declared, which an ES module throws on.

## [0.5.30] - 2026-10-08

### New Features

- **Upstream Built-in Extensions Architecture (`builtin:*`, `BuiltinExtensions`)**:
  - Embedded upstream's four core built-ins directly from repository source: `builtin:llama.cpp` (`pig-llama`), `builtin:codemode` (`pig-codemode`), `builtin:tool-search` (`pig-tool-search`), and `builtin:mcp` (`pig-mcp`).
  - Supported toggling built-in extensions in `settings.json` (`-builtin:<name>`, `+builtin:<name>`, `!builtin:*`) and interactive `pig config` under the *Built-in* group.
  - Allowed explicit loading via `-e builtin:<name>` even with `--no-extensions` enabled.
  - Automatically omitted built-in extensions when overridden by user/external extensions (`omitReplaced`) and warned against stale copies in `~/.pig/agent/extensions`.
- **Native `pig-llama` Extension & Extension API Key Auth (`extensions/pig-llama`, `ApiKeyAuth`)**:
  - Ported upstream's `extensions/llama` extension providing the local `llama.cpp` provider and runtime model discovery from `/v1/models`.
  - Added `Provider::$apiKeyAuth` enabling extensions to register interactive API key authentication flows (`/login llama.cpp`).
- **Decoupled Tool Search Extension (`extensions/pig-tool-search`, `DeferredTools`)**:
  - Decoupled `tool_search` from the MCP extension into a dedicated built-in extension, dynamically activating when any MCP server has deferred tools via `DeferredTools`.

## [0.5.29] - 2026-10-08

### Added

- **Explicit CLI Resource Loading & Multi-Option Flags (`--skill`, `--prompt-template`, `--theme`, `--use-theme`, `--no-themes`)**:
  - Added `--skill <path>` and `--prompt-template <path>` CLI options to explicitly load skill and prompt-template files/directories for single runs, taking precedence even when `--no-skills` or `--no-prompt-templates` are enabled.
  - Aligned theme CLI options with upstream pi: `--theme <path>` loads themes from files/directories, `--use-theme <name>` sets the active theme name, and `--no-themes` disables all theme directory auto-discovery.
  - Supported repeated usage for `-e`, `--skill`, `--prompt-template`, and `--theme`.

### Changed

- **`--theme` Takes Path, `--use-theme` Takes Name**:
  - Aligned `--theme <path>` to accept file/directory paths and moved theme name selection to `--use-theme <name>`, strictly matching upstream pi.

### Fixed

- **Adjacent Emoji Grapheme Cluster Splitting under PCRE2 10.42 (`Graphemes::split()`)**:
  - Implemented `splitPictographs()` in `Graphemes::split()` to work around the PCRE2 10.42 bug bundled in PHP 8.3 (where `\X` improperly joined adjacent pictographs lacking a ZWJ), ensuring `Width::visible("🙂🙂🙂")` computes as 6 columns matching terminal rendering and fixing cursor alignment and markdown wrapping.
- **Explicit `-e` Options Preserved under `-ne`**:
  - Allowed explicit `-e` extension paths to load even when `--no-extensions` (`-ne`) is passed, and properly preserved multiple `-e` options without dropping prior occurrences.

## [0.5.28] - 2026-10-08

### New Features

- **Pure-PHP Unicode Mermaid Diagram Renderer (`Mermaid\*`, `Interactive\MermaidTransformer`)**:
  - Implemented a pure-PHP zero-dependency port of `grok-mermaid` 0.2.3, rendering ````mermaid` blocks natively in terminal as Unicode box-drawing diagrams (flowcharts with subgraphs, state, class, ER, and sequence diagrams).
  - Integrated `MermaidTransformer` into markdown rendering pipeline with `markdown.mermaid` setting (`streaming` [default], `final`, `off`) and `/settings` row.
- **Provider Attribution & Telemetry Headers (`ProviderAttribution`)**:
  - Automatically dispatched attribution headers matching upstream pi: `X-OpenRouter-Title: pig` and site URL for OpenRouter, `X-BILLING-INVOKE-ORIGIN: Pig` and custom User-Agent for Cloudflare, and `x-opencode-client: pig` for OpenCode, controlled via `enableInstallTelemetry` and `PIG_TELEMETRY`.
- **UUID v7 Implementation (`Pig\Ai\Utils\Uuid::v7`)**:
  - Implemented standard timestamp-ordered UUID v7 generation for session request tracing and summaries.

### Added

- Added upstream CLI tool and loader flags: `--no-tools` (`-nt`), `--no-builtin-tools` (`-nbt`), `--name <name>` (`-n`), `--no-prompt-templates` (`-np`), and `--no-context-files` (`-nc`).

### Changed

- Renamed legacy `--no-tools` (which skipped `tools/` directories) to `--no-tool-files`, adhering to upstream semantics where `--no-tools` disables all tools entirely.

### Fixed

- **Help Text Proxy Key Alignment**:
  - Updated `pig --help` proxy setting reference from deprecated `proxy.url` to standard `httpProxy`.

## [0.5.27] - 2026-10-08

### New Features

- **Pure-PHP WebSocket Client & OpenAI Codex WebSocket Transport (`Http\WebSocket`, `CachedWebSocketConnection`, `OpenAiCodexResponses`)**:
  - Implemented pure-PHP zero-dependency RFC 6455 WebSocket client (`Pig\Ai\Http\WebSocket`) over native non-blocking sockets with proxy tunneling support.
  - Added native `transport: "auto"` support for ChatGPT Codex backend (`OpenAiCodexResponses`): pooling and caching WebSocket connections per session and account (5-minute idle TTL), streaming input deltas via `previous_response_id`, and gracefully falling back to SSE on socket interruptions.
- **Independent Steering and Follow-Up Queue Modes (`steeringMode`, `followUpMode`)**:
  - Split queue modes into dedicated `steeringMode` and `followUpMode` settings (`one-at-a-time`, `all`, `cancel`), matching upstream pi.
  - Added dedicated `/settings` rows and RPC commands `set_steering_mode` and `set_follow_up_mode`.

### Added

- **Project Trust Override CLI Flags & Default Setting (`ProjectTrust`, `defaultProjectTrust`)**:
  - Added `-a` / `--approve` and `-na` / `--no-approve` CLI flags to force project trust or denial for single runs without mutating `trust.json`.
  - Added `defaultProjectTrust` setting (`always`, `never`, `ask`) and `/settings` row.
- **Changelog Display Collapse Setting (`collapseChangelog`)**:
  - Added `collapseChangelog` setting and `/settings` row to display concise update notices rather than full changelog bodies on startup.
- **HTTP Proxy Setting Parity (`httpProxy`)**:
  - Added `httpProxy` support in user settings matching upstream pi's configuration key.

## [0.5.26] - 2026-10-08

### New Features

- **Comprehensive Upstream Settings Parity (Batches 1-3)**:
  - **Display & Terminal Settings**: Added `showCacheMissNotices` (`CacheStats`), `terminal.images`/`trueColor`/`hyperlinks` overrides, `terminal.imageWidthCells`, `terminal.showTerminalProgress` (OSC 9;4 progress reporting), `markdown.codeBlockIndent`, `quietStartup`, `autocompleteMaxVisible`, `doubleEscapeAction` (Escape twice on empty prompt opens `/tree` or `/fork`), and `treeFilterMode`.
  - **Model, Tool & Summary Settings**: Added `modelThinkingLevels` (`ModelThinkingSubmenu`), `thinkingBudgets` custom budget tables, `enabledModels` default scope, `defaultTools` (`+name`/`-name` modifications), `enableSkillCommands` (`/skill:name args`), `compaction.modelOverrides` per-model budgets, `branchSummary.reserveTokens`, and `branchSummary.skipPrompt`.
  - **Images, Shell & Storage Settings**: Added `images.autoResize` (`Utils\ImageProcess`, EXIF rotation and dimensions fitting via optional `ext-gd`), `images.blockImages` (masking images to text placeholders), `shellCommandPrefix` command prepending, and `sessionDir` / `--session-dir` centralized session directories.

### Added

- Added token and cost accounting (`usage`) to compaction and branch summary entries, aggregated in `/session` totals.

## [0.5.25] - 2026-10-08

### New Features

- **Prompt Cache Warming Engine (`Session\CacheWarmer`, `cache_warming_decision`, `usage` entries)**:
  - Ported upstream pi's cache warmer: automatically re-sends the latest request with a 1-token budget just prior to cache TTL expiration (e.g. Anthropic 300s/3600s) when projected cache miss savings exceed $0.05.
  - Persisted warm events into `.jsonl` session files as pi-compatible `usage` entries (`UsageEntry`) and aggregated them in `/session` cost totals.
  - Added `cacheWarming` mode to `Settings` (`off` / `streaming` / `idle`, default `streaming`) and interactive `/settings` row.
  - Added `cache_warming_decision` hook event (`CacheWarmingDecisionEvent`, `CacheWarmingDecisionEventResult`), allowing extensions to inspect or override warming decisions.

## [0.5.24] - 2026-10-08

### Fixed

- **Cross-Process OAuth Refresh Lock & Double-Checked Locking (`FileLock`, `Auth::fresh`, `McpOauth`)**:
  - Implemented pure-PHP zero-dependency cross-process file locking via kernel-managed `flock()` with non-blocking async event loop polling (`Pig\CodingAgent\FileLock`).
  - Added double-checked locking to `Auth::fresh()` and `McpOauth`: re-reading credentials immediately upon acquiring the lock, reusing tokens freshly renewed by peer processes, and completely eliminating token rotation desync race conditions between concurrent sessions.

## [0.5.23] - 2026-10-08

### Changed

- **Extensions and hooks may declare classes, and `/reload` says what it cannot do (`Hooks\DeclaredSymbols`)**:
  - A named class or function in an extension no longer refuses the load. On `/reload`, a file with declarations runs the factory it first loaded with (PHP cannot unload a class), and when its files changed since, the reload reports it and says to restart pig. A different copy of an extension whose classes are already in memory is refused by name; an identical copy at another path is served by the first. The bundled extensions dropped their `class_exists` guards.

## [0.5.22] - 2026-10-08

### New Features

- **Session Branch Forking & Cloning (`/fork`, `/clone`, `SessionManager::fork`, `AgentSession::fork`)**:
  - Added `/fork` command (upstream's `user-message-selector.ts`): lists historical user prompts across all branches, forks the conversation prior to the chosen turn into an independent `.jsonl` session file, and restores the prompt text into the editor.
  - Added `/clone` command: clones the active conversation branch at the current leaf into a new session file.
  - Implemented `SessionManager::fork($leafId)` with upstream `createBranchedSession()` parity: re-chaining labels off the new leaf, remapping compaction `firstKeptEntryId` cuts, and recording `parentSession` lineage in the session header.
  - Integrated `session_before_fork` hook interception, lifecycle transitions (`session_shutdown('fork')` -> `session_start('fork')`), and tool notifications with reason `fork`.
  - Added RPC commands `fork`, `clone`, and `get_fork_messages` along with client wrapper methods in `RpcClient`.

## [0.5.21] - 2026-10-08

### Added

- **Disabled Extensions Configuration via Settings (`Settings::disabledExtensions`)**:
  - Added support for disabling specific bundled or installed extensions by name via `disabledExtensions` in `settings.json` (e.g. `["pig-android-use", "pig-computer"]`), merging cleanly with CLI flags across interactive TUI, session startup, and `/reload`.

## [0.5.20] - 2026-10-08

### Fixed

- **Empty Tool Parameters Encoding & Non-String Enum Rejections on Antigravity / Google (`GoogleShared`, `pig-computer`)**:
  - Enforced `new stdClass()` serialization for empty `'properties' => []` schemas in `GoogleShared::sanitizeForOpenApi()`, preventing Antigravity 400 errors (`Cannot bind a list to map for field 'properties'`).
  - Removed integer enum declaration on `computer_click.click_count`, complying with Gemini OpenAPI string-only enum restrictions.

## [0.5.19] - 2026-10-08

### New Features

- **Pure-PHP Native macOS Desktop Controller (`extensions/pig-computer`)**:
  - Replaced legacy containerized browser dependency with pure-PHP native macOS automation via `screencapture`, AppleScript, and JXA CoreGraphics event injection.
  - Provided 10 granular desktop manipulation tools: `computer_doctor`, `computer_ready`, `computer_observe`, `computer_click`, `computer_drag`, `computer_scroll`, `computer_type_text`, `computer_press_key`, `computer_launch_app`, and `computer_batch` (up to 15 operations with anti-ban jitter).
  - Added built-in Web live stream monitor `/api/computer/stream` and image capture endpoints `/api/computer/screen.jpg`.
  - Added `/computer status | screenshot | app <name>` slash command for interactive desktop diagnostics.

## [0.5.18] - 2026-10-08

### New Features

- **Pure-PHP Android Device Automation Extension (`extensions/pig-android-use`)**:
  - Implemented standalone, zero-dependency Android UI automation using native platform `adb` CLI (`adb exec-out screencap -p`, input tap, swipe, text, keyevents), adhering strictly to the pure-PHP architecture rule without Python or auxiliary interpreter wrappers.
- **OpenAI Decisions Classifier Protocol & Image Support (`Providers\OpenAiDecisions`, `openai/gpt-6-luna`)**:
  - Added `Providers\OpenAiDecisions` (`api: openai-decisions`, POST `/decisions`) supporting up to 128 images in `ClassifierContext::$images`.
  - Added `openai/gpt-6-luna` decision model with 272k long-context pricing tiers.
  - Enabled image passing in codemode `$models->classify()`.

### Added

- **Tool Call Token Accounting & Billing (`AgentToolResult::$usage`, `ToolResultMessage::$usage`)**:
  - Added token and cost accounting to tool results: codemode `$models->*` calls and nested tools record their usage, and `AgentSession::stats()` aggregates tool costs alongside assistant turns in session totals.
- **Nested Tool Call Session Lifecycle & Parent Tracking (`AgentSession::executeNestedTool`, `parentToolCallId`)**:
  - Routed nested script tool calls through `AgentSession::executeNestedTool()` with `<parent>/<n>` call IDs and `ToolArguments::validate()` schema enforcement.
  - Dispatched `parentToolCallId` across agent events, hook notifications, and RPC streaming, cleanly grouping child executions under parent cards in TUI.

### Fixed

- **Home Directory Test Isolation in Antigravity Catalog Tests (`AntigravityCatalogTest`, `AntigravityExtensionTest`)**:
  - Corrected test isolation to isolate both `PI_HOME` and `PIG_HOME` across catalog and extension tests, eliminating test leaks from local developer configuration files.

## [0.5.17] - 2026-10-08

### New Features

- **Models Namespace in Codemode Sandbox (`$models`, `__CodemodeModels`)**:
  - Exposed `$models` in sandbox scripts providing `$models->getModelOfType()`, `$models->classify()`, and `$models->generateImages()`, supporting non-LLM image generation and classification with a 4-slot concurrency limiter and aggregated usage details.
- **Codemode Tool Exposure Modes (`codemode.mode: on | only`, `prepareLoadout`)**:
  - Implemented `prepareLoadout` hook support on `CustomTool`:
    - `on` mode: automatically appends `Codemode: $tools->x([...]) resolves to a string.` to all declared tool descriptions.
    - `only` mode: hides declared tools completely from the agent, delegating their availability exclusively into codemode script execution.
- **Namespace Tool Inspection & Search (`describe_namespace`, `search_tools`)**:
  - Added `describe_namespace($name)` and namespace filtering on `search_tools()`, allowing scripts to inspect and query tools belonging to specific MCP servers or namespaces.
- **Lark Grammar Sampling for Codemode (`Source::GRAMMAR`)**:
  - Declared native Lark grammar sampling on the codemode tool, constraining model outputs to syntactically valid PHP on supported endpoints (`supportsOpenAIGrammarTools`).

### Added

- **Script Output Resource Limits & Circuit Breaker (`__CodemodeBridge`)**:
  - Implemented 16 MiB payload size and 100,000 item thresholds for `text()`, `image()`, and `echo` calls, terminating runaways with `LengthException` while preserving prior output.
- **Magic Byte Image Type Verification (`image()`)**:
  - Validated image payloads by sniffing binary magic bytes (PNG, JPEG, GIF, WebP) and stripping whitespace/newlines, preventing corrupted base64 payloads from causing provider 400 errors.
- **Automated Image Disk Persistence in Results**:
  - Automatically persisted rendered images to `pig-codemode-*.<ext>` on disk with an `[Image saved to ...]` banner, ensuring models can inspect generated images.
- **Structured Console & Multiblock Output Formatting**:
  - Consolidated `echo` outputs into trailing `<console_output>` blocks, joined adjacent text items with blank lines, and labeled multiblocks with `==> text N/M <==` headers.

## [0.5.16] - 2026-10-08

### New Features

- **Collapsible Skill Invocation Cards in Web UI (`app.js`, `style.css`)**:
  - Replayed `/skill:name` user messages as sleek collapsible `[skill] name` summary cards with child prompts cleanly separated, preventing raw `<skill>` XML blocks and `SKILL.md` contents from flooding the user chat bubble.
- **Web UI Notice Blocks & Dynamic Fallback Alerts (`msg-notice`, `model_fallback`)**:
  - Added quiet `.msg-notice` transcript line styling matching TUI `say()`.
  - Added real-time notifications when hitting quota walls and transitioning to fallback models (`model_fallback_notice`), automatically updating the active model selector bar.
  - Displayed virtual model routing telemetry in Web UI header (`→ routed-id • level`).
  - Added banner indicators for summarization and branch summary retries (`summarization_retry_*`).

### Added

- **Home Directory Expansion in MCP Stdio Configs (`Paths::expand`, `McpConfig`)**:
  - Automatically expanded `~` in `command`, `args`, and `cwd` fields of `mcp.json` stdio configurations, enabling clean, portable configs across different machines and user paths.

## [0.5.15] - 2026-10-08

### Added

- README and package documentation now explain how packages can be listed on pigagent.dev/packages via GitHub topic `pig-package` and preview assets (`extra.pig.image` / `extra.pig.video`).

### Changed

- Transitioned `pig-vless` proxy control lifecycle to match `/web` conventions: loading the extension sets UUID and listen address without opening ports; `/vless start | stop | restart | status` provide runtime daemon control.

### Fixed

- **Operator Precedence in Tool Renderer Checks (`ToolExecutionComponent::drawsItself`)**:
  - Parenthesized null-coalescing expressions in `ToolExecutionComponent::drawsItself()` (`($a ?? null) !== null || ($b ?? null) !== null`), eliminating `PHP Warning: Undefined array key "renderResult"` when invoking custom tools that provide no custom renderers.

## [0.5.14] - 2026-10-08

### New Features

- **Native VLESS Protocol Inbound Extension (`extensions/pig-vless`)**:
  - Implemented standalone, pure-PHP VLESS proxy inbound supporting TCP relay via dual asynchronous `Web\TcpConnection` pipelines over `Raw` protocol, allowing mobile clients (Shadowrocket) to proxy traffic.
  - Supported optional non-blocking TLS server handshake after socket acceptance.
  - Designed with opt-in configuration (`"vless": {}` in `settings.json`), automatic UUID generation and persistence, silent drop defense on authentication failures, and `/vless` share URL generation.

## [0.5.13] - 2026-10-08

### Fixed

- **Recursive Directory Deletion in WebSearch Test (`WebSearchExtensionTest`)**:
  - Replaced shallow directory cleanup in `WebSearchExtensionTest::cleanStaleChromeDirs` with recursive tree deletion (`removeTree`), preventing `rmdir(): Directory not empty` PHP warnings during Chrome temporary profile teardown on CI test runners.

## [0.5.12] - 2026-10-08

### New Features

- **MCP Stdio Transport Support (`--mcp-stdio`, `McpStdio`)**:
  - Implemented JSON-RPC 2.0 line-delimited communication over standard input/output for host environments that spawn MCP servers as subprocesses (e.g. `claude mcp add pig -- pig --mode mcp --mcp-stdio`).
  - Extracted shared dispatcher logic into `McpDispatcher` serving both Streamable HTTP and Stdio transports.
- **Concurrent `ask` Tool Call Serial Queue (`McpDispatcher`)**:
  - Automatically queued concurrent incoming `ask` tool calls in order of arrival instead of rejecting them with busy errors, executing turns sequentially while continuously sending HTTP `: keep-alive` frames during wait states.

### Added

- Added `--session <id>` / `-c` support documentation and test verification for `--mode mcp`, allowing external clients to attach to existing session histories.

### Changed

- Changed HTTP transport response protocol negotiation to dynamically select JSON or SSE streaming based on resolution timing (synchronous dispatch returns JSON, asynchronous turns open SSE streams).

## [0.5.11] - 2026-10-08

### Added

- Added `mcp` as a value of a hook's `$ctx->mode()`, allowing extensions and hooks to identify MCP callers; `--mode mcp` sets this mode.
- Recognized `PI_PROGRAM_STATUS=1|0` as well as `PIG_PROGRAM_STATUS` to force or silence OSC 7501 program status reporting.

### Changed

- Extracted the repetitive twelve-closure hook initialization across `PrintMode`, `RpcMode`, `InteractiveMode` and `McpMode` into `HookRunner::wire($session, $ui)`.

## [0.5.10] - 2026-10-08

### New Features

- **MCP Server Mode (`--mode mcp`)**:
  - Implemented standalone MCP server mode exposing an `ask` tool over Streamable HTTP (`Mcp\McpMode`, `McpServer`, `Web\Protocols\Sse`), allowing external MCP clients (Claude Desktop, Cursor) to delegate turns to pig.
  - Implemented SSE streaming support for `tools/call` progress notifications and pure-PHP HTTP stream termination (`TcpConnection::closeAfterSend`).

### Added

- Added `Web\Protocols\Sse`, the server side of `text/event-stream`, and `TcpConnection::closeAfterSend()` for a response whose end is the close.

### Fixed

- **Turn Failure Broadcast Sequence (`Agent::handleRunFailure`)**:
  - Aligned loop failure handling with upstream pi HEAD: emitting `message_start` -> `message_end` -> `turn_end` -> `agent_end` sequence upon uncaught loop errors, ensuring failing turns are persisted to session history and rendered in TUI/RPC instead of vanishing silently.
- **AST Unresolved Class Name Static Linting (`test/lint.php`)**:
  - Added AST tokenizer analysis checking all class references across `new`, `instanceof`, `catch`, return types, and parameter type hints to catch unimported class names at build time.
- **Missing Class Imports in Interactive, Print, and Web Modes**:
  - Fixed missing `use` statements in `InteractiveMode` (`CustomTool`), `PrintMode` / `RpcMode` (`Model`), `TcpConnection` (`Throwable`), and `HttpServer` (`Http`).

## [0.5.9] - 2026-10-08

### Fixed

- **Resilient Polling in Theme Watcher Test (`ThemeWatcherTest`)**:
  - Replaced rigid 0.8s sleep in `ThemeWatcherTest` with `runUntil()` predicate polling and an expanded timeout, preventing false-positive test failures caused by scheduling jitter on busy CI virtual machines.

## [0.5.8] - 2026-10-08

### Fixed

- **Changelog Release Entry Bracket Assertion in CI (`UpdateCheckTest`)**:
  - Aligned `testTheChangelogHasAnEntryForAReleasedVersion` to recognize bracketed version headers (`## [x.y.z]`) matching the Keep a Changelog standard format, fixing tag-triggered CI test runner failures.

## [0.5.7] - 2026-10-08

### Added

- Added `-e <source>` shorthand for single-run extension package loading (`--extension`).

### Fixed

- **Exclude Quota and Account Limit Errors from Bug Report Prompts (`BugReport::worthReporting`)**:
  - Excluded provider quota wall, token rate limit, and billing errors (`Retry::isProviderLimitError()`) from triggering `/bug` report prompts, eliminating false alarm bug reports on normal provider usage limits.

## [0.5.6] - 2026-10-08

### New Features

- **Package Manager Architecture (`pig install | remove | update | list | config`, `packages` setting)**:
  - Ported upstream pi's package manager for Git repositories and local directory sources: `git:github.com/user/repo[@ref]`, `https://…`, `git@host:…`, `./path`.
  - Implemented user and project (`-l`) installation scopes with project trust gating (`ProjectTrust`).
  - Supported `composer.json` `extra.pig` manifests or conventional resource directories (`extensions/`, `skills/`, `prompts/`, `themes/`).
  - Supported upstream `!glob`, `+path`, and `-path` resource filters and `autoload: false` delta overrides.
  - Implemented package update commands (`update <source>`, `--extensions`, `--all`, `--extension <source>`) with asynchronous `git ls-remote` version checks (`PackageUpdateCheck`).
  - Added interactive `pig config [-l]` resource toggle screen for browsing and customizing package resources.

### Added

- Added `scripts/bench-tui.php` standalone benchmarking script for measuring frame render latencies, byte throughput, and peak memory consumption across real session transcripts.
- `npm:` and `composer:` sources are refused by name; pig runs no package manager inside a package and requires a package's own `vendor/autoload.php` when present.
- `pig update` without a target updates pig only and says packages were skipped, as upstream does (it used to also refresh the bundled extensions; that is `--extensions` now).
- `pig config [-l]` — upstream's resource screen: a package's extensions, skills, prompts and themes switched on and off, in the user's settings or as project overrides (Tab switches), writing `+path` / `-path` filters.
- `-e <source>` (`--extension`) takes a package source for one run — `pig -e git:github.com/user/repo` clones it under `tmp/extensions` and writes nothing to the settings — as well as a file or directory as before.
- Local package paths are written to the settings relative to the settings file (`../tools`), so a committed `.pig/settings.json` works on every checkout.

### Fixed

- A quota wall no longer shows the bug-report hint: `BugReport::worthReporting()` excludes `isProviderLimitError()` as well as the retryable errors, since v0.5.4 classifies "quota reached" as a limit for the fallback models rather than a retryable error.

## [0.5.5] - 2026-10-08

### Added

- Bound upstream's `app.message.copy` (`ctrl+x`): in fullscreen with `fullscreenCopyOnSelect` off it copies the mouse selection, otherwise the last answer, with a `Copied!` flash in fullscreen.

### Changed

- **Transcript spacing and the two padding settings, as upstream has them**:
  - A typed `!cmd` / `!!cmd` is drawn by a new `BashExecutionComponent` (upstream's `bash-execution.ts`): rule, bold command, last 20 lines, loader, status parts, rule; `!!` in `dim`. The `Not added to the conversation` note is gone with it.
  - `/hotkeys` and `/help` draw in the changelog's framed block with a bold *Keyboard Shortcuts* title; `/new` says `✓ New session started` with a blank row under it; *What's New* has upstream's spacer under the title; every overlay list has a blank row under it.
  - Added the `outputPad` (0/1) and `editorPaddingX` (0–3) settings with their `/settings` rows *Output padding* and *Editor padding*, threaded through every message, tool, command and error component and the editor.

## [0.5.4] - 2026-10-08

### New Features

- **Automatic model fallback at a quota wall (`fallbackModels`)**:
  - A turn that ends on a quota / billing limit error now moves on to the next entry of `fallbackModels` and is sent again there, instead of ending the run. The switch is kept for the session like a `/model` typed by hand, not written to `defaultModel`; `:level` on an entry sets the thinking level, otherwise the current one carries over.
  - Added `ModelFallbackEvent` (RPC `model_fallback` with `from`, `to`, `error`) and a transcript line in the terminal.
  - Added `Retry::isProviderLimitError()`, the quota / billing half of the retry classifier, with pi-antigravity's `Quota reached.` wording recognised as a limit.

## [0.5.3] - 2026-10-08

### New Features

- **Response Thinking Level Tracking (`AssistantMessage::$thinkingLevel`)**:
  - Added `AssistantMessage::$thinkingLevel` stamped on every streamed response via `AgentLoop::streamAssistantResponse()` matching upstream pi.
  - Persisted thinking level in `.jsonl` session files via `MessageJson`, allowing virtual model routers to inspect previous response thinking levels on continuation turns (`RoutedModel::$thinkingLevel`).
- **Quota Exhaustion Fallback Models Setting (`Settings::fallbackModels`)**:
  - Added `fallbackModels` setting allowing users to configure an ordered list of fallback model patterns (e.g. `google/gemini-3.8-flash:medium`) for automatic switching when a primary model encounters quota depletion.

### Changed

- **Flat Architecture for Virtual Model Contracts**:
  - Moved `VirtualModelRegistry`, `VirtualModelDefinition`, `ModelRouteRequest`, `ModelRoute`, and `RoutedModel` into the root `Pig\CodingAgent` namespace alongside `McpServerRegistry` for consistent package design.

### Fixed

- **Pre-emptive Offline Guard for Auth Token Renewal (`Auth::fresh`)**:
  - Added upfront `\Fiber::getCurrent() === null` check before initiating OAuth refresh connections, preventing startup and session restore operations in offline or DNS-less environments from failing with socket connection errors.
- **Provider Key Isolation for New Built-in Providers (`WithoutProviderKeys`)**:
  - Added environment variable clearing for DeepSeek, Azure, Mistral, and all newly supported provider endpoints in test isolation fixtures.

## [0.5.2] - 2026-10-08

### New Features

- **Virtual Models & Dynamic Request Routing (`VirtualModels`, `AgentLoopConfig::$prepareRequest`)**:
  - Added `$pi->registerVirtualModel()` and `unregisterVirtualModel()` to `ExtensionApi`: virtual models appear in the model selector (`api: pi-virtual`) and resolve dynamically on every request right before dispatch via `AgentLoopConfig::$prepareRequest`.
  - The virtual model router inspects the request reason (`user`, `continuation`, `retry`, `direct`), previous responses, and failed responses, clamping thinking levels with `ThinkingLevel::clampedFor()`.
  - Persisted router state on session branches as `pi.virtual-model-state` custom entries; the footer and UI display routed physical models (e.g. `auto • high → gpt-5.6-luna`), and providers composed solely of virtual models require no API key.

## [0.5.1] - 2026-10-08

## [0.5.0] - 2026-10-08

### New Features

- **New Native Protocol Implementations (`AzureOpenAiResponses`, `OpenAiCodexResponses`, `PiMessages`)**:
  - Added native support for `azure-openai-responses` and Azure Chat Completions (`Providers\Azure`) with automatic endpoint normalization and deployment routing.
  - Added native support for `openai-codex-responses` (ChatGPT Codex backend) with full OAuth PKCE and device code authorization (`Utils\Oauth\OpenAiCodex`, `DeviceCodeFlow`).
  - Added native support for `pi-messages` wire protocol powering the Radius gateway (`Providers\PiMessages`, `PiMessagesEventConverter`, `RadiusConfig`).
- **Non-Chat Model Architecture (`ModelType`, `ClassifierModel`, `ImageModel`, `FauxProvider`)**:
  - Introduced `ModelType` (`chat`, `classifier`, `image`), supporting classification models (`Models::classify()` via System One, Cloudflare Workers AI, and LlamaCpp) and image generation models (`Models::generateImages()` via OpenRouter).
  - Added `FauxProvider` for deterministic offline provider stream testing.
- **Full Catalog Provider Coverage (25+ Providers Aligned with Upstream pi 1.1.0)**:
  - Added official model catalog definitions and metadata across 25+ major providers: DeepSeek, OpenRouter, Vercel AI Gateway, Together, Fireworks, Baseten, Hugging Face, NVIDIA, MiniMax, Moonshot, Kimi For Coding, Meta, OpenCode, Qwen Token Plans, Z.ai Coding Plan, and Ant Ling.
  - Sourced Cloudflare Workers AI and AI Gateway models (`Providers\Cloudflare`).
  - Routed xAI models natively to `Api::OpenAiResponses` with full reasoning support.
- **Dynamic Antigravity Model Catalog Discovery & Grouping (`Discovery`, `Grouping`)**:
  - Implemented background catalog hydration and TTL-cached discovery against Google endpoints (`Discovery`), updating local `models-store.json` and grouping models (`Grouping`) without requiring full restarts.
- **Transcript-Driven Tool Loadout & Prompt Projection (`ToolLoadout`, `SystemPrompt`)**:
  - Automatically restored active and pending tool sets from transcript `SystemMessage` entries upon session resume or tree navigation.
  - Supported `forceSystemPrompt` projections and handler-injected prompt sections (`withSections`) without corrupting disk session history.

### Changed

- **Clean Working Directory Prompt Sections (`SystemPrompt`)**:
  - Stripped volatile second-by-second timestamps from `cwd` prompt sections, preventing cache prefix invalidations on unchanged system prompts.
- **Compaction & Branch Summary Cancellation Parity (`InteractiveMode`)**:
  - Aligned status indicators for `/compact`, threshold compaction, overflow compaction, and branch summarization with upstream pi, allowing graceful cancellation via escape key with localized key labels.

## [0.4.37] - 2026-10-08

### Changed

- **Retired Outdated Gemini 3.x Overrides in Model Generator (`scripts/generate-models.php`)**:
  - Removed superseded manual `fix` overrides for Gemini 3.x models (`gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-pro-preview`, `gemini-3.1-pro-preview-customtools`) as models.dev now natively catalogs their verified reasoning effort options and constructs identical `thinkingLevelMap` instances.
  - Streamlined `GenerateModelsTest` to align with the retired overrides while maintaining full test coverage.

## [0.4.36] - 2026-10-08

### New Features

- **Amazon Bedrock & Google Vertex AI Zero-Dependency Native Emulation (`Providers\Bedrock`, `Providers\GoogleVertex`, `Utils\Aws`, `Utils\GoogleAuth`)**:
  - Implemented pure PHP native protocol support for Amazon Bedrock and Google Cloud Vertex AI without external SDK packages, verified byte-for-byte against upstream pi test fixtures recorded via Node.js oracle runners.
  - Implemented SigV4 signing engine (`SignatureV4`), AWS binary event framing (`EventStream`), AWS default credential chain (`CredentialChain`, covering environment, `~/.aws` profiles, STS AssumeRole, WebIdentity, and IMDS), and Google ADC credential discovery with RS256 JWT tokens.
- **Transcript Context Architecture with First-Class `SystemMessage` (`TranscriptContext`, `SystemMessage`, `AgentLoop`, `AgentSession`)**:
  - Aligned context architecture with upstream pi: embedded system prompts and tools into conversation history via initial `SystemMessage` instances and incremental delta updates (`toolsAdded`, `toolsRemoved`, prompt section diffs), guaranteeing consistent compaction and replay across sessions.
- **Dual-Layer Provider & Session Retry Engine (`Pig\Ai\Utils\Retry`, `ProviderRetry`)**:
  - Ported upstream pi's text-pattern-based retry classifier (`RETRYABLE_PROVIDER_ERROR_PATTERN`), decoupling retry decisions from internal HTTP status guesswork.
  - Added native provider-level retries (`retryProviderRequest`) with `retry-after` header adherence, and expanded session retry countdown with dynamic seconds ticks and localized interrupt key hints.
- **Provider Request Options & Lifecycle Decoupling (`StreamOptions`, `HookRunner`)**:
  - Decoupled provider extension events (`onPayload`, `transformHeaders`, `onResponse`, `onProviderStreamEvent`) from global HTTP client observers onto per-request option lifecycles.
  - Replaced hardcoded 60s HTTP read timeouts with upstream pi's 300s default idle timeout (`HttpClient::useIdleTimeout()`).

### Fixed

- **Antigravity Protocol Enhancements (`AntigravityApi`)**:
  - Automatically re-requested empty responses up to twice with backoff, supported runtime model fallback to older designations on 404, and aligned error diagnostic text with `pi-antigravity`.

## [0.4.35] - 2026-10-08

### New Features

- **V8-Compatible JSON Parse Error Modeling (`Pig\Ai\Utils\JsJson`)**:
  - Introduced `Pig\Ai\Utils\JsJson` reproducing V8's exact `JSON.parse` SyntaxError diagnostics (e.g. `Unexpected token 'x', "..." is not valid JSON`), bringing SSE chunk parsing, Mistral streams, and StreamProxy gateway error messages into exact parity with upstream pi.
- **Provider HTTP SDK Error Formatting Alignment (`Pig\Ai\Utils\ErrorBody`, `Retry`)**:
  - Formatted provider errors matching official SDK outputs: Anthropic matching `@anthropic-ai/sdk` (`401 {"type":"error",...}`), Gemini matching `@google/genai` (`{"error":{"code":429,...}}`), and OpenAI/Mistral via `ErrorBody`.
  - Updated `Retry::STATUS` extraction to accurately detect HTTP status codes across all official SDK error formats.
- **Upstream Context Overflow Alignment (`Overflow`, `NON_OVERFLOW_PATTERNS`)**:
  - Expanded `Overflow` patterns to 25 entries matching upstream pi, adding specific matchers for Mistral, Kimi, MiniMax, and Together.
  - Added `NON_OVERFLOW_PATTERNS` to ensure rate-limit and token-quota throttling messages are never mistakenly treated as context window overflows.
  - Restricted bodiless 400/413 errors strictly to Cerebras and enabled Xiaomi MiMo 99% length-stop detection.
- **Mistral Response Headers Timeout & Global Pig User-Agent (`PigUserAgent`, `Mistral`)**:
  - Configured 60s response headers timeout for Mistral requests without cutting off long-running streams.
  - Injected `User-Agent: pig (<platform> <release>; <arch>)` via `PigUserAgent::get()` across all provider requests, overridable by model-specific headers.

### Fixed

- **StreamProxy Protocol Parity & Status Text Preservation (`StreamProxy`)**:
  - Strictly enforced `data: ` line prefix requirements matching upstream pi.
  - Preserved status text in proxy error responses (`Proxy error: ${status} ${statusText}`).
- **Antigravity Deferred Error Stream Processing (`AntigravityApi`)**:
  - Deferred error finishReason evaluation until stream completion, ensuring usage metadata and content received on the terminal chunk are not lost.

## [0.4.34] - 2026-10-08

### New Features

- **Dedicated Mistral Conversations Provider (`Providers\Mistral`, `Api::MistralConversations`)**:
  - Fully ported upstream pi's `mistral-conversations.ts`, completely decoupling Mistral from `OpenAiCompletions`.
  - Added native thinking stream chunk decoding, automatic 9-character alphanumeric tool call ID generation, runtime `reasoning_effort` versus `prompt_mode: "reasoning"` dispatch based on `thinkingLevelMap`, and native error formatting (`Mistral API error (<status>): <body>`).
  - Removed deprecated `requiresMistralToolIds` and `mistral.ai` host quirks from `OpenAiCompat`.
- **`StopReason::Deferred` Support across Agent & Session Persistence (`StopReason`, `AssistantMessage`, `MessageJson`)**:
  - Added `StopReason::Deferred` and `AssistantMessage::$deferred` handle tracking for deferred turns, persisting handles across `.jsonl` session storage and `TransformMessages` history replay.
- **Upstream Model Generator Parity for Copilot, Google, and Z.ai (`generate-models.php`, `Models`)**:
  - Applied `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS` (1,000,000 context window) across all eligible Copilot models and populated models.dev list pricing.
  - Built official `thinkingLevelMap` instances for Google (including Gemma 4 two-tier thinking) and Mistral models.
  - Configured Z.ai models from `zai-coding-plan` with `zaiToolStream: true`.

### Fixed

- **OpenAI Responses Concurrent Slot Routing & Error Unwrapping (`OpenAiResponses`, `ErrorBody`)**:
  - Re-architected item stream dispatching using `output_index` slots, eliminating delta cross-talk and corruption during interleaved reasoning and parallel tool calling outputs.
  - Standardized error formatting via `Pig\Ai\Utils\ErrorBody` matching upstream pi's `formatProviderError()`.
- **Provider Stream Disconnection & Error Safeguards (`Anthropic`, `OpenAiCompletions`, `Google`, `JsonRepair`)**:
  - Converted unannounced stream drops lacking `finish_reason` in completions or `finishReason` in Google into explicit provider errors (`Stream ended without finish_reason` / `Google stream ended without a finish reason`).
  - Integrated `JsonRepair::parse()` for Anthropic SSE events to repair control-character malformations and surface parse errors with full event debugging context instead of silently dropping chunks.

## [0.4.33] - 2026-10-08

### New Features

- **OpenAI Short-Context Capped Models & Long-Context Tier Pricing (`generate-models.php`, `Models`, `PricingTier`)**:
  - Aligned model generation overrides for `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS` (gpt-5.4, gpt-5.5, GPT-5.6, GPT-6, 6.1 Sol), capping context windows at 272,000 (output 128,000) to trigger compaction before OpenAI's expensive long-context pricing threshold.
  - Implemented `withOpenAiLongContextPricing()` for `OPENAI_LONG_CONTEXT_PRICING_MODEL_IDS` (2x input/cache, 1.5x output past 272k) and set gpt-5-pro max output tokens to 128,000.
- **Chat Completions Caching, Affinity Headers & Anthropic Markers (`OpenAiCompletions`, `OpenAiCompat`)**:
  - Transmitted `prompt_cache_key` on `api.openai.com` endpoints and `x-session-id` on affinity-enabled providers (e.g. OpenRouter).
  - Applied Anthropic `cache_control` markers on system prompt, final tool, and final user block when `cacheControlFormat` is `anthropic`.
- **Strict Custom Model Pricing Tier & Limit Validation (`CustomModels`)**:
  - Implemented TypeBox-compatible schema validation for `cost.tiers`, `inputLimits`, and `promptCache` in `models.json`, rejecting malformed numeric fields with diagnostic path errors instead of silently falling back to zero rates.

### Fixed

- **Premature Stream Termination Safeguards (`StopReason::Pending`, `Anthropic`, `OpenAiResponses`)**:
  - Initialized stream builder stop reasons as `StopReason::Pending`.
  - Converted uncompleted streams that terminate without `message_stop` / `stop_reason` or Responses terminal events into explicit provider errors (`Anthropic stream ended without a stop reason`, `OpenAI Responses stream ended before a terminal response event`) rather than misrepresenting aborted turns as successful completions.
- **OpenAI Responses SDK Error Translation & ChatGPT Quota Deep Links (`OpenAiResponses`)**:
  - Mapped SSE error events matching the `openai` SDK (`sdkEvent()`), unwrapping nested API error payloads cleanly.
  - Appended `\nCheck your ChatGPT usage: https://chatgpt.com/settings/usage` when encountering `subscription_sharing_usage_limit_exceeded`.
- **Azure Encrypted Reasoning Backfill & Arguments Completion (`OpenAiResponses`)**:
  - Backfilled encrypted reasoning payloads from `response.completed.response.output` for endpoints (such as Azure) that omit encrypted content from delta items.
  - Replaced partial arguments and dispatched missing deltas upon receiving `function_call_arguments.done`.
- **StreamProxy Interrupted Connection Detection & Provider Thinking Level (`StreamProxy`)**:
  - Detected proxy disconnections occurring without terminal `done` or `error` events, throwing `Connection closed by proxy server before the response completed`.
  - Relayed `providerThinkingLevel` and updated final tool call snapshots on `toolcall_end`.

## [0.4.32] - 2026-10-08

### New Features

- **Context-Aware `max_tokens` Headroom Clamping & Budget Adjustment (`Estimate`, `Stream`)**:
  - Introduced `Pig\Ai\Utils\Estimate` to calculate approximate token consumption from message character counts (~3.5 UTF-16 characters per token).
  - Implemented upstream pi's `clampMaxTokensToContext()` and `adjustMaxTokensForThinking()`: automatically clamping `maxTokens` to the context window headroom and expanding budget-thinking allocations up to model ceilings while safeguarding 1,024 tokens for final responses, preventing Anthropic 400 budget-exceed errors.
- **Anthropic Tool-Inclusive Cache Breakpoints & Session Affinity (`Anthropic`, `AgentSession`, `SimpleStreamOptions`)**:
  - Injected ephemeral cache breakpoints across system prompt, the final tool declaration (when supported), and the final user block, supporting 1-hour cache retention (`cacheRetention: long` / `PI_CACHE_RETENTION=long`).
  - Propagated active session file IDs through `Agent` into stream options, transmitting `x-session-affinity` / `x-session-id` headers and Responses `prompt_cache_key` for sticky provider caching.
- **Comprehensive Upstream Thinking Level Maps for Claude & GPT Models (`Models`, `ThinkingLevel`)**:
  - Fully populated `thinkingLevelMap` across Anthropic, OpenAI, and Copilot GPT models matching upstream pi's generator (`applyThinkingLevelMetadata()`), unlocking `xhigh` on capable models (Opus 4.7+, gpt-5.2+, GPT-6) and properly respecting `off: null` restrictions on non-disableable reasoning models.
- **Full-Fidelity Gateway Context & Model Serialization (`StreamProxy`, `PricingTier`)**:
  - Serialized context as upstream transcripts (system message with prompt and `toolsAdded`) with complete tool definitions, forwarding model `thinkingLevelMap`, `PricingTier` pricing tiers, and session parameters over `StreamProxy`.

### Fixed

- **Authoritative OpenAI Responses Thinking & Incomplete Status Mapping (`OpenAiResponses`, `Models`)**:
  - Replaced legacy `# Juice: 0` prompt hack with official `reasoning: {effort: map.off ?? "none"}` matching upstream `thinkingLevelMap` definitions.
  - Enforced a minimum `max_output_tokens` of 16, avoiding API rejections on minimal budgets.
  - Mapped only `max_output_tokens` incomplete statuses to `StopReason::Length`, while treating other truncation causes as actionable `Response incomplete: <reason>` errors.
- **Anthropic Refusal, Sensitive, and Unknown Stop Reason Handling (`Anthropic`)**:
  - Correctly intercepted `refusal` and `sensitive` stop reasons, converting them to error events with full explanatory details rather than letting sessions silently succeed with missing turns.

## [0.4.31] - 2026-10-08

### New Features

- **OpenAI Grammar Tools & Custom Constrained Sampling (`ConstrainedSampling`, `OpenAiResponses`)**:
  - Implemented the grammar half of upstream pi's constrained sampling (`resolveGrammarConstrainedSampling`, `createGrammarToolInputProperties`, `getGrammarToolInput`, and `appendGrammarToolInputJsonDelta`), supporting custom tools with Lark or regex grammars on compatible endpoints (e.g. OpenAI `supportsOpenAIGrammarTools`).
  - Added streaming support for `response.custom_tool_call_input.delta` and `.done` in `OpenAiResponses`, translating custom tool text into JSON argument deltas and replaying `custom_tool_call` and `custom_tool_call_output` in conversation history.
- **TypeBox Value.Convert Parity for Built-in Tools (`Tool::$typeBox`, `ToolArguments::valueConvert()`)**:
  - Added `typeBox: true` flag to all built-in tools (`bash`, `edit`, `find`, `grep`, `ls`, `read`, `write`), running `valueConvert()` matching upstream typebox 1.3.27 behavior (converting `"path": null` to `"null"`, `""` for numbers to `0`, `"1"`/`"TRUE"` to booleans, and scalar wrapping for array properties) prior to standard schema coercion.
- **Anthropic Protocol Parity & Managed-Effort Support (`Anthropic`, `AnthropicCompat`, `AssistantMessage`)**:
  - Extended `AnthropicCompat` with `supportsTemperature`, `supportsEagerToolInputStreaming`, and `supportsMidConvoEffort`, mapping models via `AnthropicCompat::forBuiltIn()`.
  - Added support for managed-effort Claude models (Opus 5, Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1): enforcing adaptive thinking with `block_binding`, sending managed-effort betas, persisting `providerThinkingLevel` on `AssistantMessage` and `MessageJson`, and inserting effort-only system messages across historical turns.
  - Aligned header generation with upstream: omitting temperature when thinking or on temperature-unsupported models (Opus 4.7+), sending tools with `eager_input_streaming: true` (using `fine-grained-tool-streaming` only when unsupported), and appending `anthropic-dangerous-direct-browser-access: true`.
  - Fully populated `Models::anthropicThinkingLevelMap()` including `xhigh`, `max`, and 5.5 overrides matching upstream's generator.
- **Copilot Claude Model Routing & Token Endpoint Alignment (`Models`, `generate-models.php`, `Anthropic`)**:
  - Updated generator pattern `needsResponsesApi` to match `gpt-`, `grok-`, `oswe`, and `mai-`, while routing all Copilot Claude 4.x/5.x models to `anthropic-messages`.
- **Nested Empty Objects Preservation in Model Routing (`CustomModels::keepRoutingObjects()`)**:
  - Maintained nested empty objects as `stdClass` instances in `openRouterRouting` and `vercelGatewayRouting` so wire payloads serialize as `{}` instead of `[]`.

## [0.4.30] - 2026-10-08

### New Features

- **Tool Argument Schema Coercion (`ToolArguments`, upstream `coerceWithJsonSchema`)**:
  - Implemented upstream pi's `coerceWithJsonSchema()`: automatically coerced stringified numeric arguments (`"10"` -> `10`), string booleans (`"true"`/`"false"` -> `true`/`false`), primitive string conversions, and required null primitives to type zero values (`""`, `0`, `false`) before JSON schema validation, eliminating tool rejections caused by LLM argument type hallucinations.
- **Anthropic Thinking Control & Summarized Output Parity (`Anthropic`, `AnthropicOptions`, `Models`)**:
  - Explicitly emitted `thinking: {type: "disabled"}` when reasoning is switched off unless model metadata indicates `off: null` (such as Fable 5 and managed-effort Claudes), preventing adaptive models from silently executing thinking loops and billing.
  - Defaulted `thinking.display` to `"summarized"` across budget and adaptive modes (overridable via `AnthropicOptions::$thinkingDisplay`), ensuring reasoning text streams consistently on newer Claude models (such as Opus 4.7+).
- **GitHub Copilot Claude Protocol Routing Alignment (`Models`, `Anthropic`, `generate-models.php`)**:
  - Routed Copilot Claude 4.x/5.x models to `anthropic-messages` via `copilotApi()` matching upstream pi.
  - Implemented Bearer token authorization, dynamic host selection from token `proxy-ep`, Copilot dynamic telemetry headers, and adaptive thinking metadata in `Anthropic` provider for `github-copilot`.
- **OpenRouter & Vercel Gateway Routing Compatibility (`OpenAiCompat`, `CustomModels`, `StreamProxy`)**:
  - Supported `openRouterRouting` and `vercelGatewayRouting` in `models.json` compat blocks, merging provider and model settings and relaying preferences through proxy and client requests.
- **Custom & Extension Tools Strict Sampling Propagation (`CustomTool`, `WrappedCustomTool`)**:
  - Added `$constrainedSampling` to `CustomTool` and forwarded it in `WrappedCustomTool::definition()`, allowing extension tools to leverage strict tool sampling.

### Fixed

- **OpenAI Responses Tool Result Inline Images (`OpenAiResponses`)**:
  - Aligned `convertToolResultOutput()` with upstream pi by embedding tool result images directly inside `function_call_output.output` (`input_text` + `input_image`), removing separate synthetic user turns that confused models.

## [0.4.29] - 2026-10-08

### New Features

- **Strict Tool Sampling on Built-in Tools & Optional Parameter Null Normalization (`ToolArguments`, `BashTool`, `EditTool`, `ReadTool`, `WriteTool`)**:
  - Configured built-in tools (`bash`, `edit`, `read`, `write`) with `['type' => 'json_schema', 'strict' => 'prefer']`, enabling strict schema enforcement on capable endpoints (Anthropic with `supportsStrictTools`, OpenAI with `supportsStrictMode`, Gemini 3+ `parametersJsonSchema`).
  - Implemented upstream pi's `normalizeOptionalNulls()` in `ToolArguments::validate()`, dropping unrequired `null` arguments whose schemas reject null prior to schema checking, preventing spurious `limit: must be number` validation rejections when models omit optional parameters under strict sampling.
- **Anthropic Adaptive Thinking & Strict Tools Metadata (`AnthropicCompat`, `Anthropic`)**:
  - Replaced ad-hoc model ID string pattern checks with declarative `AnthropicCompat` metadata (`forceAdaptiveThinking`, `supportsStrictTools`), properly identifying Opus 4.6, Sonnet 4.6, Opus 5, and related adaptive models without warnings or missed adaptive configs.
  - Omitted `interleaved-thinking` beta headers on non-thinking turns and adaptive thinking runs.
- **OpenAI Completions Thinking Format Architecture (`OpenAiCompat`, `OpenAiCompletions`)**:
  - Added support for diverse thinking formats matching upstream pi: `deepseek` (`thinking: {type}` + `reasoning_effort`), `zai` (`thinking: {type, clear_thinking: false}`), `openrouter` (`reasoning: {effort}`), `together` (`reasoning: {enabled}, reasoning_effort`), `qwen`, `chat-template`, and `baseten` with template argument interpolation.
  - Fully aligned `OpenAiCompat::detect()` with upstream `detectCompat()`, matching both provider IDs and lowercased URLs for DeepSeek, z.ai, Moonshot, Together, NVIDIA, Cloudflare, Chutes, Cerebras, and OpenRouter.
  - Enhanced `OpenAiCompat::resolve()` to merge `compat` settings key-by-key over detected settings, enabling model-level and provider-level `compat` overrides from `models.json`.

### Fixed

- **Authoritative Responses API Thinking Text Reconstruction (`OpenAiResponses`)**:
  - Reconstructed reasoning text from finished `output_item.done` items (joining `summary` parts, then `content` raw reasoning text, falling back to streamed deltas), preventing trailing newlines from summary part delimiters and retaining raw reasoning text.
- **Accurate Tool Result Output Placeholders (`OpenAiCompletions`, `OpenAiResponses`, `Google`)**:
  - Output `(see attached image)` only when images are present without text, and emit `(no tool output)` or `""` for empty results, eliminating bogus image references.

## [0.4.28] - 2026-10-08

### New Features

- **JSON-Schema Constrained Sampling Architecture (`ConstrainedSampling`, `UnsupportedStrictJsonSchema`)**:
  - Fully ported upstream pi's strict JSON schema transformation engine (`makeStrictJsonSchema`), enforcing `additionalProperties: false`, required property backfilling, and optional-to-null union expansion for strict tools on supported models (e.g. Gemini 3+ `parametersJsonSchema`).
  - Added `Tool::$constrainedSampling` supporting `json_schema` strict sampling configurations (`prefer` vs `require`).

### Fixed

- **OpenAI Responses API Refusal and Finished Output Text Reconstruction**:
  - Reconstructed complete assistant text from `response.output_item.done` items, combining both `output_text` and `refusal` segments and preventing refusal messages from being dropped.
- **Graceful Image Downgrade for Text-Only Models (`TransformMessages`)**:
  - Implemented `downgradeUnsupportedImages()` in `TransformMessages::apply()`: automatically replaced images in user messages and tool results with structured placeholder notes (`(image omitted: model does not support images)` / `(tool image omitted: model does not support images)`) when targeting models without vision input, coalescing adjacent images into single lines.
- **DeepSeek Reasoning Content Filler (`OpenAiCompat`)**:
  - Added `requiresReasoningContentOnAssistantMessages` compatibility detection for DeepSeek endpoints, automatically populating `reasoning_content: ""` on replayed assistant turns without reasoning content and eliminating 400 parameter errors.

## [0.4.27] - 2026-10-08

### Fixed

- **Fixed Linux / Ubuntu `dash` Incompatibility in RPC Client Child Launcher (`RpcClient`)**:
  - Replaced hardcoded `/bin/sh` in `RpcClient::withoutInheritedFds()` with preferred `/bin/bash` (falling back to `/bin/sh` max fd 9).
  - Eliminated fatal exit code 127 (`exec: 10: not found`) on Debian/Ubuntu systems where `/bin/sh` links to `dash` and rejects multi-digit file descriptor redirects, enabling RpcClient and WebMode sub-processes to spawn cleanly on Linux.
- **Fixed Linux Extension Module Availability in Codemode Sandbox (`Sandbox`)**:
  - Removed `-n` (no configuration files) from `Sandbox::command()`, ensuring shared extensions (like `ext-mbstring` on Ubuntu CI runners) load properly while retaining strict `-d` security overrides (`disable_functions`, `open_basedir`, and `memory_limit`).
  - Added explicit `$result['ok']` assertion in `CodemodeTest` to prevent array key warnings on unexpected execution failures.
- **Robust Child Session Binding Assertion in Web Mode Test (`WebModeTest`)**:
  - Added safety assertion verifying `session_bound` before array indexing on `SessionPool::all()`, eliminating undefined array key warnings.

## [0.4.26] - 2026-10-08

### Fixed

- **Google / Gemini Thinking Configuration and Level Model Compatibility (`GoogleShared`, `Stream`)**:
  - Aligned Google thinking mode selection with upstream pi's `usesGoogleThinkingLevel()`: correctly matched Gemini 3.x, `-latest` aliases, and Gemma 4 models to named thinking levels rather than falling back to `-1` token budgets.
  - Implemented `disabledGoogleThinkingConfig($model)`: prevented 400 errors ("Budget 0 is invalid. This model only works in thinking mode") on models without an `off` level by clamping to their lowest supported level.
  - Aligned Gemini 2.5 thinking token budget tables across 2.5 Pro, 2.5 Flash, and 2.5 Flash-Lite.
- **OpenAI Responses Multi-Phase Signatures (`TextSignatureV1`)**:
  - Ported upstream's `TextSignatureV1` encoding (`{"v":1,"id":...,"phase":...}`), preserving message IDs and reasoning phases (`commentary` / `final_answer`) across multi-turn interactions.
- **Chat Completions Reasoning Streaming and OpenRouter `reasoning_details` Parity**:
  - Merged streaming `reasoning.text`, `reasoning.summary`, and `reasoning.encrypted` deltas into thinking block signatures for OpenRouter reasoning models, echoing them back as `reasoning_details`.
  - Read only the first non-empty reasoning delta field, eliminating duplicate thinking text on endpoints (such as chutes.ai) that send multiple reasoning keys.

## [0.4.25] - 2026-10-08

### Fixed

- **Provider-Driven Tool Call ID Normalization (`TransformMessages`, `ShortHash`)**:
  - Fully ported upstream pi's per-provider `normalizeToolCallId` architecture: decoupled call ID rewriting from `TransformMessages` and delegated rule definitions to each target provider.
  - Aligned Anthropic ID sanitation: restricted IDs to `[a-zA-Z0-9_-]` and truncated to 64 characters, renaming the corresponding `toolResult` to match.
  - Aligned Chat Completions ID sanitation: transformed Responses API `call_id|item_id` pairs into `call_item` (with 40-character prefix + 8-char short hash fallback), preserving distinct call references across turn boundaries.
  - Aligned Responses API ID sanitation: converted foreign item IDs to `fc_<hash>` for supported providers (`openai`, `openai-codex`, `opencode`) and omitted `id` when switching models of the same provider to avoid OpenAI 400 errors.
  - Aligned Google / Gemini tool call ID requirements: included `id` in `functionCall` and `functionResponse` only for models that require tool call IDs (`requiresToolCallId()`: Claude, gpt-oss, and Gemini 3+).
  - Ported upstream pi's 32-bit UTF-16 deterministic `ShortHash` utility with dedicated unit test suite `ShortHashTest`.

## [0.4.24] - 2026-10-08

### Fixed

- **Dropped Errored / Aborted Assistant Turns from Future Model Replays (`TransformMessages`)**:
  - Aligned turn pruning with upstream pi: assistant turns ending in `StopReason::Error` or `StopReason::Aborted` are completely excluded from outgoing history replays.
  - Eliminated OpenAI Responses API 400 error `reasoning was provided without its required following item` when continuing from interrupted thinking turns.
  - Ensured dangling tool calls from prior completed turns are closed while calls originating from aborted turns are cleanly skipped without generating synthetic tool results.
- **Google / Gemini Base64 Signature Validation and Untagged Cross-Model Thinking (`GoogleShared`)**:
  - Added strict base64 validation (`isValidThoughtSignature`) on `thoughtSignature` before sending to Google API, avoiding 400 parameter errors caused by non-base64 tokens.
  - Maintained `thoughtSignature` on empty text parts when present, preserving Gemini's reasoning continuity.
  - Sent cross-model reasoning as untagged plain text, preventing models from mimicking thinking tags.
- **Moonshot / Kimi Choice-Level Usage Fallback (`OpenAiCompletions`)**:
  - Added fallback to inspect `choices[0].usage` when chunk root lacks `usage`, fixing zero-token accounting across Moonshot endpoints.

## [0.4.23] - 2026-10-08

### Fixed

- **Accurate Model Signature Scrubbing Across Model Changes (`TransformMessages`)**:
  - Aligned cross-model message sanitization with upstream pi's `isSameModel` rule: signatures (thinking signatures, text signatures, and tool thought signatures) are only preserved when provider, API, and model ID all match identically.
  - Converted thinking blocks into clean plain text without synthetic tags when switching models, preventing models from mimicking tag structures.
  - Automatically stripped redacted thinking blocks and empty thoughts when switching to a different model.
- **Accurate Token Usage and Prompt Cache Accounting (`OpenAiCompletions`, `GoogleShared`)**:
  - Fixed duplicate reasoning token counting in OpenAI-compatible completions by treating `completion_tokens` as the complete output figure and tracking `reasoning_tokens` strictly as a breakdown subset.
  - Added support for provider-specific prompt cache hit keys: DeepSeek (`prompt_cache_hit_tokens`), Kimi (`cached_tokens`), and OpenRouter (`cache_write_tokens`).
  - Subtracted `cachedContentTokenCount` from `promptTokenCount` in `GoogleShared::usage()` so cached tokens are accurately priced at the cache rate without double-billing.

## [0.4.22] - 2026-10-08

### Fixed

- **Anthropic `redacted_thinking` Block Support & Multi-Turn Continuation Integrity**:
  - Parsed `redacted_thinking` content blocks from Anthropic into `ThinkingContent::$redacted`, keeping encrypted reasoning payloads intact within `thinkingSignature` and avoiding protocol dropouts on multi-turn reasoning conversations.
  - Aligned `TransformMessages` to preserve redacted blocks across identical provider/model requests while stripping them across model changes without inventing fake thinking text.
- **Full Assistant Response Metadata & Extended Token Accounting**:
  - Added optional response metadata fields to `AssistantMessage` matching upstream pi: `responseId`, `responseModel`, `endTurn`, and `diagnostics` (`AssistantMessageDiagnostic`, `DiagnosticErrorInfo`).
  - Added `reasoning` token tracking to `Usage` across all providers reporting thinking output tokens.
  - Supported Anthropic 1-hour cache write tracking (`Usage::$cacheWrite1h`) and differential pricing calculation (2x base input rate).

## [0.4.21] - 2026-10-08

### Added

- **Raw Provider Stop Reasons (`AssistantMessage::$rawStopReason`)**:
  - Added `rawStopReason` property across `AssistantMessage`, `AssistantMessageBuilder`, and session persistence layer (`MessageJson`), recording unmapped provider-specific stop indicators.
  - Recorded exact provider stop tokens: Anthropic (`stop_reason`), Google/Gemini (`finishReason`), OpenAI Completions (`finish_reason`), and OpenAI Responses (`status` / `incomplete_details.reason`).
  - Included `rawStopReason` in `/bug` diagnostics reports to instantly differentiate Gemini `MALFORMED_FUNCTION_CALL` from safety blocks without requiring full transcript attachments.
  - Transparently preserved `rawStopReason` during message transformations, model switches, and session resolution.

## [0.4.20] - 2026-10-08

### Fixed

- **Google / Gemini Stop Reason Mapping and Truncated Tool Call Handling**:
  - Aligned stop reason priority with upstream pi's `google-generative-ai.ts`: mapped finish reasons prior to inspecting tool calls, ensuring only `STOP` finish reasons accompanied by tool calls transition to `StopReason::ToolUse`.
  - Prevented truncated or corrupted tool calls from executing: calls cut off by `MAX_TOKENS` remain `StopReason::Length`, and calls carrying `MALFORMED_FUNCTION_CALL` remain `StopReason::Error`.
  - Aligned Google raw stop reason error messages to upstream's format: `Provider stopped with: <raw reason>`.
  - Added regression unit tests `testACallCutOffByTheTokenLimitIsALengthNotAToolUse` and `testAMalformedCallIsAnErrorEvenWithACallPartInIt` in `GoogleTest.php`.

## [0.4.19] - 2026-10-08

### Changed

- **Official `@xterm/xterm` 6.0.0 and `@xterm/addon-fit` 0.11.0 Standard Vendoring**:
  - Replaced the previously bundled unofficial xterm re-bundle with official npm package builds (`xterm.mjs`, `xterm.css`, and `addon-fit.mjs`), copied byte for byte without modification.
  - Eliminated minification-induced parser crashes in WebTerminal and NodeWorkbench.
  - Added `VendoredXtermTest` with SHA-256 integrity verification to prevent bundle tampering and regressions.

## [0.4.18] - 2026-10-08

### Fixed

- **Fixed Leaked Inherited File Descriptors and Port Lingering in PTY Child Processes**:
  - Closed all inherited file descriptors past stdio (`fd > 2`) inside `PtyProcess::launcher()` prior to execing the target shell, preventing child processes from holding copies of server listening sockets and the pty master descriptor.
  - Eliminated process hangup failure where shell processes remained running after terminal closures and retained port bindings.
  - Fixed PHPUnit test hang in `PtyTest` caused by child processes holding open pipes.
- **Dedicated Slave Device Discovery via Pipeline Reporting (fd 3)**:
  - Added dedicated pipeline reporting (`fd 3`) in `PtyProcess::launcher()` to send the exact slave pty device path back to the server once the initial size is applied.
  - Replaced heuristic `lsof` / `/proc` polling in the parent process, preventing race conditions from misidentifying the server's own controlling terminal during fork and avoiding unexpected console resizes.
  - Automatically queues and applies early window resize requests once the launcher reports ready.

## [0.4.17] - 2026-10-08

### Fixed

- **Fixed Premature `agent_settled` Event Emissions During Active Prompt Runs**:
  - Aligned prompt lifecycle architecture with upstream pi's `_runAgentPrompt()`: moved retry handling (`prepareRetry()`), overflow compaction (`compactForOverflow()`), and follow-up/steering queue execution into the main post-run loop of the caller's fiber rather than resolving on individual runs.
  - Ensured `emitAgentSettled()` fires strictly in `runAgentPrompt()`'s `finally` block, firing exactly once per user prompt and eliminating bogus "Task Completed" desktop notifications while the agent is still actively working or retrying.
  - Aligned `AgentSession::isStreaming()` to track the entire active prompt lifecycle matching upstream's `_isAgentRunActive`.
  - Added dedicated unit tests in `AgentSettledTest.php`.
- **Fixed WebTerminal PTY Process Isolation, Vim Rendering, and Job Control**:
  - Implemented `PtyProcess::launcher()` executing `posix_setsid()`, opening slave devices as the session's controlling terminal (forkpty semantics), applying initial `stty` rows/cols, and executing shells under true job control.
  - Enabled Ctrl+C (`SIGINT`), Ctrl+Z (`SIGTSTP`), and `SIGWINCH` window resizing for foreground child processes (e.g. Vim, less).
  - Fixed UTF-8 chunk fragmenting across WebSocket text frames by buffering partial multibyte sequences in `PtyProcess::flushOutput()` and enforcing `JSON_INVALID_UTF8_SUBSTITUTE` in `Websocket::encode()`.
  - Replaced the vendored `xterm.js` (an unofficial re-bundle of 5.5 + FitAddon whose minifier broke `requestMode()`, throwing a ReferenceError on the DECRQM cursor blink query `?12$p` Vim sends at launch) with the official `@xterm/xterm` 6.0.0 `xterm.mjs` / `xterm.css` and `@xterm/addon-fit` 0.11.0 `addon-fit.mjs`, copied byte for byte.
  - Fixed EIO read handling on Linux when slave closes, avoiding 100% CPU busy loops.
  - Added dedicated test suites in `PtyProcessTest.php` and `VendoredXtermTest.php`.

## [0.4.16] - 2026-10-08

### New Features

- **Upstream Pi Standard Resource Loading Layout & Issue Diagnostics (`showLoadedResources()`)**:
  - Aligned startup resource display into dedicated `loadedResourcesContainer` under `builtInHeader`, rendering modular collapsible sections for `[Context]`, `[Skills]`, `[Prompts]`, `[Extensions]`, and `[Tools]`.
  - Added structured diagnostic warning blocks for `[Skill conflicts]` and `[Extension issues]` rendering file paths and error details when conflicts occur.

### Added

- **Extension UI Theme Management Parity**:
  - Aligned `HookUi::setTheme()` returning structured `array{success: bool, error?: string}` and accepting either a theme name string or a `Theme` instance.
  - Aligned `HookUi::getAllThemes()` returning rich `list<ThemeInfo>` with path metadata.
  - Added `McpManagerViewTest` verifying MCP server authorization URL and action rendering.

### Fixed

- **Fixed Markdown Theme Invalidation Escape Prefix Caching**:
  - Reset `$this->stylePrefix` on `Markdown::invalidate()` so dynamic theme switching accurately re-samples text style prefixes without leaking stale palette escape sequences across inline spans.

## [0.4.15] - 2026-10-08

### Added

- **Interactive Dynamic Text Components (`ThemedText`, `ExpandableText`)**:
  - Ported upstream pi's `ThemedText` for lazy theme-evaluated text rendering that dynamically repaints when the active palette shifts without manual string reconstruction.
  - Ported `ExpandableText` providing built-in collapsed/expanded toggling for startup banners and header announcements.
  - Added dedicated unit tests in `ThemedTextTest.php`.

### Changed

- **Interactive Component Mouse Handling Parity**:
  - Aligned mouse click and wheel dispatching across interactive widgets (`Editor`, `SelectList`, `SettingsList`, `Box`, `BorderedLoader`, `CustomEditor`), allowing clicking to focus and select rows directly via mouse input.
  - Enhanced `TuiMouseDispatchResult` and `TuiMouseEventResult` supporting target delegation and custom focus routing.

## [0.4.14] - 2026-10-08

### New Features

- **Full Upstream Pi Theme Architecture & Color Space Alignment (`Theme`, `Themes`, `SystemTheme`, `Oklab`, `Colors`)**:
  - Replaced legacy static palette with upstream pi's modular theme engine (`Theme`, `Themes`, `ThemeInfo`, `ThemeJson`, `ThemeStyle`).
  - Added full perceptual color space conversions (`Oklab`, `OklchChannels`, `OkhslChannels`, `RgbColorValue`, `IndexedColor`) with perceptual lightness blending and color mixing.
  - Ported system theme dynamic terminal background detection (`SystemTheme`, `SystemThemeColors`) adapting UI accents to dark and light terminal backgrounds automatically.

### Added

- **Bundled Themes & Dynamic Theme Controller (`InteractiveThemeController`)**:
  - Bundled upstream's standard `dark.json`, `light.json`, and `labra.json` theme files with strict JSON schema validation (`theme-schema.json`).
  - Implemented `InteractiveThemeController` managing theme previews, settings persistence, and live TUI color updates on the fly.
  - Added comprehensive test suites (`ColorsTest`, `ThemeTest`, `ThemeJsonTest`, `SystemThemeTest`, `ThemeControllerTest`).

### Changed

- **Standardized UI Component Styling**:
  - Refactored all interactive TUI components and tools (`AssistantMessageComponent`, `UserMessageComponent`, `ToolExecutionComponent`, `FooterComponent`, `TerminalUi`, `HtmlExport`, `McpManagerView`) to consume structured `Theme` styling tokens.

## [0.4.13] - 2026-10-08

### New Features

- **Terminal Image Protocols & Kitty Graphics Architecture Parity (`TerminalImage`, `KittyImagePlacement`, `KittyImageMetadata`)**:
  - Fully ported upstream pi's Kitty image graphics protocol with image placement tracking, placement row extraction, off-screen cache eviction, and WezTerm z-order redraw handling.
  - Implemented `TerminalCapabilities` detection and override system (`KITTY_WINDOW_ID`, `GHOSTTY_RESOURCES_DIR`, `WEZTERM_PANE`, `ITERM_SESSION_ID`) with iTerm2 and Kitty protocol negotiation.
  - Added dynamic terminal color palette negotiation (`TerminalColors`, `PendingTerminalColorQuery`) via OSC 4/10/11 color reporting.

### Added

- **Image Dimension & Cell Ratio Calculations (`CellDimensions`, `ImageCellSize`, `ImageRenderOptions`)**:
  - Implemented accurate aspect-ratio-preserving cell rows and columns calculation against terminal cell pixel sizes.
  - Added dedicated test suites `TerminalColorsTest` and `TerminalImageTest`.

### Changed

- **Standardized Terminal Capability & Color Models**:
  - Replaced legacy `Images\Capabilities` and `Images\CellSize` with `TerminalCapabilities` and `CellDimensions`.
  - Updated `Image` and `Markdown` components to consume structured `ImageOptions` and `ImageRenderResult`.

## [0.4.12] - 2026-10-07

### New Features

- **Live TUI Mode Switching (`switchTuiMode()`) & Fullscreen Exit Transcript Replay**:
  - Implemented in-session live switching between `regular` (streaming scrollback) and `fullscreen` (AltScreen viewport) via `/settings` without restarting the process.
  - Aligned `fullscreenExitOutput` behavior with upstream pi: when quitting fullscreen mode with `fullscreenExitOutput: "transcript"` (the default), the entire conversation transcript is seamlessly rendered into the terminal scrollback buffer.

### Added

- **Dynamic Interactive TUI Reference Proxy & Overlay Controls**:
  - Ported upstream's `createInteractiveTuiReference()`, allowing components, extensions, and loaders to hold a stable `TUI` reference while `InteractiveMode` hot-swaps active renderers under the hood.
  - Added unit tests covering dynamic mode switching, listener preservation across renderer migration, and exit transcript output formatting.

### Changed

- **Clean Terminal Extension Listener Rebinding**:
  - Automatically rebinds all extension terminal input listeners via `TerminalUi::rebindTerminalInputListeners()` upon renderer migration.
  - Replaced ad-hoc exit handling with structured `stopInteractiveTui()`, ensuring overlays are dismissed and terminal state is cleanly restored.

## [0.4.11] - 2026-10-07

### New Features

- **Fullscreen Transcript Search (AltScreen Search via Ctrl+Shift+F / Ctrl+F)**:
  - Ported upstream pi's `AltScreenSearchIndex`, `AltScreenSearch`, and `AltScreenSearchComponent`.
  - Supports live regex/substring incremental search across rendered transcript lines with case-folding, match segment mapping, and real-time highlighted matches.
  - Interactive navigation: `Enter` / `Ctrl+G` (next match), `Shift+Enter` / `Ctrl+Shift+G` (previous match), `Escape` (dismiss search), with viewport auto-scroll tracking the selected result.

### Added

- **Full Mouse Interaction & Region Dispatch Engine (`Mouse`, `MouseHandler`, `MouseRegion`, `TuiMouseEvent`)**:
  - Implemented component-level mouse dispatching, mouse tracking, region hit-testing (`MouseRegion`), and target capture for interactive elements.
- **TUI Overlay System (`OverlayBounds`, `OverlayHandle`, `OverlayOptions`, `OverlayStackEntry`)**:
  - Added floating overlay stack support with anchored bounds calculation (`top-left`, `top-right`, `bottom-left`, `bottom-right`, `center`) and margin constraints.
- **TUI Keybindings Registry Parity (`KeybindingsManager`, `KeybindingDefinition`)**:
  - Ported global keybinding registry matching upstream pi's `TUI_KEYBINDINGS`, conflict detection, and user keymap rebinding.

## [0.4.10] - 2026-10-07

### Added

- **Upstream Pi Focus Management & Virtual Terminal Integration (`Focusable`, `VirtualTerminal`, `TuiMainScreenTest`)**:
  - Replaced ad-hoc caret interface with upstream's standard `Focusable` contract (`focus()`, `blur()`, `isFocused()`), aligning focus lifecycle across all interactive widgets.
  - Added `VirtualTerminal` in test suite for deterministic VT sequence simulation and exact terminal screen assertions.
  - Added `TuiMainScreenTest` unit test suite covering full `TuiMainScreen` rendering, differential line updates, shrink handling, and terminal lifecycle.

### Changed

- **Standardized TUI Class Names and Component Hierarchy**:
  - Renamed `Pig\Tui\Tui` to uppercase `Pig\Tui\TUI` matching upstream TypeScript export conventions.
  - Unified input caret mapping and focus delegation through `Container::focusPath()` and `LayoutContext`.
  - Refactored `Editor` and `Input` to implement `Focusable`, ensuring cursor positioning and IME composition anchor correctly in both main screen and alt-screen layouts.

## [0.4.9] - 2026-10-07

### New Features

- **Full Upstream Pi 0.85+ ~ 1.0 TUI Architecture Alignment (`TuiAltScreen`, `TuiMainScreen`, `Layout`, `VStack`, `HStack`)**:
  - Fully ported upstream pi's modular TUI rendering engine: decomposed monolithic TUI into `TuiBase`, `TuiMainScreen` (regular mode), and `TuiAltScreen` (fullscreen viewport mode).
  - Ported flexbox-style layout engine (`Layout`, `VStack`, `HStack`, `LayoutFrame`, `LayoutRect`, `ScrollLayoutNode`, `StackLayoutNode`) enabling precise coordinate layout, flex-grow/shrink constraints, and robust bounds clipping.
  - Implemented `ViewportTUI` interface and `TuiRenderer` proxy with dynamic mode switching (`switchTuiMode`) between fullscreen and regular without restarting the session.

### Added

- **Scrollbar Geometries & Viewport Hit-Testing**:
  - Added `ScrollbarGeometry` with support for track/thumb drag-and-drop, track click-to-jump, and hover tracking.
  - Added full test suites `LayoutTest`, `WheelScrollAcceleratorTest`, and comprehensive `TuiAltScreenTest` covering all lifecycle events.

### Fixed

- **Fixed Fullscreen Viewport Dock Separation & Clean Terminal Restoration**:
  - Decoupled `ChatViewport` into flex-dock layout with `VStack`, ensuring editor prompt, status bar, and footer remain permanently locked at the terminal bottom without being displaced during scrolling.
  - Preserved terminal state and clean mouse unbinding upon exit (`DISABLE_MOUSE`, `ENABLE_AUTOWRAP`, `EXIT_ALT_SCREEN`).

## [0.4.8] - 2026-10-07

### Added

- **Complete Upstream Pi-Aligned Fullscreen AltScreen & Trackpad Wheel Acceleration**:
  - Added `WheelScrollAccelerator` matching upstream pi's `wheel-scroll.ts` to support smooth trackpad gesture inertia and velocity acceleration (up to 6 lines/event on non-accelerating transports, or 15 lines/event with Alt/Option).
  - Added `AltScreenFlashContainer` to support ephemeral overlay notifications and flash confirmations (`Copied!`).
  - Added full mouse text selection across the fullscreen viewport with click-and-drag, double-click word selection, triple-click line selection, auto-scroll at viewport edges, and automatic clipboard copying (`copyOnSelect`).
  - Added `TuiAltScreenTest` unit test suite covering AltScreen lifecycle, SGR mouse reporting, wheel event batching, and text selection.

### Fixed

- **Fixed Fullscreen Viewport Dock Eviction and Terminal Rolling**:
  - Switched AltScreen rendering from relative cursor movements to absolute row addressing (`\x1b[<row>;1H\x1b[2K`), completely eliminating unintentional `\r\n` terminal rolling that pushed the bottom input dock and status footer off the screen during scroll.
  - Added batch and stream parsing for SGR mouse sequences (`\x1b[<...M`) and traditional X10/XTerm mouse sequences (`\x1b[M...`), resolving an issue where macOS trackpad gestures with multiple queued wheel events failed strict regex matching and were silently dropped.
  - Fixed terminal dimension measurement guard in `ProcessTerminal` to preserve valid dimensions when transient resize events report width <= 3, preventing `checkWidth` crash loops.
  - Enhanced `ProcessTerminal::stty` with automatic fallback to process input descriptor if direct `/dev/tty` opening fails, avoiding startup crashes (`Could not run stty -g`).
  - Aligned `fullscreenExitOutput` behavior to dump the full transcript back to the main terminal screen buffer on exit, allowing users to scroll through past history in their terminal scrollback after quitting.

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
  - **Responsive Prompt Compactness**: On narrow mobile screens (<= 640px), the lengthy hostname is automatically hidden and long paths are abbreviated to `~/.../folder` without breaking.
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
