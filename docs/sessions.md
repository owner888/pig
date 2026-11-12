# Sessions and Context

pig saves a conversation as a session. The active branch of that session supplies conversation history for the next model request. Use session commands to continue work, explore another branch, or reduce the amount of history sent to the model.

## Continue or switch sessions

pig saves sessions automatically unless you start it with `--no-session`.

```bash
pig --continue
pig --resume
```

`--continue` opens the most recent session for the current working directory. `--resume` opens the session picker. In interactive mode, `/resume` opens the same picker and `/new` starts a new session.

A session names itself after its first prompt — a few words from the model on what it is about, shown in the footer, the session list, the Web UI's tabs and the terminal's title (`autoSessionName.enabled: false` turns it off). Use `/name` or `--name` to give it a name of your own; that one is never replaced. Run `/session` to verify the current session file, ID, message count, token usage, and cost.

The session picker lets you search, rename, and delete sessions. `Tab` switches between this folder's sessions and all sessions, `Ctrl+S` cycles the sort (threaded, recent, fuzzy), `Ctrl+N` shows only named sessions, `Ctrl+P` shows paths, and `Ctrl+R` renames (in `/resume` only). Search matches words fuzzily; `"exact phrase"` matches a phrase and `re:pattern` a regular expression. Deleting asks for confirmation and refuses the current session. See [Keybindings](/docs/latest/keybindings#sessions) for its shortcuts.

pig also lists sessions pi wrote under `~/.pi/agent/sessions/`, and appends to such a session when you open it. A session from another project picked in the selector continues in that project's directory, with that project's settings and trust; `--session` with another project's session offers to fork it into the current one instead. Resuming a session whose directory no longer exists (the project folder was renamed, say) offers three choices: continue in the current directory (not remembered, so it asks again next time), fork it into the current directory (a new session ID whose header names the current directory, so it is not asked again, with the original kept), or cancel.

## Choose how to branch

pig stores entries as a tree, so returning to an earlier point does not erase the branch you leave.

| Action | Result | Use it when |
|---|---|---|
| `/tree` | Moves within the current session file | Related alternatives should stay together |
| `/fork` | Creates a new session from an earlier user message | The alternative should become separate work |
| `/clone` | Copies the active branch into a new session | You want a separate copy of the current state |

In `/tree`, select a user message to put its text back in the editor. Edit and submit it to create another branch. Selecting an assistant response or another entry continues after that entry with an empty editor.

When you leave a branch, pig can summarize it and attach that summary to the branch you enter. This preserves relevant work from the abandoned path without including every message from it.

For the persisted tree and entry types, see [Session Format](/docs/latest/session-format).

## Manage conversation context

The model receives the active branch, not every branch in the session file. pig combines that history with the system prompt, discovered context files, available tools, and loaded skill descriptions. [How pig Works](/docs/latest/how-pig-works#context) describes how those inputs are assembled.

The footer shows current context usage. When the active context approaches the model's limit, pig normally compacts older history automatically. Compaction adds a summary and keeps recent messages. It does not delete the original session entries.

Run `/compact` to compact manually. You can add instructions when the summary should preserve a particular topic or decision. Configure automatic compaction and retained history through [Settings](/docs/latest/settings#compaction).

Compaction can fail if the provider is unavailable or cannot accept the summarization request. Correct the provider problem and run `/compact` again. Disabling automatic compaction does not disable the manual command.

See [Compaction Reference](/docs/latest/compaction) for thresholds, retained boundaries, branch-summary behavior, and extension hooks.

## Control session storage

By default, pig stores sessions under `~/.pig/agent/sessions/`, grouped by working directory. Use `--session-dir`, `PIG_CODING_AGENT_SESSION_DIR`, or the `sessionDir` setting to choose another location. The CLI option has highest precedence.

Use `--no-session` for an ephemeral run. An ephemeral session still has an ID (set it with `--session-id`) but cannot be resumed after pig exits.

Use `--session` when you already know the session path or ID, and `--session-id` to open or create a session with an exact ID. Use `--fork` to create a new session from an existing session before interactive mode starts. `/import <path>` brings a JSONL session file into the session store (the current project's session directory, by default) and resumes it.

### Keep sessions in a database

On a server, sessions can live in MySQL or SQLite instead of files. Set `storage` in `~/.pig/agent/settings.json` (the global file only):

```json
{ "storage": "sqlite:pig.db" }
```

```json
{
  "storage": {
    "dsn": "mysql:host=127.0.0.1;dbname=pig;charset=utf8mb4",
    "user": "pig",
    "password": "$PIG_MYSQL_PASSWORD"
  }
}
```

- A `sqlite:` path that is not absolute is relative to `~/.pig/agent`. The tables are created the first time pig opens the database. `prefix` (default `pig_`) is put in front of their names.
- A `user` or `password` written `$NAME` or `${NAME}` is read from that environment variable, so the password stays out of the file.
- PHP needs the matching extension: `pdo_mysql` for MySQL, `pdo_sqlite` for SQLite. `php -m` lists what is installed. The default, `"jsonl"`, needs neither.
- If the database cannot be opened, pig stops and says why. It never falls back to files, where a server's conversations would end up somewhere nobody looks.
- What is stored is still pi's session format, line for line, so `/export x.jsonl` writes the same file pi reads.
- Sessions you already have stay in `~/.pig/agent/sessions/`. Set `storage` back to `"jsonl"` and they are listed again. `/import <path>` brings a session file into whichever store is in use.

## Export or share a session

Use `/export` to write the current session as HTML or JSONL (`/export md` and `/export pr` write Markdown or a pull-request description). Use `/share` to share the HTML export and get a link. pig first lists what in it looks like a key or token, to redact, send as it is, or cancel. With [`sessionShareDestination`](/docs/latest/settings) `auto` (the default) the page is gzipped and encrypted with AES-256-GCM under a key made for this share, uploaded to pigagent.dev for the 30, 7 or 1 days you pick, and linked as `https://pigagent.dev/s/<id>#<key>`. The key is only in the part after `#`, which browsers do not send, so pigagent.dev stores ciphertext; the page at that link decrypts it in your browser. The share and the token that deletes it are recorded in `shares.json` in the agent directory, and `/unshare` deletes one before it expires. With `gist`, pig checks `gh auth status` and uploads the page as a secret GitHub gist with `gh gist create`; the link is the gist ID appended to `PIG_SHARE_VIEWER_URL` (default `https://pigagent.dev/session/`).

Review exported or shared sessions first. They can contain prompts, model responses, tool arguments, command output, file contents, and extension messages.

## Report a bug

Run `/bug [description]` to prepare a private report for the pig developers. You can include the session transcript, omit it, or ask the current model to summarize the problem. Review any transcript or generated summary because it can contain sensitive conversation data.

The report includes the pig version, operating system, PHP version, the current model and provider (without credential values), the last provider error, recent crashes, and `/doctor`'s findings. Choose **Upload** to send it to `https://pigagent.dev/api/bug-reports`, or **Export as Zip** to write `pig-bug-report-<id>.zip` to the current directory (with `report.md`, plus `session.jsonl` or `summary.md` when included) to inspect and share yourself. Uploads need no login and are refused while `PIG_OFFLINE` is set. If an upload fails, pig offers to export the zip. Either way the session records a `pig.bug-report` entry.
