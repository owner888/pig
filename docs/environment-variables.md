# Environment Variables

pig uses environment variables in three ways:

- Variables such as `PIG_OFFLINE` configure the pig process.
- pig sets process markers so child processes can identify pig as the launching agent.
- Commands run by the LLM-callable shell tools receive `PIG_*` variables describing the current session.

Provider API-key variables are documented separately in [Provider Authentication](/docs/latest/providers#use-an-api-key-from-the-environment).

## Process Marker

`bin/pig` sets two process markers at startup, so they cover every mode, RPC included:

- `AI_AGENT=pig` is a generic marker that lets tooling identify pig as the agent that launched the process.
- `PIG_CODING_AGENT=true` is pig-specific and lets child processes detect that they run inside pig.

Child processes inherit both markers. They are not session-specific and are not set automatically when pig is embedded through the SDK.

## Shell Tool Session Environment

Commands run by the `bash` and `powershell` tools receive the current pig session state:

| Variable | Description |
|----------|-------------|
| `PIG_SESSION_ID` | Current session ID |
| `PIG_SESSION_FILE` | Absolute path to the current session JSONL file; unset for `--no-session` sessions, which still have an ID |
| `PIG_PROVIDER` | Currently selected model provider |
| `PIG_MODEL` | Currently selected model ID |
| `PIG_REASONING_LEVEL` | Current effective reasoning level: `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, or `max` |

A variable whose value is unknown is left unset rather than set empty, and values inherited from a parent pig are removed first. The values are resolved when each command starts. Switching models or changing the reasoning level therefore affects the next shell command without restarting pig. `PIG_PROVIDER` and `PIG_MODEL` identify the selected pig model, not a different upstream model that a router may choose internally.

When asked which model or provider is running, inspect these variables instead of inferring the answer from the system prompt:

```bash
printf '%s/%s\n' "$PIG_PROVIDER" "$PIG_MODEL"
printf 'reasoning=%s session=%s\n' "$PIG_REASONING_LEVEL" "$PIG_SESSION_ID"
```

The session file can be inspected directly when the session is persistent:

```bash
if [ -n "$PIG_SESSION_FILE" ]; then
  tail -n 1 "$PIG_SESSION_FILE"
fi
```

These variables are injected into the LLM-callable `bash` and `powershell` tools. They are not injected into user-entered `!` or `!!` commands.

### Custom Shell Tools

`Pig\CodingAgent\Tools\BashTool` and `PowerShellTool` take the session environment as a closure, `sessionEnvironment`, that answers a `SessionEnvironment` when each command starts. pig's own `bash` and `powershell` tools are given one; a shell tool an extension builds gets the session variables only if it passes one too. Injection happens before `spawnHook`, so a hook receives the variables in the `SpawnContext`'s `env`:

```php
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\SessionEnvironment;
use Pig\CodingAgent\Tools\SpawnContext;

$bashTool = new BashTool(
    $cwd,
    sessionEnvironment: fn (): ?SessionEnvironment => $ctx->session !== null ? SessionEnvironment::of($ctx->session) : null,
    spawnHook: fn (SpawnContext $spawn): SpawnContext => $spawn->withEnv([...$spawn->env, 'CI' => '1']),
);
```

Disable session metadata independently of the spawn hook:

```php
$powershellTool = new PowerShellTool(
    $cwd,
    exposeSessionEnvironment: false,
    spawnHook: fn (SpawnContext $spawn): SpawnContext => $spawn,
);
```

When disabled, pig removes inherited values for these variables so nested pig processes do not expose stale parent-session metadata. `BashTool` also takes `commandPrefix` and `shellPath`.

## pig Process Configuration

These variables are read by pig itself:

| Variable | Description |
|----------|-------------|
| `PIG_CODING_AGENT_DIR` | Override the agent directory; default is `~/.pig/agent`. A leading `~` is expanded |
| `PIG_CODING_AGENT_SESSION_DIR` | Override session storage; overridden by `--session-dir`, and overrides the `sessionDir` setting |
| `PI_CODING_AGENT_DIR`, `PI_HOME` | Where pi's own directory is (default `~/.pi/agent`), whose `auth.json` and sessions pig shares |
| `PIG_PACKAGE_DIR` | Override the package directory (where `CHANGELOG.md` is read), useful for Nix/Guix store paths |
| `PIG_OFFLINE` | `1`, `true`, or `yes` disables startup network activity, including model catalog refreshes and package installs, and refuses `/bug` uploads; `--offline` sets it |
| `PIG_MODEL_CATALOG_URL` | Model catalog server for the built-in providers (default `https://pigagent.dev`); `off` or empty turns catalog updates off |
| `PIG_SKIP_VERSION_CHECK` | Any non-empty value disables the Packagist latest-version request; `--offline` sets it |
| `PIG_SELF_UPDATE_COMMAND` | Custom command `pig update` runs to update pig itself; `{packageName}`, `{version}`, and `{installSpec}` are expanded |
| `PIG_THEME` | Initial theme for this run, like `--use-theme`; `--use-theme` wins |
| `PIG_TELEMETRY` | Override `enableInstallTelemetry` (the provider attribution headers): `1`/`true`/`yes` turns them on, anything else off |
| `PIG_CACHE_RETENTION` | Set to `long` for extended provider prompt caching where supported |
| `PIG_SHARE_VIEWER_URL` | Override the gist viewer base URL `/share` prints with `sessionShareDestination: "gist"` (default `https://pigagent.dev/session/`) |
| `PIG_SHARE_SERVER` | Where `/share` keeps encrypted sessions and `/unshare` deletes them (default `https://pigagent.dev`) |
| `PIG_OAUTH_CALLBACK_HOST` | Address the OpenAI Codex sign-in callback server binds to (default `127.0.0.1`); the redirect still names `localhost` |
| `PIG_HARDWARE_CURSOR` | Set to `1` to show the hardware cursor when the `showHardwareCursor` setting is unset; see [Terminal setup](/docs/latest/terminal-setup) |
| `PIG_CLEAR_ON_SHRINK` | Set to `1` to clear empty rows when the rendered content shrinks, when the `terminal.clearOnShrink` setting is unset |
| `PIG_PROGRAM_STATUS` | `1` or `0` skips the OSC 7501 program-status query and assumes the terminal does or does not support it |
| `PIG_HYPERLINKS` | Override OSC 8 hyperlink detection with `1`, `0`, or `auto` |
| `PIG_IMAGE_PROTOCOL` | Override inline image detection with `kitty`, `iterm2`, `none`, or `auto` |
| `PIG_TRUE_COLOR` | Override truecolor detection with `1`, `0`, or `auto` |
| `PIG_TUI_ESC_TIMEOUT` | How long to wait after a lone ESC before treating it as Escape, in milliseconds; defaults to `100` over SSH and `10` otherwise. Increase if Alt-key input is misread as Escape |
| `PIG_TUI_WRITE_LOG` | Append every byte written to the terminal to this file; a directory gets one `tui-<timestamp>-<pid>.log` file per run |
| `PIG_LOG_LEVEL` (or `LOG_LEVEL`) | Log level for pig's own log: `info`, `debug`, or `verbose` |
| `PIG_LOG_KEEP_DAYS` (or `LOG_KEEP_DAYS`) | Days of log files to keep |
| `PIG_TIMING` | `1` prints startup timings |
| `VISUAL`, `EDITOR` | External editor fallback when `externalEditor` is unset |
| `https_proxy`, `HTTPS_PROXY`, `http_proxy`, `HTTP_PROXY` | Proxy for outbound requests: HTTPS requests use `https_proxy`, then `HTTPS_PROXY`, then the HTTP proxy; HTTP requests use `http_proxy`, then `HTTP_PROXY`. `ALL_PROXY` is not read |
| `no_proxy`, `NO_PROXY` | Hosts reached directly; `*.example.com` and `.example.com` forms match subdomains |
| `COMPOSER_BINARY` | The `composer` executable `pig update` uses |

Bundled extensions read their own variables: `LLAMA_BASE_URL` and `LLAMA_API_KEY` (llama.cpp router). Packages read theirs: `pig-antigravity` reads `ANTIGRAVITY_CLIENT_ID` and `ANTIGRAVITY_CLIENT_SECRET`, `pig-gemini-video` reads `PIG_GEMINI_VIDEO_VIA` and `PIG_GEMINI_VIDEO_MODEL`, `pig-android-use` reads `ADB_PATH` and `FFMPEG_PATH` (hardware-accelerated screenshots), `pig-web-search` reads `CHROME_PATH`.

pig reads no `PI_*` fallback for its own variables, and does not read `PIG_MODEL`, `PIG_PROVIDER`, `PIG_THINKING`, `PIG_TUI_MODE`, or `PIG_RADIUS_GATEWAY`; choose a model with `--model` or the settings instead. `PIG_MODEL` and `PIG_PROVIDER` are only set for shell tool commands, as described above.

Provider credentials such as `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, and cloud-provider configuration are listed in [Provider Authentication](/docs/latest/providers#use-an-api-key-from-the-environment).
