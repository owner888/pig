<a id="cli-and-modes-reference"></a>

# Command Line

This page documents pig's built-in command-line commands and options. Run `pig --help` or append `--help` to a command for the exact interface in your installed version. The top-level help also includes options registered by loaded extensions.

```sh
pig [options] [--] [@files...] [messages...]
pig web <start|stop|status|restart|password> [options]
pig update [options]
pig install <source> [options]
pig remove <source> [options]
pig uninstall <source> [options]
pig list
pig config [options]
pig auth login <provider> [--api-key] [--no-browser]
pig auth logout <provider>
pig auth <check|print-api-key|print-bearer-token> [options]
pig mcp <add|remove|list|login|logout> [options]
```

<a id="modes"></a>

## Invocation and output

```sh
pig
pig --print "Summarize this repository"
git diff | pig --print "Review this change"
pig --mode json "Inspect this repository" > events.jsonl
```

With terminal stdin and stdout, pig opens the terminal UI unless `--print`, `--mode json`, `--mode rpc`, `--mode web`, or `--mode mcp` selects another interface. When either stream is redirected and none of those modes is selected, pig uses print mode. Print mode with no prompt to send exits with status 0 without output. See [CLI Integration](/docs/latest/cli-integration) for choosing between interactive, print, JSON, RPC, and SDK integration.

| Input | Behavior |
|---|---|
| `message` | Provide an initial prompt |
| `@path` | Include a text file or image in the first prompt |
| Piped stdin | Prepend its contents to the first prompt, before any `@path` text and the message |
| `--` | Stop option parsing so a prompt can begin with `-`; `@path` after `--` is still read as a file |

pig resolves `@path` from the current working directory. The working directory also controls project configuration, resource discovery, and session grouping.

`--print` controls whether pig runs once and exits. `--mode` selects the output interface. `--mode text` does not force one-shot execution when stdin and stdout are terminals; use `--print` for that behavior.

| Option | Behavior |
|---|---|
| `-p`, `--print` | Run the supplied prompts, write the final assistant text to stdout, then exit |
| `--mode text` | Select text output; still open the terminal UI when stdin and stdout are terminals |
| `--mode json` | Run the supplied prompts, write JSONL events to stdout, then exit |
| `--mode rpc` | Read JSONL commands from stdin and write responses and events to stdout until shutdown |
| `--mode web` | Launch Web UI in the current terminal foreground; stops on terminal exit |
| `--mode mcp` | Serve this conversation as an MCP server with one tool, `ask`, over Streamable HTTP (`--mcp-host`, `--mcp-port`, default `127.0.0.1:8089`) or stdio (`--mcp-stdio`); see [Serve pig as an MCP Server](/docs/latest/mcp-server) |
| `--export <input> [output]` | Export a session file to HTML and exit; derive the destination when `output` is omitted |

RPC and MCP modes reject `@file` arguments and prompts. JSON, RPC and stdio MCP modes reserve stdout for protocol records. See [JSON Event Stream](/docs/latest/json) and [RPC Protocol](/docs/latest/rpc).

<a id="web-daemon"></a>

## Web Daemon Management (`pig web`)

Run the Web UI persistently in the background, independent of your terminal or SSH session.

```sh
pig web start [-d] [--port=8088] [--host=127.0.0.1]
pig web stop
pig web status
pig web restart [-d] [--port=8088] [--host=127.0.0.1]
pig web password <name>
```

- `start`<br>
  Starts the web service. Adding `-d` runs it as a background daemon (decoupled from the terminal). Default: port 8088 on 127.0.0.1.
- `stop`<br>
  Safely stops the background daemon and reaps all active session child processes.
- `status`<br>
  Displays daemon status (PID, address, port, uptime, and process pool stats).
- `restart`<br>
  Restarts the daemon service gracefully.
- `password <name>`<br>
  Sets or changes an account's password. With no account yet, it makes the first one, the admin — the same as making it in the browser on the first visit.

