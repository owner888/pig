# Decisions on record


| Decision | Choice | Why |
|---|---|---|
| Port fidelity | File-by-file literal | Class/file/method names track upstream so `git diff` against a newer upstream commit stays mechanical |
| Concurrency | Hand-written `Fiber` + `stream_select` loop | One `select()` must wait on the LLM socket and STDIN together — that is what makes Esc-to-interrupt and typing mid-stream possible |
| HTTP transport | Raw `tls://` streams, hand-written HTTP/1.1 | Sockets and STDIN are then the same kind of thing; one loop, no `ext-curl` |
| Unions | real union type where the arms are closed and few, marker interface otherwise | PHP has union types; what it lacks is naming one and typing an array's elements — see [Porting the unions](design/runtime.md#porting-the-unions) |
| Layout | one published package, five namespaces under `packages/` | Mirrors upstream's package split one-to-one; packagist.org cannot serve a package from a subdirectory, so the split is a layout rather than five packages — see the Packagist entry in the traps |
| Session file | pi's format exactly, in pi's directory layout | A conversation started in either tool opens in the other; `--resume` lists both — see [The session file is pi's file](design/session.md#the-session-file-is-pis-file) |
| Back-compatibility | none before a release | pig has never shipped, so there is nobody with an old file; a reader for one would be compatibility with nothing, exercised only by the test written to exercise it |

## Deliberate exceptions to "prefer the platform library over your own"

Both were chosen explicitly, not by default:

- **TUI is hand-written ANSI + `stty`, not `php-tui/php-tui`** (the Ratatui port, which already
  has differential rendering and widgets). Chosen to port `pi-tui` literally.
- **Event loop is hand-written, not `amphp/amp` v3** (also Fiber-based, with SSE-capable HTTP).
  Chosen to keep the core dependency-free.
- **Protocol framing is hand-written Workerman-style `ProtocolInterface`, not a framework.**
  `TcpConnection` manages non-blocking buffered socket transport while `Protocols\Http` and
  `Protocols\Websocket` decouple wire-framing (`input`), decoding (`decode`), and encoding (`encode`),
  providing pure PHP RFC 6455 full-duplex WebSocket RPC streaming with zero dependencies.
- **Fault Isolation Boundaries (inspired by Workerman's resilience without swallowing errors).**
  Rather than blanket try-catches that mask logical bugs, structural error guards are placed at process
  and event dispatch boundaries:
  1. `TcpConnection` & `HttpServer`: Decoupled `decode` and `onMessage` handlers are wrapped in try-catch.
     Unhandled client exceptions trigger `$connection->onError`, respond with clean 500 JSON or WebSocket error
     frames, and cleanly close the faulting connection while keeping the web daemon and event loop alive.
     `HttpServer::start()` registers `Loop::setErrorHandler()` to absorb unhandled timer/callback throws.
  2. `RpcMode`: Top-level pipe reading in `line()` wraps command execution to ensure any uncaught exception
     returns a structured JSON-RPC failure response (`{"id": ..., "type": "response", "success": false, "error": ...}`)
     rather than crashing child agent processes.
  3. Providers: a transport failure is worded as Node's fetch words it — `Connection error.` /
     `Request timed out.` before the response for Anthropic and OpenAI (`SdkRequest`), `fetch failed`
     for Gemini and Mistral, `terminated` mid-body — so upstream's retryable patterns
     (`Pig\Ai\Utils\Retry`) recognise it and the session retries.
- **Unified Zero-Dependency Logger (aligned with smart-book OmniPHP\Logger specifications).**
  `Pig\CodingAgent\Logger` (aliased as `Pig\Logger`) provides a static, zero-dependency logging suite:
  - 5 log levels with ANSI terminal colors: `VERBOSE` (34 blue, 0), `DEBUG` (36 cyan, 1), `INFO` (32 green, 2),
    `WARNING` (33 yellow, 3), `ERROR` (31 red, 4).
  - Configurable priority threshold via `PIG_LOG_LEVEL` or `LOG_LEVEL` (default: `INFO`).
  - Automatic daily log rotation (`~/.pig/agent/logs/pig-YYYY-MM-DD.log`) with configurable retention (default: 5 days,
    `PIG_LOG_KEEP_DAYS`).
  - Safe console toggling (`setConsoleOutput(false)`) during interactive TUI sessions to prevent screen buffer
    corruption while maintaining persistent disk logs.
  - Standard helpers: `dump($label, $data)`, `time($label)`, and `timeEnd($label)`.

`Pig\Async` has no counterpart in *upstream* at all — JS ships an event loop, PHP does not. It has a
reference all the same: it is read against workerman's `EventInterface` in the traps below, which is
where the three deliberate absences, the two places pig is ahead of it, and the one real gap are.

### `Future` + `Deferred` is the Promise, and `then()` is what fibers make unnecessary

Asked directly whether a fiber could be wrapped in a Promise class, the answer is that it already
is: `Future` is the read side, `Deferred` the write side, which is the promise/resolver split, and
`Future`'s own docblock says what it stands in for. What fibers make *unnecessary* is `.then()` —
that exists in JavaScript because a JavaScript function cannot stop in the middle, and `await()`
gives straight-line code instead. Adding `then()` would put two async styles in one codebase, half
the calls reading `$x = f()->await();` and half `f()->then(…)`, which the rule at the top of this
file forbids.

**The one genuinely inexpressible piece is the combinators** — `all`, `race`, `any`. Those are not
syntax sugar that a fiber replaces: "wait until all three finish" needs a counter and a shared
`Deferred`, written once. They are **not here**, and the reason is this project's usual standard
rather than an oversight: nothing in pig waits on more than one thing at a time. Outside
`packages/async` there is exactly one use of `Future::` at all — `Agent::waitForIdle()`'s
`Future::complete(null)`.

So this is the marker for the day that changes, because the shape of the API should be decided by
its first caller and not before. **The case to expect is fanning out to several providers at once**
— a `--model 'anthropic/*'` scope, a race between a fast model and a good one, or a settings screen
probing every signed-in provider's key. Any of those wants `all()` or `race()` on `Future`, and
that is new public surface in `Pig\Async`, so it is the developer's call: ask before adding it, and
write it once rather than letting the first caller hand-roll a counter of its own — which is this
file's commonest find, arriving early for once.

**The rule has a second edge, and it is the one that gets missed:** where upstream is *working
around* something JavaScript does not have, the port is the PHP call, not the workaround.
`Changelog` was written with upstream's split-into-three-and-subtract version comparison before
`version_compare()` replaced it, and the hand-rolled one was wrong about `0.2` and `0.2.0-beta`.
Copying the arithmetic is not the same as porting the behaviour. Same story as `Graphemes::split()`
being one `preg_match_all` where upstream needs `Intl.Segmenter` and a package.

## Where pig's TUI differs from upstream on purpose

pig follows upstream `main` (AGENTS.md); these are the developer's choices that a port must keep.

- **The loader shows how long it has been running**: `── ⠏ Working · 16s ──` (`· 1m15s` past a
  minute) — the turn, `/compact`, an automatic compaction, a branch summary and their retries
  (`InteractiveMode::showLoader(timer: true)`, `Loader::withTimer()`). Upstream's says `Working` and
  nothing else. Taken out in 0.6.75 when the TUI was aligned with upstream, and put back at the
  developer's request.

## Extensions

`ext-mbstring`, `ext-json`, `ext-openssl` and `ext-pcre` are required and are in every build worth
running. `pig/tui` additionally requires **`ext-pcntl`**, because SIGWINCH is the only way to learn
that the window was resized; without it the UI would draw at the startup width forever, so
`ProcessTerminal` refuses to start rather than doing that quietly. `ext-intl` is deliberately *not*
required — see [Five upstream dependencies that are not needed here](design/tools.md#five-upstream-dependencies-that-are-not-needed-here).

**`shellPath` in the settings names the shell**, which is upstream's key and was a key pig stored
and nothing read — the `terminal.showImages` shape again. It earns its keep on macOS: `/bin/bash`
there is **3.2**, with no `mapfile` and no `${var^^}`, and `Shell::resolve()` prefers `/bin/bash`, so
installing a homebrew bash 5 does nothing without it. `~` and a `file://` URL are expanded
(`Settings::shellPath()`, upstream's `normalizePath()`), and a path that is not there is **refused by
name** (`Custom shell path not found: …`) rather than falling back to `/bin/bash`, because the
fallback is the bug the setting was written to work around. `Shell::useShellPath()` is static for `Models::register()`'s reason: `bash`, a typed
`!command` and a hook's `exec()` all reach one shell, and a shell only some of them could see is a
shell that works until you use another door.

**One required external binary: `stty`.** PHP core has no termios binding and ext-pcntl does not
add one, so raw mode and the window size go through `proc_open('stty …')` against `/dev/tty`.

Everything else is optional and probed, never assumed. `Pig\Tui\Process::capture()` answers
`null` for a missing program exactly as it does for an empty result, because the caller does the
same thing either way — "this machine has no `wl-paste`" is a capability, not an error:

| Binary | For | Where |
|---|---|---|
| `pbpaste`, `pngpaste`, `osascript` | clipboard text and images | macOS |
| `wl-paste`, `xclip` | clipboard text and images | Wayland, X11 |
| `powershell.exe`, `wslpath` | clipboard images | WSL |
| `fd` | the `@` file picker in `pig/tui` | anywhere; `@` offers nothing without it |
| `ext-gd` (an extension, not a binary) | fitting an image inside the provider's limits | anywhere; without it an in-limits image goes through and an oversized one is left out |

**`fd` and `rg` are required by the `find` and `grep` tools**, as upstream requires them. A PHP
walk with `.gitignore` support was written and measured first — about 220ms over a 100k-file
tree against fd's ~40 — and then deleted: matching upstream's search semantics exactly is worth
more than the saved dependency, and two implementations that can disagree about which files
exist is a bug the model would have to debug.

`ensureTool()` is ported too, so the same thing happens as in pi: `ExternalTool` looks in
`~/.pig/agent/bin/`, then the PATH (knowing that Debian calls `fd` `fdfind`), and only then has
`ToolInstaller` fetch the release from GitHub. Two differences, both of which upstream has at
HEAD or would be better for: `PIG_OFFLINE=1` turns fetching off, and the download says what it
is pulling and from where **before** it starts, because an executable arriving from the
internet should not arrive quietly. Nothing checks a signature — neither project publishes
one — so this trusts GitHub and those two repositories exactly as far as upstream does.

The archive names are upstream's table, copied per tool rather than tidied into one rule.
The two projects do not publish the same Linux builds — ripgrep has an x86_64 musl build but
only a gnu one for aarch64, fd publishes gnu for both — so a shared rule names an archive that
does not exist, which is a 404 at download time on a machine nobody is watching. `archive()`
and `url()` are public so the naming can be tested without a download; that was the only way
the first version's wrong names could have been caught.

The download goes through `pig/ai`'s own HTTP client, which grew `HttpClient::follow()` for it:
a release URL is answered with a 302 to GitHub's object store, and a streaming API never
redirects, so `send()` keeps its single-request behaviour and only downloads pay for the loop.

## The shell tools: one engine, a structured result, and the session in the environment

`Tools\ShellTool` is upstream's `createShellToolDefinition()` and the engine under both `bash`
(`BashTool`) and `powershell` (`PowerShellTool`); only the shell, the label and a command wrapper
differ. `BashTool`'s options are upstream's: `commandPrefix`, `shellPath`,
`exposeSessionEnvironment`, `sessionEnvironment` and `spawnHook` (a closure over a `SpawnContext`
{command, cwd, env} that may rewrite what is spawned). A timeout of zero or less, or above
2147483.647 seconds, is an error, and so is a working directory that is not there.

- **Two results, as upstream returns them.** The model reads text cut to 2000 lines / 50KB; a
  programmatic caller gets `structuredContent` matching `ShellTool::OUTPUT_SCHEMA` — `{output,
  truncated, full_output_path?, exit_code, wall_time_seconds}`, `output` up to 1 MiB (head and tail
  512 KiB around an omitted marker, from `Run::fullOutput()`). `AgentToolResult::$structuredContent`
  and `AgentError::$structuredContent` are agent-core's; `Ai\Tool::$outputSchema` is never sent to a
  provider; neither is written to the session (`details` is). `HookedTool` keeps `structuredContent`
  unless a hook replaced the content. A non-zero exit is an error to the model and still carries it.
- **The session is in the command's environment.** `Tools\SessionEnvironment` sets `PIG_SESSION_ID`,
  `PIG_SESSION_FILE`, `PIG_PROVIDER`, `PIG_MODEL` and `PIG_REASONING_LEVEL` (upstream's `PI_*`) on each
  command, re-read per command so a model switched between two commands reaches the second, and
  strips inherited copies. Not for `!` / `!!`, and a tool an extension builds gets none unless it is
  given `sessionEnvironment`. `bin/pig` also sets `PIG_CODING_AGENT=true` and `AI_AGENT=pig` at start,
  so every child knows — RPC included.
- **`powershell`** is in `ToolSet::ALL`, not the defaults (`--tools`, `defaultTools: ["+powershell"]`);
  `Shell::powerShellConfig()` prefers `pwsh.exe`, then `powershell.exe`; on anything but native Windows
  a call fails with upstream's "only available on Windows". **Untested on native Windows.**
- **Which bash** is `Shell::resolve()`, upstream's `getShellConfig()`: `shellPath` first, refused by
  name when missing (`Custom shell path not found: …`); on Windows Git Bash under `ProgramFiles` /
  `ProgramFiles(x86)`, then `bash.exe` on `PATH`; elsewhere `/bin/bash`, then `bash` on `PATH`, then
  `sh`. pig's old fixed Homebrew and `/usr/local` candidates are gone — `shellPath` is the way to a
  newer bash.

Rule: a new shell-shaped tool is a `ShellTool` configuration, not a copy of `BashTool`.

## Environment variables are `PIG_*`, and only `PIG_*`

Every variable upstream reads as `PI_X` is `PIG_X` here, with **no `PI_` fallback**: two names for one
switch is how a setting ends up on in one shell and off in the next. No compatibility spelling is
kept, as none was for `~/.pig` → `~/.pig/agent`.

- **Renamed**: `PIG_HOME` → `PIG_CODING_AGENT_DIR` (upstream's `PI_CODING_AGENT_DIR`, `~` expanded as
  `getAgentDir()` does), `PI_CACHE_RETENTION` → `PIG_CACHE_RETENTION`, `PIG_TUI_TRACE` →
  `PIG_TUI_WRITE_LOG`. `PIG_CLEAR_ON_SHRINK`, `PIG_HARDWARE_CURSOR`, `PIG_OFFLINE` (`1`/`true`/`yes`),
  `PIG_PROGRAM_STATUS`, `PIG_HYPERLINKS`, `PIG_IMAGE_PROTOCOL`, `PIG_TRUE_COLOR` and
  `PIG_OAUTH_CALLBACK_HOST` no longer fall back to their `PI_` names.
- **Not read at all**, because upstream reads none of them: `PIG_MODEL`, `PIG_PROVIDER`,
  `PIG_THINKING`, `PIG_TUI_MODE` and `PI_MODEL`, `PI_PROVIDER`, `PI_REASONING_LEVEL`, `PI_TUI_MODE`
  (`CodingAgent::session()` lost its `environment:` parameter). `ALL_PROXY` is not read either.
- **New, upstream's**: `PIG_PACKAGE_DIR` (`Config::packageDir()`, where `CHANGELOG.md` is),
  `PIG_TUI_ESC_TIMEOUT` (ms a lone ESC waits; 100 over SSH, else 10), `PIG_SKIP_VERSION_CHECK`,
  `PIG_SHARE_VIEWER_URL`. `--offline` sets `PIG_OFFLINE=1` and `PIG_SKIP_VERSION_CHECK=1` for the run.
- **New, pig's own**: `PIG_STALL_LOG=1` logs every moment the event loop is held for 0.3s or more
  (`Loop::watchStalls()`) to `~/.pig/agent/logs` — how long, which callback, and where the coroutine it
  resumed had suspended before and after, which brackets the slow code.
- **Set, not read**: `PIG_CODING_AGENT=true` and `AI_AGENT=pig` for every child, and the
  `PIG_SESSION_*` / `PIG_PROVIDER` / `PIG_MODEL` / `PIG_REASONING_LEVEL` a shell command is given
  (see the shell tools above). The last three share names with the dropped config variables; they
  describe the session to a command and are never read back.

The one `PI_` pair still read is pi's own directory (`PI_CODING_AGENT_DIR`, then `PI_HOME`), because
that is about finding pi's files, not about configuring pig.

## Reaching the provider through a proxy

`Ai\Http\Proxy` has **no upstream counterpart, and is not optional** where pig runs. Node's
`fetch()` is undici, which reads `HTTPS_PROXY` through an agent somebody else wrote; PHP's streams
connect to the address they are handed and to nothing else. On a network where
`api.anthropic.com` is unreachable directly — the developer's, among others — pig without this does
not talk to a provider at all, so "direct" is not a worse route, it is no route.

Two protocols, because a local Clash or v2ray offers both and people use whichever port they
remember: **HTTP CONNECT** (RFC 9110 §9.3.6) and **SOCKS5** (RFC 1928, with RFC 1929 for a
password). `--proxy <url>` first; otherwise the environment as upstream's undici `EnvHttpProxyAgent`
reads it — an `https://` target through `https_proxy`, else `HTTPS_PROXY`, else the plain-HTTP one;
an `http://` target through `http_proxy`, else `HTTP_PROXY`, else directly — with `httpProxy` in the
person's settings (upstream's key, never the project's) filling an unset `HTTP_PROXY` / `HTTPS_PROXY`
(`applyHttpProxySettings()`). `ALL_PROXY` is curl's, not undici's, and is not read. `no_proxy` matches
`*.example.com` and `.example.com` alike; a non-empty `proxy.bypass` replaces it and an empty one
leaves it alone. `--no-proxy` ignores all of it.

**The order inside `Proxy::open()` is the security property, not an implementation detail.** The
TCP connection goes to the proxy with TLS *off*; TLS starts only after the proxy has said the far
end is open, and it verifies the **provider's** certificate. That is why `Socket::enableTls()` had
to become public, and why it sets `peer_name` itself: a tunnelled socket was dialled to the proxy
and would otherwise be checked against the proxy's name. The proxy carries pig's bytes and can read
none of them.

Eight decisions worth the words:

- **A hostname is sent as a hostname**, so the *proxy* resolves it — `socks5h` behaviour for the
  `socks5` spelling too, deliberately. In the network that makes a proxy necessary, the local
  resolver is the other thing that does not work, and a name resolved here would already be the
  wrong address by the time the proxy saw it. `ProxyTest` asks for `provider.invalid` throughout:
  by RFC 2606 it cannot resolve, so a version of this that resolved locally fails the test rather
  than passing for the wrong reason.
- **Loopback is always direct, and that is not a convenience.** pig's own OAuth callback listens on
  `127.0.0.1:8085`; a tunnel through a proxy to reach the machine pig is on cannot work. It also
  means the whole test suite keeps talking to its own servers.
- **Nothing reads the environment on its own.** `bin/pig` and `bin/pig-ai` each do it once. The
  first draft had `HttpClient` do it in its constructor, and every test in this container — which
  has `HTTPS_PROXY` set — would then have tunnelled through the container's own agent proxy.
- **The default is process-wide**, which is `Models::register()`'s shape and for the same reason:
  `new HttpClient()` appears in thirteen places — five providers and four OAuth flows among them —
  and none of them is handed one. A provider that reached the network directly while the rest
  tunnelled would work right up until somebody switched to it.
- **A bad proxy URL is fatal at startup.** Falling back to a direct connection would report the
  *provider* as unreachable and send somebody looking at the wrong machine.
- **An unreachable proxy says it was the proxy.** The first version's message was `Cannot connect to
  127.0.0.1:7890`, which never says whose address that is; it now reads `Cannot reach the proxy
  socks5://127.0.0.1:7890: … (asked for api.anthropic.com:443)`.
- **`describe()` never prints the password**, and it is what every error message above uses.
- **`https://` as the proxy's own scheme is refused by name.** TLS to the proxy with TLS to the
  provider inside it is two crypto layers on one stream and
  `stream_socket_enable_crypto()` does one. Every proxy that speaks `https://` also speaks
  `http://` or `socks5://` on another port, so naming it *is* the fix. SOCKS4 is refused too: it
  cannot carry a hostname, which is the one thing that matters here.

An `http://` target is tunnelled with CONNECT rather than sent in absolute form: every provider pig
talks to is `https://`, and a plain-HTTP endpoint is usually a local server the loopback rule or
`no_proxy` sends direct anyway.

The tests forward for real, through `test/TunnelServer.php` — a loopback proxy that speaks both
protocols — because a handshake can be byte-perfect and still leave one byte unread on the socket.
That was not hypothetical: deleting the read of SOCKS5's bound address makes four tests fail with
`Malformed status line: "  8HTTP/1.1 200 OK"`, which is the bound address arriving where the
response was meant to be. Asserting only on the bytes pig *sends* would have passed.

## Packages: upstream's package manager, with Composer where upstream has npm

`pig install | remove | update | list | config`, `-e <source>` and the `packages` setting are
upstream's `package-manager.ts` + `package-manager-cli.ts` + `config-selector.ts`, ported into
`packages/coding-agent/src/Packages/`, `Cli/PackageCommands.php` and `Cli/ConfigSelector.php`,
with the developer's three decisions on what PHP changes:

- **`composer:` in `npm:`'s place, and `npm:` refused by name.** `composer:vendor/name[@<constraint>]`
  is upstream's `npm:name[@version]` line for line: one shared Composer project per scope
  (`~/.pig/agent/composer`, `.pig/composer` for `-l`, `tmp/extensions/composer/<hash>` for `-e`)
  where upstream keeps its npm root, `composer require` / `composer remove` for install / remove,
  an exact version pinned and left by `update`, identity by name. The two reasons this was once
  reserved both have answers: Composer is how pig itself is installed, so it is on the machine,
  and the project's `composer.json` **replaces `pigagent/pig`** — upstream's `--omit=peer` — so a
  package that requires pig never puts a second copy in `vendor/`, whose autoloader (Composer
  prepends it) would otherwise win for every `Pig\` class the host had not loaded yet. Plugins
  are blocked (`allow-plugins: false`), since pig runs nothing a package brings. A missing
  Composer is refused by name rather than read as a failed install.
- **The manifest is `composer.json`'s `extra.pig`**, upstream's `package.json` `pi` key under the
  place composer keeps what is not its own. Same four fields, same globs and `!` / `+` / `-`
  patterns. No `composer.json` → the conventional `extensions/ skills/ prompts/ themes/`.
- **pig runs nothing inside a git or local package.** Upstream runs `npm install` in a cloned
  package; pig requires `vendor/autoload.php` beside an extension (or one level up) when the
  author shipped it, and otherwise loads the PHP that is there. A `composer:` package's libraries
  are the shared project's `vendor/`, which `ExtensionLoader` finds above the last `/vendor/` in
  the extension's path. `Pig\*` comes from the host and a git or local package's `require` must
  not name `pigagent/pig` — the docs say so; nothing checks.

What is upstream's to the line: the source grammar (`PackageSource`, `hosted-git-info`'s
shorthand table replaced by the five hosts a source string can use), the install layout
(`~/.pig/agent/git/<host>/<path>`, `.pig/git/…` for `-l`, `tmp/extensions/git/<hash>` for
`-e`), identity (host + path for git, resolved path for local), the two-scope reconciliation with
`autoload: false` deltas, the filters (`PackageFilter`), `ensureGitRef`'s fetch-then-reset and
the `@{upstream}` / `origin/HEAD` fallback, the CLI's parser and every refusal, `list`'s output,
and the startup `ls-remote` check (`PackageUpdateCheck`).

Where `composer:` differs from upstream's npm, and why: **an installed constraint is matched by
what the project was asked for**, not by what is installed — upstream checks the installed
version satisfies the range; here `resolve()` re-requires when the project's `require` holds a
different constraint than the settings, because comparing an installed version against a range
would be a second constraint solver beside Composer's. **The update check is two Composer
questions**: with a constraint, `composer update <name> --dry-run` (what `update` would actually
do); without one, `composer outdated <name>` (any newer release, since `update` requires the bare
name again). `composer outdated` alone reports the newest version and ignores the constraint.
Tests reach a path repository through `COMPOSER_HOME`'s `config.json` with Packagist switched off,
the way the git tests reach a bare repository through `GIT_CONFIG_*`.

What is not: **`.gitignore` is not read while discovering a package's files** — upstream uses
the `ignore` package; pig skips dot entries, `vendor` and `node_modules` and stops there, the
developer's call after weighing a port. A git-installed package is a clone, and a clone holds no
gitignored file to hide, so there the rule does nothing; a local-path package with its author's
drafts in it says what to leave out with the manifest's `!` patterns, which upstream has too. And
upstream's own matcher is not git's semantics either (`prefixIgnorePattern()` re-roots each nested
file's patterns), so porting it would be a third notion of "ignored" beside git's and `fd`'s.
**`resolve()` covers the packages
only**: the top-level `extensions` / `skills` settings and the auto-discovered directories stay
with the four loaders, which already had them, and a package's resources are appended after
theirs — `ResolvedPaths::rank()` is upstream's order, and "first wins" in each loader does the
rest. For the same reason **`pig config` shows package rows and the built-in extensions only**:
upstream's `toggleTopLevelResource()` has nothing to stand on here, and that `origin ===
'top-level'` arm is left out of `ConfigSelector` rather than carried as code nothing reaches; the
Built-in rows are upstream's (see [Built-in extensions](design/extensions.md#built-in-extensions)). What is there is upstream's to the line: the two write scopes on Tab, `+path` / `-path`
written into the package's entry, the project's inherit → load/unload cycle with an
`autoload: false` delta made and removed as needed, dimmed inherited rows, the search box.
**`update` keeps pig's own half**: `--extensions` also refreshes the bundled
extensions' copies under `~/.pig/agent/extensions` (`SelfUpdate::updateExtensions()`), which are
not packages and have no other updater — all but the four built-ins, which are not copied at all.

**Where the resources go in:** `bin/pig` resolves once trust is known (`$resolvePackages`),
hands the extension files to `ExtensionLoader::load(packageExtensions:)` — loaded last, in the
same `EventBus` as everything else — and the rest to `CodingAgent::session(packageResources:)`,
which appends skills (`Skills::fromFiles()`, by name, the local one winning), prompts
(`SlashCommands::fromFiles()`) and themes (`Themes::setPackageThemeFiles()`, listed after the
directories).

**`-e <source>` is upstream's temporary package**: every value goes through
`PackageManager::resolveExtensionSources(temporary: true)` — a git source is cloned under
`<home>/tmp/extensions/git/<hash>` and refreshed when unpinned, a directory with the package
shapes is read as a package, a file or a bare extension directory as one extension — and nothing
is written to the settings. The extensions go to the loader as the CLI paths; the skills, prompts
and themes join the configured packages' in `bin/pig`'s `$resolvePackages`. `Arguments::SHORT`
gained `e` for it.

**Local paths in the settings are relative** — `Paths::relativeTo()`, Node's `path.relative()` —
because a project's `.pig/settings.json` is committed and `../tools` is the same directory on every
checkout where an absolute path is one machine's. The developer's call, after a first version wrote
absolute paths for anything outside the settings directory.

Tests: `PackageSourceTest` (upstream's `git.test.ts` cases), `PackageManagerTest` (local
packages, manifest, filters, both scopes, and git against a bare repository reached through
`GIT_CONFIG_*` `url.<dir>.insteadOf`, so no network), `PackageCommandsTest` (the parser),
`ConfigSelectorTest` (what a toggle writes, in each scope).

- The gallery at pigagent.dev/packages is GitHub topic `pig-package` (the smart-book site's
  `PigPackageGalleryService`), not anything pig reads; `extra.pig.image` / `extra.pig.video` exist
  only for it, so `PackageManifest` does not parse them — do not add write-only fields for the site.

## Version floor: PHP >= 8.3

`Fiber` arrived in 8.1 and the whole async runtime rests on it, so 8.1 is the absolute floor;
8.3 is the floor actually declared, because 8.1 is end-of-life and 8.2 loses security support at
the end of 2026. **`require.php` in the root `composer.json` is the source of truth**, because that
is the one Composer reads — the five under `packages/` declare their own and are structure
documentation now rather than manifests; see the Packagist entry in the traps. This line said "the
three composer.json files" for as long as there were three, and by the time anybody looked there
were six and only one of them was load-bearing.

Off-limits until the floor moves, even though the dev machine runs 8.4:

| Feature | Since |
|---|---|
| Property hooks, asymmetric visibility (`public private(set)`) | 8.4 |
| `new Foo()->bar()` without parentheses | 8.4 |
| `array_find` / `array_find_key` / `array_any` / `array_all` | 8.4 |

Where 8.4 would express something better, say so in a comment rather than leaving it unexplained —
`FutureState` reads that way, since on 8.4 its readers would collapse into `public private(set)`.

Verify against the floor, not just the dev version — `php8.3 test/lint.php`. That does two things,
because `php -l` only parses:

- linting with the **floor's own binary** rejects 8.4-only *syntax* at parse time;
- a grep catches 8.4-only *functions*, which `php -l` never resolves. `array_any()` lints clean on
  8.3 and fails only on the line that reaches it.

Neither catches a **missing `use`**: an unimported class is resolved at run time too, and shows up
only on the code path that touches it. Exercise every branch — `ThinkingStartEvent` went unimported
and only the thinking test found it.
