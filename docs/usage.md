# Use pig in the terminal

Run `pig` from the folder you want to work in. pig uses that folder to discover files, instructions, and configuration, and to group saved sessions. If you have not installed pig or chosen a model yet, follow the [Quickstart](/docs/latest/quickstart).

pig may ask whether you trust the working folder before loading its project resources. See [Project trust](/docs/latest/security#understand-project-trust).

<p align="center"><img src="images/interactive-mode.png" alt="pig interactive mode showing a conversation, editor, and status information" width="750"></p>

The transcript shows your prompts, pig's responses, tool calls, results, and errors. You write prompts and commands in the editor. The footer shows the current folder, session, model, context usage, and accumulated usage and cost.

## Enter a prompt

Type a request and press `Enter` to send it. Use `Shift+Enter` to add a line, or press `Ctrl+G` to work on a longer prompt in your configured external editor.

To include files or images:

- Type `@` to search for a file and add it to your prompt.
- Press `Tab` to complete a path.
- Paste an image or drag it into a compatible terminal.

## Follow pig's work

pig shows each tool call and result while it works. Press `Ctrl+O` to expand or collapse tool output. Press `Ctrl+T` to show or hide thinking blocks.

The startup header lists the instructions and resources pig loaded. The editor border indicates the current thinking level. The footer updates as the model uses context and reports usage.

pig does not ask before every tool call. Review commands and changed files, and use a sandbox for untrusted or unattended work. See [Security](/docs/latest/security).

## Change direction

You can send more input while pig is working:

| What you want | Action |
|---|---|
| Adjust the current task | Type a message and press `Enter` |
| Add work after the current task | Type a message and press `Alt+Enter` (or `Command+Enter` where the terminal sends it) |
| Return queued messages to the editor | Press `Alt+Up` |
| Stop the current task | Press `Escape` |

A message sent with `Enter` waits until the current response and its tool calls finish, then guides the next response. A follow-up sent with `Alt+Enter` waits until pig finishes the current task. Aborting returns queued messages to the editor.

Windows Terminal reserves some Alt shortcuts. See [Terminal Setup](/docs/latest/terminal-setup) for the Windows alternatives.

## Change the model or settings

Type `/` to search the available commands. The commands you will use most often are:

- `/model` selects a model. Press `Ctrl+L` to open the same selector.
- `/thinking` selects how much reasoning the current model uses. Press `Shift+Tab` to cycle through supported levels.
- `/login` and `/logout` manage provider access.
- `/settings` changes common preferences.

Prompt templates, skills, and extensions can add more commands to the same menu. See [Choose a Model](/docs/latest/models), [Configuration](/docs/latest/configuration), or the complete [Slash Commands reference](/docs/latest/slash-commands).

## Continue or start over

pig saves sessions automatically unless session persistence is disabled.

- `/new` starts a new session.
- `/resume` opens another saved session.
- `/name` gives the current session a recognizable name.
- `/session` shows its file, ID, message count, token usage, and cost.

Use `/tree`, `/fork`, or `/clone` when you want to explore another approach without losing existing work. Use `/compact` to reduce the conversation history sent to the model. See [Sessions and Context](/docs/latest/sessions) for these workflows.

After leaving pig, run `pig --continue` from the same folder to resume its most recent session.

## Run a terminal command

Prefix a command with `!` to run it and include its output in the conversation:

```text
!git status
```

Use `!!` when you want to run a command without sending its output to the model. pig still records it in the session, marked as excluded from context.

## Copy, export, or share results

Press `Ctrl+X` or run `/copy` to copy the last assistant response. Use `/export` to save the session as HTML or JSONL, or `/export md` for Markdown.

Use `/share` to share the session and get a link. pig first lists anything in it that looks like a key or token — the values in `auth.json`, credential-named environment variables, the providers' key shapes — so you can redact it, send it as it is, or cancel; the scan cannot know every secret, so read the session first anyway. By default the session is kept on pigagent.dev for 30, 7 or 1 days, as you choose, encrypted on your machine with the key only in the link: the server holds ciphertext it cannot read, and anyone with the link can read the session until it expires. `/unshare` deletes one of your shares sooner. With `sessionShareDestination: "gist"` pig makes a secret GitHub gist instead, through the GitHub CLI (`gh`, signed in); GitHub can read a gist. A session can contain prompts, tool output, file contents, and credentials exposed during the conversation.

## Adjust the terminal

Regular mode uses the terminal's normal scrollback. Fullscreen mode keeps the editor and status area fixed while the transcript scrolls within the terminal window. Choose a mode through `/settings` or `--tui-mode`.

Terminal support for mouse input, keyboard shortcuts, and inline images varies. See [Terminal Setup](/docs/latest/terminal-setup) for platform-specific configuration and [Keybindings](/docs/latest/keybindings) for every configurable shortcut. Run `/hotkeys` to inspect the shortcuts active in your current session.

## Collect diagnostic information

When troubleshooting terminal rendering or conversation state, run `/debug`. pig writes the rendered terminal lines and current session messages to `pig-debug.log` in your [agent directory](/docs/latest/configuration#agent-directory).

Review this file before sharing it. It can contain prompts, model responses, tool output, file contents, and terminal data.