See [Web UI Interface](/docs/latest/web) for full details.

<a id="system-commands"></a>

## Diagnostics & Updates

```sh
pig update
```

pig has no `doctor` subcommand. Inside a session, the `/doctor` slash command reports the PHP runtime, external binaries, and auth state.

- `pig update`<br>
  Checks Packagist or git origin and updates pig to the latest release based on your installation method.
- `--update-check` / `--no-update-check`<br>
  Checks Packagist for a newer pig at startup even when the `update.check` setting turns it off, or skips the check. `PIG_SKIP_VERSION_CHECK=1` also skips it.

<a id="model-options"></a>

## Models

```sh
pig --model sonnet:high
```

See [Choose a Model](/docs/latest/models) for model selection and [Provider Authentication](/docs/latest/providers) for credentials.

- `--provider <name>`<br>
  Restricts `--model` lookup to one provider. It requires `--model`.
- `--model <pattern>`<br>
  Selects by exact ID or fuzzy ID/name match. It accepts `provider/id` and an optional `:<thinking>` suffix.
- `--api-key <key>`<br>
  Uses a non-persistent API-key override. It requires a model selected through `--model` or `--models`.
- `--thinking <level>`<br>
  Sets `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, or `max`. It overrides a `--model` suffix and is clamped to the model's capabilities.
- `--models <patterns>`<br>
  Sets a comma-separated scope for startup and cycling. It accepts exact IDs, fuzzy matches, case-insensitive globs, and optional `:<thinking>` suffixes.
- `--list-models [search]`<br>
  Lists available models, optionally filtered by a fuzzy search, then exits.

<a id="session-options"></a>

## Sessions

```sh
pig --continue
```

See [Sessions and Context](/docs/latest/sessions) for resuming, forking, naming, and storing sessions.

- `-c`, `--continue`<br>
  Continues the most recent session for the current project.
- `-r`, `--resume`<br>
  Opens the session selector. It takes no value and needs a terminal. Escape in the selector exits with `No session selected`.
- `--session <path|id>`<br>
  Opens by file path, exact ID, or partial ID. pig searches the current project first and offers to fork a cross-project match.
- `--session-id <id>`<br>
  Opens the exact project session ID or creates it if absent. IDs accept letters, numbers, `.`, `_`, and `-`.
- `--fork <path|id>`<br>
  Forks an existing session into a new session for the current project.
- `--session-dir <dir>`<br>
  Overrides storage and lookup. It takes precedence over `PIG_CODING_AGENT_SESSION_DIR` and the `sessionDir` setting.
- `--no-session`<br>
  Uses an in-memory session that is not persisted. The session still has an ID, which `--session-id` can set.
- `-n`, `--name <name>`<br>
  Sets the session display name.

Constraints:

- Session IDs must start and end with a letter or number.
- `--fork` cannot be combined with `--session`, `--continue`, `--resume`, or `--no-session`.
- `--session-id` cannot be combined with `--session`, `--continue`, or `--resume`. Combine it with `--fork` to choose the new ID.

<a id="tool-options"></a>

## Tools

```sh
pig --tools read,grep,find,ls --print "Review this project"
```

See [Settings](/docs/latest/settings#tools) for configuring the default tool selection.

- `-t`, `--tools <list>`<br>
  Replaces the default selection with a comma-separated allowlist of built-in, extension, or custom tools.
- `-xt`, `--exclude-tools <list>`<br>
  Disables comma-separated tool names after all other selection options.
- `-nbt`, `--no-builtin-tools`<br>
  Disables default built-in tools while retaining extension and custom tools.
- `-nt`, `--no-tools`<br>
  Starts with all built-in, extension, and custom tools disabled.
- `--read-only`<br>
  Removes `edit`, `write`, and `bash`.
- `--no-mcp`<br>
  Does not load the built-in MCP extension, so no servers connect and no MCP tools are registered.

Default enabled tools are `read`, `bash`, `edit`, and `write`, unless `defaultTools` changes them. `--tools` and `--exclude-tools` accept `*` patterns such as `'mcp__gh__*'`. Plain names in `--tools` replace the whole selection, so name every tool you want; `--tools` and `defaultTools` also accept `+name` and `-name` alone to change the defaults instead. The two kinds cannot be mixed in one list.

| Built-in | Purpose |
|---|---|
| `read` | Read text files and supported images |
| `bash` | Run shell commands |
| `powershell` | Run PowerShell commands on Windows |
| `edit` | Apply exact text replacements to an existing file |
| `write` | Create or overwrite a file |
| `grep` | Search file contents |
| `find` | Find paths using glob patterns |
| `ls` | List directory contents |

Built-in extensions add two more tools. They are off by default; the MCP extension turns them on when an MCP server needs them (see [MCP](/docs/latest/mcp#exposure)). `codemode` is always registered and can also be turned on by name ([below](#enable-codemode)). `tool_search` is registered only while it is needed, so naming it in `--tools` or `defaultTools` does not turn it on.

| Built-in extension | Purpose |
|---|---|
| `codemode` | Run a PHP script that calls the other tools, for example in parallel with `parallel_settled()`; only the script's output reaches the model |
| `tool_search` | Search tools that are not declared to the model (`codemode` and `deferred` exposure, such as MCP tools) and declare the matches for the next call |

A third built-in extension, pig's own `schedule`, adds the `schedule` tool, which is on by default: the model creates, lists and removes [scheduled tasks](/docs/latest/web#9-scheduled-tasks) with it. Turn it off with `-schedule` in `--tools` or `defaultTools`.

### Enable codemode

`codemode` is registered inactive. Name it to turn it on: for one run with `--tools read,bash,edit,write,codemode` or `--tools +codemode`, or for every session in `defaultTools` in `~/.pig/agent/settings.json` or a project's `.pig/settings.json`:

```json
{
  "defaultTools": ["+codemode"]
}
```

This keeps `read`, `bash`, `edit`, and `write` and adds `codemode`. An MCP server with `codemode` exposure also turns it on when it connects, and it stays on.

Codemode is useful without MCP: scripts can run several tool calls in parallel, filter large output before it reaches the model, and call classifier models such as TypeSafe's Jev through `$models->classify()` (see [Classifier models](/docs/latest/models#use-classifier-models)).

### How codemode works

Codemode scripts are PHP. The tool input is raw PHP source, without `<?php` or a code fence, run as the body of a function in a fresh child `php` process, so a top-level `return` works and `$tools` is in scope. The sandbox has no shell, file system, network, or `include` (`disable_functions` and `open_basedir` enforce it); string, array, math, JSON, regex, and date functions work, and scripts reach everything else through tools. Tools are methods of `$tools`, named by their identifier with characters that are not valid in PHP replaced by `_`, and take one associative array: `$tools->read(['path' => 'a.txt'])` or `$tools->mcp__dev_radius__search([...])`. A failed or blocked call throws an exception carrying the tool's error text.

Output comes from `text($value)`, `image($dataUrlOrImageBlock)`, `echo`/`print`, and a top-level `return $value`; `exit_script()` ends the script early (PHP's `exit` would end the sandbox instead). `parallel([fn () => ..., ...])` runs independent calls at the same time and keeps the keys; `parallel_settled([...])` answers `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => '...']` per entry, so one failure does not stop the rest. The result starts with `Script completed` or `Script failed`, the wall time, and the output; a failed script keeps its partial output, followed by `Script error:` and the error. Calls still running when the script ends are cancelled.

```php
[$a, $b] = parallel([
    fn () => $tools->read(['path' => 'a.txt']),
    fn () => $tools->read(['path' => 'b.txt']),
]);
return strlen($a) + strlen($b);
```

A script may start with an options line such as `// @options: {"max_output_tokens": 2000, "timeout_ms": 60000}`. `max_output_tokens` (default 10000) limits the output: longer output keeps its start and end, and the full text is written to a temp file whose path is included in the result. `timeout_ms` is a hard deadline, unset by default. A script has a 256 MB memory limit and may output at most 16 MiB or 100,000 items; scripts cannot start other `codemode` scripts.

While `codemode` is active, `codemode.mode` in [settings](/docs/latest/settings#tools) decides how the other tools are presented. With `on` (default) declared tools keep being declared and their descriptions show how to call them from scripts. With `only` they are hidden from the model and listed in the `codemode` description instead, so the model calls them through scripts.

The `codemode` description lists the callable tools with their PHP declarations, grouped by namespace (for example one MCP server). Declarations share a budget of 3000 estimated tokens (`codemode.inlineBudget` in [settings](/docs/latest/settings#tools)); a namespace whose tools did not all fit is marked `(some tools not listed)` or `(tools not listed)`, and the description says when tools are missing. Scripts find the rest with `search_tools($query, ['limit' => 8, 'namespace' => ...])`, which ranks tools with BM25, `describe_tool($name)`, `describe_namespace($name)`, `$tools->has($name)`, or by filtering `ALL_TOOLS`. The full reference for scripts is `extensions/pig-codemode/CODEMODE.md`, which the description points the model to.

Tools with an output schema answer structured arrays: `bash` answers `['output', 'truncated', 'full_output_path'?, 'exit_code', 'wall_time_seconds']`, also for non-zero exit codes, and MCP tools their whole `CallToolResult`. Other tools answer their text output as a string, and a result with pictures answers `['text' => ..., 'images' => [...]]`. The `output` of `bash` is not limited to the 2000 lines or 50KB the model sees: it holds up to 1 MiB, and longer output keeps its first and last 512 KiB around an omission marker, with `truncated` set and the full output in `full_output_path`.

`store($key, $value)` and `load($key)` keep small JSON values across `codemode` calls: each successful script that stores values appends a `codemode-store` custom entry to the session, so resumed sessions keep the values and each branch sees only the values written on its path. Scripts can also use `$models`: `getModelsOfType()`, `getAvailableOfType()`, and `getModelOfType()` list the model catalog, `classify($model, $context)` runs a classifier model and `generateImages($model, $context)` an image model with the session's credentials, at most four at a time per script. Image and classifier models that extensions bring are listed too, such as `antigravity/gemini-3.1-flash-image` once you are signed in to Antigravity; what these calls cost is billed on the `codemode` call.

### Tool search

`tool_search` is registered while any `deferred` MCP tool is listed, and removed when none is; there is no setting for it. It uses the same ranking as `search_tools()` (BM25, default limit 8) over `deferred` tools that are not loaded yet and declares the matches for the next model call. Loaded tools are recorded in the session like other tool changes, so they stay declared on that branch.

<a id="resource-options"></a>

## Resources

```sh
pig --extension ./review.php
```

See [Configuration](/docs/latest/configuration) for conventional directories and project trust, [Settings](/docs/latest/settings#resources) for configured paths, and [pig Packages](/docs/latest/packages) for package sources.

- `-e`, `--extension <source>`<br>
  Loads an extension file or directory, a package source such as `git:github.com/user/repo` (cloned under a temporary directory and not written to settings), or a built-in extension such as `builtin:mcp`, and is repeatable.
- `-ne`, `--no-extensions`<br>
  Disables discovered, configured, and built-in extensions. Explicit `-e` paths still load, so `pig -ne -e builtin:mcp` keeps only the built-in MCP support.
- `--skill <path>`<br>
  Loads a skill file or directory and is repeatable.
- `--skills-dir <dir>`<br>
  Adds one more skills directory on top of the standard ones.
- `-ns`, `--no-skills`<br>
  Disables discovered and configured skills. Explicit `--skill` paths still load.
- `--prompt-template <path>`<br>
  Loads a prompt-template file or directory and is repeatable.
- `-np`, `--no-prompt-templates`<br>
  Disables discovered and configured templates. Explicit `--prompt-template` paths still load.
- `--theme <path>`<br>
  Loads a theme file or directory and is repeatable.
- `--use-theme <name[/name]>`<br>
  Selects the initial interactive theme for this run.
- `--no-themes`<br>
  Disables discovered and configured themes. Explicit `--theme` paths still load.
- `-nc`, `--no-context-files`<br>
  Disables `AGENTS.md` and `CLAUDE.md` discovery.

Resource paths apply only to the current process. Relative paths resolve from the current working directory.

<a id="prompt-and-display-options"></a>

## Prompts and process

```sh
pig --append-system-prompt ./instructions.md
```

See [Configuration](/docs/latest/configuration) for saved configuration, [Security](/docs/latest/security#understand-project-trust) for project trust, and [Environment Variables](/docs/latest/environment-variables) for process controls.

- `--system-prompt <text|path>`<br>
  Replaces the default system prompt with text or the contents of an existing file.
- `--append-system-prompt <text|path>`<br>
  Appends text or an existing file to the system prompt and is repeatable.
- `--tui-mode <mode>`<br>
  Uses `regular` or `fullscreen` terminal mode.
- `--verbose`<br>
  Shows verbose interactive startup information, overriding `quietStartup`.
- `-a`, `--approve`<br>
  Trusts project-local configuration and resources for this process.
- `-na`, `--no-approve`<br>
  Ignores trust-gated project-local configuration and resources for this process.
- `--cwd <path>`<br>
  Works in this directory instead of the current one.
- `--proxy <url>`<br>
  Reaches providers through an `http://` or `socks5://` proxy for this run. Otherwise pig uses `https_proxy`/`HTTPS_PROXY` and `http_proxy`/`HTTP_PROXY`, with the `httpProxy` setting filling an unset `HTTP_PROXY`/`HTTPS_PROXY`; `no_proxy` and `proxy.bypass` list hosts reached directly, and loopback always is.
- `--no-proxy`<br>
  Ignores all proxy configuration and connects directly.
- `--offline`<br>
  Disables startup network activity, including model catalog refreshes and the version check. Sets `PIG_OFFLINE=1` and `PIG_SKIP_VERSION_CHECK=1`.
- `-h`, `--help`<br>
  Shows help, including flags registered by loaded extensions, then exits.
- `-v`, `--version`<br>
  Shows the pig version, then exits.

Extensions may register additional long-form options; other unknown long options are rejected once extensions have loaded. Unknown short options are rejected with exit status 1.

## Package commands

```sh
pig install git:github.com/user/repo
```

See [pig Packages](/docs/latest/packages) for source formats, filtering, installation, and project scope.

### Common tasks

| Task | Command |
|---|---|
| Install a package | `pig install <source>` |
| List configured packages | `pig list` |
| Remove a package and its settings entry | `pig remove <source>` |
| Configure which package resources load | `pig config` |

Add `--local` or `-l` to `install`, `remove`, `uninstall`, or `config` to use project settings instead of global settings. pig supports `git:` and `https://`/`ssh://` git URLs and local paths; `npm:` and `composer:` sources are rejected.

### Update pig or packages

Running `pig update` without a target updates pig itself.

| Task | Command |
|---|---|
| Update pig | `pig update` |
| Update all installed packages and the bundled extensions' copies | `pig update --extensions` |
| Update one installed package | `pig update <source>` |
| Refresh every provider's model catalog now | `pig update --models` |
| Update pig and all installed packages | `pig update --all` |

Add `--force` to reinstall pig when the selected update includes pig.

### Aliases and command options

- `pig uninstall <source>` is an alias for `pig remove <source>`.
- `pig update --self`, `pig update self`, and `pig update pig` are aliases for `pig update`.
- `pig update --extension <source>` is an alias for `pig update <source>`.
- `-a`, `--approve` trusts project-local files for one command. `-na`, `--no-approve` ignores trust-gated project-local files.
- Append `-h` or `--help` to a command for its exact usage and option constraints.

## Credential commands

`pig auth login <provider>` logs in to a provider in the terminal and exits, without starting a session. It signs in with an account and opens the browser, like `/login <provider>`; when the provider has another sign-in method (Anthropic's copy-code login, OpenAI's device code), it says so, and `--no-browser` uses that method instead, asking only when there are several. `--api-key` saves an API key instead, typed without echo; providers without account sign-in ask for an API key either way. After logging in it fetches the provider's model catalog. It requires an interactive terminal.

`pig auth logout <provider>` removes the credentials that `pig auth login` or `/login` stored, like `/logout`. Environment variables and `models.json` config are unchanged. It exits with status `0` when nothing is stored, so it is safe to repeat.

```sh
pig auth login anthropic
pig auth login openai-codex --no-browser
pig auth logout anthropic
pig auth check --provider openai --json
```

The other authentication commands require `--provider <provider>` or `--model <model>`. See [Provider Authentication](/docs/latest/providers) for supported methods.

| Command | Description |
|---|---|
| `pig auth check` | Print `ready`, `not_ready`, or `invalid`; exit with status `0`, `1`, or `2`, respectively |
| `pig auth print-api-key` | Print the resolved API key |
| `pig auth print-bearer-token` | Print a resolved OAuth bearer token |

| Option | Applies to | Description |
|---|---|---|
| `--provider <provider>` | All | Resolve credentials for a provider |
| `--model <model>` | All | Resolve credentials from a model; may be combined with `--provider` |
| `--json` | `auth check` | Write the structured result as JSON |
| `--credentials` | `auth check` | Emit the resolved credential when ready |
| `--no-refresh` | `auth check` | Do not refresh expired OAuth credentials; refresh is the default |
| `--min-expiry <duration>` | `print-bearer-token` | Require remaining token lifetime using `ms`, `s`, `m`, or `h`, such as `30m` |

Credential-printing commands write secrets to stdout.

## MCP commands

These commands work outside a session, so agents can run them through `bash`. See [MCP Servers](/docs/latest/mcp).

| Command | Description |
|---|---|
| `pig mcp add <server> [options] -- <command> [args...]` | Add or replace a stdio server in `mcp.json`; `--env KEY=VALUE` (repeatable) and `--cwd <dir>` set its environment and working directory. Arguments after the command are passed to it |
| `pig mcp add <server> [options] --url <url>` | Add or replace a streamable HTTP server; `--header KEY=VALUE` (repeatable), `--bearer-token-env-var <NAME>` (sends `Authorization: Bearer ${NAME}`), `--oauth-client-id`, `--oauth-client-secret`, `--oauth-callback-port`, `--oauth-client-name`, `--oauth-client-registration` (`auto`, `cimd` or `dcr`), and `--oauth-auth-server-metadata-url` configure authentication |
| `pig mcp remove <server>` | Remove a server from `mcp.json`; stored OAuth credentials are kept |
| `pig mcp list [--json]` | Connect to every enabled server and print its state, tools, and errors; exit with `1` when a config entry is invalid or an enabled server is not connected |
| `pig mcp login <server> [--timeout <seconds>]` | Sign in to an OAuth server: open the authorization page and wait for the browser (default 300 seconds); a terminal also accepts the pasted redirect URL |
| `pig mcp logout <server>` | Delete the stored OAuth credentials of a server |

`add` and `remove` change `~/.pig/agent/mcp.json`, or `.pig/mcp.json` in the current directory with `--local` (`-l`). `add` also takes `--exposure <mode>` (written to the entry as given; see [Exposure](/docs/latest/mcp#exposure)) and does not connect; run `pig mcp list` to check the server.

Project `.pig/mcp.json` files are only read for projects that are already trusted.
