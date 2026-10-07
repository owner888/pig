# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## pig is a minimal agent

**The developer's standing rule, above every other decision in this file: pig stays minimal, and
nothing that muddles the logic gets added.** More tools, more options, more paths through the same
code are not free — the model spends part of every turn choosing between them, and every extra arm
is a place for the rules to disagree with each other, which is what most of the traps at the bottom
of this file turned out to be.

So a feature that upstream does not have needs a reason that survives being read out loud, and a
feature upstream *does* have arrives in upstream's shape rather than a wider one. When a choice is
between two workable designs, the one with fewer branches wins. When something is already in reach
another way — searching through `bash` rather than a third search tool — the second way does not get
added for convenience.

This is the rule that decided the built-in tool set: four by default, as upstream has it, with
`grep`, `find` and `ls` reachable by name through `--tools`.

### The developer's name does not appear in this repository

Every file here goes to git, so **the developer is "the developer"** — never a name, in source
comments, docblocks, test fixtures, `CLAUDE.md` or either README. A test that needs a home directory
uses `/Users/dev/...`, a fixture that needs a tenant header uses `acme`, an example address is
`dev@example`. This was found the hard way: the name had reached six files, including a docblock in
`CustomModels` and a row in the table below, because a fixture written from a real path is the
easiest thing in the world to paste.

## Upstream anchor

**Port against commit `d0a4c37` (2026-01-02)** — the snapshot behind the "418 lines" claim
(`packages/agent/src/agent-loop.ts` was 417 lines + trailing newline there). Do not port
against upstream HEAD: by 2026-09 that file is 898 lines and `packages/agent/src` is 33k.

Upstream at that commit was still published as `@mariozechner/pi-*`; it is `@earendil-works/pi-*` now.

Reference checkout for diffing lives outside this repo — clone pi and `git checkout d0a4c37`.

### The anchor commit does not compile

`d0a4c37` is the commit that split the agent's single queue into `steer()` and
`followUp()`, and it changed `packages/agent` **only**. `coding-agent/src/core/agent-session.ts`
at that same commit still calls `agent.queueMessage()`, `clearMessageQueue()`,
`getQueueMode()` and `setQueueMode()` — none of which exist there any more. The anchor is a
snapshot taken mid-break.

**And `packages/agent`'s own test suite is on the old side of it too**, which matters when the tests
are read as a specification: `agent/test/agent.test.ts` calls `agent.queueMessage(message)` and
asserts the old refusal wording, so that file cannot run at the anchor either. Where a test there
describes behaviour the split did not touch, it is still the spec and pig pins it; where it names the
queue, it describes an API that no longer exists.

So "port it literally" has no meaning for the parts of `coding-agent` that touch the queue:
there is no working original. Those are ported against the **split** API, which is what
`Pig\Agent\Agent` has, and the mapping is written down where it happens. Anywhere else the
anchor turns out not to build, do the same and say so here rather than porting a call that
cannot have worked.

### Things taken from upstream HEAD on purpose

The anchor is a floor, not a ban. Where HEAD has solved something the snapshot had not, and
the developer asked for it, it is ported and listed here:

| From HEAD | Where it lives here | Upstream |
|---|---|---|
| Ctrl+V pastes a clipboard image as a temp file, and its path into the prompt | `Pig\Tui\Clipboard`, `Editor::pasteFromClipboard()` | `coding-agent/src/utils/clipboard-image.ts` + `interactive-mode.ts` |
| A three-line banner with the keys on one line, the rest behind ctrl+o, and a `[Context]` section for what was loaded | `InteractiveMode::banner()` | the startup screen at 0.87 |
| A 4-cell half-block startup mascot logo icon (custom pink piglet) | `Pig\CodingAgent\Interactive\PigLogo`, `InteractiveMode::banner()` | `pi-logo.ts` at 0.99.1 |
| `!!command` runs without joining the conversation | `AgentSession::executeBash(remember: false)` | `!!` at 0.87; the anchor has `!` only |
| Ctrl+G opens the prompt in `$VISUAL`, without stopping the event loop | `InteractiveMode::editPromptExternally()`, `Process::interactive()` | `openExternalEditor()` in `interactive-mode.ts`, which blocks |
| Fullscreen ChatViewport with fixed bottom dock & scroll-to-end indicator | `Pig\CodingAgent\Interactive\ChatViewport`, `Pig\Tui\Components\ScrollView` | `chat-viewport.ts` + `tui-alt-screen.ts` at 0.85+ |
| Skill invocation collapsible message component | `Pig\CodingAgent\Interactive\SkillInvocationMessageComponent`, `Pig\CodingAgent\Prompt\SkillBlock` | `skill-invocation-message.ts` |

The anchor's banner is a column of thirteen keys, which is taller than most of the
conversations it sits above; HEAD moved the list behind `ctrl+o` and put a one-line
summary in its place. `[Skills]` and `[Extensions]` are sections there too; both are
here now.

Upstream reads the clipboard through a native Node addon on macOS and Windows and falls back
to `wl-paste` / `xclip` / PowerShell on Linux. PHP has no addon, so every platform goes
through a command here — including macOS, which uses `pngpaste` when it is installed and
AppleScript, which always is, when it is not.

## Decisions on record

| Decision | Choice | Why |
|---|---|---|
| Port fidelity | File-by-file literal | Class/file/method names track upstream so `git diff` against a newer upstream commit stays mechanical |
| Concurrency | Hand-written `Fiber` + `stream_select` loop | One `select()` must wait on the LLM socket and STDIN together — that is what makes Esc-to-interrupt and typing mid-stream possible |
| HTTP transport | Raw `tls://` streams, hand-written HTTP/1.1 | Sockets and STDIN are then the same kind of thing; one loop, no `ext-curl` |
| Unions | real union type where the arms are closed and few, marker interface otherwise | PHP has union types; what it lacks is naming one and typing an array's elements — see [Porting the unions](#porting-the-unions) |
| Layout | one published package, five namespaces under `packages/` | Mirrors upstream's package split one-to-one; packagist.org cannot serve a package from a subdirectory, so the split is a layout rather than five packages — see the Packagist entry in the traps |
| Session file | pi's format exactly, in pi's directory layout | A conversation started in either tool opens in the other; `--resume` lists both — see [The session file is pi's file](#the-session-file-is-pis-file) |
| Back-compatibility | none before a release | pig has never shipped, so there is nobody with an old file; a reader for one would be compatibility with nothing, exercised only by the test written to exercise it |

### Deliberate exceptions to "prefer the platform library over your own"

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
  3. `Socket` & `Retry`: Non-blocking OpenSSL transport failures (`Broken pipe`, `SSL operation failed`,
     handshake drops, zero-write stalls) are recognized as retryable transient errors in `Retry::WORDS`,
     triggering automatic backoff retries rather than aborting sessions.
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

#### `Future` + `Deferred` is the Promise, and `then()` is what fibers make unnecessary

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

### Extensions

`ext-mbstring`, `ext-json`, `ext-openssl` and `ext-pcre` are required and are in every build worth
running. `pig/tui` additionally requires **`ext-pcntl`**, because SIGWINCH is the only way to learn
that the window was resized; without it the UI would draw at the startup width forever, so
`ProcessTerminal` refuses to start rather than doing that quietly. `ext-intl` is deliberately *not*
required — see [Five upstream dependencies that are not needed here](#five-upstream-dependencies-that-are-not-needed-here).

**`shellPath` in the settings names the shell**, which is upstream's key and was a key pig stored
and nothing read — the `terminal.showImages` shape again. It earns its keep on macOS: `/bin/bash`
there is **3.2**, with no `mapfile` and no `${var^^}`, and `Shell::bash()` prefers `/bin/bash`, so
installing a homebrew bash 5 did nothing. A path that is not executable is **refused by name**
rather than falling back to `/bin/bash`, because the fallback is the bug the setting was written to
work around. `Shell::useShellPath()` is static for `Models::register()`'s reason: `bash`, a typed
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

**`fd` and `rg` are required by the `find` and `grep` tools**, as upstream requires them. A PHP
walk with `.gitignore` support was written and measured first — about 220ms over a 100k-file
tree against fd's ~40 — and then deleted: matching upstream's search semantics exactly is worth
more than the saved dependency, and two implementations that can disagree about which files
exist is a bug the model would have to debug.

`ensureTool()` is ported too, so the same thing happens as in pi: `ExternalTool` looks in
`~/.pig/agent/tools/`, then the PATH (knowing that Debian calls `fd` `fdfind`), and only then has
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

### Reaching the provider through a proxy

`Ai\Http\Proxy` has **no upstream counterpart, and is not optional** where pig runs. Node's
`fetch()` is undici, which reads `HTTPS_PROXY` through an agent somebody else wrote; PHP's streams
connect to the address they are handed and to nothing else. On a network where
`api.anthropic.com` is unreachable directly — the developer's, among others — pig without this does
not talk to a provider at all, so "direct" is not a worse route, it is no route.

Two protocols, because a local Clash or v2ray offers both and people use whichever port they
remember: **HTTP CONNECT** (RFC 9110 §9.3.6) and **SOCKS5** (RFC 1928, with RFC 1929 for a
password). `--proxy <url>`, then `https_proxy`/`all_proxy`, then `proxy.url` in the settings — the
order stated at the top of `bin/pig`, and `--no-proxy` ignores all three.

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

`http_proxy` is not read, and an `http://` target is tunnelled with CONNECT rather than sent in
absolute form. Both follow from the same fact: every provider pig talks to is `https://`, and a
plain-HTTP endpoint in `models.json` is a local or self-hosted server that goes direct anyway.

The tests forward for real, through `test/TunnelServer.php` — a loopback proxy that speaks both
protocols — because a handshake can be byte-perfect and still leave one byte unread on the socket.
That was not hypothetical: deleting the read of SOCKS5's bound address makes four tests fail with
`Malformed status line: "  8HTTP/1.1 200 OK"`, which is the bound address arriving where the
response was meant to be. Asserting only on the bytes pig *sends* would have passed.

## Layout

```
packages/async/      → Pig\Async\        (pig/async)       Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           (pig/ai)          unified LLM API, HTTP/SSE, Anthropic; Extension\ for a provider an extension brings
packages/agent-core/ → Pig\Agent\        (pig/agent-core)  AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          (pig/tui)         renderer, widths, keys, editor, markdown, images, env
packages/coding-agent/ → Pig\CodingAgent\ (pig/coding-agent) tools, theme, session, interactive CLI
bin/pig                                     the entry point
```

**There are two `Paths` classes and that is deliberate.** `Pig\Tui\Paths` is a path as a *person*
typed or pasted it — a leading `~`, and the spaces macOS substitutes on the way to the clipboard —
and `Pig\CodingAgent\Tools\Paths` is the rest: resolving against a working directory, collapsing
`..`, the macOS screenshot retry. The split is which package can see which: the `@` file picker lives
in `pig/tui` and had its own worse copy of the first part until this, which is the third time that
has happened to the same fourteen lines. A grep for `Paths::expand` finding both is the point of the
shared name, and the second one delegates.

`Pig\Tui\Env` has no upstream counterpart either, and exists for one reason: `getenv()` answers `''`
for a variable exported without a value where JavaScript's truthiness reads that as absent, so the
same correction was being written out in six places. Both traps are at the bottom of this file.

Ported so far: all of `ai` (`types.ts`, `utils/event-stream.ts`, `stream.ts`, the Anthropic provider),
all of `agent-core` (`types.ts` 217 → `agent-loop.ts` 417 → `agent.ts` 439), and `tui`'s foundation
(`utils.ts` 712 → `terminal.ts` 138 → `tui.ts` 351 → `keys.ts` 547 → `autocomplete.ts` 576 →
`components/`, `terminal-image.ts` 340, `image.ts` 87). **`pig/tui` is now done**: `components/settings-list.ts` was the last file, and it arrived with the `/settings` screen that is its only consumer — a component with no consumer is dead code, which is why it waited. This paragraph said "done" for months while that file was missing; the file-list check below is what caught it, and the claim is only worth as much as the last time that check was run.

`coding-agent` is upstream's biggest package — 23k lines at the anchor, and most of it is not
the agent: OAuth, twenty-five selector components. What is being ported is the part
that makes it a coding agent: `core/tools/` (done), `core/system-prompt.ts` (done), enough of
`core/agent-session.ts` to hold a session (done — `Session\AgentSession`), an interactive mode
built on `pig/tui` (done — `Interactive\`), `core/hooks/` (done — `Hooks\`),
`core/custom-tools/` (done — `CustomTools\`), `core/messages.ts` (done — the four app message
types under `Session\`), `modes/rpc/` (done — `Rpc\`) and `modes/print-mode.ts` (done —
`PrintMode`). The rest is left out until something needs it.

### A replayed summary had no end the model could find

`messages.ts` is 189 lines and four of them are the constants that wrap a summary when it is
replayed:

```ts
COMPACTION_SUMMARY_PREFIX = "…compacted into the following summary:\n\n<summary>\n"
BRANCH_SUMMARY_PREFIX     = "…a summary of a branch that this conversation came back from:\n\n<summary>\n"
```

pig had **its own sentence and no tags at all** on both — `"The conversation so far, summarised:"`
and a preamble of its own. A summary is prose a model wrote *about a conversation*, so it contains
lines like `Assistant: I reverted it` and, if the model was thorough, instructions it found in what
it was summarising. Replayed with a sentence in front and nothing behind it, **where the summary
stopped and the live conversation resumed was something the next model had to work out** — and pig
already wraps its summarisation *request* in `<conversation>` tags for exactly this reason, which is
the same fact one direction over.

Both are upstream's constants now, word for word, with pig's `<read-files>` and `<modified-files>`
after the close, because a file list is not part of the prose. **Upstream's two suffixes disagree
about a leading newline** and that is kept: it reaches a prompt either way, and a port that tidies
one of two literals is a port whose next diff shows a change nobody made on purpose.

The branch preamble's own docblock said **"Upstream's wording"** and it was not — the fourth shape
from the index, in the one place where the claim was the whole justification for the deviation. It
went with the constant rather than being corrected in place, and the reason it gave (that the
sentence should say *the user* went somewhere else, so the model does not read the summary as its own
last turn) survives in upstream's sentence anyway: "a branch that this conversation came back from"
says the same thing.

Regression test: `CompactionTest::testAReplayedSummaryIsWrappedSoItsEndIsFindable`, over both
message types, which also asserts the file lists are outside the tags.

The rest of `messages.ts` matched, and one difference is pig's own with a reason: `toText()` rtrims
the command output before fencing it, so `"hi\n"` does not come out as a fence with a blank line in
it and an output of nothing but newlines reads as `(no output)` rather than an empty fence.

`AgentSession` is 1901 lines upstream and ~1000 here, because the one thing it coordinates that
is not ported is not there to coordinate: branching to a second session file.
What is left is the conversation, the event fan-out,
the queue of messages someone typed while the agent was working, the thinking level, what the
session has cost, persistence, compaction, tree navigation and the hook events. Each of the
rest can arrive on its own when something needs it.

主题照 upstream 的 `theme.ts` / `theme-json.ts` / `system-theme.ts` / `theme-controller.ts` 移植（开发者要求 100% 对齐，旧的 `Theme\Palette` 已删除，不留别名）：`Theme\Themes` 是 `theme.ts` 的模块函数和全局主题（`Themes::theme()` 对应 upstream 的 `theme` 代理），`Theme\Theme` 是 upstream 的 `Theme` 类，颜色运算在 `Pig\Tui\Colors` / `Pig\Tui\Oklab`（`colors.ts` / `oklab.ts`）。内置主题 `dark.json` / `light.json` 与 upstream 逐字相同（OKHSL 写法），`labra.json` 是 pig 额外保留的内置主题。没有主题设置时用 upstream 的 `system` 主题：终端报告颜色前是灰阶，`InteractiveThemeController` 用 `TUI::queryTerminalColors()` 拿到颜色后生成，DEC 2031 深浅色切换时自动跟随（设置为 `light/dark` 一对时在两者之间切换）。

**避坑规则**：组件在绘制时取 `Themes::theme()->fg(...)`，不要把 `Theme` 实例存起来，否则切主题后不变；会把颜色烘进字符串的组件要在 `invalidate()` 里重建（upstream 同样），切主题时控制器 `invalidate` 整个 UI。`Theme::fg()` 给背景色 token 会抛错，背景用 `bg()`。`InteractiveMode` 装了 `ThemeJson::validateThemeJson` 校验器：缺 token 的自定义主题会被拒绝并报原因（不再用 dark 补齐）。自定义主题目录是 pig 的四个（`~/.pig/agent/themes`、`~/.pi/agent/themes`、项目信任后的 `<cwd>/.pig/themes`、`<cwd>/.pi/themes`），先找文件名再找文件里的 `name`；读不了的主题记在 `Themes::getCustomThemeErrors()` 并在列出主题时说出来。语法高亮仍用 pig 的 `Highlight`/`Grammar`（`Themes::highlightCode()`）。`/theme` 不带参数打开选择器（移动即预览，Esc 还原），`/theme <name>` 直接切换并保存。Web UI（`pig --mode web`）自带 `Dark`/`Labra`/`Light` 切换并用 `localStorage` 记住。

**烘进颜色的文字用 `ThemedText`**（upstream `components/themed-text.ts`）：`new ThemedText(fn () => Themes::theme()->fg('dim', $msg), 1, 0)`，`invalidate()` 后下次绘制用当前主题重建；数据要在建组件前取好快照。`say()`/`sayError()`/`sayWarning()`、横幅（`ExpandableText`，ctrl+o 用 `setExpanded()`）、各命令输出都走它。直接 `new Text(Themes::theme()->fg(...))` 切主题后颜色不变——`system` 主题在终端回报颜色前是灰阶，这种文字会一直是灰的。

**扩展的主题接口照 upstream `ExtensionUIContext`**：`getAllThemes()` 返回 `list<ThemeInfo>`（`->name`、`->path`），`getTheme($name): ?Theme`，`setTheme(string|Theme): array{success, error?}`；`NoUi` 回 `UI not available`，`RpcUi` 回 `Theme switching not supported in RPC mode`（不再转发给宿主）。扩展组件不要存 `custom()` 给的 `Theme`，绘制时读 `Themes::theme()`（upstream 的 `theme` 是代理）。

**启动信息的布局照 upstream**：`headerContainer`（内置头部 `ExpandableText`）之后是 `loadedResourcesContainer`，由 `showLoadedResources()` 每段一个 `ExpandableText`（Context、Skills、Prompts、Extensions，pig 多一段 Tools），问题用 `[Skill conflicts]`/`[Extension issues]` 的 `ThemedText` 块；ctrl+o 展开收起全部，`/reload` 重建。

**`Markdown::invalidate()` 必须清掉缓存的默认样式转义**：行内 span（`**粗体**`、`` `代码` ``）之后恢复默认样式用的转义是算一次存起来的，只清行缓存不清它，切主题后粗体后面的文字还是旧颜色（用户消息、压缩/分支摘要正文都中招）。

**鼠标**：`Box`、`Editor`、`SelectList`、`SettingsList` 照 upstream 有 `handleMouse()`。包装组件（如 `CustomEditor` 包着 `Editor`）必须实现 `MouseHandler` 并转发，否则点击输入框什么都不做；`Mouse::dispatchMouseEvent()` 会把焦点给到包装组件本身。

| 旧 | 新 |
|---|---|
| `Theme\Palette`、`PaletteTest` | 删除；`Theme\Themes` + `Theme\Theme` |
| `$palette->fg/bg('x', $t)`、`$palette->of('x')` | `Themes::theme()->fg/bg('x', $t)`（绘制时取）；闭包 `static fn ($t) => Themes::theme()->fg('x', $t)` |
| `$palette->hex('x')` | `Themes::getResolvedThemeColors($name)['x']` |
| `markdownTheme()/selectListTheme()/editorTheme()/settingsListTheme()` | `Themes::getMarkdownTheme()/getSelectListTheme()/getEditorTheme()/getSettingsListTheme()` |
| `highlightTheme()` + `Highlight::lines` | `Themes::highlightCode($code, $lang)` |
| `thinkingBorder($l)`、bash 模式边框、`isTruecolor()` | `Themes::theme()->getThinkingBorderColor($l)`、`getBashModeBorderColor()`、`getColorMode() === 'truecolor'` |
| `Palette::named/dark/light/labra`、`names()`、`customThemes()` | `Themes::setTheme()/initTheme()/getThemeByName()`、`setCustomThemesCwd()` + `getAvailableThemes()`、`getAvailableThemesWithPaths()` |
| `HookUi::palette()`、`FooterComponent::setPalette()` | `HookUi::theme()`；页脚直接读全局主题 |
| `InteractiveMode::useTheme()/$palette`，构造参数 `(session, palette, cwd, version, theme, …)` | `$themeController`（`InteractiveThemeController`），`(session, cwd, version, ?themeSetting, …)` |

`!command` runs a shell command and puts the result in the conversation, as a
`Session\BashExecution` — an app message, not an LLM one. `CodingAgent` supplies the
`convertToLlm` that turns it into a user message on the way to the model; the agent's own
default drops anything that is not one of the three LLM types, which is exactly what
`!!command` wants, since it never makes a `BashExecution` at all. So the difference
between the two is one boolean and no special case downstream.

A command run while the agent is working is **held back until the run ends**. A message
added between a tool call and its result is a request the provider rejects outright, so
`AgentSession` queues it and flushes on `AgentEndEvent` — before the listeners see that
event, so a UI redrawing on it already has the message.

`Session\SessionManager` is the conversation on disk: one JSON object per line, appended
to, under `~/.pig/agent/sessions/<the project's path, flattened>/`. Appending rather than
rewriting is what makes a session survive whatever ends the process. **Nothing is written
until the first assistant message** — someone who starts pig, reads the banner and quits
leaves no file behind, which is what makes the directory worth opening at all; the
messages before that one are written in front of it when it arrives.

The log is a **tree**, as upstream's is: every entry carries an `entryId` and a `parent`,
and the session holds a *leaf* — the end of the branch being talked on. `messages()` walks
from the leaf back to the root rather than reading the file in order, because the file holds
every branch and only one of them is this conversation.

Going back (`/tree`) moves the leaf to an earlier entry; the next thing said hangs off it and
forks. **Nothing is deleted and nothing is rewritten** — the entries on the abandoned road
keep their parents, so it can be gone back to in exactly the same way. That is the whole
value of the shape: a wrong turn costs nothing.

Three things this changed that are worth knowing:

- **A compaction is resolved on the way out, every time.** It used to be applied once as the
  file was read, which was correct while the log was a line and wrong the moment a branch is
  taken from *before* the compaction — that branch never had it.
- **Format version 3, and it is pi's version 3.** It was 2 — pi's 2 — until pi moved, and a
  pig file under the old number was rewritten by pi on every open. See the `context_edit` trap
  below for what v3 adds; there is still no reader for the shape pig briefly wrote under `2`.
- **The end of the file is the leaf.** A branch is only ever made by appending, so the newest
  entry is always on the branch that was being talked on when the session was last open.

### The session file is pi's file

**It was not, and it said it was.** pig wrote the message flattened onto the line with
`entryId` and `parent` and an integer millisecond timestamp, under a header saying
`version: 2` — which is the number pi uses for a different shape. So pi opening a pig file
would agree about the version and then find no `type` on any line. A different version number
would have been honest; the same number for a different format is the one thing that cannot be.

It is pi's now, field for field:

| | pi, and now pig | pig, before |
|---|---|---|
| project directory | `--Users-dev-Dev-pig--` | `Users-dev-Dev-pig` |
| file | `2026-01-02T21-29-30-123Z_<uuid>.jsonl` | `2026-01-02-212930-<12 hex>.jsonl` |
| header timestamp | `"2026-01-02T21:29:30.123Z"` | `1735849770123` |
| a message | `{"type":"message","id","parentId","timestamp","message":{…}}` | the message, flat, plus `entryId` and `parent` |
| entry id | eight characters off a UUID | twelve hex characters |
| compaction | `type:"compaction"` with `firstKeptEntryId` | `role:"compactionSummary"` with `replaced` |
| a hook's message | `type:"custom_message"` | `role:"hookMessage"` |
| a hook's note | `type:"custom"`, in the tree | `type:"custom"`, outside it |
| what the model is not shown | `type:"context_edit"` with `targetId` and `replacement` | nothing — the message was taken off the state and the file said nothing |

`Session\SessionEntries` encodes the **line**; `Session\SessionCodec` encodes the **message**
that sits inside one. Upstream keeps them apart for a reason that only shows up here: not
every line is a message. A compaction, a branch summary, a hook's message and a hook's private
note are each a line of their own with its own `type`, and two of them are not part of the
conversation at all. `RpcEvents` keeps using `SessionCodec`, because a host wants a message.

Three things this changed that are worth knowing:

- **`replaced` became `firstKeptEntryId`.** They say the same thing from opposite ends — "this
  stands in for the first N messages" against "the kept part starts here" — and only one of
  them is something pi can read. `replaced` survives as a number on a screen, *derived* when
  the file is read: a file holding both is a file that can disagree with itself.
  `SessionManager::entryAt()` is the join, because the cut is an index into the resolved
  conversation and the file is a tree of entries, and those stop being the same numbering the
  moment one compaction has already happened.
- **A line neither tool understands stays in the tree.** Skipping one **broke the chain**,
  because the entries after it name it as their parent — a pi conversation came back as its
  first message and nothing else. Such a line is kept as a node holding nothing and walked past
  on the way out. The general shape: **in a tree read from a file, an entry you cannot use is
  still an entry other entries point at.**
- **`~/.pi/agent/sessions/` is read too**, beside pig's own, so `--resume` lists both and
  opening one appends to it in its own directory in its own format — the conversation stays one
  conversation. The `agent` in that path is not a typo: upstream's `getAgentDir()` is
  `join(homedir(), ".pi", "agent")`. `PI_CODING_AGENT_DIR` — upstream's own, whose name is
  *built* from `APP_NAME` rather than written anywhere, which is how pig came to look for
  `PI_AGENT_DIR` instead — and `PI_HOME`, pig's own, override it.

**There is no reader for the old shape, on purpose.** The developer's call, and the reasoning
is worth keeping because it is the kind that gets forgotten: pig has never been released and
nobody has ever had a session file in the old format, so a reader for it would be compatibility
with nothing — code that can only ever be exercised by a test written to exercise it. It was
written first and then deleted, along with the two tests that covered it. Anything that is not
a line pi could have written is a line pig has nothing to do with, and takes the same path as
`thinking_level_change`: a node holding nothing, walked past on the way out.

The general rule, for the next time this comes up: **back-compatibility is a debt to real users,
and there are none until there is a release.** Before that, a format change is a format change.

**And that rule is about pig's own old shape, which is the distinction that hid a real gap for
months.** pi's v1 sessions are somebody else's history, and there are real users with those:
`--resume` lists them, because pig reads pi's directory. A v1 file has **no `version`, no `id` and
no `parentId` — the order of the lines is the chain** — and a compaction there names its cut by
`firstKeptEntryIndex`, an index into the file with the header counted.

Read as v2, every entry is parentless, so every entry is a root, so walking back from the leaf finds
exactly one message. Measured on a three-message file: **pig opened it as its last line and nothing
else.** Somebody moving over from an older pi saw their conversation in the list, opened it, and got
one sentence.

`SessionManager::upgrade()` is upstream's `migrateV1ToV2()`, and like upstream **the file is
rewritten**. That is the decision worth arguing and the argument is `Migrations`': what happens is
exactly what pi does on its next start, so running it converges rather than diverges. Leaving it
alone is worse than it sounds — pig would append v2 entries to a v1 file and pi's own migration would
then re-id every line from the top, flattening whatever branches pig had made in between. A file that
cannot be written is read correctly and left alone, which costs one more upgrade next time.

What made it findable at all was reading the upstream file's **top-level functions** rather than its
class: `migrateV1ToV2`, `migrateToCurrentVersion` and `migrateSessionEntries` are three of the nine
functions above `class SessionManager`, and a method-by-method comparison of the two classes skips
all nine.

Regression tests: `PiFormatTest::testAV1SessionComesBackWholeRatherThanAsItsLastLine`,
`testOpeningAV1SessionUpgradesTheFileTheWayPiWould`,
`testAV1CompactionsIndexBecomesTheIdItPointsAt` — one per end, and each stays green when the other
two are broken.

**The class docblock had inventoried what was missing**, and every item on the list had since been
ported: the tree, labels, the model and thinking-level entries, the migrations. *A docblock that
lists absences is a docblock that goes stale silently* — the fourth shape from the index, at its
largest, since the list was four items long and wrong about all four. It says what is there now, and
the one real absence (`branch()` in upstream's sense — forking into a second file) is named.

### What a conversation was being had with

`model_change` and `thinking_level_change` are lines in the file that are not messages: the
model never sees them and `/tree` does not offer them, but they are what makes resuming mean
something. Without them `--continue` after an afternoon on opus came back on whatever the
settings said — which is the wrong answer to "carry on where I left off", and was pig's answer
until the file format became pi's and there was somewhere standard to put them.

`SessionManager::settings()` walks the branch and takes the last of each. The model falls back
to **whatever answered last**, exactly as pi's does: an assistant message carries the provider
and the model that produced it, so a conversation records what it was had with even if nobody
ever changed it on purpose.

Four things about it:

- **The branch, not the file.** Switching model on a branch you later walked away from is not
  what this conversation is being had with.
- **`--model` beats the file**, which is why `restoreSettings()` takes a flag rather than
  deciding for itself: a model named on the command line is someone saying what they want
  *now*, and the file is saying what was true last time. The order is otherwise unchanged —
  typed, then the environment, then this session, then the settings, then the default. This
  session is new and sits above the settings, because the settings hold what to open a *new*
  conversation with.
- **A level the restored model cannot do is clamped**, for the same reason `setModel()` clamps:
  the person resuming did not ask for it, the file did, and a provider would reject it.
- **A model this pig has no entry for is not an error.** pig's registry carries only the models
  whose protocol is ported and excludes OpenRouter's, so a pi session on one of those restores to
  whatever pig had. The conversation still opens, and the footer says which model is answering.

Recorded only when something actually changed: `setModel()` is also how the thinking level gets
clamped, and a line per call would be a file full of a model changing to itself.

### Naming a point

`/label before the refactor` names where the conversation is, and `/tree` shows the name beside
what was said — because a list of "4 back · 7 back · 12 back" is a list nobody can choose from.
`/label` with nothing clears it. pi's `type: "label"` entry, so a name set in either tool shows
up in the other.

Four things about it:

- **It names the last thing that was *said*, not the leaf.** Writing a label advances the leaf
  — the label entry becomes it — so naming the leaf would mean `/label a name` followed by
  `/label` to clear it named the first label rather than clearing the message's name. The first
  test of this **passed while doing the wrong thing**, because it asked about the leaf too.
- **Clearing writes a line rather than removing one.** Nothing in a session file is ever
  deleted: a name that was taken off is a line saying so, sitting after the line that set it,
  and reading in file order is what makes the last one win.
- **Labels are read in file order, not branch order**, which is upstream's rule and the right
  one: a label names an entry by id and that entry can be on any branch. Read off the current
  branch, a name would vanish and come back as you moved around.
- **A label on an id nothing has is refused.** It would be a line nobody ever reads back, and
  the mistake is in the caller.

The name is shown *beside* the message rather than instead of it: the name is what you were
thinking and the message is what was actually said, and only one of those is a fact.

`Session\SessionCodec` has no upstream counterpart at all: a message there is a plain
object and `JSON.stringify` is the whole persistence layer. PHP objects do not survive
that, so the shapes are written out by hand — which is the one place that has to change
when a message type gains a field. A tool's `details` is `mixed`, so it is flattened to
arrays on the way out; the only things anything reads back out of it are `edit`'s diff and a
truncation `notice`, which are strings and integers and survive exactly.

A resumed conversation is **redrawn from its messages**, not from anything saved about the
screen — `InteractiveMode::replay()`. A transcript is a view of the conversation, and
keeping a second copy of it on disk is how the two end up disagreeing.

`Session\Compaction` is upstream's `core/compaction/` (1246 lines) as pure functions: how
full the window is, where the conversation can be cut, what the summariser is asked, and
which files were touched. Running the model and swapping the messages over is
`AgentSession::compact()`, so everything with arithmetic or string handling in it is
testable without a provider. The summary itself is `Session\CompactionSummary` — an app
message like `BashExecution`, turned into a user message by `CodingAgent::toLlm()`.

Three things about it are worth knowing before changing any of it:

- **The cut never lands on a tool result.** A result separated from the call that produced
  it is a request every provider rejects outright, so `cutPoint()` collects the legal
  positions first and only then looks for the one nearest the budget. `CompactionTest`
  checks this at every budget from 1 to 6000 rather than at one number, because the
  arithmetic that picks the cut is exactly the kind of thing that is right for the number
  you tested.
- **The log is still append-only.** Compaction does not rewrite the file: the summary is
  appended like any other message and carries `replaced`, the number of messages from the
  start it stands in for. `SessionManager::add()` splices on the way back in, so a resumed
  session comes back compacted. Without that count, replaying the log would hand back the
  whole conversation that had just been compacted away — and the messages themselves stay
  in the file, because a session log is a record of what was said.
- **The file lists are separate from the prose.** A summary that says "read some files"
  sends the model to read them again. `Compaction::files()` also carries an earlier
  summary's lists forward, or a file read before the last compaction disappears from the
  record entirely.
- **A usage reading older than the last compaction is discarded.** See the trap below.

`compact()` throws upstream's own messages — `Nothing to compact (session too small)` and
`Already compacted` — rather than returning quietly. The wording is upstream's on purpose,
because it is the wording someone will search for.

Everything `InteractiveMode` says goes through one of three methods, which is what keeps
the three kinds of line apart on a screen that is already full of colour: `sayError()`
(red, prefixed `Error:`), `sayWarning()` (yellow, `Warning:`) and `say()` (dim, no label)
— upstream's `showError()` / `showWarning()`. A message that is drawn by colour alone is
a message nobody can name, so no new one is drawn that way.

Not ported from upstream's compaction: branch summarisation (`branch-summarization.ts`,
which needs the tree) and the split-turn prefix summary — upstream generates a second,
smaller summary when the cut falls inside a turn, which needs turn boundaries that the
linear log here does not mark.

`Interactive\` is the terminal front end. `InteractiveMode` is the arrangement — which event
becomes which component, which key means what — and `bin/pig` is the entry point.

The screen is five containers between the banner and the footer: `$chat` (the transcript),
`$pending` (what is queued), `$status` (what is *happening*), `$overlay` (what is being *asked* —
a picker, `/settings`, a hook's dialog), then the editor. `$status` and `$overlay` are separate on
purpose and the trap below says why in full: the overlay holds whatever has the focus, and only
the thing that gave it the focus ever clears it.

**The loaders are not in `$status` any more — they are in the prompt's top border**, which is
where pi draws them (`CustomEditor.renderTopBorder()` with `embedWorkingStatus`): `── ⠋ Working... ─────`
(with dynamic elapsed time `· 16s` while running). Working, retry, both compactions and the branch summary all go through
`InteractiveMode::showLoader()`/`hideLoader()`, which hand the `Loader` to
`CustomEditor::setWorkingStatus()` → `Editor::setBorderStatus()`; the editor asks the closure on
every frame, so the spinner ticks without the editor knowing one is there, and it is painted in
the border's own colour so the rule reads as one rule. What it replaced was a line of its own with
a blank above it, which grew the frame by two rows at the start of every turn and shrank it at the
end. The retry countdown lost the error text on the way in — it is already a red line in the
transcript, and in an 80-column border it pushed `esc to stop` off the end — and says upstream's
`Retrying (1/3) in 30s... (esc to stop)`. `$status` stays for anything that is not a loader.

The
components it draws with are `UserMessageComponent`, `AssistantMessageComponent`,
`ToolExecutionComponent`, `FooterComponent`, `DiffView`, `BashOutputComponent`,
`CompactionComponent` and `CustomEditor`. They keep upstream's `…Component` names rather than `pig/tui`'s suffix-free
`Text` / `Box` / `Markdown`, because `UserMessage` and `AssistantMessage` are already taken by
`Pig\Ai`; the developer chose matching upstream over matching the sibling package.

`InteractiveMode` is ~1200 lines against upstream's 2439, and the difference is almost entirely
selectors: upstream has twenty-five of them — models, sessions, settings, hooks, OAuth, branch
trees — and each needs a subsystem that is not ported. What is here is the loop that makes it
an agent you can talk to, twenty-two slash commands plus whatever the hooks add, the keys, and
the dialogs a hook or a custom tool can open mid-turn (`Interactive\TerminalUi`).

**Audited against its 2439 lines by surface** — 68 upstream methods mapped name by name against
pig's 88, then every unmatched one chased down, which is the method `agent-session.ts` was read
with and the only one that works on a file this size. It found three things to fix, each its own
trap below (the editing keys named nowhere, `/debug` three-quarters ported, a message lost to an
auto-compaction), one command left out on its merits (`/share`, in the table above), and four
places where pig's arrangement differs on purpose. Those four, so nobody re-derives them:

- **A status line is appended, not replaced.** Upstream's `showStatus()` overwrites the previous
  one when it is still the last thing in the chat, so toggling ctrl+t twice leaves one line there
  and not two. pig has one `say()` where upstream has `showStatus` *and* `showError`/`showWarning`,
  and `say()` also prints `/help`, `/skills` and the hook list — replacing those would make
  `/help` vanish when the next thing is said. Splitting it in two is the fix if the duplicate
  lines ever matter; the payoff is cosmetic and the cost is deciding, per caller, which kind each
  `say()` is.
- **Compaction leaves the transcript where it is**, in both paths, with the reason on the code: it
  is what was said, and the summary is a note about it rather than a replacement for anyone's
  memory of reading it. Upstream clears the chat and rebuilds it from the compacted messages, in
  the manual path and the automatic one both, so its screen matches the model's view and its
  scrollback loses what was summarised. pig's two paths agree with each other, which is the part
  that matters.
- **A `!command`'s output goes straight into the transcript**, so there is nothing to flush.
  Upstream draws it in the pending area and moves it into the chat when the next message is
  submitted (`flushPendingBashComponents`), because its component and its *message* arrive at
  different moments. pig's message still joins the conversation on `AgentEndEvent` —
  `AgentSession::flushBash()` — and only the drawing is immediate.
- **A picker goes in the overlay, above the editor, not in place of it.** Upstream's
  `showSelector()` clears the editor's own container; the trap below on `$overlay` is why pig does
  not, and it is the arrangement that made pig's "focus on an invisible list" bug possible in the
  first place.

`/copy` needed a clipboard *writer*, which nothing had: `SystemClipboard` could only read.
`Process::feed()` is the piece under it — a command with text on its standard input, which is
how `pbcopy`, `wl-copy` and `xclip` take theirs. Passing the text as an argument would put a
whole answer in the process list for anyone to read, if the length limit allowed it at all.
Two things in `feed()` are the whole reason it is not three lines: the write is **looped**,
because a long answer is larger than a pipe buffer and one `fwrite` stops short; and stdin is
**closed before waiting**, because that is what tells the program its input ended — a copy that
hangs forever is the other way round.

The writer knows about Wayland, which upstream's does not. Its own *reader* handles `wl-paste`,
so a session that could paste but not copy would be a puzzle with nothing on screen to explain
it.

An image in a tool result is **drawn**, on terminals that can draw one. `pig/tui`'s `Image`
falls back to a label by itself, so `ToolExecutionComponent` does not choose *that* — it adds the
component and lets it decide, and the text half stopped naming the image so it is not named
twice. What it does choose is whether to add the component at all: `terminal.showImages` off
adds `TerminalImage::fallback()`'s label instead, which is the same string from the same
function, so a picture turned off and a picture that cannot be drawn read the same. The images
are rebuilt on every `draw()` rather than appended to, because a running tool reports its result
again on each update — and `setShowImages()` rebuilds them for the same reason `setExpanded()`
does, since the setting is changed while a transcript is already on screen.

**The footer's two markers were both missing, and both for the same reason.** Upstream writes
` (auto)` after the context percentage while auto-compaction is on, and ` (sub)` after the cost
while the model is reached with a signed-in account rather than a key — and it shows the cost at
all in that case, even at `$0.000`. Neither was here. `(auto)` had a docblock saying it was "not
ported: nothing to report yet", which was true when it was written and stopped being true the day
auto-compaction landed; a comment asserting a thing about the rest of the repository is a comment
that goes stale silently, which is the `PI_AGENT_DIR` trap in a different file. `(sub)` was never
mentioned. What they cost: a percentage that means two different things — a pause and a summary,
or a request that gets refused — with nothing saying which, and a subscriber reading a running
bill that is not a bill.

Upstream pushes the first one in, with a `setAutoCompactEnabled()` that whoever changes the
setting has to remember to call. Here both are **read on every frame** — `Settings` and `Auth` are
constructor arguments and the render asks them — because the footer redraws constantly anyway, the
two facts are already stored where they can be looked up, and a setter is one more thing to wire
at one end only. `InteractiveMode` passes `$this->settings` and not its own argument: with no
settings given, that is the in-memory object `/settings` writes to, and the footer has to read
what that screen changes.

**Two preview sizes for command output**, both upstream's, in one component where upstream has
two: `BASH_LINES = 5` for a command the model ran, `TYPED_BASH_LINES = 20` for one typed with
`!`. Upstream's numbers live in `tool-execution.ts` and `bash-execution.ts` respectively, and the
difference has a reason worth keeping — a `!` command is the thing the person just asked for and
is looking at, where a model's is one step inside something else. pig reuses one component for
both, so the number is a constructor argument rather than a second class — and the trap entry on
what that sharing cost is below, because a component with two callers needs somewhere to put what
only one of them knows.

**A truncation notice has two readers, so it is said twice.** Every tool that cuts its own output
ends the text with a line saying so — `[Showing lines 1-2000 of 8431. Use offset=2001 to
continue]` and its siblings — and *the last line of the output is exactly the line a collapsed
tool view cuts*, because the preview keeps the first ten, twenty or fifteen. So for a while
`read`, `ls`, `find` and `grep` told the model what was left out and told the person nothing;
`bash` was fine by accident, since its preview keeps the tail, which is where its notice already
is. Upstream has the same two copies for the same reason: one in the text, one in the component,
where collapsing cannot reach it.

**The tool builds the sentence; the result carries it.** The notice goes in `details` under
`notice`, as the same string the text ends with, without its brackets. The alternative, upstream's,
is for the component to build a second sentence out of structured fields — a second wording of one
fact, which then has to be kept in step with the first. Guessing from the text was the other
candidate and it guesses wrong: a `read` of a file whose last line is `[section]` would be handed a
notice it does not have.

### A tool result's `details` is pi's shape, under pi's names

`details` is written into the session file, and the session file is pi's — so a name pig invented
here is a field pi cannot read. It cost nothing pig could see and everything pi could: a
conversation pig had written opened in pi with its truncation records unreadable, so pi drew no
truncation warning on any of them. The keys are now upstream's exactly, `notice` aside:

| Tool | `details` |
|---|---|
| `read` | `truncation` (only when something was cut) |
| `bash` | `truncation`, `fullOutputPath` |
| `ls` | `entryLimitReached`, `truncation` |
| `find` | `resultLimitReached`, `truncation` |
| `grep` | `matchLimitReached`, `truncation`, `linesTruncated` |

A key whose value would be null is left out rather than written as null, which is upstream's shape
too — its details start empty and it only assigns the ones that apply, and `read` has no `details`
at all when the file fitted.

**`notice` is the one key upstream does not have**, and it is set in one case upstream leaves
empty: a `limit` that stopped short of the end of the file, where nothing was *truncated* — the
model asked for five lines and got five — but there is more file and both readers should know.

Renames, kept because they are what a `grep` for the old name should find:

| pig had | pi's name, now pig's |
|---|---|
| `Truncation::$by` | `$truncatedBy` |
| `Truncation::$lastPartial` | `$lastLinePartial` |
| `Truncation::$firstTooBig` | `$firstLineExceedsLimit` |
| `details['fullOutput']` (bash) | `details['fullOutputPath']` |

`Truncation` also gained `maxLines` and `maxBytes`, which upstream records and pig had left to its
constants. They are per call and not constant: `ls`, `find` and `grep` pass `PHP_INT_MAX` for the
line limit, because the entry, result and match limits above them already cap how many there are.

One deviation from upstream, and it follows from showing the tool's own sentence rather than a
second one: the component prints the notice **only while the preview is cut**. Upstream prints its
warning either way, which it can afford because its two sentences are worded differently. Two
identical lines under each other read as a rendering fault, and an expanded view already has the
tool's own copy on screen.

`bash` carries `notice` too, though nothing reads it there. A shape that holds for four tools and
not the fifth is the next person's puzzle, and the reason it is unread — that one preview keeps the
tail — is a fact about that tool, not about what a result is allowed to say.

`Components\Rule` is upstream's `DynamicBorder`, renamed: "dynamic" there means "asks the width
at render time", which is what every component in `pig/tui` does — there is no static kind for it
to be the opposite of, and what it is is a rule. The colour is a closure taken at construction,
which upstream's own note argues for in the hardest way available: its `theme` is a module-level
global, and a hook loaded through jiti gets a second module cache where that global is
`undefined`, so a border drawn from inside a hook crashed on a theme present everywhere else. pig
has no module caches, but the shape is right for a second reason — `/theme` replaces the palette,
and a component holding a closure it was given cannot hold a stale colour table.

`Interactive\BorderedLoader` is upstream's, and it is for hooks: a hook that goes away for a while
hands one to `HookUi::custom()`, and the rules are what separate it from the transcript above and
the prompt below. `TerminalUi`'s own dialogs draw no rules, because a question reads as one thing;
this is a **wait**, and a wait with nothing around it reads as output that stopped. It is a
`Container` **and** an `InputHandler`, which is the trap on `SettingsSubmenu` again — and here the
key a container would eat is the only one that cancels.

Its `dispose()` is not a nicety: the spinner reschedules itself, so a loader nobody disposed of is
a loop that never runs out of work and a `bin/pig` that never exits.
`testARunningBorderedLoaderNeverLetsTheLoopSettleAndADisposedOneDoes` is the test, and its name is
long because the first version of it was wrong: it asserted `isIdle()` immediately after
`dispose()`, which is false for a moment because **a pending render counts as work**. That looked
like a bug in `dispose()` and was a bug in the test. The question worth asking is not whether the
loop is idle now but whether its work *ends*.

`Interactive\ArminComponent` is upstream's easter egg, reached by typing `/arminsayshi` — known
and not listed, like `quit`, because `COMMANDS` is also what `/help` prints and the autocomplete
offers, and something you have to already know about is the whole idea. Upstream leaves it out of
its own command list for the same reason.

It earns a place in a port for something other than the joke: it is the only thing in either tree
that draws a **bitmap with half-block characters**, two pixel rows to a cell — `█` for both, `▀`
and `▄` for one. The 144 bytes are upstream's, LSB first, and **a zero bit is foreground**, which
is an XBM convention and the one detail that turns the picture into a photographic negative if it
is guessed at.

**One of the seven effects never ends, in pi and in the first version here.** `rain` finishes a
column when `settled >= height`, and `settled` is `height - target` — so it only reaches `height`
for a column with ink in row 0. Four of these 31 columns have that and two have no ink at all, so
27 can never satisfy it: `allSettled` is never true, the effect never reports done, and because pi
creates this component and **never calls `dispose()`**, a one-in-seven roll leaves a 30fps redraw
running for the rest of the session.

It was found by driving each effect to its end in a test and watching one of them pass 100,000
frames, then by printing the topmost ink row of all 31 columns rather than reasoning about it. The
fix is the condition `settled >= height` was reaching for: a column is done when there is nothing
left above what has landed. Nothing about the finished picture changes — every effect was already
verified to end on the byte-identical grid.

Re-measured during the `armin.ts` audit, forty runs of each, because a number in this file is worth
a re-run rather than a quotation — and the sentence that used to be here said 8 to 186, which is one
out at both ends: **glitch 9, crt 19, scanline 19, dissolve 28, fade 38, rain 137–174, typewriter
187.** Only rain varies, which is the randomness in where each drop starts. All seven end on the
same grid and it is byte-identical to what upstream's `buildFinalGrid` produces from the same 144
bytes, which were compared byte for byte as well.

Three smaller notes:

- **`shuffle()`, not upstream's hand-written Fisher-Yates**, which is what `shuffle()` already is.
  The same rule as `version_compare()`: where upstream is working around a missing library call,
  the port is the call.
- **Three of the seven effects are one function.** typewriter, fade and dissolve are "reveal N
  cells per frame in a given order"; upstream has three copies because the order lives in three
  differently-named state bags. Written once here as `reveal()`.
- **The effect can be named in the constructor.** A seam that upstream has no equivalent of,
  because seven effects cannot be tested by starting this seven times and hoping — and it is what
  found the one that never ended.

`CustomEditor` wraps `Pig\Tui\Components\Editor` rather than extending it — the editor is
`final`, and wrapping keeps the list of keys an application may steal explicit. `Editor` grew
one method for this: `setTheme()`, so the border can change colour with the thinking level.

The terminal is injected, so `InteractiveModeTest` drives the whole front end by typing into a
`FakeTerminal`: `start()` draws the first frame, keystrokes go in by hand, and `run()` — which
blocks on the loop — is the only thing a test never calls.

`Theme\Colour` carries the one piece of real arithmetic in there: fitting a hex colour onto
a 256-colour terminal. The guard worth knowing about is that the grey ramp only wins when
the colour was nearly neutral to begin with — without it every muted tone in the theme
snaps to a pure grey and the theme loses its tint.

`Ai\Models` is the registry, and what is in it is the models whose *protocol* is ported — offering
a model and then failing to send the request is a worse answer than "no such model", so the rest
arrive with their protocols. OpenRouter's speak a ported protocol and are still left out: that list
is a directory of everyone else's models and goes stale fastest.

**The rows are generated now, and the paragraph this replaces is why.** It read *"the figures are
upstream's at the anchor commit, not whatever models.dev says today: a port should agree with the
thing it was ported from"*, and quoted a count per provider. That is fidelity to the bytes and not
to the mechanism: upstream has never kept this table by hand — the file pig transcribed opens with
*"Do not edit manually - run 'npm run generate-models' to update"*, and by upstream HEAD the data is
not in git at all, each provider's catalogue being fetched from models.dev at build time. pig froze
a generator's output and then treated the freeze as a decision, and the counts in this file were the
freeze quoted back. See [The registry was a transcription of a generated
file](#the-registry-was-a-transcription-of-a-generated-file).

So `scripts/generate-models.php` writes the rows and the counts are no longer quoted here: they are
whatever the last regeneration found, and the run prints them. The anchor still decides every line
of protocol; it never had anything useful to say about which models a provider sells this week.

The table is keyed `provider/id`, and `get()` used to take an id alone because no two
providers claimed the same one. **Copilot's table is the one that broke that**, exactly where
this said it would: it serves OpenAI's, Anthropic's and Google's models under their own ids, so
`gpt-5` now names two things.

The rule is `Models::RESOLD`, and it is one line of data rather than an ordering: **a bare id
means the direct provider**, and Copilot's is `github-copilot/gpt-5`. Writing it down mattered
more than it looks — building Copilot's table last already gave the right answer, and would
have stopped doing so the first time somebody moved a `foreach`. `Models::isResold()` is public
so `ModelResolver` asks the same question rather than keeping its own copy, in both the
exact-id pass and the sort over substring matches.

Two things about it that are easy to get backwards:

- **A resold id is still an answer**, just never the first one. `oswe-vscode-prime` is VS Code's
  own preview model and no direct provider carries it, so `get()` falls back to the reseller
  rather than answering null.
- **`grok-code-fast-1` looked Copilot-only and is not** — xAI's table carries it too, so a bare
  id means xAI's. That was a wrong guess in a test, not in the code, and the test now asserts
  both halves.

`ModelsTest::testEveryDuplicatedIdIsOneOfTheResoldOnes` replaced the uniqueness check: two
providers may share an id only when one of them is reselling, and any other collision is a typo
in a table that would make `get()` answer with whichever was written first.

It replaced a `CodingAgent::model()` that invented the figures around an id — 200k of context,
64k of output, reasoning on. Right for one model and wrong for most: `claude-3-haiku` caps
output at 4096 and cannot reason, so `--model claude-3-haiku-20240307` built a request the
provider rejects, from a flag that looked like it had worked.

`Providers\OpenAiCompletions` is upstream's `openai-completions.ts`, and it is worth more than
the one name on it: Groq, Cerebras, xAI, Zai, Mistral, OpenRouter and GitHub Copilot all answer
this shape. Structurally it differs from `Anthropic` in one way that matters — **Anthropic
numbers its content blocks and says when each opens and closes; this does not.** A block runs
until something of a different kind arrives, so the boundaries are worked out in the provider,
and that is the only real complexity in the file. `AssistantMessageBuilder` grew `nextWire()`
and `setToolCall()` for it: OpenAI numbers nothing, and a tool call's id and name arrive in
whichever delta they arrive in.

`Ai\OpenAiCompat` is the table of ways an "OpenAI-compatible" endpoint is not one. Mistral
wants tool ids of exactly nine alphanumeric characters, Grok rejects `reasoning_effort`,
Cerebras rejects `store`, Copilot re-answers every earlier prompt if assistant text arrives as
an array. None of that is documented anywhere as a difference; it is what a 400 looks like
after you have sent it. A table per endpoint rather than one rule, for the same reason the tool
archive names are a table — see that note above.

`Providers\TransformMessages` (upstream's `transorm-messages.ts`, misspelling and all) is what
makes `/model` safe across providers. Two things get cleaned up before any provider sees the
history: a **thinking block from another provider becomes `<thinking>` text**, because it is
signed and the signature means nothing anywhere else; and a **tool call with no result gets one
invented** saying so, because an interrupted turn leaves a dangling call and every provider
rejects the whole conversation rather than ignoring it. A stated "No result provided" is worse
than the truth and far better than a request that cannot be sent at all.

`Providers\OpenAiResponses` is upstream's `openai-responses.ts` — what gpt-5 and codex speak,
and the third shape in three providers. A response is a list of *items* and the stream says
when each opens and closes, which after `openai-completions` is a relief. Two things in it have
no counterpart anywhere else:

- **A reasoning item goes back whole.** What arrives as text is a *summary*; the reasoning
  itself is encrypted and opaque, and the model wants its own item back verbatim or it reasons
  from nothing again. So the entire item is kept as the thinking block's signature and replayed
  as it came — which is what `TextContent::$textSignature` and the builder's `setSignature()`
  are for. Asking for it at all needs `include: ["reasoning.encrypted_content"]`.
- **A tool call has two ids.** `call_id` addresses the result, `id` is the item's own, and both
  have to go back, so they travel joined as `call_id|id` and are split on the way out. A call
  from another provider has one id, which is then used for both.

`# Juice: 0 !important` is not a joke: gpt-5 has no documented way to turn reasoning off, and
that developer message is what upstream found works.

`Providers\Google` is upstream's `google.ts` — Gemini, and the shape furthest from the other
three. A chunk carries a list of *parts*, and a part is text, or thinking (text with
`thought: true` on it), or a whole function call. Which means:

- **A tool call arrives complete**, arguments and all, in one part — so it is opened,
  delivered and closed in the same breath. There is nothing to stream.
- **Thinking and text are the same field**, told apart by a flag, so a block ends where the
  flag changes: the boundary problem from `openai-completions`, one field over.
- **Gemini often sends no id for a call**, and a result has to be addressed to something, so
  one is invented — and a repeat within a message is replaced for the same reason.
- **Saying nothing about thinking means dynamic thinking, not none.** A turn that did not ask
  for it has to ask for `thinkingBudget: 0`, or the model thinks anyway and bills for it.
- **Thinking is said two ways.** Gemini 3 takes a named level and ignores a budget; 2.5 takes a
  budget in tokens, with a different ceiling for pro and flash. `Stream::gemini()` picks; the
  provider sends whichever arrived.
- **Eighteen of its twenty finish reasons mean "no"** — safety, recitation, a malformed call, a
  language it will not answer in. Only `STOP` and `MAX_TOKENS` are not errors.

Upstream hands this to `@google/genai`; the REST endpoint is called directly here —
`:streamGenerateContent?alt=sse`, which is what that package does underneath. Without `alt=sse`
the response is one enormous JSON array rather than a stream.

`Providers\GoogleShared` is upstream's `google-shared.ts`, and it exists now for the reason it
exists there: **two providers speak this shape.** Google Cloud Code Assist wraps the same request
in a project envelope and answers with the same chunk one key deeper, so what a Gemini request
and a Gemini chunk look like is not `Google`'s private business any more. All of it used to sit
in `Google` as private methods — 609 lines — because there was one caller; the extraction moved
about 400 of them out and `Google` is 207. The developer's call, with the alternative being a
second copy of the conversion and CLAUDE.md's own rule against two implementations that can
disagree about what a request looks like.

Three things about the split:

- **The caller unwraps.** Code Assist's chunks arrive under a `response` key, and
  `GoogleShared::onChunk()` is handed the candidate rather than being told which provider it is
  speaking for.
- **pig shares more than upstream does.** Upstream keeps the chunk walk in each provider and
  shares only the request conversion; the chunk is the same shape one key deeper, and two walks
  over it are two things that can come to disagree about what a Gemini answer is.
- **Each provider keeps its own business**: where it sends, how it authenticates, how it words a
  failure, and the body it assembles around the shared pieces. `explain()` stayed behind in both
  for that last reason — the message names the provider.

It was an extraction and nothing else, which is what `GoogleTest`'s thirty-one cases passing
unchanged is the evidence for. A move that needed a test changed would have been a rewrite.

`ModelResolver` is upstream's `model-resolver.ts`: `sonnet` finds the model, `sonnet:high`
finds it and sets the thinking level. When several match, the alias beats the dated build
behind it — someone typing `sonnet` wants the current one, not the June 2024 build that sorts
first. The pattern is tried whole before it is split on a colon, because an id can contain one
(OpenRouter's `:exacto`). `scope()` is upstream's `resolveModelScope()` — what `--models
sonnet:high,'anthropic/*'` narrows a session to, `fnmatch()` in place of `minimatch` — and the
trap entry on the two flags swapping names says what porting it decided.

`Export\HtmlExport` and `Export\MarkdownHtml` are upstream's `core/export-html/`, with the
rendering moved from the browser into PHP. Upstream's export is 211 lines of logic, 65KB of
templates, and **160KB of vendored `marked.min.js` and `highlight.min.js`** — and `marked` is
the dependency `Pig\Tui\Markdown` was written to replace, so shipping it in the export would
be the same dependency through a side door. The lexer and the highlighter are already here;
`MarkdownHtml` is the second thing that walks their output, and the file it writes has no
script in it at all. The same conversation comes out at 5KB rather than 230KB, and it reads
with JavaScript off, prints, and greps.

`Highlight` is reused through the seam that already existed for theming: a `HighlightTheme`
whose closures write `<span class="hl-keyword">` instead of an escape sequence. The one trap
in that is below.

**The system prompt and the tool list are not in it**, where upstream's payload carries both for
its viewer to show. An export is what gets pasted into a ticket or sent to a colleague, and what
belongs there is the conversation: the prompt is pig's own text plus whatever `AGENTS.md` and the
skill list put in it, and the tools are the same seven every time. Two more sections to leave out of
every export by hand is worse than not writing them.

`<details>` does the folding, which is the only interaction upstream's JavaScript provided
that was worth keeping. Open or closed is decided on the *text* length, not the number of
newlines — the renderer turns a paragraph of prose into one long line, so counting newlines
folds every code block and never folds any thinking, which is exactly backwards.

`Settings` is upstream's `core/settings-manager.ts`. Two JSON files, both optional:
`~/.pig/agent/settings.json` is the person's and is written back to; `<cwd>/.pig/settings.json` is
the project's and is only ever read. The project wins, which is the point of it being
separate — a repository can say "compaction keeps more here" without touching anyone's
preferences, and a `/theme` typed in that repository still saves to the person's file and
still loses to the project's answer while they are in it.

Upstream is 374 lines, most of it forty getter/setter pairs. The pairs here are only for the
settings something actually reads — theme, model, thinking level, hidden thinking, pictures, the
three compaction numbers, the skill filters — and everything else is reachable through `get()` under
upstream's own key names, so a settings file written by either project is read by both. A key
gains a typed accessor when something needs one, not before.

Three decisions in it:

- **Merged one level deep, not recursively.** Every nested thing in this format is a flat
  group of scalars; a deeper merge would be answering a question the format never asks.
- **A list replaces rather than appends.** A list here is an answer, not a contribution:
  merging two would leave a project no way to *stop* ignoring a skill the person ignores.
- **A file that is not JSON is named, not ignored.** A typo in a settings file is otherwise a
  setting that quietly does nothing for the rest of its life. `bin/pig` prints the complaints
  before the UI starts, the same as it does for a malformed skill.

Order for anything with more than one source: what was typed, then the environment, then what
was chosen last time, then the built-in default. `--no-save` gets `Settings::inMemory()`, so a
session that is not written down does not write anything else down either.

### `/settings`, and why its list is shorter than upstream's

`Components\SettingsList` is upstream's `tui/components/settings-list.ts` and `/settings` —
`InteractiveMode::showSettings()` — is upstream's `settings-selector.ts`. It was the last
unported file in `pig/tui`, and it waited this long for a good reason: a component whose only
consumer is unported is dead code, so the two only make sense together.

It is not a `SelectList` with two columns, and the difference is worth naming. A select list is
*answered* — one question, Enter means "this one", and it closes. A settings list is **lived
in**: several things are changed in one visit, so Enter changes the row under the cursor and
leaves the list open, Escape means "done" rather than "cancelled", and the screen has to keep
saying what each row is set to now. Space does what Enter does, as upstream allows, because a
two-value row reads like a checkbox.

Two decisions of pig's own:

- **`SettingItem` is readonly and the values live in the list.** Upstream writes the new value
  back into the item it was given. Here `SettingsList` holds an `id => value` map, so a
  declaration built in one place stays a declaration, and `setValue()` exists for the setting
  that moves on its own — the thinking level changes when a model that cannot reason is chosen,
  and the screen should agree with the truth rather than with what was last pressed on it.
- **The label column is measured in columns, not characters.** Upstream's `.length` puts 主题 at
  2 where the terminal gives it 4, which pushes every value column after it out of line. Same
  deviation, same reason, as `SelectList`'s description column.

**The list only offers things that take effect**, which is what decided its seven rows:

| Row | Reaches |
|---|---|
| Theme | `useTheme()`, split out of `switchTheme()` so a row can name a theme rather than toggle |
| Thinking | a submenu of the levels *this model* offers, so a model that cannot reason gets no row at all rather than six ways to change nothing |
| Thinking blocks | `useHideThinking()`, split out of the ctrl+t handler |
| Pictures | `useShowImages()`, and its reader — see below |
| Queued messages | `AgentSession::setQueueMode()`, which tells the agent and writes the setting |
| Auto-compact | `compaction.enabled`, read again on every turn |
| Auto-retry | `retry.enabled`, read again on every failure |

**Row by row against upstream's seven, two differ.** Upstream's list is `autocompact`,
`queue-mode`, `hide-thinking`, `collapse-changelog`, `thinking`, `theme`, `show-images` — so pig
has `autoRetry`, which upstream has no setting for at all, and lacks `collapse-changelog`.
`collapse-changelog` governs one thing: whether the note shown once after an upgrade is the
release notes or the line "Updated to vX. Use /changelog to view full changelog.". It stays out,
and the reason is the rule at the top of this file rather than an oversight — a row whose whole
job is to make one banner shorter is a second way to render a thing pig renders one way, and
`sayChangelog()` exists precisely because `/changelog` and the upgrade note are "the same thing
arriving for two reasons". Somebody who does not want to read release notes has the shorter answer
already: they are shown once, under the conversation, and never again. If pig ever ships a
`CHANGELOG.md` long enough that the note is genuinely in the way, the row is three lines and this
paragraph is where to start.

**Queue mode is a row now, and getting there meant the anchor commit's own break.** Upstream has
one `setQueueMode()`; pig has two queues, because `d0a4c37` is the commit that split the single
queue into `steer()` and `followUp()` and it changed `packages/agent` only — `agent-session.ts` at
that same commit still calls a method that no longer exists there. So there is no working original
to copy, and `Agent::setQueueMode()` picks the queue the *setting* is about: a message typed while
the agent is working is a **follow-up**, which is where the submit handler puts it, and steering is
a different act with a different key. `setSteeringMode()` arrives if something ever wants it apart.

Three smaller decisions fell out of it:

- **The modes are copied out of `AgentOptions` into fields on `Agent`.** `AgentOptions` is readonly
  and stays that way: it records what an agent was *set up* with, and a settings screen writing a
  field on it would turn that record into a record of the present.
- **`AgentSession`'s constructor applies the stored mode**, not whoever built the agent. It is the
  class holding both the agent and the settings, and `setQueueMode()` writes through the same
  pair — a caller passing it into `AgentOptions` instead would be a second route for one fact.
- **Upstream's `queue-mode-selector.ts` has no consumer.** Checked before deciding not to port the
  component: the only `QueueModeSelectorComponent` in upstream's tree is its own definition, and
  the live path is a row in its settings screen. So the row is the port and the standalone
  component is dead code there.

Writing the test for it found a gap in the test harness rather than in the code:
`InteractiveModeTest` was passing **null settings to `AgentSession`** while giving the same
settings to `InteractiveMode`, which `bin/pig` does not do. Three settings the session reads — the
queue mode, auto-compaction and auto-retry — were live in production and inert in every test.

**`terminal.showImages` was a setting pig stored and nothing read.** Building the screen is what
surfaced that, and the choice was between leaving the row off and giving the setting its reader.
Upstream has a real one — `tool-execution.ts` takes `showImages`, checks
`caps.images && this.showImages` before drawing, and has a `setShowImages()` for the transcript
already on screen — so pig now has the same: `ToolExecutionComponent` takes the flag and
`setShowImages()` redraws. Off draws the label `TerminalImage::fallback()` already produces for a
terminal that cannot draw pictures, from the same function, so "turned off" and "cannot" look
alike and neither pretends no picture came back. Queue mode is on the list now and has its own note above.

`useTheme()`, `useHideThinking()` and `useShowImages()` being split out is the part that matters
most and is the least visible: ctrl+t writes the setting *and* tells every
`AssistantMessageComponent` already on screen. A second place writing only the setting is a
toggle that half works — the file says hidden and the transcript still shows thinking — so both
paths go through the one method. The theme is the exception and says so: the list holds the old
palette's closures, so new colours arrive the next time it is opened, which is the same thing
`/theme` already says about the transcript.

`Changelog` is upstream's `utils/changelog.ts`, with two readers: `/changelog` shows the whole
file and startup shows only what is newer than the version this person last saw. The second is the
one that has to be right — a tool that greets you with its whole history every morning is a tool
whose greeting you stop reading.

Four things in it, three of which are upstream's and one of which is arithmetic:

- **`version_compare()` does the comparing.** Upstream splits the number into three and
  subtracts, because JavaScript has nothing else; the first version here copied that and was
  worse than the platform call in two ways that only showed up against real input — `0.2` read
  as `0.2.0` and `0.2.0-beta` read as `0.2.0`, so both compared *equal* to a release they are
  not, and somebody on a pre-release saw nothing after upgrading to the release it preceded.
  `version_compare` gets those right and `0.10.0 > 0.9.0` too, which is the one the hand-rolled
  version existed for. **Copying upstream's arithmetic is not the same as porting it** — where
  JavaScript is working around a missing library call, the port is the call.
- **A `##` with no version in it ends the entry and starts nothing**, discarding what it had
  collected. Right for a file people edit by hand: folding an `## Unreleased` section into the
  release above would file unreleased notes under a released number.
- **A string that is no kind of version means everything is new**, because it sorts below every
  release. Same end as upstream's `Number()` of a missing part: showing the whole file once beats
  silently showing nothing because a settings file had a typo in it.
- **The version is written down when the notes are shown**, not when pig starts, so a run that
  showed nothing does not mark them as seen. `-c` and `-r` skip it: somebody picking a
  conversation back up is in the middle of something.

**There is a `CHANGELOG.md` now**, and the machinery was ported before it existed on the grounds
that "it is the same answer somebody who deleted theirs gets" — which was right, and cost two tests
that were pinning the absence of the file rather than the behaviour. See
[A changelog and a version, and two tests that were pinning the absence of the file](#a-changelog-and-a-version-and-two-tests-that-were-pinning-the-absence-of-the-file).
The empty answer is still a real one and still has a case, against a path that is not there.

`Session\BranchSummarization` is upstream's `core/compaction/branch-summarization.ts`, and
### Where compaction cuts, and the half-turn upstream drops

`Compaction::cutPoint()` returns one index: everything before it is summarised, everything from it is
kept. Upstream's `findCutPoint()` returns three things — the index, whether the cut lands mid-turn,
and where that turn started — and `prepareCompaction()` then ends the summarised history at the
**turn's start** rather than at the cut, while the kept range still begins at the cut.

Reading their code, that leaves the messages between the two in neither: not summarised, because the
history stopped short of them, and not replayed, because `buildSessionContext()` emits the summary and
then everything from `firstKeptEntryId`. `turnPrefixMessages` collects exactly those messages, and
**nothing in upstream's own compaction reads it** — it is put on the preparation object for a
`session_before_compact` hook, and its own comment says they are messages that "will be turned into
turn prefix summary", which is a sentence about something not done yet.

pig summarises up to the cut, so the split turn's first half is inside the summary and nothing falls
between. That is a divergence in pig's favour and it is stated as a reading rather than a measurement:
it was found by comparing the two, not by running upstream.

The rest of the file matched, and was checked rather than assumed: `contextTokens()` prefers the
provider's own total; `shouldCompact()` is the same comparison plus a `contextWindow > 0` guard, which
upstream lacks and which stops a model declaring no window from asking to compact for ever; the
enabled flag lives in `AgentSession::shouldCompact()` rather than inside the arithmetic;
`estimateTokens()` divides by four; the cut never lands on a tool result; `files()` carries an earlier
summary's lists forward and takes read minus modified, sorted; `request()` wraps the conversation in
`<conversation>` tags with `<previous-summary>` when there is one, and appends `Additional focus:` for
custom instructions; the summariser gets `0.8 * reserveTokens` and high reasoning.

One cosmetic difference, written down so nobody later builds on the assumption that it is not there:
**the two summarisation prompts are re-wrapped.** The words are upstream's exactly, but pig's heredoc
breaks the long lines, so where upstream has a space mid-sentence pig has a newline.

`Session\BranchSummary` is the message it produces. `/tree` goes back to an earlier point and
carries on from there; the branch that was left is still in the file, but the model no longer
sees it — and the model is usually what did the work on it. So `/tree` asks whether to write it
down, and the answer is appended to the branch being *joined*, which is the whole point: it is
context for carrying on here, not a note on the branch it describes.

Almost all of it is compaction's machinery reached for rather than repeated —
`Compaction::estimateTokens()`, `serialize()`, `files()` and `SYSTEM_PROMPT` are the same
questions about a different span. What differs is the prompt (a handover: goal, progress,
blocked, next steps — not a recap) and five things worth keeping straight:

- **It replaces nothing.** A `CompactionSummary` stands in for the messages before it and
  carries a `replaced` count that `SessionManager::resolve()` splices on. A branch summary
  describes messages that were never on this branch, so it has no count and nothing is spliced.
- **File lists are collected from the whole branch, prose from as much as fits.** Two passes,
  and the first is the point: the budget may drop the older half of a long branch, but "which
  files did this touch" has to be complete or whoever reads it goes to a file on disk that no
  longer matches. The walk for prose is newest-first for the same reason compaction keeps the
  recent end.
- **Cancelling means not moving.** Escape during the summary returns `TreeJump(moved: false,
  aborted: true)` and the leaf stays where it was, so the tree list comes back up. Jumping
  anyway would give someone the move they asked for and silently drop the summary they also
  asked for.
- **A hook may write it instead**, through `SessionBeforeTreeResult(summary: …)` — and then
  the file lists are still pig's own reading of the branch. The prose is the hook's; which
  files were touched is not its to get wrong.
- **Its lists carry forward into a later compaction**, exactly as a compaction summary's do,
  *unless* a hook wrote it. Missing that would lose the other branch's files the first time
  the context filled up.

`Prompt\SlashCommands` is upstream's `core/slash-commands.ts`: a markdown file in
`~/.pig/agent/commands/` or `.pig/commands/` becomes `/name`, and its body is the prompt.
`/review src/Foo.php` sends `review.md` with `$1` filled in.

Not the same thing as a skill, though both are markdown with frontmatter and the two are
easy to confuse. A skill is **offered** to the model, which reads it when a task matches; a
command is **sent** by the person, now, as the message. One is a capability, the other is a
macro. They are loaded separately for that reason.

Two things worth keeping straight: a built-in command is tried **first**, so a `help.md`
someone forgot they wrote cannot shadow `/help`; and `$@` is substituted **before** the
numbered placeholders, or an argument that happens to contain `$1` would have an argument
substituted into it.

**Audited against its 216 upstream lines difference by difference, and it found no bug** — which
is worth the words only with the list attached, since four of the nine differences had no reason
written beside them and now do. Five already did: the byte walk in `arguments()` (the same answer
as upstream's code-unit walk, because the four characters it looks for are all below 0x80),
`str_replace` for `$@` rather than `String.replace` (whose replacement treats `$&` and `$1` as
special, so an argument spelled `$&` would insert the match), dotted names skipped (upstream
recurses into a `.git` in a commands folder), `expand()` answering **null** rather than the text it
was given, and `is_readable()` where upstream wraps the read in a `try`.

The four that did not, all now on the code:

- **The frontmatter key pattern is `Skills`', not this file's upstream** — `skills.ts` matches
  `\w[\w-]*` and `slash-commands.ts` only `\w+`, so **upstream has two answers to one question**,
  and a hyphen in a key is ordinary (`allowed-tools`). Nothing reads such a key yet, so the two
  behave alike today; taking the wider one in both places is what keeps that from being luck.
- **CRLF is normalised once at the top**, where upstream survives it by accident and then leans on
  `.trim()` per value.
- **`scandir()` sorts and `readdirSync` does not**, and the first of two same-named commands wins —
  so without it, which file answers `/review` could differ between two machines.
- **`firstLine()` trimmed in one of its two branches.** Invisible, because the body is trimmed one
  method away, so the first line with anything on it cannot start with a space. Trimmed once now:
  *a rule that holds because of a `trim()` somewhere else is a rule that breaks when that
  somewhere else changes* — the same thing `SessionManager::opening()`'s test exists to say.

`Prompt\Skills` is upstream's `core/skills.ts`. A skill is a folder with a `SKILL.md` whose
frontmatter says what it is for; only the name and description reach the prompt, and the
instructions are a file the model reads when a task matches. That is what makes many skills
affordable — a hundred of them cost a hundred lines, not a hundred documents.

Seven roots, in this order: `~/.codex/skills`, `~/.claude/skills`, `.claude/skills`,
`~/.pi/agent/skills`, `.pi/skills`, `~/.pig/agent/skills`, `.pig/skills`, plus whatever `--skills-dir`
adds. Someone who wrote a skill once should not have to write it again per agent. The `~/.claude`
roots are scanned **one level deep** and the others recursively, because a folder per skill is the
layout there and descending further finds a skill's own examples rather than more skills.

Five of the seven are upstream's. **pi's two are pig's own addition**, and upstream has no reason to
have them — it *is* pi, so `~/.pi/agent/skills` is the root pig renamed to `~/.pig/agent/skills`. Reading
pi's as well follows from what pig already does everywhere else: it opens pi's sessions, its
`auth.json` and its `models.json`. Keeping somebody's conversations and credentials across the move
and silently dropping their skills is the half-migration that is worse than none. Note the depth:
`getAgentDir()` upstream is `join(homedir(), ".pi", "agent")`, so a root pointed at `~/.pi/skills`
would find nothing — there is a test whose only job is to say so.

**Each of the five another tool owns can be turned off on its own**, under upstream's own key names:
`skills.enableCodexUser`, `enableClaudeUser`, `enableClaudeProject`, `enablePiUser`,
`enablePiProject`. The developer's call, and the case for it is the one `ignoredSkills` cannot serve —
a large `~/.claude/skills` that does not belong in front of the model, without naming every skill in
it. Three things about the shape:

- **`Skills::load()` takes mechanism, `CodingAgent::session()` holds policy.** The loader's `$roots`
  switches off *any* root by its source name and knows no settings keys at all, the same way it takes
  `$ignored` as patterns rather than reading `ignoredSkills` itself. The join between upstream's five
  key names and the five roots they govern is written once, where the settings are read.
- **A root that is off is not scanned**, rather than scanned and filtered: it should cost nothing to
  have, and a malformed skill in a folder nobody asked to read is not worth a warning.
- **pig's own two roots have no key, and that is a naming problem rather than a missing feature.**
  Upstream's own root *is* `~/.pi/agent/skills`, so its `enablePiUser` and pig's name the same
  directory and no upstream key is left over for `~/.pig/agent/skills`. Inventing `enablePigUser` would put
  a key in a settings file that only pig understands — the `models.json` `compat` trade, which this
  file already argues the other way. `skills.enabled` and `--no-skills` are the switch for those two,
  `ignoredSkills` for anything narrower.

Regression tests, one per end so the wire is covered rather than the two sides:
`SkillsTest::testARootAnotherToolOwnsCanBeTurnedOffOnItsOwn` (one case per root, because turning all
five off at once cannot tell a working switch from a `continue` in the wrong place),
`testARootThatIsOffIsNotReadAtAll`, `testAnyRootCanBeSwitchedOffHereAndAnUnknownNameIsIgnored`, and
in `CodingAgentSessionTest` the two that go through a real settings file —
`testAClaudeRootTurnedOffInTheSettingsIsNotRead` and
`testThereIsNoSettingThatTurnsOffPigsOwnSkills`. Dropping the `roots:` argument in
`CodingAgent::session()` leaves every `SkillsTest` case green and turns the end-to-end one red, which
is the split working as intended.

**Order is precedence, lowest first: a later root overrides an earlier one of the same name.** So
`pig > pi > claude > codex`, a project folder beats the home one of the same tool, and `--skills-dir`
beats all of them because it was typed. `SkillsTest` proves the whole chain at once rather than one
link — four folders, one name, and the pig one is what loads.

**This is the second divergence, and upstream is the other way round.** It keeps the *first* one and
warns `"skipping this one"`, in this same order — which makes `~/.codex/skills` outrank everything,
including pi's own skills. Nobody who edits a skill in `~/.pig/agent/skills` expects a copy in another
tool's folder to be the one that runs, and between two answers to the same name the more specific one
is right. The override is named in a warning either way, so which of the two files is in effect is
never a guess: `name taken: "review" overrides the one from ~/.pi/agent/skills/review/SKILL.md`.

One file reached through two roots — `~/.claude/skills` symlinked into `~/.pig/agent/skills` — is still one
skill and no warning, because the `realpath` check comes first. That is the normal way to keep one
copy, and it is not an override.

Three things it does that are easy to get wrong:

- **The first root wins, and the loser is named.** Shadowed silently, the second copy looks
  like a skill that simply does not work.
- **One file reached through two roots is one skill, not a collision.** Symlinking a folder of
  skills into another root is a normal way to keep a single copy, so the check is on
  `realpath()`, not on the name.
- **A skill with something wrong is still loaded, and still complained about.** A name two
  characters too long is worth saying and not worth refusing over. The one exception is a
  missing description: it is the only thing the model sees, so a skill without one could never
  be chosen and would sit in the prompt as a name nobody can use.

Warnings go to stderr before the UI starts, not into the transcript: a malformed skill is its
author's problem, and the author is whoever just ran `bin/pig`.

The frontmatter is read a line at a time rather than with a YAML parser. The spec's fields are
all scalars and pig has no YAML parser to reach for; what this cannot read stays unread, which
for a nested `metadata:` block means its key and nothing else — and the key is all that is
validated anyway. `fnmatch()` is `minimatch` for `--ignore`-style patterns.

**All five of upstream's protocols are here.** The fifth took a correction on the way: this
used to say it "was never a protocol" — that `google-gemini-cli` was Gemini behind a sign-in. It
is not. Its models carry `api: "google-gemini-cli"` and upstream has a 603-line provider for it,
because Code Assist has its own endpoint and wraps a Gemini request inside a Cloud-project
envelope. The claim was wrong and is recorded as wrong; checking it took one grep of
`models.generated.ts` for the `api` field.

`Providers\GoogleGeminiCli` is 250 lines against upstream's 603, because upstream re-implements
the chunk walk and this shares it — see `GoogleShared`. **GitHub Copilot is done** too, and needed
no new protocol at all: it serves everything through the two OpenAI shapes.

### Signing in instead of pasting a key

`Ai\Utils\Oauth\` is upstream's `ai/src/utils/oauth/`, and **all four flows are ported now**.
They arrived in that order and the order is the story: Anthropic's, which is what a Claude Pro or
Max subscription is used through, and GitHub Copilot's went first because neither needed anything
pig did not already have. The two Google ones waited on a piece that did not exist — they are
loopback PKCE flows that want an HTTP server on `127.0.0.1` and a browser opened at it, so
`CallbackServer` had to be written before either could work.

Anthropic's is **pi 1.0.3's**, not the anchor's — the one flow updated past the anchor on purpose,
because the anchor's endpoints had moved under it (`console.anthropic.com`'s callback page is a 301
to `platform.claude.com`, measured) and its three scopes are half of what a token now carries.
`/login` asks which way in first, as pi does: **browser**, where `CallbackServer` listens on
`localhost:53692/callback` and the paste box is open beside it for a browser on another machine,
whichever answers first winning and the other closed (`Anthropic::firstOf()`, the race
`McpOauth` already runs); or **copy code**, Anthropic's own page showing a `code#state` to bring
back. Copilot's is a **device flow**: pig asks GitHub for a pair of codes, shows one, and then asks
over and over whether it has been typed in yet.

The far end is `Providers\ClaudeCode`: an `sk-ant-oat` token goes out as a bearer token under
**both** betas (`claude-code-20250219,oauth-2025-04-20`), `user-agent: claude-cli/2.1.280`,
`x-app: cli`, Claude Code's identity as the first system block — and **its tools spelled Claude
Code's way**, `read` → `Read`, `bash` → `Bash`, on the declaration, on the calls already in the
history, and mapped back to the name this request declared on the call that comes back. Upstream
calls that "stealth mode" and has carried it since January 2026, a week after the anchor; a token
Anthropic issued to Claude Code is checked against what Claude Code's requests look like, and the
anchor's half of it was the half that still passed.

Five things worth keeping straight:

- **`state` is the verifier**, not a second random string. Upstream's choice and Anthropic's
  flow: the callback hands back `code` and `state`, so the state travels home — through the
  socket or the clipboard — and the exchange can tell the code came from the request it made.
  **A state that is not the verifier is refused**, on both paths; upstream throws "OAuth state
  mismatch" there too.
- **A paste is read in every shape pi reads it** — the whole redirect URL, `code=…&state=…`,
  `code#state`, a bare code — and **half a paste is refused before anything is sent.** The
  copy-code page always hands over both halves, so a bare code there is a paste that lost its
  end, and saying that is an answer somebody can act on where `invalid_grant` is not. The paste
  is trimmed for the same reason: a code copied out of a terminal arrives with a newline on it
  about half the time.
- **The new refresh token replaces the old one.** Anthropic rotates them, so a session that
  kept sending the one it signed in with would work exactly once more.
- **Five minutes is taken off every expiry**, which is upstream's margin. A token that expires
  in flight fails the request it was attached to rather than the next one, and that request is
  a whole turn.
- **`available()` is false for the three that are not ported**, and they are still named.
  Both halves matter: the credentials file is keyed by these strings and shared with pi, so a
  name pig cannot sign in with is a name pig still has to *read* — and offering a sign-in that
  cannot finish is the same mistake as offering a model whose protocol is not ported.

`Pkce` is upstream's `pkce.ts` with two differences, both because PHP has what JavaScript had to
reach for: it is not asynchronous (`hash()` returns a string where Web Crypto's `digest()`
returns a promise), and `random_bytes()` *throws* when it cannot be a CSPRNG, where
`crypto.getRandomValues` has no failure to report. A machine with no entropy source stops the
sign-in rather than continuing with something guessable.

The client id is written out rather than hidden behind `atob()` of a base64 string as upstream
has it. It is in a published npm package and in every request this makes, so it is not a secret
— and a reader who cannot see what it is has to go and decode it to find out.

The token endpoint is a constructor parameter so the flow can be tested against a loopback
server. That is not a seam existing for the test alone, which this project otherwise refuses:
every provider in `ai` takes its base URL from outside already, and the alternative here is an
authentication flow with no coverage at all.

### The device flow

`Oauth\GithubCopilot` is upstream's `github-copilot.ts`. It ends with a swap that is easy to
miss: what the device flow produces is a **GitHub** token, and Copilot's API does not accept one.
`refresh()` trades it at `copilot_internal/v2/token` for a short-lived Copilot token — so the
GitHub token is stored as the `refresh` half and the Copilot token as the `access` half, which is
exactly what those two fields mean everywhere else here, and it is also why there is no separate
renewal endpoint: renewing *is* the swap, done again.

Four things about it:

- **The polling can be stopped.** The one addition to upstream, and the reason this flow waited
  for a decision: upstream loops for fifteen minutes with nothing able to interrupt it, which in
  pig is a fiber parked where nobody can reach it — the failure the hook dialogs already have a
  rule about. An `AbortSignal` ends it, escape raises one through `interrupt()`, and a
  cancellation comes back as **null rather than an exception**, because somebody changing their
  mind is an outcome.
- **Where Copilot answers is in the token.** Its claims carry
  `proxy-ep=proxy.individual.githubcopilot.com`, which becomes `api.individual.…`; a business
  account says something else and an enterprise install answers at `copilot-api.<domain>`. So
  the `api.individual…` in `Ai\Models` is a default and never the answer once there is a token —
  `Providers\OpenAiCompletions` and `OpenAiResponses` both ask `GithubCopilot::baseUrl()`
  instead, which is where the token and the request meet. Upstream rewrites it in its model
  registry, which pig has no equivalent of because pig's registry knows nothing about
  credentials.
- **Some models are off until the account accepts them**, Claude's and Grok's among them, so
  `enableModels()` POSTs a policy for each one after signing in. A refusal for one model is
  reported and does not stop the rest — the question asked was "may this account use that
  model", and no is an answer rather than a failure of a sign-in that is already finished. It is
  the one swallowed exception in this module and the docblock says why. The ids come from
  `Ai\Models` rather than from the flow: which models exist is the registry's question and the
  flow should not have a second answer to it.
- **`authorization_pending` is not an error and everything else is.** `slow_down` adds five
  seconds to the interval, as upstream does; anything else — `access_denied`, `expired_token` —
  ends the wait at once, because asking again says the same thing and a quarter of an hour spent
  on an answer that has already arrived is the wrong behaviour.

Something typed at the enterprise prompt that is not a host is **refused**, as upstream refuses
it: carrying on against github.com would sign somebody in to the wrong GitHub and look like it
had worked. Blank means github.com, which is what `allowEmpty` on the prompt is for.

### The loopback the browser comes back to

Google's flow has no device code and no paste: it redirects to
`http://localhost:8085/oauth2callback?code=…&state=…`, so something on this machine has to be
listening. Upstream reaches for Node's `http.createServer`; PHP has no HTTP server, so
`Oauth\CallbackServer` is one — deliberately the narrowest one that answers the question. One
path, three query parameters, one page written back, stop.

Five things about it:

- **The port is not negotiable.** `8085` is part of the redirect URI registered with Google, so
  a flow that picked a free port instead would be refused by Google rather than by the socket.
  `listen()` is therefore a separate step from `await()`: a port that cannot be taken has to be
  reported *before* anybody is sent to a browser, and it is reported by name.
- **`listen()` first, then open the browser.** The order matters and the split is what allows
  it — a browser that arrives before the socket exists gets a connection refused, and the
  sign-in is over with nothing to show for it.
- **It is on the loop.** `await()` parks the fiber on a `Deferred` while the loop keeps serving
  the terminal. A blocking accept would stop the UI for as long as the person took to sign in.
- **A browser opens more than one connection.** The callback, and usually a favicon request
  behind it. Each is read separately and only the one asking for the callback path answers the
  question; everything else gets a 404.
- **Cancel on Google's screen is a refusal, not a cancellation.** An `error=` in the callback
  throws, where escape returns null — something was said and it was no, which is different from
  walking away.

`stream_socket_server()` both warns and returns false, so the warning is caught with a handler
rather than suppressed: it is the only place the reason appears, and `@` is not allowed here.

`InteractiveMode`'s `onAuth` now **opens the browser** and prints the URL as an OSC 8 hyperlink,
which is upstream's behaviour and helps the two flows that were already here. The link text is
the URL itself rather than upstream's "Click here to login": a terminal that does not understand
the sequence shows the label, and a label is not something anybody can copy. `Ansi::strip()`
already knew about OSC 8, so the line measures correctly. Opening is best effort — the URL is on
screen, so a headless box or one without `xdg-open` is one extra copy-and-paste rather than a
broken sign-in.

### Google's sign-in

`Oauth\GeminiCli` is upstream's `google-gemini-cli.ts`, and the third shape of sign-in in three
flows: Anthropic's shows a URL and takes a paste, Copilot's shows a code and polls, and this one
**redirects to this machine** — `CallbackServer` listens, the browser goes to Google, and the code
arrives on a socket rather than through a person.

It is also the only one that does not finish when the tokens arrive. A Code Assist token is
useless without a Cloud project to spend it against and most accounts have none, so `project()`
asks for one and provisions a free-tier one when there is none — an API call that answers "not
yet" and has to be asked again. That is what `projectId` on `Credentials` is for, and why
`Provider::apiKey()` encodes it alongside the token: an api key field carries one string, and
Code Assist needs two things.

Six things worth keeping straight:

- **The state is checked and a mismatch is refused.** It is the verifier, so a code that came back
  with a different state came from a request this process never made. Upstream calls it a possible
  CSRF attack; this is the one check in the module that is about somebody else rather than about a
  mistake, and it has a test that drives a real socket to reach it.
- **`listen()` before the browser is sent anywhere**, which is the whole reason `CallbackServer`
  is two steps.
- **There has to be a refresh token**, and its absence gets its own complaint rather than being
  folded into "no tokens". Google only sends one for `access_type=offline` with `prompt=consent`,
  both of which are in the URL — so a sign-in that came back without one is worth saying plainly,
  because the fix is to try again.
- **A half-built project is not a finished one.** `onboardUser` answers with an id while it is
  still working, so `done` is checked as well: taking that id would name something nothing can be
  spent against.
- **Provisioning can be given up on**, which upstream cannot. Ten attempts three seconds apart is
  half a minute of a fiber nobody can reach.
- **The email is optional and its failure is ignored**, which is upstream's rule and the only
  place in the module where swallowing an error is right: it is a label on the credential and
  nothing downstream reads it.

**Google's client id and secret are not in this repository**, which is the one real difference
from upstream. They are constructor arguments, and `Auth::googleClient()` finds them — the
environment (`GEMINI_CLI_CLIENT_ID`, `GEMINI_CLI_CLIENT_SECRET`) and then the settings file
(`geminiCli.clientId`, `geminiCli.clientSecret`), which is pig's usual order. A flow built without
them is refused **in the constructor**, not at the first request: a flow that sends somebody to a
browser and then fails is worse than one that never starts, and the caller is what knows where to
look. Two pushes were refused before this was the answer — see the trap below, which is about what
scanners do rather than about what is secret.

Both READMEs say so now, because `/login` offers Gemini CLI and a reader who picks it needs to
know that two values have to come from somewhere else — and that port 8085 has to be free.

`available()` is true for it now. It was false for two turns while the protocol and the models
were missing, because a sign-in that unlocks nothing to choose is the same mistake as a model
whose protocol is not ported, from the other end.

`Providers\GoogleGeminiCli` is the protocol, and three things are its own:

- **The request is wrapped**: `{project, model, request: {…a Gemini request…}, userAgent,
  requestId}`, posted to `v1internal:streamGenerateContent?alt=sse`.
- **The answer is wrapped too**, one key deeper, so it unwraps and hands `GoogleShared::onChunk()`
  the same thing `Google` hands it. A chunk with no `response` in it is skipped rather than read as
  an empty candidate.
- **Thinking is only mentioned when it was asked for.** The one place the body differs from the
  public endpoint's: there, a turn that wants none has to say `thinkingBudget: 0`; here a
  `thinkingConfig` on a model that cannot think is rejected.

And the key is two things in one string — `Provider::apiKey()` encodes `{token, projectId}` —
which is taken apart in `credentials()`. **An ordinary Gemini API key arriving there is refused by
name**, because that is the likely mistake and a 401 three layers later names nothing.

**Upstream's in-provider retry is not ported**: three attempts with exponential backoff, because
Code Assist's free tier rate-limits hard. `Session\Retry` already waits out a 429 one level up, and
that one can be turned off with `retry.enabled`, counts down on screen and stops on escape. Two
retry mechanisms means neither is the one somebody is looking at. If Code Assist turns out to need a
tighter loop than a session-level wait, it belongs in the provider and its docblock says so.

**The one thing that loop knew, the policy knows now.** Upstream's loop reads the moment the quota
comes back out of the error — `Your quota will reset after 18h31m10s`, `Please retry in 250ms`, a
`retryDelay` field in the body — and pig had kept the loop out and the reading with it, so a 429
saying "39 seconds" was answered at 2s, 4s and 8s: three more 429s inside the window the provider
had just named, and a turn dead after fourteen seconds of waiting when forty would have worked.
`Retry::statedDelay()` reads the three shapes and `AgentSession` prefers it to the doubling. A
second is added, as upstream adds it, because coming back at the exact moment a quota resets is a
coin toss between two clocks.

**Past a minute it is not a retry any more, so there is none.** `MAX_STATED_WAIT = 60` is the
developer's number and it is checked in `worthRetrying()`, before the status and before the word
list, because it has to beat both: a 429 is retryable and "rate limit" is in the word list, and
neither of them knows that the sentence beside it says "not for ten minutes". So a stated wait
inside the minute is waited out as asked; one past it ends the turn then and there, and what the
person reads is the provider's own line — which names the time, and is the only thing anybody can
act on. The alternative was upstream's, which waits whatever it is told inside a `setTimeout`: an
eighteen-hour countdown is a session that looks dead, and *a retry whose point is that the turn
carries on is not a retry when the turn resumes after lunch*.

### A provider an extension brings: `Pig\Ai\Extension`, and why Antigravity is one

**Decided 2026-10-06.** pi removed Gemini CLI and Antigravity from its core in 0.71 and the
community rebuilt Antigravity as an extension (`Rahularya01/pi-antigravity`) on pi's
`registerProvider()`. pig had kept Antigravity built in, spread across `Models`, `Api`, `Stream`,
`Oauth\Provider`, `Auth`, `AgentSession`, `Doctor`, `HttpServer` and the web front end — 28 source
files, 95 references — with a 370-line extension that was a thin shell over all of it. Google's
private protocol (its User-Agent, its envelope, its `v1internal:` endpoints) was pig's largest
piece of core code with no upstream behind it, and every change Google made meant a core release.

The developer's call was to do pi's move in one step: **nothing about Antigravity is in
`packages/` any more.** `extensions/pig-antigravity/src/` holds the protocol (`AntigravityApi`),
the sign-in (`AntigravityOauth`), the routing, the model table, the accounts store, the catalogue,
quota and image generation; `index.php` registers the provider and wires the rest through hooks.
Install the extension and `--model antigravity/gemini-3.8-flash` works; remove it and the name
means nothing. The tracking target is `Rahularya01/pi-antigravity`, not pi.

**What the core grew to make that possible is the provider half of pi's `ExtensionAPI`**, which pig
had not ported because nothing needed it — and which is exactly what CLAUDE.md's rule about new
surface asks for: decided by its first caller.

- **`Pig\Ai\Extension\Provider`** is one object for what a built-in provider spreads across four
  classes: id, name, models, a `StreamApi` (or null for a protocol pig already has), an
  `OauthFlow` (or null for a key), the environment variables that carry its key, and `resold` —
  `Models::RESOLD`'s dynamic half, so `claude-sonnet-4-6` bare stays Anthropic's.
- **`ProviderRegistry`** is static for `Models::register()`'s reason, and each of the four core
  classes asks it when its own table comes up empty: `Models::isResold()` and
  `Models::forgetProvider()`, `Api::Extension` with `Stream::start()`'s one new arm and
  `Stream::translate()`'s, `Stream::envApiKey()`'s default, and `Auth`'s `signIns()`/`signIn()`,
  which `/login`, `pig-ai` and `Doctor` list from in place of `Provider::cases()`.
  **Still no `default` in `Stream::start()`**: `Api::Extension` is one named arm, and the registry
  refuses by name — `No extension provides the protocol for antigravity/gemini-3.8-flash. Is its
  extension loaded?` — where "No API key" would send somebody to the wrong file.
- **`Auth::useSecondStore(provider, read, renewed)`** is the seam `antigravity-accounts.json`
  needed: `credentials()` asks it only when `auth.json` has nothing, and `fresh()` tells it about
  a renewal. `Auth::login()` takes `Provider|OauthFlow` and stores under the flow's `id()`.
- **Three provider-traffic hooks**, upstream's names where upstream has them: `before_provider_request`
  (chained like `context`, through `HttpClient::observe()` — the one place every provider's bytes
  go), `after_provider_response` (status and headers, before the body), and `before_retry`, which
  is pig's own: the 429 account rotation used to be an `if provider === 'antigravity'` inside
  `AgentSession::waitAndCarryOn()`, and is now the extension answering `BeforeRetryResult(delay:
  0.5, resetAttempts: true)`. `model_select` and `thinking_level_select` came in the same batch.
  The observer is only installed when a hook listens (`HookRunner::listensToProviderTraffic()`),
  so a session with no provider hooks pays nothing per request.
- **`ExtensionApi` grew the rest of the gap that this needed**: `registerProvider()`,
  `registerFlag()`/`getFlag()` (`bin/pig` hands every option over after the extensions load,
  because which flags exist is only known then), `getSettings()`, `setModel()`,
  `getThinkingLevel()`/`setThinkingLevel()`, `sendUserMessage()`, `setLabel()`, `getCommands()`, and
  `registerHttpRoute()` — pig's own, with no upstream counterpart because pi-web imports nothing
  from an extension; it is how the accounts panel left `HttpServer`.

Three things about the move that are not obvious from the diff:

- **`bin/pig` loads the extensions before `--list-models`**, and hands the result to
  `CodingAgent::session()` as `preloadedExtensions` rather than loading twice — a factory run
  twice is an MCP server connected twice. `HttpServer::start()` loads them for the shell too.
- **The default model is `claude-sonnet-4-5` again** (`CodingAgent::DEFAULT_MODEL`), which both
  READMEs have said all along; for a while the code said `antigravity/gemini-3.8-flash`, a provider
  that only exists once an extension has loaded.
- **`ProbeModels` gained `zzp-thinker`** — a model whose map says `off => null` — because seven
  tests had used Antigravity's rows for "a model that cannot be turned off" and "a remembered
  provider that is not the direct one", which are facts about the rules and not about Google.

Regression tests: `ProviderRegistryTest` (8, from the core's side), `ExtensionApiTest` (7, the
hooks, flags and session drive), `AntigravityExtensionTest::testLoadingTheExtensionRegistersTheProviderAndUnloadingTakesItBack`
and `…WithoutTheClientPairAndRefusesOnlyAtSignIn`, the moved `AntigravityApiTest` (now registering
through the registry in `setUp`), `AntigravityOauthTest` (moved out of `OauthTest`),
`AgentSessionTest::testAHookCanChangeTheTermsOfARetryBeforeItWaits` and `…CanCallARetryOff`, and
`InteractiveModeTest::testAnExtensionsSignInIsARowInSlashLoginLikeAnyOther`. Verified live:
`/login` lists Antigravity as `subscription configured` with the extension loaded and not without,
`--list-models antigravity` shows its fourteen rows, `/antigravity.doctor` answers, and a `-p` turn
reaches the real deployment (and was told the quota resets in 16h, which is the deployment's
answer and not pig's).

### `pig-ai`, the second entry point

`Cli\SignIn` is upstream's `ai/cli.ts` and `bin/pig-ai` is nine lines over it: `list`, `login`,
`login <provider>`, `help`. `/login` does the same thing inside pig, so this is for the times there
is no terminal UI to be inside — a machine being set up over ssh, a container being built, or a
flow that has to be watched because it is not working.

Two things about it, and the first is the reason the logic is in `Cli\` rather than in the script:

- **The first version was inline in `bin/pig-ai`, and its `$onPrompt` was a `static function`
  with no `use ($ask)`** — so the moment Anthropic's flow asked for the pasted code, it called
  null. Nothing catches that except *reaching the prompt*, and a script that ends in `exit()`
  cannot be reached twice by a test. `Arguments` already says this about itself and the lesson did
  not travel; it has now. `SignInTest` reaches all four flows by escaping each at its first
  question.
- **It writes where pig reads.** Upstream's `AUTH_FILE` is the string `"auth.json"` — a relative
  path, so a login run inside a checkout leaves a file full of refresh tokens next to the code,
  and nothing reads it there afterwards either. Here it goes through `Auth`, into the one file
  `pig` and `pi` both read, with the mode that file gets. The usage text says which file, because
  that is the one thing somebody on a fresh machine needs to know.

**`composer.json` declares `bin` now.** This paragraph used to say the opposite — "declaring
executables is a packaging decision rather than part of this port" — and the decision has since been
taken, because the command the update check prints (`composer global update pigagent/pig`) only works for
an install that puts `pig` on the PATH. There is deliberately **no** `version` field beside it; the
two entries below have that and the rest of the packaging.

### Tidying pi's directory, which is pig's problem

`Migrations` is upstream's `migrations.ts`, two one-time tidies run before `Auth::discover()`.
Neither is about pig's own history, and that is why the old reason for skipping them — "pig writes
pi's format and has never shipped another" — was answering the wrong question. **They migrate
pi's** old shapes, and pig lives in pi's house: it reads pi's sessions and shares pi's `auth.json`.
So a pi install left mid-upgrade has credentials and conversations pi can see and pig cannot.

- **`oauth.json` and `settings.json`'s `apiKeys` into `auth.json`.** Nothing happens at all if
  `auth.json` is already there, which is upstream's first line and the whole safety of it: a file
  that exists is the current shape. An OAuth credential beats an api key for the same provider,
  because signing in is what replaced pasting a key.
- **Session files pi 0.30.0 left at the root of its own directory** instead of under
  `sessions/<the project's path, flattened>/`. Where each belongs is read from the `cwd` on its own
  header line, and filed with `SessionManager::slug()` — the same slug, or this would move a file
  out of pi's sight without bringing it into pig's. Upstream's issue #320.

**pig writing in pi's directory is a real thing to be uneasy about**, so the bound is worth stating
rather than assuming. What happens here is exactly what pi itself would do on its next start, so
running it converges rather than diverges. Nothing is deleted: `oauth.json` is renamed, a session
is moved, a file that already exists at the destination wins. Every step is skipped on the first
sign of trouble, because a half-migration of somebody else's data is worse than none.

**Which is the argument for auditing every difference rather than only the ones that felt like
decisions** — "worse than none" is exactly what a near-miss produces. The audit found two real
gaps and one thing that had been written down as a deviation and is not one:

| Difference | Verdict |
|---|---|
| `glob('*.jsonl')` | **A gap.** A glob does not match a leading dot and upstream's `readdirSync` does, so `.something.jsonl` was a stray session this could not see. Now a scan. The test helper in `MigrationsTest` had the same bug a few lines later, which is how thoroughly the habit travels |
| the message | **A gap.** Upstream shows one warning with the providers joined; this printed one stderr line per provider in its own words. Now upstream's line, once. stderr because that is where pig puts warnings from before the UI exists, beside the settings and credential ones |
| pi's `settings.json` keeps its own permissions | **Not a deviation at all** — upstream's plain `writeFileSync` does the same, and this entry used to claim otherwise. It was a deviation from the *first draft here*, which passed `0600` and would have tightened a file that was never pig's to tighten |
| `auth.json` written with 4-space JSON and a trailing newline | Deliberate: upstream's is `JSON.stringify(x, null, 2)` with no newline, but **`auth.json` is a file pig itself writes**, and `Auth::save()` writes it this way. Matching upstream would make the migration produce a shape pig never produces, so the next ordinary save reformats the whole file |
| the session directory created `0700` | Deliberate: `SessionManager` and `Auth` create directories with `0700` and pig creates this exact one in ordinary use. Upstream's default umask would put one project's sessions under two modes |
| only the first line of a session file is read | Deliberate: upstream reads the whole file to split off line one, and a session file is a conversation long. The header is line one either way |

### Where the keys live

`Auth` is upstream's `core/auth-storage.ts`, named after `Settings` rather than after upstream's
`AuthStorage` — both are one JSON file under the home directory with a typed reader over it, and
the developer chose to keep those two matching rather than `SessionManager`'s kept suffix. One
entry per provider, in upstream's own shape, either `{"type":"api_key","key":…}` or
`{"type":"oauth","refresh":…,"access":…,"expires":…}`.

**It is pi's file, not a copy of it.** `Auth::discover()` opens `~/.pi/agent/auth.json` when
that exists and only falls back to `~/.pig/agent/auth.json`. That is not the same decision as reading
pi's sessions, and the reason is specific: Anthropic **rotates** refresh tokens, so the old one
is void the moment a new one is issued. Two files holding the same token is two tools taking it
in turns to log each other out — whichever refreshes first wins, and the other has to sign in
again. One file is the only arrangement where signing in once means signing in once. `Config`'s
docblock says so too, because it used to claim pig never writes in there.

Five things about it:

- **The order is upstream's**: what was typed, then a stored API key, then a stored OAuth token,
  then the environment. The last step is `Stream::envApiKey()` rather than a second table of
  variable names — it is upstream's `getEnvApiKey()` and it already knows that
  `ANTHROPIC_OAUTH_TOKEN` beats `ANTHROPIC_API_KEY`.
- **A renewal that fails is reported, not cleaned up.** Upstream deletes the stored credential
  and carries on to the environment, so one blocked network call throws away a login that was
  perfectly good — and the person finds out by being asked to sign in again for no reason.
- **A failed *write* throws**, where `Settings` records a problem and carries on. A preference
  that did not stick is a preference somebody sets again; a token that did not stick is a
  sign-in that said it worked, and the next thing that happens is an authentication error
  nobody can connect back to this.
- **`0600` in a `0700` directory, and the mode is set before there is anything to read.**
  Upstream writes the file and then chmods it, which leaves it world-readable for as long as
  those two calls take — long enough.
- **A provider name neither tool knows is kept.** The file is shared, so an entry pig has no
  enum case for still has to survive being read and written back.

`getApiKey` on `AgentOptions` is what it plugs into — a closure asked **per turn** rather than a
string resolved once, which is what makes renewing an expiring token mid-conversation possible
at all. It was already there and had no caller; `CodingAgent::create()` gained a parameter to
pass it through.

`/login` and `/logout` are the same `SelectList` in the same place as `/model`, `/resume` and
`/tree`, rather than a port of upstream's `oauth-selector.ts` — a fourth picker that behaves
like the other three is worth more than a literal port of a component that does less. Two
differences from upstream, both saying more: a provider pig cannot sign in with is greyed
**and** says why when it is chosen, where upstream ignores the key and reads as broken; and
`/logout` lists only what is actually signed in, because offering the other three would be
three ways of doing nothing.

Signing in **spawns**, because the paste box parks the fiber it is asked on and the command is
running inside the loop's own input callback — suspending there suspends the loop that has to
deliver the keystrokes. Same reason `send()` and `startCompaction()` spawn.

**`--no-save` does not apply to credentials.** It means "do not write this conversation down";
a stored sign-in is still the way in, and a token renewed during the run has to be kept or the
next run begins by renewing one that was already replaced.

`setFallbackResolver` is here as `setCustomProviderKeys()`, narrowed: upstream takes a closure
and the one thing the closure ever did was look a provider up in a map, so pig takes the map.
The indirection bought a second place to look when a key does not resolve. It sits **after** the
stored answers and **before** `Stream::envApiKey()`, which is the only order that works —
`envApiKey()` has a fixed table of variable names and answers null for a provider it has never
heard of, which is every provider this is for.

`setRuntimeApiKey` has its caller now: **`--api-key <key>`**, for this run and this provider
only, never written to `auth.json`. Three things about it, the first two being differences from
upstream:

- **It is applied after the model is resolved**, because the provider it belongs to is that
  model's. Upstream refuses the flag unless a model was named explicitly; pig always resolves
  one (`--model`, then `PIG_MODEL`, then the settings, then `claude-sonnet-4-5`), so there is
  nothing to refuse.
- **An empty value is refused by name.** `api-key` had to go in `Arguments::TAKES_A_VALUE` — a
  key is exactly the kind of thing that does not start with a dash — and an option at the end
  of the line with nothing after it gets `''`. Storing that would put an empty string at the
  top of the precedence order, where it beats the environment and then fails as a missing key
  three layers away.
- It beats everything else, including a stored sign-in, because it is the most recent thing
  anybody said.

**`CodingAgent::apiKey()` is gone**, and this is the other half of the same story. It read the
environment itself, naming `ANTHROPIC_API_KEY` where `Stream::envApiKey()` prefers
`ANTHROPIC_OAUTH_TOKEN` — so for `CodingAgent::create()` callers with both variables set, which
one won depended on which door you came in by. Its `default => strtoupper($provider) .
'_API_KEY'` arm was worse: `github-copilot` came out as `GITHUB-COPILOT_API_KEY`, which is not
a name a shell can even export. `create()` now passes a null key straight through and `Stream`
is the only thing that reads the environment. `examples/agent.php` is the one caller affected,
and the change is that it gains upstream's precedence.

### Somebody else's endpoint, declared in a file

`CustomModels` is upstream's `core/model-registry.ts` — the half of it that reads `models.json`.
The other half was already here under other names (`Ai\Models`, `Auth`, `ModelResolver`), which
is exactly how this one stayed invisible long enough to be the fourth thing the left-out table
got wrong.

`~/.pig/agent/models.json`, or pi's `~/.pi/agent/models.json` when pig has none — a fallback rather
than a merge, because one file is what upstream has and somebody who already told pi about
their box should not have to say it twice. Neither is ever written to. `--no-save` reads
neither: that flag means the run touches nothing of the person's.

Every key is upstream's, so a file written for pi works here unchanged:

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "MY_BOX_KEY",
  "api": "openai-completions", "authHeader": true, "headers": { "X-Tenant": "acme" },
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] } } }
```

Five things decided here:

- **`apiKey` names an environment variable before it is a key.** Upstream's
  `resolveApiKeyConfig`, and it earns its keep: this is the one config file in pig whose
  natural contents are a credential, and a file in a repository is a credential in a
  repository. `authHeader: true` resolves it before putting it in `Authorization: Bearer …`,
  because a proxy handed the literal string `MY_BOX_KEY` answers 401 and explains nothing.
- **A built-in always wins a collision.** The table and `find()` are both keyed `provider/id`,
  so `Models::register()` fills gaps rather than overwriting — a config file able to quietly
  redefine a shipped model is a bug report nobody could read. A provider with a name of its own
  collides with nothing, which is the normal case.
- **`Models::register()` is static, because `Models` is.** Upstream threads a `ModelRegistry`
  instance through everything; here `--model`, `/model`, restoring a session and `RpcMode` all
  ask `Models` by id, and a model only some of them could see is a model that works until you
  save the conversation. The price is `forgetRegistered()`, which every test that registers has
  to call.
- **Nothing throws and nothing is all-or-nothing.** A bad line loses only itself: the good
  models in the same file still load, the built-ins are untouched, pig still starts, and every
  problem names the file, the provider and the model. That is `Settings`' rule — a file that is
  not JSON is named, not ignored — applied to a file with far more ways to be wrong. There is no
  AJV and no TypeBox; the checks are written out, which is `Agent\ToolArguments`' trade again.
- **A `cost` block is read and is optional**, under upstream's four names and upstream's unit:
  dollars per million tokens, so Sonnet's three dollars is `3.0` and not `0.000003`. Absent is free,
  which is what a local model is — and a price that is **not a number is refused by name** rather
  than read as free, because `/session`, the footer and `--list-models` all report money and a model
  that silently costs nothing misreports it every turn. `"input": "0.28"` with the quotes left on is
  the mistake to expect. Only the absent case had a test until a live run against a declared
  endpoint reported `the model is priced at zero` and that was mistaken — in this file's own summary
  of it — for the field not existing at all. *A claim about what this repository does is worth a
  grep, which is the fourth shape from the index pointed at pig rather than at a docblock.*
- **No `compat` block means null**, so `OpenAiCompat::detect()` still works it out from the URL.
  That is a better default than any set of flags: a local llama.cpp gets what it needs with
  nothing written. A block uses **all eight of upstream's key names** so files stay portable — the
  four `supports…`/`maxTokensField` ones and the four upstream prefixes with `requires`. This
  sentence used to say upstream had four of the eight and that the other four were pig's own; see
  the trap entry on it.

`--list-models` moved to **after** this loads, which it had to: a listing that cannot show the model
somebody just declared is a listing they will not trust about the rest either. It costs a
settings read on a command that prints and exits.

### Checking a tool call against its schema

`Ai\Utils\JsonSchema` is upstream's `utils/validation.ts` — the AJV part, written out. `ToolArguments`
is still what `AgentLoop` calls and still owns the **message**, which is upstream's format down to
the blank line before the arguments; what moved out is the deciding.

**It replaced a two-rule checker, and the reason is the reason it was written that way.**
`ToolArguments` used to look for a missing required property and a wrong primitive type and pass
everything else through, because nothing was bundled that could read a schema. That was defensible
until there *was* something, and then it was two notions of what a tool's schema means — the shape
that goes wrong quietly, and the narrower one is the one nobody would have thought to look at.

The subset is `type` (one or several), `enum`, `const`, `required`, `properties`,
`additionalProperties`, `items` (single and tuple), the number bounds, `multipleOf`, the string
lengths, `pattern`, the item and property counts, `uniqueItems`, and `anyOf`/`oneOf`/`allOf`/`not`.
Left out with reasons in the docblock: `format` (an annotation in draft-07; `ajv-formats` makes it
an assertion and a tool has a better place to check an email), `$ref` (needs a resolver and a cycle
guard, and a tool's parameters are self-contained everywhere in either tree), and
`if`/`then`/`else`, `dependencies`, `patternProperties`, `propertyNames`, `contains` (nothing uses
them).

**An unknown keyword is ignored, never a failure.** That is the most important line in the file: a
schema using something unimplemented has to keep working, or adding a keyword to a tool breaks the
tool instead of tightening it.

**Then it was run against AJV**, `ajv@8.20.0` with `ajv-formats` and upstream's own options, over
~190 schema/value pairs covering every keyword it implements and every keyword it does not, values
transported as JSON text so both sides decode the same document. Three things came out of it, and
the first two are the same mistake in two places:

- **`const` and `enum` compared with `!==`**, so `const: 5` refused `5.0`. JSON has one number type
  — a provider sending `5.0` for a count sent 5 and had no way to send anything else, AJV compares
  in a language that cannot hold the difference, and **`isType()` two methods away already said so
  in a comment** for `integer`. A model cannot correct a value that was already right. `same()` is
  numeric when both sides are numbers, recursive through lists and objects, and never PHP's `==` —
  which would make `1` equal `true` and `0` equal `null`.
- **`uniqueItems` sorted object keys at the top level only**, so an object nested inside a list
  inside an object read as two different documents depending on the order its keys arrived in.
  `identity()` recurses now.
- **The duplicate is named by position**, as AJV names it: `(items ## 1 and 3 are identical)`. The
  model has to fix that list, and "one of these is a duplicate" makes it read the whole list again.

A branch that normalised whole floats in `identity()` was written at the same time and **removed for
doing nothing**: PHP's `json_encode(1.0)` is already `1`. It was the mutation check that found it —
mutating it changed no test, which is the only reason anybody looked. The docblock now says the
verified thing rather than the assumed one.

What the run left, all documented in the docblock: four verdicts that are the empty-array ambiguity
(`type: object` against `[]` and `type: array` against `{}` are the same PHP value), three messages
where AJV also lists why each `anyOf` branch failed and this reports only that none matched, and the
missing-property path convention below.

Five things that took a real answer rather than a guess:

- **The messages are AJV's wording**, not invented ones — `must be integer`, `must have required
  property 'path'`. The reader is the **model**: it is about to correct itself from this sentence
  and it has seen AJV's phrasing everywhere else. One existing test changed wording because of it.
- **A missing property is reported at its own path.** Upstream prints `err.params.missingProperty`,
  the bare name, so a nested one cannot say which object it was missing from; here it reads
  `edits/0/path`.
- **`[]` is both an empty array and an empty object.** `json_decode(assoc: true)` gives a PHP array
  for both, so the two are told apart by `array_is_list()` — and the empty one is
  indistinguishable. It satisfies either, because there is no third answer and rejecting one would
  reject a valid document for being ambiguous with another valid one.
- **`multipleOf` took three tries.** `fmod(0.3, 0.1)` is `0.0999999999999999778` — close to *the
  divisor*, not to zero — so a one-sided tolerance on the remainder calls 0.3 not a multiple of
  0.1. It asks whether the quotient is whole, to a tolerance. AJV asks
  `Number.isInteger(value / multipleOf)` and therefore *does* reject 0.3, which is the standard's
  literal reading and is deliberately not copied: "0.3 must be multiple of 0.1" is a correction a
  model cannot act on.
- **`pattern` is delimited with `#`, and lengths are counted in characters.** A schema's pattern is
  an undelimited ECMA-262 regex, and `/` as the delimiter would silently change every pattern
  containing a path. `strlen` would make a four-character Chinese string twelve and reject what the
  schema allows.

A pattern that is not a valid PCRE is the schema's mistake and is ignored rather than reported
against the value, which would send the model looking where it did nothing wrong. Not ported:
upstream's `validateToolCall(tools, call)`, which is `validateToolArguments` plus a find-by-name —
it has no caller upstream either, because `AgentLoop` has the tool in hand by the time it
validates and needs it afterwards to run.

### `packages/agent`'s test suite, read as a specification

Upstream's tests are the only written statement of what its code is *supposed* to do, so the sweep
after auditing `agent-core` was: for each `it(...)` over there, is there something here that would
fail if pig got it wrong? Five were not pinned, and all five turned out to pass — which is the useful
answer, because the alternative was not knowing:

- **`transformContext` runs before `convertToLlm`.** The order is the point: a transform works on the
  app's own message kinds — compaction and the `ContextEvent` hook both arrive there — and the
  conversion then drops what the model cannot read. Reversed, a transform would be handed a list with
  the app's own kinds already removed, which is the one thing it exists for. Pinned by reversing the
  two in `AgentLoop` and watching the test go red.
- **An app's own message may be the last one when continuing.** Only an assistant message is refused;
  a hook message at the end is the caller's business, because `convertToLlm` is what turns it into
  something a provider accepts. Refusing it would refuse the case `continue()` exists for.
- **`continue()` while streaming is refused**, the other half of the guard `prompt()` has.
- **`abort()` on an idle agent does nothing** — there is no controller, and a UI's escape key does
  not know whether a turn is in flight.
- **The state mutators each write their own field**, and `replaceMessages()` re-indexes. Upstream
  asserts that one as "should be a copy", which PHP does by itself; the half worth pinning here is
  `array_values()`, since a message list with a gap in its keys reaches `count()` and
  `$messages[count - 1]` in three places and both read the wrong thing.

`e2e.test.ts` is the same five scenarios against seven live providers and needs real keys, so it is
not a spec pig can run; the one part of it that is not provider-specific — `Agent.continue()`'s two
validation throws — is the find above it in this file, which upstream tests at the `Agent` level and
pig had only in the loop.

### `coding-agent`'s session-manager tests, read the same way

The same sweep over `test/session-manager/` — five files, 1,098 lines, the suite that describes the
file pig shares with pi. **Two of its `it()` names had no counterpart here and both were real**, and
they are the two entries below: the `--continue` that opened the wrong conversation ("returns most
recently modified session" — a claim about `mtime`, where pig sorted by name) and, from reading
`branch-summarization.ts` alongside it, the handover that carried the files it was summarising.

The rest lined up, and the four worth writing down are the ones where the answer is *not* a matching
line:

- **"uses last entry when leafId not found" has no case here.** Upstream's `buildSessionContext` is a
  free function handed a leaf id from outside, so it needs a fallback; pig's is a method on the class
  that owns both, and the leaf is always an entry that exists — `open()` sets it to the last line read
  and `goTo()` refuses an id it does not have. A fallback for a state that cannot arise would be a
  branch only a test could reach.
- **"handles orphaned entries gracefully" already agrees.** `pathTo()` stops when a parent is not
  there, so an entry whose parent is missing comes back as itself and nothing before it — upstream's
  one message, for the same reason. It is the other half of the rule the file format section states:
  *an entry you cannot use is still an entry other entries point at.*
- **The label cases are pinned three ways already**: out of the conversation (`isSaid()`), last one
  wins (read in file order), refused on an id nothing has.
- **The `createBranchedSession` cases are not pig's**, being the fork into a second session file that
  pig does not do — `/tree` is the answer to the same wish and has its own tests.

`build-context.test.ts`'s remaining claims — the thinking level and model tracked from their own
entries *and* from the last assistant message, a summary in front of the kept messages, the latest of
several compactions winning, a branch summary appearing on the path — are each already a test here,
which is what makes this read cheap: the expensive part of the file format was done when it became
pi's.

### The rest of `coding-agent`'s tests, and the `try` that turned out to have none

The same sweep over the other sixteen files — `agent-session-*`, `compaction`, `compaction-hooks`,
`tools`, `skills`, `args`, `model-resolver`, `fuzzy`, `truncate-to-width`, `rpc`. No bug, one real
coverage gap, and one divergence in pig's favour; the value of writing it down is the *evidence*, so:

**The gap: `HookRunner::ask()`'s `try` had no test at all.** Removing it broke **nothing** in 2,433
cases. Four events are *decisive* — `session_before_compact`, `session_before_tree`,
`session_before_switch`, `before_agent_start` — and they go through `ask()`, which is a second walk
over the handlers with its own `try`, beside `emit()`'s. Only `emit()`'s was pinned
(`testAHandlerThatThrowsIsReportedAndTheRestStillRun`). So a hook with a typo in a
`session_before_compact` handler would, if that `try` were ever removed, throw out of `compact()`,
`goTo()` or `switchTo()` instead of letting the default happen — and upstream's suite is what names
the rule: *"should continue with default compaction if hook throws error"*. **The first shape from the
index, on a `try` rather than on a rule**: two methods over the same handlers, one of them tested.
`HookRunnerTest::testAThrowingHandlerOnADecisiveEventIsReportedAndTheNextOneStillAnswers` and
`testADecisiveEventWhoseOnlyHandlerThrowsAnswersNothing` are the two ends.

**The divergence: an empty `--model` is refused here and is a lottery upstream.** Its own test says so
— *"empty pattern matches via partial matching: Empty string is included in all model IDs, so partial
matching finds a match"* — so `--model ""` there silently opens on whichever model the substring pass
happens to rank first. `ModelResolver::parse('')` answers null and `CodingAgent::session()` refuses by
name: `No model matches ''. Try --list-models for the list.` Verified through the binary.

Everything else in those sixteen files is already pinned or has no case here, and the six worth
naming because the check was not a grep:

- **Where a branch summary's parent lands**, upstream's two cases: navigating to a *user* message
  attaches it to that message's parent, navigating to an answer attaches it to the answer. pig's
  `goTo()` moves the leaf to the resolved target and appends afterwards, so both fall out of one line
  — and `abandoning($entryId)` is passed the **original** id, not the resolved one, which is what
  upstream passes to `collectEntriesForBranchSummary` too: the message whose text went to the editor
  is not also summarised.
- **`sonnet:`, `sonnet:random` and `sonnet:high:random`** all answer the model with thinking `off` and
  a warning naming the level, which is upstream's three cases (its own are spelled with OpenRouter's
  `qwen3-coder:exacto`, an id pig's registry does not carry).
- **The `truncate-to-width` crash from upstream's issue report** — `'✔ script to run › dev $ …'` at
  terminal width 67 — comes out of `Width::truncate()` at exactly 65 columns, 67 with the cursor. Run,
  not reasoned about.
- **`grep` on a single file still names the file**, because pig passes `--json` as upstream does and
  the filename is in the event. Plain `rg` output would have dropped it for a single path, which is
  what that test exists to catch.
- **Every one of `skills.test.ts`'s validation claims is pinned**: the folder-name mismatch, 64
  characters (in characters), the character class, a leading or trailing hyphen, two hyphens in a row,
  an unknown frontmatter field, the folder name used when the frontmatter has no `name`, and XML
  escaping. Its filter rules too — `ignoredSkills` is checked before `includeSkills`, so it wins, and
  an empty `includeSkills` filters nothing. All four settings keys are upstream's key paths.
- **A `session_compact` event fires after the summary is saved**, which is upstream's *"should include
  entries in compact event after compaction is saved"*.

**One absence was a decision rather than a find, and the decision has since been taken**: upstream's
five per-root skill switches — `skills.enableCodexUser`, `enableClaudeUser`, `enableClaudeProject`,
`enablePiUser`, `enablePiProject`, which two of its tests exercise (*"should load from
customDirectories only when built-ins disabled"*, *"should return empty when all sources disabled and
no custom dirs"*). pig had `--no-skills`, all seven roots or none, and `ignoredSkills` by name. Five
switches is new public surface, so it was the developer's to call and the answer was yes; the shape it
took, and why pig's own two roots still have no key, is in the skills section above.

### `tui`'s tests, where the specification was the find

Five files, 1,492 lines, and the useful one is `wrap-ansi.test.ts` — six cases about style bleeding
across a wrap, which is the family two entries in the traps section already belong to. pig satisfies
all six as written, **and the comment inside the rule they describe is wrong about two attributes**:
the entry below on inverse and strikethrough is what came out of reading them.

The other four files needed no change and the evidence is worth keeping:

- **`truncated-text.test.ts`'s nine claims all hold**, run rather than read: exact padding to the
  width at every size, one line whatever the input, the `\e[0m` before the ellipsis so the ellipsis is
  not styled with whatever was truncated, the first line only when the text has newlines, and an empty
  text still padded to width.
- **`editor.test.ts` and `markdown.test.ts` are the two files already answered by a corpus** — 1,308
  keystroke sequences against `editor.ts` and 54 documents against `marked`, both in the traps below.
  A corpus compares behaviour where the tests state intent, and where a corpus has been run the tests
  are the weaker instrument: it is how the byte offset that crashed on CJK and the two list bugs were
  found, neither of which any `it()` over there names.
- **`autocomplete.test.ts`'s four cases are the `/`-is-not-a-path rule**, which has its own entry
  below and eight regression tests.

### `ai`'s tests are all live-provider, and two of them are still a specification

Twelve files, 6,017 lines, and **every one of them needs real keys**: they are the same handful of
scenarios repeated per provider, `e2e.test.ts`'s shape one package over. So none of them is a suite
pig can run — but two state a *cross-provider invariant*, which is checkable against a canned server,
and that is where the whole batch's value was.

**`tokens.test.ts`: an interrupted turn still reports what it cost.** Anthropic and Google send usage
at the *start* of a stream, so a turn escape stopped half way has real numbers; the two OpenAI APIs
only report in the final chunk and upstream's test documents them as the exception. pig gets this
right — measured through a canned stream, `input: 1200`, `cost.input: 0.0036`, `stopReason: aborted`
— **and had no test for it**, because the existing abort case serves a stream with no `message_start`
in it, so no usage ever arrives and nothing would catch a regression. The mutation that names it is
one line in `fail()` — `$this->usage = new Usage()`, the plausible mistake of "an aborted turn
produced nothing so it cost nothing" — and it breaks **exactly one** test in 2,438: the new one. What
it would have cost is a bill that misses every interrupted turn, on the key pig is built around.
`AnthropicTest::testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived`.

**`empty.test.ts`: what goes on the wire for a message with nothing in it.** Four shapes — an empty
content array, an empty string, whitespace only, and an empty assistant message mid-conversation —
built for three providers against a canned server and read off the request:

| | anthropic | openai-completions | google |
|---|---|---|---|
| empty content array | `[]` | `[]` | `[]` |
| empty string | dropped | **sent**, `text: ""` | **sent**, `text: ""` |
| whitespace only | dropped | **sent** verbatim | **sent** verbatim |
| empty assistant | dropped | dropped | dropped |

pig matches upstream on every cell, **including where upstream's own two providers disagree with each
other**: `anthropic.ts` filters a text block on `trim().length > 0` and `google-shared.ts` and
`openai-completions.ts` do not. So a conversation of one empty message reaches Anthropic as
`messages: []` and reaches Gemini as a part holding the empty string, and which of the two is right
is not decidable from here — upstream's assertion is deliberately tolerant ("either handle gracefully
or return an error"), and only a live call would say. It is written down so nobody tidies pig's Google
path into agreeing with pig's Anthropic path and thereby diverges from both.

Worth knowing about that row: **an empty assistant message is dropped by all three, which leaves two
consecutive user messages.** That is upstream's behaviour too (`if (blocks.length === 0) continue;`),
and it is the one shape where the dropping changes the *structure* of the conversation rather than
just removing nothing.

The other ten files are live-only with nothing to extract: `abort`, `stream`, `handoff`,
`image-tool-result` and `context-overflow` are per-provider round trips (their invariants are already
entries below — the overflow table, `TransformMessages`, the Copilot headers), `image-limits`
*discovers* each provider's limits empirically rather than asserting one, `total-tokens` and `xhigh`
are the two finds already recorded against them, and `unicode-surrogate` is `Utf8::sanitize()`, whose
docblock carries its own verification.

### `test/live.php`, and what Anthropic said back

The worry the provider-SDK section states plainly — *"a bug in them shows up as tokens going missing
rather than as an error, and there is no vendor implementation to fall back to"* — is about 4,977
lines of hand-written protocol with 167 tests over it, **every one of which answers a server pig
wrote itself, from fixtures pig wrote itself.** A fixture written to agree with the code is a failure
this project has had twice: Anthropic's `message_delta` usage and `retry.maxAttempts`. So the
canned-server suite cannot settle the one question that matters most, which is not "does pig parse
what a provider sends" but **"does the provider accept what pig sends"**.

`test/live.php` is that question, as a script rather than a PHPUnit test — it costs money and needs
credentials, so it must not be reachable by a runner at all, which is upstream's `skipIf(!API_KEY)`
one step further. Keys come from `Auth::discover()` and the environment, never from an argument,
because argv is in the process list. Twelve scenarios per provider, each capped at a few hundred
tokens; the overflow case is refused before it is billed. `php test/live.php anthropic google` names
which to run, and `php test/live.php google/<model-id>` names the **model** — added the first time a
question was about one model rather than a provider, since the table's ids are a cheap default and
editing it to ask one question is how a default stops being one. What was typed wins over the table
and over `models.json`, and adds a provider neither offered. A provider name that is not one is now
said so as well — before this, a typo in the one argument this script takes printed a summary of zero
scenarios and no reason for it, and a model skipped for want of a key did not say which model it
would have used, which is exactly what somebody checking whether their `provider/id` took effect
needs to see.

**And the first real use of it hit the anchor, which is the second time.** The id asked for was
`gemini-3.8-flash` — a model that exists and that `Ai\Models`, pinned at 2026-01-02, has never heard
of, so the answer was `no such model in the registry`. True, and it sends somebody looking for a typo
in what they typed. So the message now names the cause and the route, and **the route was measured
end to end rather than asserted** — a `models.json` declaring the model under the `google` provider:

```
$ PIG_HOME=… php test/live.php google/gemini-3.8-flash
google / gemini-3.8-flash   (reasoning: yes, images: yes)
  NO  text   google returned 403: Host not in allowlist: generativelanguage.googleapis.com
$ PIG_HOME=… bin/pig --list-models gemini-3
google  gemini-3-flash-preview  1.0M  65.5K  yes  yes
google  gemini-3-pro-preview    1M    64K    yes  yes
google  gemini-3.8-flash        1.0M  65.5K  yes  yes
```

The reasoning and image columns are read off the declaration, the built-in Google rows are untouched
(`Models::register()` fills gaps rather than overwriting), and the 403 is this container's egress and
not the wiring. So "a model pig has never heard of is reached through `models.json`" is now a
verified sentence rather than a plausible one, and it holds for `bin/pig` at the same time — which is
the answer to the staleness generally, and the reason the pin stays affordable.

**The other half of that turn is a documentation rule.** The example in these three places was
`google/gemini-3-pro-preview`, and it was pointed out as already dated. It is an id the pin
*guarantees*, so the command works — but the sentence it sits in is the one telling somebody how to
reach a model the registry is too old for, and naming a perishable model there is the worst place for
one. The READMEs say `google/<model-id>`, and `live.php`'s own docblock names an id the pin carries
and says why it is not the newest one. **An id written into documentation dates the documentation**,
which is the same rule this file already applies to measured figures ("a number in this file is worth
a re-run rather than a quotation").

**Run against the real Anthropic API: twelve of twelve.** The four that only the far end can rule on
are the reason it exists:

| | |
|---|---|
| a replayed thinking signature | **accepted** — it is signed, and meaningless to anybody but the issuer, so nothing local could have told us |
| `TransformMessages`' invented `No result provided` | **accepted** — the claim that every provider refuses a dangling call, tested on one |
| the live wording of a too-long prompt | **matched** `Ai\Utils\Overflow`'s table — `prompt is too long: 300024 tokens > 200000 maximum`, caught by Anthropic's own row rather than by one of the three generic ones |
| `cache_control: ephemeral` on the last block | **acted on** — wrote 5,611 tokens, read 5,611 back on the second call |

And four that pin what earlier reads had only argued: an aborted turn keeps the usage that arrived, a
finished turn has both an input and an output count (the `message_delta` merge, live), `max_tokens`
comes back as `StopReason::Length`, and an empty conversation is refused with `400 messages: at least
one message is required` — **which is not read as an overflow**, so an empty prompt cannot set a
compaction going. That last one settles the question the canned-server read left open two batches
earlier: pig drops an empty message and sends `messages: []`, exactly as upstream does, and Anthropic
says no. Both halves were predicted; only the wording needed a call.

**Then the developer ran it on a machine with three keys**, which is the half this container cannot
reach: only `api.anthropic.com` is allowlisted here and on the desktop bridge alike, every other
provider host answering `CONNECT tunnel failed, 403`. Anthropic, OpenAI and Google, 36 scenarios plus
two handoffs, and **one real bug** — the entry below on a JavaScript index past the end, where a call
from another provider reached the Responses API with an item id it validates the shape of, refusing
the whole conversation. The two handoffs passed, which is what made it worth finding rather than
obvious: `handoff anthropic → openai` carries no tool call, so the case that breaks is the one a
conversation reaches by switching model *mid-tool-use*.

Two things the run said about pig that were not failures and are worth keeping: `gpt-5-mini` answers
an empty message rather than refusing it, where Anthropic refuses — the two providers' own choice,
reached by pig's faithful reproduction of upstream's split behaviour — and the Responses API reports
no usage before the final chunk, so an aborted turn there has none, exactly as upstream's
`tokens.test.ts` documents.

**Three of the four failures were the harness**, and all three are the same lesson from different
angles, so the fixes are commented where they are rather than quietly applied:

- **A 1×1 PNG** is a valid PNG that Anthropic refuses with `400 Could not process image` — on screen
  indistinguishable from a provider rejecting pig's image encoding. 16×16 passes.
- **A fixed 1.1MB prompt** overflows Anthropic's 200k window and fits comfortably inside gpt-5-mini's
  400k and Gemini's 1M, so two providers came back "accepted a prompt larger than its window" for a
  prompt that was nothing of the kind. Sized from `$model->contextWindow` now — and with spaced
  single letters rather than prose, because four characters per token is `Compaction`'s estimate for
  English, which is about 4.5, so sizing that way errs *under* the window and would have reported the
  wrong thing a second time.
- **A 64-token budget** on a reasoning model is spent on thinking, so the turn stops at the limit with
  no text in it and the scenario said `no text arrived as deltas` — a sentence that reads like a
  protocol fault. 512 now, and the failure message names the stop reason and the block kinds, so the
  next one cannot be mistaken the same way. (A one-token budget was the same mistake elsewhere: the
  Responses API refuses `max_output_tokens` below 16 outright.)

*A live harness can fail for reasons that have nothing to do with the thing under test, and the first
failure is worth suspecting in the harness before the port.* Three of four, on the first run.

Still untested against a real endpoint: Groq, xAI, Cerebras, z.ai, Mistral and Copilot — their
protocol code rests on canned servers alone. And none of this is a one-off: the numbers here are
worth re-running rather than quoting, which is what the script is for.

### Talking to a gateway instead of a provider

`Agent\StreamProxy` is upstream's `agent/proxy.ts`. **It is the second thing in this repository
called a proxy and it does the opposite of the first.** `Ai\Http\Proxy` carries pig's own encrypted
bytes to the provider and cannot read them; this one posts the conversation, the system prompt and
the tool schemas to a server that holds the provider keys and makes the call. For a team that does
not want keys on laptops that is exactly the point, and for anybody else it is a reason not to use
it — so nothing turns it on. It is a `streamFn`, passed in:

```php
$proxy = new StreamProxy('https://genai.example.com', $token);
$agent = new Agent(new AgentOptions(streamFn: $proxy->stream(...)));
```

The wire shape is upstream's, so a gateway written for pi serves pig unchanged: `POST
{proxyUrl}/api/stream`, `Authorization: Bearer …`, a body of `{model, context, options}`, and
events back with the `partial` field stripped to save bandwidth — the client rebuilds it.

**That rebuilding is the whole file.** `AssistantMessage` is readonly here, so there is no object to
mutate as upstream does; every event pig hands on carries a real snapshot from
`AssistantMessageBuilder`, which is the same accumulator the five providers use. Reaching into it is
a reach across an `@internal` line, and it is the faithful one: upstream imports
`pi-ai/dist/utils/json-parse.js` here with a comment saying it is an internal import, for the same
reason — a second answer to "what does a half-finished tool call look like" is one too many.

Six things that took a decision:

- **`MessageJson` moved to `pig/ai` for this.** The request body is the session file's message
  shape — upstream sends the same objects to both — and the encoder was in `coding-agent`, where
  `agent-core` cannot reach it. Copying it would have been a second hand-written notion of a
  message, which is the mistake `ToolArguments` had already made once about tool schemas; twice is a
  pattern. `SessionCodec` now keeps the three roles only pig has and delegates the rest, and went
  from 302 lines to 146.
- **The stream is read line by line, not with `SseParser`** — and not merely because upstream does
  it that way. Splitting on `\n` and treating each `data:` line as a whole event is an assumption,
  and a sound one: `JSON.stringify` never emits a newline, so an event is always exactly one line.
  Because a blank line does not start with `data:`, this reader also handles a properly framed
  stream, which makes it the **superset**; a strict SSE parser is not, because it waits for the
  blank line and joins consecutive `data:` lines into one event, so against a gateway sending events
  back to back it stalls or glues two together. The gateway's source is in neither repository, so
  which wire it sends cannot be checked, and only one of the two readers is right either way. Four
  tests hold it down: no blank lines, blank lines, CRLF, and an event split across two TCP reads.
- **Two details around that reader *are* bugs upstream, and the proof is that pi contains the same
  twelve lines twice.** `proxy.ts` tests `startsWith("data: ")` and slices 6; its own
  `google-gemini-cli.ts` tests `startsWith("data:")`, slices 5 and trims, and wraps `JSON.parse` in
  `try/catch { continue }`. The event-stream spec makes that space optional and strips exactly one,
  so the gemini one is correct and `proxy.ts` silently drops every event from a gateway that writes
  `data:{…}`. Putting upstream's rule back in pig fails one test in the ugliest possible way: the
  turn returns `stopReason: stop` with **zero content**, because the `done` line in that fixture
  happens to have a space and the content lines do not. pig takes the gemini spelling of both — one
  optional space, and a line that is not JSON is skipped rather than fatal, so a `: keep-alive`
  comment cannot kill a working stream.
- **These are the only two hand-rolled event-stream readers in pi at all.** Everywhere else the
  Anthropic, OpenAI and Google SDKs parse it, which is why upstream never wrote the parser pig had
  to write — and why `GoogleGeminiCli` here uses `SseParser` while this does not: Google's Code
  Assist frames properly and can be checked, a gateway cannot.
- **A stream that ends without `done` is a failure.** `stopReason` defaults to `stop`, so a gateway
  that died mid-sentence would otherwise be indistinguishable from a model that finished one. This
  is a divergence: upstream calls `stream.end()` and reports success.
- **An unknown `done` reason is a plain stop, and an unknown event type is ignored.** The turn did
  finish; refusing it over a word this pig does not know loses the work. Same rule as `JsonSchema`'s
  unknown keywords, applied to a wire protocol.
- **`model` goes over whole — `headers` and `compat` included, and `cost` not `pricing`.** The
  server reads the provider and api off it to decide who to call, and pig renamed `cost` to
  `pricing` internally while the wire keeps the name the server was written against. A custom
  provider's extra auth header therefore reaches the gateway; that is upstream's behaviour and it is
  written down rather than quietly trimmed, because a model whose headers pig withheld would fail at
  the gateway for a reason nobody could see.

**The usage a gateway reports is trusted as sent, cost included** — and for a while it was not.
`AssistantMessageBuilder::setUsage()` recomputed the cost from the model's public price list, which
is right for the five providers (none of them sends a cost, so the list price is the only figure
there is) and wrong for the one caller that gets one: the gateway made the call and knows what it
paid. A gateway on its own deal was reported at list price and one running flat-rate was reported as
owing money. `setUsage(…, priced: true)` keeps what arrived, which is upstream assigning
`partial.usage = proxyEvent.usage` whole. The other side of trusting the wire, stated rather than
worked around: a gateway that sends no `cost` is reported as costing nothing — upstream's own type
requires the field, so an absent one is taken at its word instead of guessed at from a price list
pig has no reason to think applies. The token *total* is still filled in when the gateway omits it,
because that is arithmetic on numbers it did send.

Not ported: `validateToolCall(tools, call)`, which has no caller upstream either, and
`ProxyAssistantMessageEvent`, which is a TypeScript type whose counterpart is the event classes in
`pig/ai`.

### An `Agent` nobody configured has a model, and it is the free one

`AgentState::DEFAULT_PROVIDER` and `DEFAULT_MODEL` are upstream's default —
`google/gemini-2.5-flash-lite-preview-06-17`, the cheapest thing Google sells, which is the only
reason a default is defensible at all: five lines of library code answer without anybody choosing a
model, and choosing an expensive one for somebody is the thing a default must not do. pig had `null`
here and threw `No model configured`, which is safer in one way and unhelpful in the way that
matters, since upstream's own tests and examples assume an agent that works out of the box.

Two constants rather than a parsed string, so there is one place to change it. **`bin/pig` never
reaches this**: `CodingAgent::session()` resolves its own model — `--model`, then `PIG_MODEL`, then
the settings, then `claude-sonnet-4-5` — and calls `setModel()` before anything is sent, so nobody's
coding session quietly goes to Google.

`$model` stays nullable and `Agent::prompt()` keeps its `No model configured`, because
`Models::find()` answers null if that id ever leaves the registry — and the honest message for that
is about configuration and not a null three layers down. Three tests that used to get "no model" for
free now clear it explicitly, which is what the state they are testing actually is: a model that was
lost, not one never chosen.

### Starting up, and why it had to stop living in `bin/pig`

`CodingAgent::session()` is upstream's `createAgentSession()`. The rest of `sdk.ts`'s 673 lines has
no counterpart and needs none: about 35 are re-exports, which an autoloader does, and about 155 are
`discoverHooks()`, `discoverSkills()`, `loadSettings()` and four more like them — thin wrappers so
an SDK user need not import from `./internal/…`. pig's loaders were public from the start, so
`ContextFiles::load()`, `Skills::load()`, `SlashCommands::load()`, `HookLoader::load()`,
`CustomToolLoader::load()`, `Auth::discover()`, `CustomModels::discover()`, `Settings::load()` and
`SystemPrompt::build()` are already the things to call.

**The remaining ~235 lines were the ones that mattered, and until this they were `bin/pig`'s
middle.** `create()` above builds the `Agent`; nothing built everything around it. So the resolution
order — which of `--model`, `PIG_MODEL`, the settings and the built-in default wins, when a thinking
level is clamped, which file `--continue` picks, what a broken hook does — existed only as top-level
script statements, and *a script that ends in `exit()` cannot be called twice*. That is the same
sentence that made `Cli\Arguments`, `Cli\ModelList` and `Cli\SignIn` classes; this is the fourth
time, and the largest.

Three rules make it testable, and they are worth keeping when it grows:

- **Nothing writes to a stream.** Warnings come back as `StartedSession::$warnings`, already worded,
  in the order they were found; whoever asked prints them. There is a test whose whole assertion is
  that a startup with a broken hook *and* a malformed skill prints nothing at all.
- **Anything fatal throws `CodingAgentError`** with a sentence worth showing as it is. `bin/pig`
  catches it, prints it red and exits 1 — the same refusal reads the same to a terminal, a
  JSON-lines host and a test.
- **What needs a screen stays with the caller.** `--resume` with no value draws a list, so
  `bin/pig` resolves that to a path first; `session()` takes a path or nothing. The theme is the
  caller's too, and so are `Auth` and the custom models — because `--list-models` prints the registry and
  exits, and it has to see a model somebody just declared without a session file being created on
  the way past.

`bin/pig` went from 539 lines to 411.

`Hooks\` is upstream's `core/hooks/`. A hook is a PHP file in `~/.pig/agent/hooks` or
`.pig/hooks` that returns a callable; the callable is handed a `HookApi` and registers what
it wants to hear about:

```php
<?php // ~/.pig/agent/hooks/no-force-push.php

use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;

return function (HookApi $pi): void {
    $pi->on('tool_call', function ($event) {
        if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', '--force')) {
            return new ToolCallEventResult(block: true, reason: 'No force pushes from here.');
        }

        return null;
    });
};
```

**`require`, not a subprocess.** That was a decision with a cost, taken deliberately. A hook
runs in this process with this process's permissions: it can hand back an object, which a
command-line hook could not, and it can be written against pig's own classes because it is
running inside pig. What it cannot be given is a timeout or any isolation. A hook that loops
does not time out; one that calls `exit()` takes the session with it. What *is* caught is
everything PHP raises as a `Throwable` — including a syntax error, which an included file
raises as a catchable `ParseError` — so a broken hook is a complaint on the shell at startup
rather than a crash. `--no-hooks` starts without loading any.

Sixteen of upstream's eighteen events are fired. `session_before_branch` and `session_branch`
are not: they are about forking a conversation into a second session file, and pig branches
inside one file instead. Subscribing to either says so rather than silently never firing —
as does a typo, since the names are a list here rather than eighteen TypeScript overloads.

**What is not here is upstream's `HookCommandContext`**, and this is the one place to look for it.
Upstream gives a slash command's handler four methods an event handler does not get —
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()` — walled off that way because
calling them from inside the agent loop deadlocks. pig has one `HookContext` for both, with
everything that can be read at any moment plus `abort()`. The four are not ported, and not as an
oversight: `branch()` is the session-file fork pig does not do, `navigateTree()` is `goTo()`, whose
answer is a summary, an abort or text for the prompt rather than a yes; and the other two would need
a second context class to be safe from the deadlock upstream avoids by having one. Two context types
so a hook command can restart the session is more machinery than the thing is worth — see the rule
at the top of this file. A hook that wants a handoff can say so with `sendMessage()` and let the
person press `/new`.

**`isError` on a `tool_result` result is honoured in one direction**, which is one more than
upstream: `true` raises, so a hook can call a successful tool's output a failure, and the verdict
still comes from the one place the loop reads it — `execute()` throwing. `false` on a tool that
*did* throw is not read, because rescuing it would be a second route to "this succeeded". Upstream
declares the field and reads neither direction, so setting it there does nothing.

Four things worth keeping straight:

- **A `tool_call` hook that throws blocks the call.** Upstream's rule and the right one: a
  hook asked whether a tool may run and answering with an exception has not said yes. The
  alternative is a `rm -rf` guard that stops working the day someone leaves a typo in it,
  which is the day it matters. Every other event catches and reports instead — a hook that
  watches is not allowed to stop the thing it watches.
- **A handler that returns the wrong type is reported, not ignored.** Treating it as "no
  opinion" turns a typo into a hook that appears to work.
- **`context` is chained and nothing else is.** Each `context` handler is given what the last
  one returned, so two hooks can both edit the conversation without knowing about each other.
  A `tool_result` handler is given the tool's own output every time, so a hook can always tell
  the tool from another hook's edit of it.
- **A hook's note goes in through `prompt()`, not onto the state.** `before_agent_start`
  returns text that becomes its own user message in front of the prompt — appended to the
  agent's messages directly it would never reach the session file, and a resumed conversation
  would be missing the thing that explained it.

`HookedTool` is `tool-wrapper.ts` as a decorator, because PHP has interfaces where TypeScript
has `{...tool, execute}`. `Process::run()` grew an optional `$cwd` so `$pi->exec()` can run a
command where the project is.

**`$pi->exec()` is on the loop**, as upstream's `execCommand` is asynchronous. It was not: it went
through `Process::run()`, which polls with `usleep()`, so while a hook's command ran there were no
keystrokes, no spinner and no redraw — a thirty-second linter was a thirty-second freeze. It and
`CustomToolApi::exec()` both go through `Process::runAsync()` now; see the trap below.

**And escape stops it**, which is pig doing something upstream cannot. Upstream's `ExecOptions`
has a `signal` field and its `HookContext` has no signal to put in it, so a hook's command can
only be waited out there. Here `Agent::signal()` answers the run in progress's — null between
runs, because the controller is cleared in `run()`'s `finally` and handing back the last one would
mean an already-aborted signal for everything asking after an interrupted turn — and the modes
pass it to `HookRunner::initialize()` the way they already pass `getModel`. It reaches `exec()`
through the **context provider** rather than as a parameter: the runner builds a `HookContext`
fresh per emit, so what `exec()` reads is the turn running now, and a signal the hook had to
remember to pass is one whose author did not. `$ctx->signal` is there too, for a handler whose own
waiting is not a subprocess. `CustomToolApi` is wired the same way, through the `withContext()`
the modes already call.

Two differences from upstream's version remain, both deliberate. **There is a timeout by default**
where upstream's is opt-in, so a hook that forgets one cannot park the turn for as long as its
command wants. And **`killed` is `ExecResult::stopped()`**, wider by two cases and saying so on
itself: a program that is not on this machine, and one escape stopped, answer the same way as one
that ran too long — where upstream reports the first as `code: 1, killed: false`.

`HookUi` is upstream's `HookUIContext`, and it is what makes `tool_call` more than a
yes-or-no rule: a handler can ask the person something and wait for the answer.

```php
$pi->on('tool_call', function ($event, $ctx) {
    if ($event->toolName !== 'bash' || !str_contains($event->input['command'] ?? '', 'rm -rf')) {
        return null;
    }

    return $ctx->ui->confirm('Let bash run?', $event->input['command'])
        ? null
        : new ToolCallEventResult(block: true, reason: 'You said no.');
});
```

**It blocks the turn, and that is the whole trick.** A handler runs inside the agent's
fiber, so awaiting an answer suspends that fiber and nothing else: the loop keeps serving
the terminal, the keystroke arrives, the deferred completes, the handler carries on where it
stopped and returns an ordinary value. This is the one thing `pig/async` exists for, and it
is why upstream can have this feature in JavaScript and a synchronous PHP port could not.

Three things that keep it from parking a session forever:

- **Every dialog can be escaped.** `SelectList` always could. `Input` could not — nothing
  pig opened had needed it, because every input was one somebody had asked for. An
  un-escapable prompt in front of a suspended fiber is a session that has to be killed, so
  `Input::setCancelHandler()` was added for this.
- **One dialog at a time.** A second `confirm()` while the first is open would take focus
  from it, leaving the first fiber waiting on a component nobody can reach. The second is
  refused with its safe answer instead.
- **Escape is a no, and so is having no terminal.** `NoUi::confirm()` returns false, which
  for a guard means a tool nobody could approve does not run. Upstream's choice, and the
  only safe direction.

All nine members are ported. `select` and `confirm` are `SelectList`, `input` is `Input`,
`editor` is a `CustomEditor` — the wrapper, not the bare `Editor`, because that is the piece
that turns escape and Ctrl+G into named keys, so they mean the same thing in the dialog as at
the prompt. `notify` is the transcript, `setStatus` is a third footer line that appears only
when something has put a line on it, and `theme` is `palette()`.

`custom()` hands a hook the `TUI`, the palette and a `done()`, and shows whatever component
it builds. An earlier note here said it was left out because it exposes the renderer to code
loaded off disk; that reasoning was wrong and is recorded as wrong — a hook is `require`d
in-process and can already call `exit()` or reach anything by reflection, so withholding
`$tui` was a lock on a door in an open field. What it does need is care: `done()` is
idempotent and always closes, a factory that returns something that is not a `Component` is
refused *and* releases the one-at-a-time latch, and a component that never calls `done()` and
ignores escape parks the turn — which nothing here can prevent, because it holds the keys.

### A hook with something to say

`sendMessage()`, `appendEntry()` and `registerMessageRenderer()` are the rest of upstream's
hook API, and the first two are opposites that are easy to confuse:

| | Where it goes | Who sees it | What it costs |
|---|---|---|---|
| `sendMessage()` | the conversation | the model, and a person unless `display: false` | context, like any message |
| `appendEntry()` | the session file only | the next run of the same hook | nothing |

`sendMessage()` makes a `Session\HookMessage` — an app message like `BashExecution`, turned
into a user message by `CodingAgent::toLlm()`. It is what a hook uses to tell the model
something the model had no way to find out: a build that just failed, a file that changed
underneath it, a rule about this repository. Its `content` goes to the model **whole** rather
than through `toText()`, because it is the only one of these whose content may be images —
which is how a hook gets a screenshot into a conversation.

`appendEntry()` makes a `Session\CustomEntry`, and the interesting thing about it is where it
is *not*: outside the tree. Every message has a parent, because going back to an earlier point
is what the tree is for, and a note about the session is not a point in the conversation anyone
could go back to. Upstream reads these with a flat scan; so does `customEntries()`.

Five things worth keeping straight:

- **A hook's message is not drawn as a user message.** It reaches the model as one, and
  showing it as one would have someone scroll back and find themselves saying something they
  never typed. `HookMessageComponent` labels it with the hook's own type instead, and the HTML
  export does the same.
- **`display: false` is honoured on screen and in the export**, and nowhere else. A reminder
  injected before every turn is talking to the model; a person who has to scroll past it every
  time stops reading the screen.
- **A note written before the first answer is held back, not dropped.** Nothing is written
  until the first assistant message, and upstream's own example is a `session_start` hook
  noting permissions — which happens before that. So the catch-up flushes held-back notes
  along with the held-back messages, interleaved by timestamp, or the file would read
  messages-then-notes and the common case would be lost entirely.
- **`triggerTurn` is a hook driving the agent.** Idle, it appends and runs; working, the
  message is queued as a follow-up and the flag is ignored, because a message between a tool
  call and its result is a request every provider rejects.
- **Calling either before a session exists throws.** A hook file is read at startup, before a
  mode has wired anything up, and a message that silently goes nowhere is a hook that appears
  to work.

`registerMessageRenderer()` is the same shape as a custom tool's `renderResult` and behaves the
same way: null means "draw it normally" and is not a complaint, a throw or a non-component
falls back **and says so**. One renderer per type, last registration wins, and a later hook
wins a clash — unlike a command clash, which is worth complaining about, two hooks claiming one
message type means one of them is drawing messages it did not send.

A compaction names it — `[Hook build]: …` — rather than folding it in as a user message, or a
summary would read "the user said the build is broken" and send the model looking for a
conversation that did not happen.

`BeforeAgentStartEventResult` still carries text rather than a `HookMessage`: upstream's is one,
and the part that survives into the conversation is the part that reaches the model.

**Nothing of upstream's hook API is left out now.**

**Each mode wires its own UI**, which is upstream's rule too. `InteractiveMode` builds the
`TerminalUi` and calls `HookRunner::initialize()` and `CustomToolSet::withUi()` itself;
`bin/pig` loads and constructs but wires none of it, because it has no screen to draw a
dialog on and a second mode will have a different one.

`CustomTools\` is upstream's `core/custom-tools/`, on the same loader. A tool lives in a
folder of its own — `~/.pig/agent/tools/<name>/index.php`, or the same under `<cwd>/.pig/tools`
— because a tool is likelier than a hook to want a second file beside it, and a folder is
where that goes. The file returns a factory; the factory returns a `CustomTool` or a list
of them:

```php
<?php // ~/.pig/agent/tools/wc/index.php

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolApi;

return fn (CustomToolApi $pi) => new CustomTool(
    name: 'wc',
    label: 'Count lines',
    description: 'Count the lines in a file.',
    parameters: [
        'type' => 'object',
        'properties' => ['path' => ['type' => 'string', 'description' => 'the file']],
        'required' => ['path'],
    ],
    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
        [new TextContent(trim($pi->exec(['wc', '-l', $params['path']])->stdout))],
    ),
);
```

Upstream's `CustomTool` is an object literal with five fields and three optional callbacks;
the PHP equivalent of an object literal is a value object built from closures, and the
developer chose that over an interface to implement. `WrappedCustomTool` turns one into an
`AgentTool` — upstream's `wrapper.ts` — and the one thing it adds is the session: `execute`
is given the same `HookContext` a hook gets, which is the reason to write a custom tool
rather than a built-in one. It can read the conversation, see which model is answering, and
stop the run.

Five things worth keeping straight:

- **The declaration validates itself.** A nameless tool, a tool with no description, a
  `parameters` that is not a JSON Schema object — all refused in the constructor, which the
  loader turns into a complaint naming the file. Left to the provider, a bad name fails the
  whole request rather than the tool, and a missing description is a tool the model can
  never choose.
- **A tool may not take a built-in's name.** `bash` is described in the system prompt; a
  custom tool answering to that name would be called in its place by a model that was told
  something else. Named, not dropped, because only the author can fix it.
- **Custom tools are wrapped with the built-ins, not after them.** `CodingAgent::create()`
  concatenates first and hands the lot to `HookedTool::wrap()`, so a `tool_call` hook guards
  a tool somebody wrote exactly as it guards `bash`. A guard that covered only the built-ins
  would not be a guard.
- **The session context is a closure, not a value.** `/model` replaces the model mid-session,
  and a tool that asked which one is answering must not be told about the one that was
  replaced.
- **The UI arrives after loading, not during it.** A factory runs at startup, before there
  is a screen; `CustomToolApi::ui()` answers as `NoUi` until the mode calls `withUi()` on the
  one shared API object every factory closed over. That is why `bin/pig` constructs the API
  itself and hands it to both the loader and the set.
- **`onSession` is fired from the interactive mode, not from the session.** All four moments
  a tool hears about — startup, `/new` and `/resume`, `/tree`, quitting — are things someone
  did in the UI, and the UI is the only place with somewhere to report a callback that
  failed. Routing them through the hook runner was the other option and was worse: `/hooks`
  would then list tool files as hooks. Upstream's fifth reason, `branch`, is the fork into a
  second session file that pig does not do.

`--no-tools` loads none of them. Settings name extra entry files under upstream's key,
`customTools`. The system prompt's "Available tools" list stays the built-ins, as upstream's
does: a custom tool reaches the model as a tool definition, which is the part that matters.

**`~/.pig/agent/tools/` holds two unrelated things**, and that is upstream's doing rather than a
choice made here: `ToolInstaller` downloads `fd` and `rg` into it, and custom tools live in
it too. They are told apart by shape — a downloaded binary is a file, a custom tool is a
folder with an entry file — and the discovery glob is `*/index.php`, so neither sees the
other. Worth knowing before wondering why `ls ~/.pig/agent/tools` shows a mixture.

`renderCall` and `renderResult` are ported too: a tool hands back a `Pig\Tui\Component` and
`ToolExecutionComponent` draws that instead of its own tool view — which is what stops a tool
whose result is a table from being shown through formatting built for files and commands. Four
things about them:

- **Two independent halves.** A tool may draw its heading and leave the result to the default
  text, or the other way round. `drawsItself()` is true if either is set, and each falls back
  on its own.
- **A renderer that throws falls back *and says so*, once.** Upstream catches and silently
  draws the default; a picture that quietly turns into plain text is a bug nobody reports. Once
  per call, because `draw()` runs again on every update and a broken renderer would otherwise
  fill the transcript with its own failure.
- **Returning null is not a failure.** "Nothing to draw here" is a legitimate answer and gets
  the default without a complaint; returning something that is not a component is a complaint.
- **`renderCall` gets the arguments as they stand**, which mid-stream may be half a JSON
  object. Every field is therefore optional to the renderer, exactly as it is to the built-in
  headings.

`CustomToolAPI.ui` is ported as `HookUi`, in both `$pi->ui()` and the context handed to
`execute`. Nothing of upstream's custom-tool module is left out.

`Extensions\` is upstream's `core/extensions/`. An extension is a PHP file (or a folder with
`index.php`) in `~/.pig/agent/extensions`, `<cwd>/.pig/extensions` or `<cwd>/extensions` that
returns a callable. It unifies what used to be split between hooks and custom tools: the callable
is handed an `ExtensionApi` (which inherits `HookApi`) and can register commands, tools, event
handlers and message renderers all from a single file or directory.

`extensions/pig-antigravity/index.php` is the first ported full extension, bringing Antigravity's
quota monitoring (`/antigravity.usage`, `/antigravity.models`), account management
(`/antigravity.accounts`), doctor diagnostics (`/antigravity.doctor`), model catalog refresh
(`/antigravity.refresh`), image command (`/antigravity.image`), and the `generate_image` agent tool.
All extensions in pig are pure PHP implementations without Node.js or npm dependencies.

`extensions/pig-web-search/index.php` provides real-time web search via DuckDuckGo HTML scraping
(`web_search`), readable page text extraction (`fetch_web_page`), and `/search <query>`. Future
headless browser or Puppeteer / CDP scraping extensions belong in this extension, preserving core
agent minimalism.

### MCP servers, and the tool that arrives after startup

`packages/mcp/` is upstream's `pi-mcp` package (`Pig\Mcp`) and `extensions/pig-mcp/` is its
built-in `mcp` extension, both ported file for file; `docs/mcp.md` upstream is the specification
for what the config file means. Two things are pig's own and both are consequences of the platform:

- **`codemode` is ported, in PHP.** This bullet used to say it could not be, because upstream's
  is a QuickJS VM in wasm and PHP has no wasm runtime. That was true of the *sandbox* and not of
  the *feature*, which is "the model writes code that calls tools and filters the results, and
  only what it keeps reaches the model". See the section below.
- **`deferred` has no loadout under it.** Upstream keeps an active set per tool and records a
  `tool_search` load in the transcript, so a loaded tool survives `/tree` and resume. pig has no
  such record: a deferred tool is simply **not registered** until `tool_search` names it, then
  registered like any other, through the same `adopt()`/`onChange()` door a connecting server uses.
  The cost is that a resumed session starts with the deferred tools unloaded and the model searches
  again; the gain is no second copy of what the agent's tools are. `ToolSearch` is upstream's
  `tool-search/tool.js` arithmetic for arithmetic — tokenizer, stemmer, Okapi BM25 — and the one
  thing worth knowing about it is that **`GitHub` tokenizes to `git hub`** there too (every
  lower-to-upper boundary splits), so a server named `github` is found by its name and not by the
  word inside a camelCased tool.
- **A tool loaded mid-run has to reach the *next request of that run*.** The first live try had the
  model call `tool_search`, be told `Loaded 8 tools. They are available from your next call`, call
  one, and get `Tool mcp__fs__read_text_file not found` — five times, then give up and read pig's
  source with `bash` to find out why. `AgentLoop` took a snapshot of the tools when the run started;
  `Agent::setTools()` changed the state and not the snapshot. `AgentLoopConfig::$getTools` is asked
  before every model call now, which is where upstream refreshes `context.tools` from
  `agent.state.tools` in `prepareRequest`. `AgentTest::testAToolRegisteredDuringARunReachesTheNextRequestOfThatRun`
  is the mutation's one kill.
- **A tool that arrives after startup needed a door the loader did not have.** `ExtensionLoader`
  copied `$api->tools()` once; an MCP server's tools exist only once it has connected, in a fiber,
  later. So `ExtensionApi::registerTool()` notifies after load and `removeTools()` is beside it,
  `CustomToolSet::adopt()` follows one extension's list, and `onChange()` is what re-sets the
  agent's tools — in `CodingAgent::session()` and in `InteractiveMode::reload()`, the two places
  that build the set, with the built-ins and `HookedTool::wrap()` exactly as at startup. That last
  clause is what makes the permission gate reach an MCP tool, and it was watched doing so.

**And the first real server found a provider bug of its own.** The TypeScript MCP SDK writes
`"$schema": "http://json-schema.org/draft-07/schema#"` on every tool; Gemini's `parameters` is an
OpenAPI 3.0 schema and answered `Unknown name "$schema"` for every request — a 400 for the whole
session from the moment the server connected. Upstream has `sanitizeForOpenApi()` in
`google-shared.ts` and it had never been ported, because no built-in tool carries a
meta-declaration. The lesson is the one about fixtures that agree with the code: every tool schema
in pig's tests was written by pig. Regression test:
`GoogleTest::testJsonSchemaMetaDeclarationsAreStrippedFromAToolsParameters`.

**The manager is one component whose contents are swapped**, and its `menu()` parks the caller on
a `Deferred` the way every hook dialog does — which is what lets `$manage` read as upstream's loop
over menus (`for (;;) { name = await ui.menu(servers); for (;;) { action = await ui.menu(server) } }`)
with no state machine. A menu rebuilds in place when a server's state changes: `ServerConnection`'s
`onChange` fans out to whoever `subscribe()`d, and `menu()` keeps the selection where it was so a row
changing under the cursor does not move it. Two things found by driving it through a `FakeTerminal`:
the tool is back *before* the menu is redrawn when a server is re-enabled, so a key typed in that
window lands on a status screen that takes none (the test waits for the menu, not the tool); and
`registerTools()` diffed against the names it owned rather than the names it had declared, so
switching a connected server to `deferred` left its tools on the model — the Fixed entry above.

**OAuth is `Pig\Mcp\Oauth` plus `McpOauth`**, split as upstream splits `pi-mcp/oauth` from
`extensions/mcp/oauth.js`: the package knows the protocol (discovery, registration, the flow, the
callback server, the provider's state record) and the extension knows the file, the connection
and the dialogs. Four things decided on the way:

- **`OauthCallbackServer` is not `Pig\Ai\Utils\Oauth\CallbackServer`.** That one's port is a
  constant registered with Google; this one listens on whatever is free, because the redirect URI
  is registered with each MCP server's authorization server at sign-in, and matches a callback to
  the sign-in waiting for it by `state`. Two servers for two different facts.
- **The pasted-URL prompt races the callback**, which is why `HookUi::input()` grew a `$signal`:
  without one the dialog stays open after the browser already answered, and in the first live run
  it did. `NoticingUi` parks on the signal for the same reason — a test UI whose `input()` answers
  null at once would read as "the person cancelled" before the browser got a turn.
- **A 401 is `needs-auth` only for a server that *uses* OAuth** — an HTTP server with no
  `Authorization` header of its own. One *with* a header that answers 401 has a wrong header, and
  saying "sign in" would send somebody to a browser for a typo.
- **The cross-process refresh lock is not ported.** Upstream takes `proper-lockfile` for it; pig
  takes no dependency, and the cost of two pigs refreshing one rotating token at the same instant
  is the cost a lost lock has there: one of them signs in again.

**The resource tools are one set with one exposure**, upstream's rule: the widest among the
servers that have resources. With pig's deferred-is-not-registered arrangement that means
they go into `$deferred` under a pseudo-source `mcp resources` when no resource server is
direct, and `tool_search` lists that source beside the servers. The thing to keep straight is
the **order inside `registerTools()`**: `syncResourceTools()` runs after the server's own tools
and before `syncToolSearch()`, because the first decides whether `tool_search` has anything to
offer and the second registers it — swap them and a deferred resource-only server has no
`tool_search` until the next change.

**Nothing of upstream's MCP extension is left out now**, codemode aside, and the live checks were
`server-filesystem` (stdio, tools), GitHub (streamable HTTP, 46 tools with a header; OAuth
discovery to the DCR refusal without one) and `server-everything` (resources, templates, the log).

**And a copying mistake cost an hour of looking in the wrong place.** The live run after
resources landed had the model say `list_mcp_resources` was not a tool it had, and every probe of
the code said it was registered. The extension loaded in that run was the copy under
`~/.pig/agent/extensions/pig-mcp/`, three files stale — `cp -r extensions/pig-mcp dest/` had been
the sync and it does not replace a directory that exists. `rsync -a --delete` is the sync now.
*When the code and the behaviour disagree, check which code ran before reading either again.*

**GitHub's MCP server cannot be signed into with dynamic registration**, verified live:
`github.com/login/oauth`'s metadata has no `registration_endpoint`, so `pig mcp login gh` says
`Authorization server does not support dynamic client registration` — correctly — and the way in
is a pre-registered client in the `oauth` block, or a token in the header. Upstream's docs say the
same; worth knowing before reading the refusal as a bug.

`pig mcp` is `McpCli`, beside the extension and required by `bin/pig` from there, because what it
reads and writes is the extension's file and nothing in a package knows the shape. Its tests found
the final-method trap again — `private function run()` in a test class, two entries below this
one — which is worth the sentence: *the sweep in the shim refuses it now, and I wrote it anyway.*
And then `status()` in the next test file. The names PHPUnit owns that read as natural test-helper
names: `run`, `status`, `output`, `any`, `count`, `name`, `result`, `toString`.

`McpExtensionTest` drives the whole thing through `ExtensionLoader::load()` with the stdio fixture
server (`packages/mcp/test/fixtures/stdio-server.php`): session start, the ten-second wait, a
server that fails, the startup report, a call through the connection, `/mcp`, a deferred tool
appearing only after `tool_search` finds it, and shutdown leaving the loop idle. Two of its first assertions were guesses that the code refuted — the middle cut's
byte count and a server with no exposure named reporting `(deferred)` — which is the usual cost of
asserting before measuring.

Audited difference by difference against `wrapper.ts`, `types.ts` and `loader.ts`. `wrapper.ts` is
`WrappedCustomTool` line for line, argument order included. What the rest found is one bug — the
`-p` context, below — and a row of places where pig is stricter with a reason already written
beside it: the declaration validates itself where upstream accepts a nameless tool with no
description, `hasUi` is derived from the UI rather than passed beside it as a second fact that can
disagree, and the dedupe is on `realpath()` where upstream's is on `path.resolve()`. Two things
were **checked rather than assumed** and turned out fine: `glob('*/index.php')` does follow a
symlinked tool folder, and `glob('*.php')` does match a symlinked hook file — upstream tests
`isDirectory() || isSymbolicLink()` explicitly, so the question was whether pig's globs quietly
skipped what that clause exists to catch. They do not. Left out on purpose: upstream's
`isBunBinary` branch, which is a second loader for a packaging mode pig has no equivalent of, and
`setUIContext`'s `hasUI` parameter for the reason above.

### codemode: the model writes PHP, and a child `php` runs it

`packages/codemode/` (`Pig\Codemode`) is upstream's `pi-codemode` and `extensions/pig-codemode/` is
its `extensions/codemode`, with three substitutions that follow from the one fact that PHP has no
wasm runtime and will not take one as a dependency:

| upstream | pig | why |
|---|---|---|
| the script is JavaScript in a QuickJS VM in a `worker_thread` | the script is PHP in a child `php -n` | the only sandbox PHP can make without a dependency is a process |
| `declare const tools: { read(input: {...}): Promise<string> }` (TypeScript) | `$tools->read(array{path: string} $input): string` (PHPStan shapes) | the notation PHP code already carries in its docblocks |
| `await Promise.all([...])` | `parallel([...])`, each arm a `Fiber` in the child | no `await`; the arms suspend on their first tool call and the driver sends every call before reading any answer |

**What the isolation is, in one paragraph, because it is the thing to be honest about.** `Sandbox`
starts the child with `-n` (no `php.ini`), `disable_functions` naming every process, file, stream,
socket, network and host-reading function in core and the usual extensions, `open_basedir` pointing
at the child script alone, a 256MB `memory_limit`, `allow_url_fopen=0`, and an empty `PATH`.
`eval` and `include` are constructs and cannot be disabled — the child uses `eval` to run the script,
and `open_basedir` is what stops `include`. That is a **blacklist**, and upstream's wasm is a
whitelist: a function the list forgot is a function the script can call. It is a sandbox for a
model's mistakes and not for an adversary's, and `DISABLED_FUNCTIONS` is where the next row goes.
Measured rather than argued: `file_get_contents`, `shell_exec`, `getenv`, `fsockopen`, a variable
function name, `SplFileObject`, `include` and `ini_set` are all refused, by `undefined function` or
`open_basedir restriction`; the ordinary language is all there.

**The wire is one JSON line per message**, both ways: `boot`, then `call`/`global`/`output` from the
child and `result` from the host, then `done`. The host answers each `call` in an `Async::spawn` of
its own, which is what makes `parallel()` parallel on the host side; measured, three 0.2s tools in
0.2s. The child's side of `parallel()` is `__CodemodeParallel`: an arm that calls a tool does
`Fiber::suspend($id)` with the request already written, the driver starts every arm, then reads
answers off the pipe and resumes whoever waits for each. An arm that calls two tools in sequence has
its second call made after the first answer, like `await` inside a `Promise.all` arm. **Keys are
kept** — `parallel(['a' => …, 'b' => …])` answers under `a` and `b` — which `Promise.all` has no
case for and the first live script written against this used.

**`exit_script()`, not `exit()`.** PHP's `exit` would end the sandbox with no `done` on the pipe,
which the host reads as a crash. The rest of the helpers keep upstream's names: `text()`, `image()`,
`store()`/`load()`, `ALL_TOOLS`, `search_tools()`, `describe_tool()`; `echo` is `console.log`.

**Nested calls go through the hooked tool**, so the permission gate reaches `bash` from a script
exactly as from the model — `testANestedCallGoesThroughTheToolCallHookLikeAnyOther` runs a `tool_call`
guard against `$tools->bash()` inside `parallel_settled()` and reads the refusal out of the arm. A
`codemode`-exposed MCP tool is the exception by design: it is **not on the agent at all** (the model
must not see it), so the MCP extension puts it in `Pig\Codemode\Registry` and the codemode tool
calls it from there. The registry is static for `Models::register()`'s reason — two extensions in
either load order have to see one list — and registering into it is what activates codemode, which
is upstream's `ensureDiscoveryActive()` with the active-set machinery pig does not have.

**A tool with something to say in the system prompt can say it now.** Upstream's `promptSnippet`
and `promptGuidelines` were not ported — the paragraph on custom tools above says "the system
prompt's Available tools list stays the built-ins" — and codemode is the tool that needs them:
without "Use codemode to batch or chain several tool calls…" in the Guidelines, a model knows the
tool exists and issues one tool call at a time anyway. `CustomTool::$promptSnippet`/`$promptGuidelines`,
`CustomToolSet::promptContributions()`, two parameters on `SystemPrompt::build()`, and the prompt is
rebuilt in the same `onChange()` that rebuilds the tools.

**Two things the first live runs taught.** The model reads `$res['content'][0]['text']` *and*
`$res['structuredContent']['content']` and does not know which the server fills, so a script
receives the whole `CallToolResult` (upstream's rule for a tool with an output schema) and the
declaration says so. And the second script it wrote keyed its `parallel()` arms by path, which is
the keys bullet above.

**`AgentError` carries `$details`** so a failed script still shows the UI its nested calls — upstream
returns `{ isError: true, details }` where pig throws, and the renderer needs the calls either way.

**And `HookRunner::setSession()` had no caller.** `$ctx->session` was null in every handler in
production, which is how long a documented `HookContext` field can stay wired at one end: until the
first extension that needs it. `AgentSession` attaches itself now.

Regression tests: `CodemodeTest` (24 — source, identifiers, declarations, the sandbox's isolation,
`parallel()` timing and ordering, error kinds, store, images, globals) and `CodemodeExtensionTest`
(7 — through `CodingAgent::session()` with both extensions: activation by server and by setting, the
system prompt, a script against an MCP tool, the hook guard, a failed script's error, the store in
the session file, the output budget). Verified live with `server-filesystem` at the default
exposure: the model sees `read, bash, edit, write, codemode`, writes a `parallel()` over four
`read_text_file` calls, and answers from the script's return value.

### `/bug` read a property the class never had, and nothing could have said so

Reported with the screen: `PHP Warning: Undefined property: InteractiveMode::$terminalUi` the moment
`/bug` was typed. The field is `$ui`; `reportBug()` and `handleCommitCommand()` both read
`$this->terminalUi`, and the confirm they call on it was then a method on null inside a spawned
fiber, where the only thing on screen is PHP's warning. **The one command that exists for reporting
a fault was the one command no test typed** — `BugReportTest` drives `BugReport::build()` and
`write()` directly, which is the "two ends tested, wire untested" shape this file keeps naming.

Two fixes. The test, `InteractiveModeTest::testSlashBugAsksBeforeItWritesAnything`, types the command
and asserts the question is on screen and PHP's warning is not. And **`test/lint.php` now sweeps for
the class of mistake**, because `php -l` parses and does not resolve properties, exactly as it does
not resolve function names — the lint already carries a list of 8.4-only functions for that reason,
and this is the same gap one token over. `undefinedPropertyReads()` walks PHP's own tokens (the first
version was a regex and lost the rest of `SignIn.php` at a `'` inside a docblock): every
`$this->name` not followed by `(`, against every visibility-keyword-then-variable declaration,
promoted constructor parameters included; classes that `extend` anything or define `__get` are
skipped, because an inherited or magic property cannot be seen from one file. Over 564 files it
found this one site and nothing else, costs nothing measurable beside the per-file `php -l`, and
putting the typo back turns it red.

### A closure read `$pi` it never captured, and the warning was swallowed by its own `catch`

Reported as `PHP Warning: Undefined variable $pi in …/smart-session.php on line 486`. Every
extension handler is `static function (…) use (…)` — the shape `/reload` requires — and `$pi`
exists only in the file's outermost closure, so a handler that reads it without `use ($pi)` is
reading nothing. Two sites: `session_start` read `$pi->getSessionName()` (null, so the default
branch ran and nobody noticed) and the auto-namer called `$pi->setSessionName($title)` inside a
`catch (Throwable) { /* Silent fallback */ }` — **so the warning printed and the title was never
set**, which is a feature failing on the exact line that made it worth having. Both are `$ctx->…`
now; `HookContext` had the two methods for precisely this.

**`uncapturedClosureReads()` in `test/lint.php` is the sweep**, one token over again from the
property check: for every closure, a variable read in its body that is not a parameter, not in its
`use` list, not `$this` (unless the closure is `static`), not a superglobal, and not assigned
anywhere in that body is reported with its line. "Assigned" is `=` and the compound operators,
`foreach … as`, destructuring, `catch (T $e)`, `static`/`global`, a `use (&$x)` on a nested closure
(by-ref capture springs into existence as null), and **a variable whose first appearance is as a
call argument** — `preg_match($re, $s, $m)` and `exec($cmd, $out, $code)` assign through a
reference and nothing outside the callee says so. That last rule is the deliberate blind spot: a
plain `f($undefined)` is not flagged. Arrow functions are skipped (they capture the enclosing
scope); nested closures are each checked in their own scope.

Over 618 files it found the one site and nothing else — after three rounds of false positives
that were each a syntax shape the first version did not know: `catch (\Fully\Qualified $e)`
tokenizes as `T_NAME_FULLY_QUALIFIED` and not `T_STRING`, `foreach ($rows as [$s, $e])` puts the
targets inside brackets, and a nested `use (&$acc)` is an assignment and not a read. Putting the
`$pi` back turns it red on line 486. **`lint.php` now also takes directories on the command line**
— `php test/lint.php ~/.pig/agent/extensions` — because the extensions a person wrote load into the
same process and fail the same ways, and `extensions/` in the repository is swept by default; it
was not before, which is how three `@` suppressions had survived in it.



Reported as *"任务还没完成，但是总是偶发触发系统通知"* — the desktop notification extension firing
mid-task, intermittently. Intermittent because it fired **once per failed attempt**: on a turn that
hit a 429 and retried, every attempt ended a run, and `agent_settled` went out beside `agent_end`.

`tellHooks()` emitted both on `AgentEndEvent`, with upstream's two names and pig's one moment.
Upstream's are two moments: `agent_end` is the end of a *run* and comes from the agent loop;
`agent_settled` comes from `_emitAgentSettled()`, after `_runAgentPrompt()`'s `while` loop over
retries and continuations has nothing left to do, and the docs call it "final and notification-only;
use it when an integration needs to know Pi will not continue automatically". pig's equivalent of
that loop is `afterTheRun()` → `inTheBackground()` → `finishBackgroundWork()`, and the last of those
is already the one place every ending of a prompt reaches — a clean run, the last retry, a retry
escape stopped, a compaction that worked or did not — because `prompt()`'s `$settled` handle is
released there. So that is where `agent_settled` goes out, before the handle is released so a `-p`
exiting on `prompt()`'s return has let the hook run.

Two things worth keeping:

- **Two event names emitted from one line are one event.** The port had both names, which is what
  the audit's name-by-name sweep checks, and the difference between them was the *when*, which it
  does not. A hook listening on either got the same thing, so nothing could tell them apart until
  something that cared about the difference — a notification — listened.
- **The user's own extension was on `agent_end`**, which is the right event for "a run ended" and
  the wrong one for "the task is done", and pi's own `system-notify.ts` is on `agent_settled`.
  Both `system-notify.php` and `smart-session.php` are on `agent_settled` only now; the latter had
  been on both and ran its title generation twice per prompt.

Regression tests: `AgentSessionTest::testAgentSettledFiresOnceAPromptAndNotOncePerAttempt` — three
runs, one settle, red on the old emit — and `testAgentSettledStillFiresWhenNothingWasRetried`, so the
fix cannot become "only after a retry".

### What pi 1.0.0 changed about codemode and MCP OAuth, and the one thing left out

The anchor is `d0a4c37`, and codemode and MCP were ported from upstream's HEAD at the time, so
upstream's 1.0.0 release notes were read against the port for the three items that touch it. Taken,
with the arguments:

- **The lean description.** pig's `codemode` description spelled every helper out — ~830 tokens
  before a single nested tool was listed — where upstream 1.0's is the intro, one line per global,
  and a pointer to `docs/codemode.md` "which the model reads when it needs it". pig has no `docs/`
  in an install, so the reference is `extensions/pig-codemode/CODEMODE.md` and the description
  names its absolute path. ~300 tokens now; with two MCP tools listed 929 → 395. The guideline and
  the snippet are upstream's shorter ones, and the model still wrote a keyed `parallel()` script on
  the first live try. **A description is read on every request and a reference is read once**, which
  is the whole of the argument.
- **Recovery errors.** `__CodemodeTools::__call()` names the close matches the way upstream's
  `guard()` proxy does — exact after case and punctuation are stripped, then containment, then the
  whole list when it is short. The store's size errors say what the store is for. And writing the
  test for the store found that **`RangeError` is not a PHP class**: both limits threw
  `Class "RangeError" not found` on the day they fired, which no test had reached. `LengthException`.
- **MCP OAuth.** Credentials keyed `mcp__<name>|<url>` with the URL-only legacy entry taken over by
  the first server that loads it (`stateOf()` moves it under the new key, `tokens()` only peeks,
  `remove()` takes either); the RFC 9207 `iss` check in `Flow::run()` before the code exchange, with
  a metadata flag `authorization_response_iss_parameter_supported` making a *missing* `iss` a
  refusal too; `Flow::stepUpScope()` so an `insufficient_scope` sign-in asks for granted plus
  missing, and `withScope()` recording the requested scope on a token response that names none
  (RFC 6749 §5.1), without which there is nothing to keep; `optionalString()` reading `''` as
  absent; and `oauth.authServerMetadataUrl`, read every time and never cached.

**Left out: `models.generateImages()`**, and the reason is scope rather than difficulty. Upstream's
is a `models` namespace in the sandbox over a model catalogue with a `type: image` column, run with
the session's credentials through OpenRouter — a provider pig deliberately does not carry — with the
usage added to the tool result. pig has no `type` on a model, no OpenRouter and no image-generation
protocol; what it has is `generate_image` in `pig-antigravity`, an ordinary tool a script already
reaches as `$tools->generate_image([...])`. So the feature as a *capability* is there by another
door, and the feature as an *API* is a new provider plus a registry column plus a sandbox namespace.
The developer's call was to take the three above and decide this one on its own — this entry is
where to start if it is ever wanted, and the first question is whether pig carries OpenRouter.

Regression tests: `OauthTest` (seven new — the `iss` triple, scope recorded and empty, `stepUpScope`,
the metadata URL), `McpExtensionTest::testAStepUpSignInAsksForTheGrantedScopeAsWellAsTheMissingOne`
(red on the old merge), `CodemodeTest::testAToolThatDoesNotExistNamesTheCloseMatches` and
`testAnOversizedStoreValueSaysWhatTheStoreIsFor`, and the description-length assertion in
`CodemodeExtensionTest`.

### A stranger's `.pig/` is not loaded until somebody says so

`ProjectTrust` is upstream's `trust-manager.ts` + `project-trust.ts`, and the reason it exists is a
sentence this file already had: *"a hook runs in this process with this process's permissions …
one that calls `exit()` takes the session with it."* True of `~/.pig/agent/hooks`, which the person
wrote — and equally true of `<cwd>/.pig/hooks`, which whoever pushed to the repository wrote. Until
this, `git clone` and `cd` were the whole distance between a stranger's PHP and `require`.

The shape, all of it upstream's: `~/.pig/agent/trust.json` keyed by absolute path, **nearest
ancestor wins**; a project with no `.pig/` resources is trusted without a question; a saved answer
wins; otherwise ask; and with nobody to ask — `-p`, `--mode json|rpc` — **the answer is no.** The
last is the only safe direction and the one worth stating: a script on a stranger's repository must
not run that repository's hooks because there was no terminal to refuse on.

Four things pig's arrangement decided:

- **It is asked before `Settings::load()`**, because `.pig/settings.json` is one of the six things
  being asked about (`shellPath` names what every command runs in). So `bin/pig` resolves it first
  and the prompt's palette comes from the person's own settings alone — `Settings::load($cwd,
  projectTrusted: false)` — since the project's are exactly what is not yet allowed in.
- **The prompt is `Cli\TrustPrompt`, a `SessionPicker`-shaped screen** started and stopped inside
  the call, because the question gates what `CodingAgent::session()` may `require` and so cannot be
  asked from inside the session it gates. Upstream asks through its extension UI context during a
  two-pass bootstrap; pig has no screen at that point and this is the one.
- **One flag, five loaders**: `projectTrusted` on `Settings`, `HookLoader`, `CustomToolLoader`,
  `ExtensionLoader` (both of its project roots) and `SlashCommands`, plus `Skills`' `project` root
  through the switch it already had. Each one *does not open* the directory, rather than reading
  and discarding — a broken `.pig/settings.json` in an untrusted project is not even a complaint.
  `ProjectTrustGatesTest` has one case per loader, because a gate on four of five is the first
  shape from the index, and a single assertion over all five cannot say which one is open.
- **Not gated**: `AGENTS.md`/`CLAUDE.md` (text the model reads, not code pig runs — upstream does
  not gate them either), `.claude/skills` (another tool's folder, and gating it would prompt in
  every repository that has one), and `--list-models`/`--export`, which print and exit without
  loading anything of the project's and so are not asked.

`/trust` saves a decision for *next* time and says to restart: what was `require`d cannot be
un-required, and what was left out cannot be loaded half way through a session. The "this session
only" choices exist at startup and are filtered out there, because this session is already decided.
No file lock where upstream takes one through `proper-lockfile`: the write is a rename, so a reader
never sees half a file, and two pigs saving in the same instant is a race over one JSON object that
the later write wins.

Regression tests: `ProjectTrustTest` (16), `ProjectTrustGatesTest` (6), `TrustPromptTest` (3),
`CodingAgentSessionTest::testAnUntrustedProjectWithResourcesSaysSoAndLoadsNoneOfThem`, and the two
`/trust` cases in `InteractiveModeTest`. Verified live: `pig -p` in an undecided project with a
hook prints the warning on stderr, the answer on stdout, and loads no hook.

### Picking a failed turn back up

A turn can fail for a reason that undoes itself — the provider is busy — or for a reason the
*next* request can fix: the conversation outgrew the window. `AgentSession` handles both,
after the run ends, from `afterTheRun()`.

Which one it is, is decided by what the provider said, and the two are mutually exclusive on
purpose. `Retry::worthRetrying()` asks `Overflow::happened()` first and answers false for an
overflow however retryable the rest of it looks — a 429 that says "prompt is too long" is a
429 that will say it again in four seconds.

**`Session\Retry` reads the status code, where upstream reads the prose.** Upstream matches
the error message against `/overloaded|rate.?limit|429|500|.../i` because its providers word
failures however they like. All four of pig's providers write
`"<provider> returned <status>: <message>"`, so the number is right there — 408, 429, 500,
502, 503, 504 and 529 are waited out, and everything else a provider returns is about the
request, which will not change by being sent again. The word list survives underneath for the
failures that never reached HTTP at all: a socket that died mid-stream has no status to read.

**`Ai\Utils\Overflow` is a table of what each provider actually says**, ported from upstream's
`ai/src/utils/overflow.ts` with its examples kept as comments. There is no status code for
"too long" and no field in any response that says so; every provider says it in prose, two say
it with an empty 4xx and no body at all, and z.ai does not say it — it accepts the oversized
request, answers, and bills for more input tokens than the window holds, so the only evidence
is the usage report. **A pattern with no example beside it is a guess**, and a guess here
compacts a conversation that was fine.

The four events are `RetryStartEvent`, `RetryEndEvent`, `AutoCompactionStartEvent` and
`AutoCompactionEndEvent`, and they implement `Pig\Agent\AgentEvent` so they reach the
listeners that are already there — `AgentEvent`'s docblock says why an event from above the
loop is on the loop's stream. Each mode draws them as it likes: the terminal turns a retry into
a loader that names the error and counts down, `--mode json` and RPC encode them through
`RpcEvents`, `-p` ignores them.

Five things that are load-bearing rather than tidy:

- **The failed message comes off the agent's state before the retry**, and stays in the session
  file. Leaving it would put "Anthropic returned 503" in the transcript for the model to read
  and try to make sense of.
- **Everything spawns.** `afterTheRun()` runs inside the agent's own event fan-out, and
  `continue()` starts another run — doing that there would re-enter the agent from inside its
  own notification, and the sleeping cannot happen in a callback at all. Upstream reaches for
  `setTimeout(..., 0)` "to break out of the event handler chain"; `Async::spawn` is the same
  idea with a name that says why.
- **The sleep is abortable**, because eight seconds that escape cannot reach is eight seconds
  of a terminal that will not answer. A timer and an abort listener race to complete one
  `Deferred`; the timer is cancelled on an abort rather than left to fire into nothing, because
  a pending timer keeps `Loop::isIdle()` false and `bin/pig` would not exit.
- **`prompt()` waits for a retry in progress.** A sleeping retry is not "streaming", so nothing
  else would have stopped a message racing it into the same agent.
- **The waits double** — 2s, 4s, 8s from `retry.baseDelayMs`. The failures this waits out are
  the ones where everybody else is also retrying, and a fixed delay brings the whole crowd back
  at once. **Unless the provider said when**: `Retry::statedDelay()` reads a stated reset time out
  of the error and that wins, because against a quota that comes back in 39 seconds the doubling
  is three more refusals. A stated wait **past a minute** is not waited out at all — see the Gemini
  CLI section.

Settings are upstream's keys: `retry.enabled` (on unless turned off, like compaction),
`retry.maxRetries`, `retry.baseDelayMs`. **That sentence was false until the audit read it**: the
middle one was `retry.maxAttempts` here, so a `settings.json` written for pi had its `maxRetries`
ignored and got the built-in three. Its two siblings were upstream's, which is what gave it away —
one key out of a group of three not matching is a typo, not a decision. The method is still
`retryMaxAttempts()`, because attempts is what the number counts; the file speaks upstream's
dialect and the code speaks its own.

### Where the startup time went

`Timings` is upstream's `core/timings.ts`, behind `PIG_TIMING=1` as upstream's is behind
`PI_TIMING=1`. Twelve marks in `bin/pig` — arguments, settings, credentials, context files,
skills, slash commands, the session, hooks, custom tools, the agent, the `@file` reads — and a
table on **standard error** at the point a mode takes over.

Four things about it:

- **Gaps, not moments.** Each mark records the time since the last, so what comes out is a list
  of steps and what each cost. A list of timestamps needs subtracting by whoever reads it, and
  that is the part nobody does. The first mark is an anchor and reads 0.
- **The marks are unconditional**, and `mark()` is a comparison and a return when the variable
  is unset. An `if` at each of twelve steps would be the same thing written twelve times.
- **Standard error, and before the mode starts**, not at the end. Standard output belongs to
  `--mode json` and `-p`, and a report printed when pig quits is one somebody has to scroll back
  an hour to find.
- **`table()` is separate from `report()`** because `report()` writes to standard error, which
  `ob_start()` does not capture — a test against it would pass whatever the table said. That is
  the same failure as the `/label` test that passed while doing the wrong thing, caught this
  time by noticing the assertion could not fail.

Exactly `1`, as upstream tests it: `PIG_TIMING=0` meaning "on" would be a variable that cannot
be turned off the obvious way.

### Three ways in

`bin/pig` picks one, by upstream's rule: **`--mode` given at all means no terminal, and `-p`
is the short way of saying `--mode text`.** So the terminal is what you get when neither was
said, and nothing has to ask for it.

| | What comes out | Ported from |
|---|---|---|
| terminal (the default) | a TUI | `modes/interactive/` |
| `-p` / `--mode text` | the last answer, on stdout | `modes/print-mode.ts` |
| `--mode json` | every event as a JSON line | the same file, its `"json"` branch |
| `--mode rpc` | JSON lines out, commands in | `modes/rpc/` |
| `--mode web` / `/web` | a browser chat UI | `Web\WebMode` (Workerman-inspired HTTP/SSE) |

### The web mode's process model: pi-web's shell over `pig --mode rpc`, not a mirror of the TUI

**Decided 2026-10-04, after the developer pointed at the seam.** The web UI showed a tab bar of
sessions and `HttpServer` held **one** `AgentSession`: a tab was a bookmark in `localStorage`, and
clicking one called `switchTo()` on the single session — so the TUI sharing it under `/web` moved
with the browser, a turn in flight on tab A was aborted by opening tab B, and the "working" dot
could only ever be lit on one tab. Not a bug in the switch; a UI claiming a capability the process
model did not have.

Upstream never had this problem because pi-web is a different shape: a **web shell process that
spawns one `pi --mode rpc` child per `cwd::sessionFile`** and talks JSON-lines to each
(`server.js`'s `createManagedSession()`, `rpcSessions = new Map()`). It does that for three reasons
worth knowing, because only one of them applies here: pi-web does not import pi at all
(`AGENT_CMD = npx -y @earendil-works/pi-coding-agent@latest` — a black-box command, so the boundary
is a process boundary by construction); pi carries process-level state that two sessions in one
process would fight over (`process.on('SIGINT')`, a module-level theme, jiti's module cache); and a
crashed extension takes down one tab rather than seven. **pig has the third reason and not the
first two** — `HttpServer` constructs `AgentSession` directly, theme is the module-level `Themes::theme()` (upstream's module-level theme, the same
shared-state reason), extensions are `require`d closures — so same-process multi-agent over the shared `Loop` is
*possible* here in a way it is not there. It is still not what was chosen, and the reason is the
seam: the pieces for pi-web's shape already exist and the pieces for same-process do not.

| pi-web needs | pig has |
|---|---|
| a `--mode rpc` child | `RpcMode`, 24 commands, pi's wire format |
| a client that spawns it and matches responses by id | **`Rpc\RpcClient`** — it is `rpc-client.ts`, and `RpcClientTest` already starts `bin/pig` |
| HTTP + WebSocket | `HttpServer`, `TcpConnection`, `Protocols\Websocket` |
| a pool keyed `cwd::sessionFile` with idle reaping | **the one new piece** |

So the web mode becomes the shell: `HttpServer` holds `array<string, RpcClient>` rather than an
`AgentSession`, each WebSocket connection is bound to one key (pi-web's `socketBindings`), events are
forwarded from a child's stdout to its connections, and `hook_ui_request` is routed per session.
Three consequences that are the point rather than side effects: the TUI and every web session are
separate processes, which is the "TUI handles one session" the developer named; two tabs can run
two turns at once; and because pig's rpc protocol *is* pi's, this shell drives `pi --mode rpc` and
pi-web drives `pig --mode rpc` — both directions were free.

**Workerman is not introduced**, though it was asked about. The lint of the question is right —
`pig web start -d` wants a daemon with `status`/`stop`/`restart` and a child that is restarted
when it dies — and none of it needs a framework: `pcntl_fork()` twice and `posix_setsid()` is the
daemon, a pid file is `status` and `stop`, and the child-exit handler pi-web already has is the
restart. `HttpServer` and `Protocols\*` were written *in Workerman's shape* (their docblocks say
so) precisely so the framework itself would not be needed; bringing it in now would make those
files redundant and break the first rule of this document. The shell is I/O-bound forwarding, so
one process is enough; the work happens in N children, which is multi-core for free. If a concrete
bottleneck ever wants Workerman's multi-worker model, that is the time to argue it with a number.

**Landed 2026-10-04 in four steps**: **① `pig web start|stop|status|restart [-d]`** — the
subcommand and the daemon (`WebDaemon.php`); **② the session pool** (`SessionPool.php`) and the
`HttpServer` rewrite to route connections to isolated `pig --mode rpc` child processes with 60s
idle reaping; **③ the multi-session tab bar in `index.html`** multiplexed over **a single physical WebSocket**
connection with `tabId` tagging, per-tab chat scroll areas, background streaming indicators, and state recovery on reload;
**④ Telegram-style language pack import/export and ExtensionApi locale registration** (`v0.3.26`–`v0.3.30`):
full bilingual client-side `I18N` dictionary with 101 symmetric keys (defaulting to `zh-CN`), instant zero-refresh language modal (`#lang-btn`),
Telegram-style JSON import/export, `$pig->registerLocale()` on `ExtensionApi` with `/api/locales` aggregation, and in-session
interactive `/web restart`, `/web status`, and `/web stop` controls;
**⑤ Interactive Local PTY Terminals, SSH Node Workbench, and SFTP File Explorer (aligned with `youweichen/pi-web-ui` / `omp-web-ui`)**:
- `Pig\CodingAgent\Web\Pty\PtyProcess` & `PtyManager`: Native `proc_open` with `['pty']` descriptor on macOS/Linux, 16ms output micro-batching via `Loop::delay()`, slave PTY device detection (`lsof` on Darwin, `/proc/$pid/fd/0` on Linux) with window size updating via `stty` + `SIGWINCH`, and 200KB scrollback buffering.
- `Pig\CodingAgent\Web\Node\NodeProfile` & `NodeManager`: SSH node inventory in `~/.pig/agent/nodes.json`, secret persistence in `~/.pig/agent/nodes-secrets.json` (chmod 0600), SHA-256 host key fingerprint detection (`ssh-keyscan` + `ssh-keygen -lf`), OpenSSH `~/.ssh/config` discovery, remote PTY terminal streaming (`ssh -tt`), and SFTP remote directory listing/reading/writing (capped at 512 KiB).
- Frontend: Embedded `xterm.js` + `FitAddon` multi-tab terminal drawer in `WebTerminal.js`, and comprehensive `NodeWorkbench.js` modal with remote terminal tabs and SFTP file explorer/editor.

`PrintMode` is the smallest of the three because `RpcMode` did the work: `RpcEvents` already
encodes the events, and wiring hooks and custom tools with no UI is already something that
happens. What is left is deciding what to print — and it prints **only the text blocks of the
last assistant message**, because thinking is the model talking to itself and a script would
have to strip it.

Two things about `--mode json` that are not obvious:

- **The exit code stays 0 on a failed turn.** The caller is reading events, and the failing
  turn arrived as one. `-p` exits 1, because there the failure has nowhere else to go.
- **Warnings go to standard error** — every one of them, which is why `bin/pig` was already
  written that way. A yellow sentence about a broken hook among the JSON lines stops whatever
  is parsing them at that line.

`PrintMode` hands hooks a `NoUi`, so a `confirm()` is false and a `select()` is null without
anybody being asked. That is the fail-safe direction: a `tool_call` guard that cannot reach a
person blocks the call. A hook that would rather behave differently checks `$ctx->hasUi`.

Positional arguments are the initial prompt, in every mode but `rpc`. In the terminal they are
sent before the first keystroke and then it is yours as usual — which is the difference from
`-p`, and the reason they go through the same path a typed message does rather than a shortcut
of their own.

`@file` (`Cli\FileArguments`, upstream's `cli/file-processor.ts`) is read in front of the
first message, wrapped in `<file name="/absolute/path">`. An image becomes an `ImageContent`
attachment with an empty element beside it naming the file — which is how a screenshot gets
into a conversation without a tool call. Empty files are skipped; a missing one throws rather
than calling `exit()`, because a class that exits cannot be tested and `bin/pig` is the one
place that knows how to end the program. `--mode rpc` refuses `@file` and positional messages
outright, as upstream does: there the conversation arrives as commands, so a file read here
would be prepended to a prompt that never comes.

`Cli\SessionPicker` is upstream's `cli/session-picker.ts` and `Cli\SessionList` is its
`components/session-selector.ts`: `bin/pig --resume` with nothing after it shows the earlier
conversations and opens the one that is chosen. Before it, that flag answered
`Could not read the session at ` — with nothing after the "at", because the value was the
empty string. A session file is named after six random bytes under a flattened project path,
so a flag that demands the path is a flag nobody can use from memory.

Three things about it:

- **It runs before the mode exists**, on a `TuiMainScreen` it starts and stops inside the call, so what
  comes back is an ordinary return value and the session is opened by the same code that
  opens a `--resume <path>`.
- **Escape means "start a new one", not "stop".** Someone who opened the list and changed
  their mind still wanted pig; refusing to start would only make them type the command again.
- **Only with a terminal.** In `--mode text|json|rpc` there is nobody to ask, so a bare
  `--resume` stays the error it was — a clearer one now, naming what is missing.

**Ctrl+C is the third answer, and it went missing because there were only two.** `ask()` handed
back a `?string`, so the two keys that leave the list with nothing chosen had one value between
them — and `SelectList` answers both of them with its cancel handler, because to a list inside a
running screen ctrl+c is the screen's business. Here the list *is* the screen: pressing ctrl+c
started a brand new session, so somebody who asked to leave got a fresh agent, an empty prompt
and the same key as the only way out of that. Escape is "not this session", ctrl+c is "not pig",
and `SessionChoice` is what carries three answers instead of two — upstream keeps them apart with
a third callback that calls `process.exit(0)`, which a class here cannot do and `bin/pig` does
instead. `SessionList::setQuitHandler()` is that third callback, and a search box with a
half-typed query in it does not hold on to anybody: ctrl+c is the list's whichever way.

Upstream's selector has a third key, delete. Not ported: a session file is a record of
something that happened, and a list you move through with the arrow keys is the wrong place
for an irreversible key.

### Finding the one you want

Thirty conversations under one project, each named by whatever its first line happened to be,
and the arrow keys as the only way through them. So `Cli\SessionList` has a search box over
the list, which is upstream's `SessionList` and the half of `session-selector.ts` that pig
borrowed `SelectList` for instead.

**The keys divide in two and nothing switches between them**: up, down, Enter, Escape and
Ctrl+C belong to the list, and *everything else is typing*. A search box you have to tab into
is a search box nobody finds, and there is no focus to lose this way.

**`/resume` from inside a session shows this same list**, and for a while it did not. It built its
own rows into a plain `SelectList` — so the searchable list existed, was already ported, and was
reachable from exactly one of the two places that need it, which is this audit's commonest shape.
Upstream draws the searchable selector in both. The thirty-conversations problem is worse inside a
session than at startup, not better: the overlay is eight rows tall. Escape and ctrl+c both close
the overlay there — the distinction `bin/pig` needs has nothing to answer inside a running
session, where the conversation is still behind the overlay either way.

What it matches is `SessionInfo::$text` — upstream's `allMessagesText`, every text block of
every user and assistant message run together, collected by `SessionManager::describe()` while
it is already walking the file for the count. Three things are left out of it and each matters:
**thinking**, because it is the model talking to itself and a hit on it finds a conversation by
something nobody ever read; **tool results**, because they are mostly files and a search across
every file ever read matches everything; and the **formatting**, because this is a haystack and
never a thing to show. What anybody remembers about a conversation three days later is a phrase
from the middle of it, which is the whole reason the field exists — matching the opening alone
would only find the sessions that are already easy to recognise in the list.

`Pig\Tui\Fuzzy` is upstream's `packages/tui/src/fuzzy.ts`, arithmetic for arithmetic: every character of the
query in order, somewhere in the text. **The score is a pile of penalties, so lower is better**
— a run of consecutive characters is rewarded and the reward grows along the run, gaps cost,
the start of a word is worth a lot, and a late match costs a little. A space-separated query is
several searches over the same text and all of them have to hit, which is what makes a
transcript-sized haystack narrowable at all.

Five things about it, and all of them have now been **run** against `fuzzy.ts` rather than read —
3,078 comparisons, which is where the entry on the search box came from:

- **The walk is over characters, not bytes, and not code units either.** `$text[$i]` in PHP is a
  byte, so a Chinese query would compare thirds of characters and score nonsense: `好` is the second
  *character* of `你好` and its fourth, fifth and sixth bytes, which a byte walk reads as a
  three-character run near the end of the string and scores about -13.8 instead of 0.1. This used to
  say code units and characters are the same "for everything either project searches" and **the
  corpus refuted it**: an astral character is two code units and one character, so every position
  after an emoji differs. pig's is the better answer and the reason is on the class.
- **The whitespace class is the union of PCRE's and JavaScript's**, which differ by exactly three
  codepoints — see the trap entry, and the bug that was hiding behind the tokenizer not being `/u`
  at all.
- **Ties keep the order they arrived in.** `usort` has been stable since PHP 8.0, as
  `Array.prototype.sort` is in JavaScript. That is load-bearing rather than incidental: the
  list is sorted newest-first before it gets here, and ties coming back shuffled would look
  like the list had lost its order. A blank query returns the input untouched for the same
  reason.
- **A non-match's score is 0 and means nothing.** Upstream's shape, kept; comparing one against
  a real score would rank the things that did not match above half the things that did.
- **The first character is treated as continuing a run**, because `lastMatchIndex` starts at -1
  and `i - 1` is -1 when `i` is 0. That is upstream's arithmetic and it is not obviously
  intended, but it is load-bearing for the example in upstream's own comment: without it the
  two word-boundary rewards inside `f_o_o` beat the run in `foobar`, and `foo` would rank them
  the wrong way round.

The list component is rebuilt on each keystroke rather than told about the change, because
`SelectList` takes its items at construction — upstream's does too, which is exactly why
upstream's session selector draws its own rows instead of using one. Thirty items and one
object per keystroke is not worth a setter that nothing else would call; the selected index is
carried across and clamped, so narrowing from the bottom of a long list lands somewhere
sensible rather than back at the top.

Enter with nothing matching does nothing at all — it does not close the picker. A search that
found nothing has nothing to choose, and closing on Enter would throw away the query somebody
is half way through typing.

`Cli\ModelList` is `Fuzzy`'s second reader and upstream's `cli/list-models.ts`: `--list-models`
lists the registry, `--list-models gem pro` narrows it, fuzzily, over `"{provider} {id}"` as one
string — which is what makes naming the provider the way to narrow an id several of them
resell. Two things it does *not* do, both upstream's:

- **The rows come back sorted by provider and id, not by score.** Best-match-first is right for
  a picker somebody is arrowing through and wrong for a table somebody scans.
- **The match is a subsequence, so a search finds more than a substring would.** `haiku` also
  matches `moonshotai/kimi-k2-instruct`, because h·a·i·k·u are all in there in order. That is
  the algorithm, not a bug in the query, and upstream's listing behaves the same way.

Six columns rather than pig's old three, because the questions asked straight after finding an
id are how much it holds, how much it can say, and whether it can think. The human name went
with them: `Claude Sonnet 4.5` beside `claude-sonnet-4-5` is the same string twice, and the one
thing it said that the id did not — a model resold under somebody else's id — is what the
provider column already answers. Widths are measured from the values, so `google-generative-ai`
does not push every row after it out of line, and the last column is trimmed so no row ends in
spaces.

`--list-models` became a value-taking option to get there, which is the thing `Arguments`' docblock
warns about — an option that eats the next word. It is safe here for a reason worth writing
down rather than assuming: `--list-models` prints and exits, so there is no prompt left for it to
eat. `--resume` is the same shape and was the precedent: a value opens that one, nothing opens
the list.

`FakeTerminal::queue()` was added for its test. A component that starts its own loop and
blocks until it is answered has no "after the call" to type into, so the keys go in first and
arrive through `Loop::defer()` once the screen is open — which is also what a real terminal
does with whatever was in its buffer when the program took it over, and it keeps the loop
from deciding it has nothing to do and returning before anybody has typed.

The five short options are upstream's: `-c`, `-h`, `-p`, `-r`, `-v`. **A short option is an
abbreviation and nothing else** — it resolves to its long name and then goes through the same
handling, which is what keeps `-r path` and `--resume path` from coming to mean different
things. The first version gave every short option an empty value, which would have read that
path as a message; see the trap below.

One place pig's flags are not upstream's: **upstream's `--resume` takes no value at all** and
always shows the picker. pig's takes an optional path, because `--resume <file>` existed here
before the picker did and a flag that used to work should keep working. The cost is that
`--resume "fix the bug"` reads the prompt as a path — which fails loudly, naming what it tried
to open, rather than quietly.

`Cli\Arguments` is upstream's `cli/args.ts`, and it lives in the package rather than in
`bin/pig` for one reason: a script that calls `exit()` cannot be called twice by a test, and
the parser now has something worth testing. See the trap below about the flag that ate the
prompt. `--` ends the options, and everything after it is a message exactly as written — not
an option for starting with a dash, not a file for starting with an `@`. It is the only way to
say either, and half an escape hatch is not one.

### RPC mode — the second way in

`Rpc\RpcMode` is `bin/pig --rpc`: JSON lines on standard input, JSON lines on standard
output, no terminal at all. Three kinds of line come out — a `response` to a command, an
`event` as the agent works, and a `hook_ui_request` when a hook wants to ask something — and
`id`, when a command carries one, is echoed on its response so a host can match them up.

**Standard input goes on the same loop as the model's socket**, through `Loop::onReadable()`.
That is the whole reason this mode could not have been written before the hook dialogs were:
`RpcUi` is `HookUi` over the wire, and a hook calling `confirm()` parks its fiber on a
`Deferred` exactly as it does in the terminal — the line that resumes it is a
`hook_ui_response` instead of a keystroke. `readline()` on standard input would have blocked
the loop that has to deliver it.

Three things differ from `TerminalUi`, all because the other end is a program:

- **Every question has an id**, because a host may answer three of them in any order.
- **There is no "one thing in the overlay at a time".** The terminal refuses a second question
  because it would steal the keyboard from the first, and refuses one arriving while a picker
  is open for the same reason; a host has no keyboard to steal and no screen to lose.
- **`custom()` returns null and `getEditorText()` returns `''`.** The first builds a
  `Pig\Tui\Component` and there is nothing to draw it on; the second reads an editor that
  belongs to the host. Upstream leaves both out of its RPC context for the same reasons.

Messages go out through `SessionCodec`, the encoder the session file already uses, so a host
reading `get_messages` and a `--continue` reading the file see the same shape. `RpcEvents`
is the one place the wire shape of an agent event is written down: upstream calls
`JSON.stringify(event)` and is done, because its events are plain objects, which also means a
field rename there changes the protocol silently.

`rpc-client.ts` **is** ported, as `Rpc\RpcClient`. `rpc-types.ts` is not, and the reason is worth
more than one line because "PHP has no named union type" is the weakest of the reasons and the one
that comes to mind first.

Its 203 lines are `RpcCommand` (24 object shapes discriminated on `type`), `RpcSessionState` (one
interface of ten fields), `RpcResponse` (24 more shapes plus an error arm), the two hook-UI unions,
and `RpcCommandType = RpcCommand["type"]`. In PHP that is about sixty readonly classes, a
`@phpstan-type` alias for each union — the trick `Context` already uses for the Message union — and
one string-backed enum.

**The hard reason is that all of it describes JSON that arrived from outside the process.** A static
type cannot check a byte that came over a pipe, and the host at the other end is deliberately not
PHP — that is what the mode is for. So the runtime checks stay either way: `RpcMode::text()` throws
`'message' is required and must be a string.` today, and a class per command would need that same
check to construct itself before `dispatch()` could switch on a class instead of a string. Same
checks, sixty more classes. Upstream gets value from the union because **its host is TypeScript too**,
so the union is a contract both ends share at compile time; pig's host shares nothing but the bytes.

Two things would be real, and neither is a port of this file:

- **`RpcCommandType` as a string enum** would be *better* than the TypeScript it came from, because a
  PHP enum exists at runtime: `tryFrom()` replaces a `match` arm that throws. What it buys is one
  list of command names instead of two — `RpcMode`'s `match` and `RpcClient`'s methods. What it does
  **not** buy is catching the mistake that actually happened: `RpcClient` sent `path` where the wire
  wants `sessionPath`, and that is a *field* name, which no command enum touches.
- **Typing `RpcClient`'s eleven `array<string, mixed>` returns** — `state()`, `bash()`,
  `sessionStats()`, `compact()` — would help a PHP host, at a real boundary, with types that can
  actually be guaranteed because the client is the one decoding.

Neither is done, and the reason is the standard the rest of this document is held to: **no defect has
been traced to either.** Every other item ported late in this port was justified by a reproduced
failure. The only caller of those eleven methods today is `RpcClientTest`; designing types for a PHP
host that does not exist yet is the work this project keeps declining to do. When there is one, its
needs decide the shapes.

**Six of upstream's commands are absent**, and the reasons divide in three:

| Upstream command | Why not |
|---|---|
| `queue_message` | the anchor commit split the one queue into `steer()` and `followUp()`; `steer` and `follow_up` are the two commands that replace it, rather than guessing which one a `queue_message` meant. **`set_queue_mode` used to be on this row and did not belong**: the split is about queuing a message and says nothing about the mode — see the trap below |
| `cycle_model` | `get_available_models` and `set_model` are what it is made of |
| `branch` | upstream forks a conversation into a second session file; pig branches inside one, as `go_to` with `get_branch` for the points to go to |
| `export_html` | it is `export` here, and it honours `outputPath` |

Twenty-four commands are there: `prompt`, `steer`, `follow_up`, `abort`, `get_state`,
`get_messages`, `get_last_assistant_text`, `get_session_stats`, `get_available_models`,
`set_model`, `set_thinking_level`, `cycle_thinking_level`, `compact`, `set_auto_compaction`,
`set_auto_retry`, `abort_retry`, `set_queue_mode`, `bash`, `abort_bash`, `get_branch`, `go_to`,
`new_session`, `switch_session` and `export`.

Every failure is a `success: false` response rather than a disconnection: a host asking for
something impossible should be told, not dropped. Warnings that the interactive mode would
draw on screen — a broken hook, a custom tool that failed to start — go out as `hook_error`
and `tool_error` lines, and everything `bin/pig` prints before a mode starts already went to
standard error, which is what keeps standard output the protocol's alone.

`SessionCodec::encodeContent()` and `plain()` went from private to public for `RpcEvents`.
Widening it was the cheaper of two bad options: a second content encoder living in `Rpc\` is
two things that can disagree about what a message looks like, and they would disagree the
first time a content type gained a field.

What is left unported, across every package, each for a reason:

| Upstream | Why not |
|---|---|
| eleven of the selector components, as files | every one of them is here as something else, and the audit that checked it is below: `hook-selector`, `hook-editor` and `hook-input` are `TerminalUi::select()`, `editor()` and `input()`; `queue-mode`, `show-images`, `thinking` and `settings-selector` are `/settings`' rows plus `thinkingSubmenu()`; `theme-selector` is that list's theme row; `oauth-selector` is `showSignIns()`; `session-selector` is `Cli\SessionPicker`; `model-selector` is `showModels()`. **`tree-selector.ts` is no longer among them** — it is `Interactive\TreeList` |
| `coding-agent/modes/interactive/components/user-message-selector.ts` | the **twelfth** selector, and the one that is not here as something else: it is `/branch`'s list, and `/branch` forks a conversation into a second session file, which pig does not do. `/tree` is pig's answer to the same wish and has its own list. Upstream also opens this one on a **double Escape** with an empty prompt; pig's Escape stops whatever is running and does nothing when nothing is, so that gesture has no meaning here. If a key for "go back to something I said" is ever wanted, `/tree` is what it should open and this row is where to start |
| `/share`, in `interactive-mode.ts` | **Deliberate, and the one command left out on its merits rather than for want of a subsystem.** It runs `gh gist create --public=false` on the exported HTML and prints a URL on upstream's own viewer domain. Two reasons: a "secret" gist is unlisted and not private, so a conversation — which holds whatever the model read — goes to GitHub behind a link anyone with it can open; and the URL hands it to a third party's JavaScript viewer, which is the dependency `HtmlExport` was written to avoid. `/export` already writes the file, and `gh gist create` on it is one command with the person looking at what they are uploading. If it is ever wanted, the piece pig lacks is nothing: `BorderedLoader` is ported and already cancellable |
| `ai/utils/typebox-helpers.ts` (24) | `StringEnum`, a TypeBox helper that emits `{type:"string", enum:[…]}` because TypeBox's own `Type.Enum` emits `anyOf`/`const` and Google's API rejects that. In PHP a schema **is** an array, so there is nothing to help with — you write the array, and `JsonSchemaTest` says so where the enum is tested |
| `coding-agent/modes/rpc/rpc-types.ts` | 203 lines of types for JSON that arrives from outside the process, where a static type guarantees nothing and the runtime checks are the contract — the long version is in the RPC section, including the two things that *would* be worth typing and why neither is done yet. `RpcMode`'s docblock plus `RpcEvents` is where the wire shape is written down. Its `rpc-client.ts` **is** ported, as `Rpc\RpcClient` |
| every `index.ts` | barrel re-exports, which is what an autoloader does here |

**This table has now been wrong four times.** Twice in the same way: the first time it said "the
rest is left out on purpose" while `modes/print-mode.ts` and `cli/file-processor.ts` were simply
never listed, and the second time it covered `coding-agent` only, while `agent/proxy.ts`,
`ai/cli.ts`, `ai/utils/validation.ts` and `tui/components/settings-list.ts` were unported and
unmentioned — and the `pig/tui` paragraph said "done" with a file missing.

The third was the other direction, and is the more interesting one: the table claimed
`visual-truncate.ts` was unported, with a confident paragraph about pig slicing logical lines
where upstream counts wrapped rows. **It was ported, as `Interactive\BashOutputComponent`,
before the row was written.** The mistake was comparing upstream's bash branch against pig's
*generic* branch: upstream truncates visually for bash output only, and its generic tool output
is `lines.slice(0, maxLines)` — logical and head-first, which is exactly what
`ToolExecutionComponent::cut()` does. Both halves already matched; the row compared the wrong
halves.

The fourth was the first kind again — `model-registry.ts`'s `models.json` half simply never
listed, and now ported rather than listed — and it is the one that shows what the sweep is
actually for. The file *has* a counterpart: the built-in registry, key resolution and lookup are all in pig. Reading the row
"`model-registry.ts` → `Ai\Models` + `ModelResolver`" and stopping there is how 180 lines of it
stayed invisible. **A name with a counterpart is not a file that is ported; it is a file worth
reading to the end.**

**And a ported file is worth a difference-by-difference audit, not only a look at the parts that
felt like decisions.** `Migrations` was reviewed that way after the fact and it found two real
gaps — a `glob` that skipped dotfiles where upstream scans, and a message in pig's own words where
upstream has one — plus an entry in this file claiming a deviation from upstream that was only a
deviation from the first draft. Every difference either has a reason written next to it or is a
bug; there is no third category, and the ones without a reason are the ones nobody looked at.

So the sweep below finds names, and **a missing name is a question, not an answer.** A behaviour
can be ported into a file called something else — pig's is a component rather than a function,
because only `render()` knows the terminal's width. Before writing "not ported", read what the
upstream file does and grep pig for the behaviour, which is the same evidence a "done" claim
needs.

**The last run of it went five names deep and the five answers were all different**, which is what
that rule is worth in practice. `tui/components/cancellable-loader.ts` and `truncated-text.ts` are
`Components\CancellableLoader` and `Components\TruncatedText` — ported, never named here.
`utils/mime.ts` is `Tui\Images\ImageType::ofFile()`, and its two upstream callers, `read.ts` and
`file-processor.ts`, are `ReadTool` and `Cli\FileArguments` here calling the same one.
`user-message-selector.ts` is the row above. And `core/bash-executor.ts` had a real find in it —
the entry on cleaning a command's output at the source.

So the rule, which is the same rule and a wider sweep: **a claim that nothing is missing is worth
checking against the file list rather than against memory, in every package and not just the one
being worked on.** One command does it:

```bash
for p in ai agent tui coding-agent; do
  find /tmp/pi/packages/$p/src -name '*.ts' -not -name '*.test.ts' -not -name 'index.ts' | sort
done
```

Read it against pig's own tree, and anything with no counterpart belongs in this table or in the
code. It is worth running after a run of porting, not during one — during one, every second file
is legitimately absent.

**And the command names four packages where upstream has seven, which makes the command itself a
claim.** Nobody had checked it, so it was checked: `mom` (4,440 lines, a Slack bot that delegates to
the coding agent), `pods` (1,773, a CLI for vLLM deployments on GPU pods) and `web-ui` (14,524, lit
components for a browser chat UI) are outside pig's scope, and the evidence rather than the reading
is that **the dependency direction is one-way** — nothing in `ai`, `agent`, `tui` or `coding-agent`
imports from any of the three, and all three import from them. So the four are the whole of what pig
ports, and ~20,700 lines of upstream are deliberately not in the scoreboard below. The last run of
the sweep also confirmed the scoreboard's own arithmetic exactly: 127 files, 42,644 lines, no file
new and none gone, 114 audited + 13 ruled out with no overlap and no gap.

**`mom` is still worth reading, and not as a port.** It re-implements five of the tools — `read`,
`write`, `edit`, `bash` and `truncate` — against a sandbox executor rather than the filesystem, so it
is upstream's own second answer to questions pig has already answered, which is the *first* shape
from the index pointed at upstream instead of at pig. What that found is the entry below on the diff
renderer. `truncate.ts` there is a strict subset of `coding-agent`'s (no `maxLines`/`maxBytes` on the
result, no `truncateLine` for grep) with the same arithmetic, and the tools differ structurally
because they shell out — mom's `read` even detects an image by *extension* where `coding-agent`'s
reads the file's magic. pig ported the fuller copy in every case, which is worth knowing was a
verified answer rather than the only one available.

### How much of it has been read against upstream

The sweep above answers "is anything missing". This is the other question: **which ported files have
been read against their upstream line by line**, which is what every find in the traps at the bottom
came out of. 127 upstream files, 42,644 lines, excluding tests and `index.ts` barrels:

| | files | lines |
|---|---|---|
| read difference by difference | 114 | 41,268 |
| ruled out, reason on record | 13 | 1,376 |
| **not yet read** | **0** | **0** |

**The queue is empty.** Every upstream file that has a counterpart here has been read against it
difference by difference, and the thirteen that do not have one have a reason on record in the table
above. That is what the traps at the bottom of this file are: each one came out of a read, and the
last of them — a key claimed and bound to nothing — came out of a 106-line component nobody expected
anything from.

**What the number does *not* mean**, and this matters more now that it is complete than it did while
it was a queue:

- **It is not a claim that pig matches upstream.** It is a claim that every place the two differ has
  either a reason written beside it or a trap entry. Several of those differences are pig being
  *right* where upstream is not — `diffWords` drawing the wrong text on a removed line, a 0×0 PNG
  asking for 150,000 rows, `input.ts` drawing lines of the wrong width on CJK.
- **It is not a measure of quality.** `theme.ts` was read this way and found nothing;
  `bash-executor.ts` was not on anybody's list at all until a file-list sweep turned it up. A file
  being ported and passing its tests said nothing about whether it had been read — every entry below
  came from one that was.
- **It goes stale the moment upstream moves.** The anchor is `d0a4c37`; the count is against that
  snapshot. Re-running it means re-running the sweep command above, not trusting this table.

The reads that found nothing are worth as much as the ones that did, and only with the evidence
attached: `theme.ts` (all 16,777,216 colours through `to256`, both palettes name by name),
`terminal.ts` (every escape sequence and both size defaults), `box.ts`, `text.ts`, `spacer.ts`,
`truncated-text.ts`, `loader.ts` and its two subclasses, `dynamic-border.ts`, `user-message.ts`,
`assistant-message.ts`, `hook-message.ts`, `compaction-summary-message.ts`,
`branch-summary-message.ts` and `bordered-loader.ts` — fifteen components that line up member for
member. Two dead ends in upstream turned up in them and are not ported: `Text`'s `[""]` fallback for
a render that produced no lines, which its own wrapper cannot produce, and `ImageOptions`'
`maxHeightCells`, declared twice and read nowhere.

**`markdown.ts` was the one a surface map could not read**, and the corpus is what read it — see the
trap on the two list bugs it found. The rule it confirms: where upstream leans on a package pig had to
replace, there are no two lists of methods to line up, and the only honest comparison is a corpus run
against the package itself.

**The two largest files came off that list together, and both were read by surface rather than top
to bottom** — `agent-session.ts`, 74 upstream methods against pig's 66, and `interactive-mode.ts`,
68 against 88: mapped name by name, then every unmatched one chased down. Six of the traps below came
out of those two reads. The method is the finding as much as the bugs are: **a file of two thousand
lines is not read line by line by anybody, and the gaps are all in the names that have no pair.** Of
the twelve unmatched names in `interactive-mode.ts`, three were bugs, one was a command left out on
its merits, four were arrangements pig differs on deliberately, and four were pig's own code under
another name.

All four of its gaps are fixed in the traps below — `isCompacting`/`abortCompaction`, `waitForRetry`
at both ends of `prompt()`, the `isStreaming()` guards in front of `abort()`, and
`_tryExecuteHookCommand`, which is the entry on a slash command that only worked in front of a
screen.

`get_state` gained `isCompacting` and `queueMode`; `sessionId` stays out, since pig has no session
identity apart from the file and `sessionFile` already is that.

**Two things this scoreboard is not.** It is not a measure of quality — `theme.ts` was read this way
and found nothing, and `bash-executor.ts` was not on anybody's list at all until a sweep turned it
up. And a file being *ported and working* says nothing about whether it has been read: every entry in
the traps section below came from a file that was already passing its tests.

`examples/agent.php` runs the whole stack without a UI, read-only unless given `--write`.

`markdown.ts` has no `marked` under it here: `Pig\Tui\Markdown\Lexer` and `Inline` are a
hand-written subset — see the dependency note above. They are not CommonMark and do not try
to be; what they cannot parse stays a paragraph and is drawn as the text it was.

### Five upstream dependencies that are not needed here

`pi-tui` pulls in `get-east-asian-width` and leans on `Intl.Segmenter`, both for one question:
how many columns will the terminal give this string? Neither is needed here.

- **Grapheme clusters**: PCRE's `\X` implements UAX #29 extended grapheme clusters, correctly,
  including ZWJ emoji sequences and regional-indicator flags. `Graphemes::split()` is one
  `preg_match_all` call — no ext-intl, no table of Unicode ranges.
- **Character width**: `mb_strwidth()` carries the East Asian Width table, and PCRE answers
  `\p{Extended_Pictographic}` and `\p{Emoji_Presentation}` for the emoji cases it does not cover.

`chalk` is the third, and `Pig\Tui\Style` replaces it: a TUI needs "wrap this string in a
style and close it again", which is one file, not a package. Components take styles as
`Closure(string): string`, so `Style::dim(...)` is what gets passed around.

`marked` is the fourth, and the one that was a real decision rather than an obvious win.
`markdown.ts` uses it for a token stream and throws its HTML away, and a terminal renderer
needs a subset — headings, emphasis, code spans and fences, lists, quotes, links, rules,
tables. The developer chose to write that subset here rather than take `league/commonmark`,
which would have brought `league/config`, `dflydev/dot-access-data` and a row of Symfony
polyfills with it and ended the zero-dependency claim. It is not CommonMark and does not
try to be; anything it cannot parse is drawn as the plain text it came from.

`cli-highlight` is the fifth — it wraps highlight.js and its two hundred grammars, and
`theme.ts` uses it to colour fenced code blocks. `Theme\Highlight` replaces it with a
left-to-right scanner and a table of thirteen languages, which the developer chose over
the dependency. The trade is stated plainly in the class: fewer languages, and inside a
language a handful of things coloured slightly wrong that highlight.js would get right.

The one design point worth keeping: it is a **scanner**, not a pile of `preg_replace`
calls over a keyword list. A replacement-based highlighter paints the `if` inside
`"if you like"` blue and the `#` inside a URL grey, and a reader who has seen that once
stops trusting any of the colours. Asking "what starts here?" at each position means that
once the scanner is inside a string, nothing inside that string is anything else.

Two places it does guess — a Capitalised word is a type, a word before `(` is a call —
and both are documented as guesses. This is the one part of the codebase where a guess is
allowed, because the cost of being wrong is a wrong colour.

The one thing PCRE has no answer for is JavaScript's `\p{RGI_Emoji}`, which matches a whole emoji
*sequence*; PCRE properties test single codepoints. `Width` asks the question of the cluster's
first codepoint instead, which gives the same answer for everything a terminal actually draws.

### The upstream dependencies that no package can replace: the provider SDKs

The five dependencies above were choices. **This one is not, and it is the largest single
difference between the two trees**, so it is written down with the facts it was decided on rather
than left to be rediscovered.

`pi-ai` does not implement a provider protocol at all. `@anthropic-ai/sdk`, `openai`,
`@google/genai` and `@mistralai/mistralai` are in its `dependencies`, and each one builds the
request, reads the event stream and hands back typed events. Upstream hand-writes an event-stream
reader in exactly **two** places in the whole repository — `google-gemini-cli.ts`, because Code
Assist has no SDK, and `agent/proxy.ts`. Everywhere else an SDK does it.

pig hand-writes all of it: 4,734 lines across `Providers/` and `Http/`, with 144 tests over them.
That is not a preference. As of the date on this section:

- **Anthropic has an official PHP SDK** — `anthropics/anthropic-sdk-php`, PHP `^8.1`, on `psr/http-client`
  via `php-http/discovery`.
- **OpenAI has none.** `openai-php/client`, the one everybody uses, describes itself as
  community-maintained.
- **Google has none.** The official GenAI SDK ships for Python, JavaScript/TypeScript, Go, Java and
  C#; PHP is not on the list, and the PHP Gemini clients are community projects.

So the SDK route covers **one provider out of five**, and the other four are hand-written either
way — which would leave the tree less consistent than it is now, not more.

**And that one has a conflict that matters more than the count.** The official PHP SDK streams by
iterating a PSR-18 response body synchronously, and its own documentation says what happens with a
client that does not stream: *"With a buffering client, the `foreach` loop yields every event at once
when the response completes instead of incrementally"*. Even with Guzzle, the loop blocks the one PHP
thread while it waits for the next event. pig's whole shape is one `stream_select()` waiting on the
model's socket **and** the keyboard at the same time — the first paragraph of the README. A blocking
read inside `AgentLoop` costs interrupting a running turn with Esc, typing while the model streams,
and the spinner moving at all.

There is a route that would work, and it is worth knowing about rather than reinventing later: PSR-18
is only an interface, so a PSR-18 client and a PSR-7 stream backed by `Pig\Async\Socket` would make
the SDK's `foreach` suspend its fiber instead of the process, and the loop would keep running
underneath. It was not taken, and the reason is the arithmetic above: five new packages
(`anthropic-sdk-php`, `psr/http-client`, `psr/http-factory`, `php-http/discovery`,
`standard-webhooks`) plus an adapter, to replace one of five providers and leave the other four as
they are.

Two things follow for anybody reading this later. The first is that `Http\SseParser`,
`Http\ChunkedDecoder` and `Http\HttpClient` are **load-bearing in a way they are not upstream**: a
bug in them shows up as tokens going missing rather than as an error, and there is no vendor
implementation to fall back to. The second is that this is a dated judgement, not a principle — if an
official PHP SDK appears for OpenAI and for Gemini, and the streaming question is answerable, the
arithmetic changes and this section should be re-run rather than quoted.

### Porting the unions

PHP has union types. What it does not have is a way to **name** one or to use one as an array's
element type — `type Message = A|B` and `array<A|B>` are both parse errors. That, not any absence
of unions, is what decides how each of upstream's unions is encoded here:

| Union | Encoding | Why |
|---|---|---|
| `Message` (3 arms, closed) | real union type, aliased with `@phpstan-type` on `Context` | closed like upstream, and a `match` over it can be checked for exhaustiveness |
| `AssistantMessageEvent` (12 arms) | marker interface | with no alias, a union means copying twelve class names into every signature |
| `AgentMessage` (apps extend it) | marker interface | a union cannot be extended from outside the package |
| `Content` / `UserContent` / `AssistantContent` | marker interfaces | they are array elements, where the type is a docblock either way — and the interface still holds for a single block passed on its own |

**A marker interface is not exhaustively checkable.** Anything may implement one, so every
`match (true)` over an interface keeps its `default` arm. Only the closed unions get exhaustiveness.
At runtime the two encodings are equally safe: a `match` with no arm taken throws
`UnhandledMatchError` either way.

`UserContent` / `AssistantContent` exist so that "a user message cannot hold thinking" is a type
error rather than a convention. The twelve event classes are named after their wire strings —
`text_delta` → `TextDeltaEvent`; upstream leaves those arms anonymous, so the names are ours.

Deliberate deviations from upstream, both to spare every consumer an unpacking step:
`UserMessage` wraps a bare string into a `TextContent` at construction instead of keeping
`string | Content[]`, and `Context` takes `messages` first because PHP wants required parameters
before optional ones.

## Commands

```bash
composer install        # PHPUnit 12; the five packages/ are autoloaded by the root package
php test/lint.php       # php -l over every file (PHPUnit only parses what it loads)
vendor/bin/phpunit      # filter: vendor/bin/phpunit --filter Loop
php test/live.php       # the providers against the real endpoints — costs money, needs keys
                        # `… google` for one provider, `… google/<model-id>` for one model
php scripts/generate-models.php   # rewrite Ai\Models' rows from models.dev
                        # `--dry-run` prints them instead; `--from <file>` reads a saved api.json
```

PHPUnit 12 is the newest release that still runs on PHP 8.3, so it is what the floor allows.
PHPUnit 13 requires 8.4 and becomes available if the floor ever moves.

`test/AssertsThrows.php` adds one assertion on top of PHPUnit: `assertThrows()` asserts a throw
mid-test and hands back the exception, which `expectException()` cannot do because it scopes to
the whole method.

The dev container has no route to packagist, so tests run on the Mac, not in the agent sandbox.

## Known traps

Three shapes account for nearly every find in this file, and they are described where the audit that
found them is written down: **a rule present in one place and absent in its sibling**, **a thing
wired up at one end only**, and **PHP's value model is not JSON's** — all three at the end of
[A hook's compaction summary was indistinguishable from pig's own](#a-hooks-compaction-summary-was-indistinguishable-from-pigs-own).
Before reading a file against upstream, ask the three questions there:
who else does this, who is at the other end, and is this value the same thing in both languages.

And ask the first of those three of **upstream** as well as of pig. Upstream carries second copies of
its own code — `mom`'s five tools and its diff renderer, `bash-executor.ts` beside `bash.ts`,
`google-gemini-cli.ts`'s event-stream reader beside `proxy.ts`' — and each pair is a corpus somebody
already wrote: where the two disagree, one of them is wrong and the diff is short. Three of the finds
below came out of exactly that — `proxy.ts`' event-stream reader dropping every `data:{…}` line its
own sibling handles, `mom`'s diff renderer numbering the wrong lines, and the three disagreeing
spellings of the sanitize composition, one of which is safe only because its caller had already
cleaned the text.

### `stream_socket_enable_crypto()` returns `0`, not just `true`/`false`

On a non-blocking socket the TLS handshake needs several passes. `0` means "call me again after
the socket is readable"; treating it as failure breaks the connection intermittently — measured
2 passes against `api.anthropic.com`.

```php
while (true) {
    $ok = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($ok === true) break;
    if ($ok === false) throw new TransportError('TLS handshake failed');
    $r = [$sock]; $w = $e = null; stream_select($r, $w, $e, $timeout);  // $ok === 0
}
```

### `str_pad()` counts bytes, so every column it lines up is ASCII-only

Eight sites, two of them already right, and the two right ones are what make this the first shape
from the index rather than a new find. `SelectList` and `SettingsList` both measure their label
column with `Width::visible()` and pad with `str_repeat(' ', …)` — that correction is written down
twice in this file, against upstream's `.length`. The other six used `str_pad()`:

```
review        strlen= 6  columns= 6   padded to 24: 24 columns  aligned
代码审查      strlen=12  columns= 8   padded to 24: 20 columns  off by 4
データ整理    strlen=15  columns=10   padded to 24: 19 columns  off by 5
émoji-🎉      strlen=11  columns= 8   padded to 24: 21 columns  off by 3
```

`strlen` is never *smaller* than the column count, so the padding always falls **short** and the
column after it starts early — which means this cannot trip `TuiBase::checkWidth()` and nothing ever
failed. It just reads as a table pig cannot draw. Where it shows: `/skills` (a skill's name is a
folder name, and a folder can be called 代码审查), `/hooks` and `/help` (a hook registers its own
command name), and `--list-models`, whose provider and model names come out of a hand-written
`models.json`. A name with a combining mark or an emoji in it is off by its own amount.

`Width::pad()` is the one implementation now, beside `visible()` and `truncate()` where it belongs,
and `Width::background()` — which had the same three lines inline — delegates to it. Two things
worth keeping:

- **`--list-models` was self-consistent and still wrong.** It measured with `strlen()` *and* padded with
  `str_pad()`, so the arithmetic agreed with itself and disagreed with the terminal. A pair of calls
  that cancel out is harder to spot than a single wrong one, and `testTheColumnsLineUpDownTheWholeTable`
  passed throughout because every name in the built-in registry is ASCII.
- **`pad()` cuts nothing**, as `str_pad()` cuts nothing. The two lists truncate first and then pad,
  which is the order that fits a fixed column; a caller that pads without truncating gets a wide row,
  which is a different bug with a different fix.

The mutation check is the one that says the extraction held: putting `str_pad()` back inside
`Width::pad()` turns **nine** tests red across all three consumers plus `background()`'s own two.

Found by sweeping for it rather than by anybody looking at a crooked table — `str_pad`, `str_split`,
`ucfirst` and `strtolower` are the calls that have no business touching text from outside, and there
are only 41 of them. The other three came back clean: `str_split` is `mb_str_split` everywhere it
walks text (the two byte ones walk a key sequence and a base64 blob), `ucfirst` is applied to a
`ThinkingLevel` enum value, and every `strtolower` on something a person typed is `mb_strtolower`.

**Then the two that were already right were mutated, and only one of them had a test.**
`SettingsList` has `testACjkLabelIsMeasuredInColumnsNotCharacters`, which goes red the moment its
padding counts bytes. `SelectList` had nothing: swapping `Width::visible()` for `strlen()` in its gap
broke no test at all — and the case that looks like it should have caught it,
`testAWideLabelDoesNotPushTheDescriptionOffTheScreen`, cannot, because measuring in bytes makes the
gap **smaller**. The row still fits; the description just starts in the wrong column. *A test that
asks "did it overflow" cannot see a rule about where things line up*, which is the same shape as the
`grep` test that asserted `'ripgrep exited'` and thereby pinned the shortfall. Both consumers assert
the column now, and `SettingsList`'s inline three lines are `Width::pad()` like everybody else's.

`SelectList`'s gap is **not** `Width::pad()` and should not become it: `max(1, …)` there keeps at
least one space between a label and its description, which is a separator and not a fill to a fixed
width. Two lines that look alike and answer different questions — the thing this file says to check
before sharing one.

Regression tests: `WidthTest::testPadFillsToTheColumnAndNotToTheByte` (six shapes),
`InteractiveModeTest::testTheDescriptionColumnLinesUpForASkillNamedInAnotherAlphabet`,
`ModelListTest::testTheColumnsLineUpWhenAModelIsNamedInAnotherAlphabet`.

### A line wider than the terminal is fatal here, so a hint upstream lets overflow is a crash

`/settings` on a terminal 32 columns or narrower took the session down:

```
Pig\Tui\TuiError — Rendered line 8 is 33 columns wide, terminal is 32
```

Line 8 is the hint, `  Enter to change · Esc when done`, which `SettingsList` returned at its full
length however narrow the screen was. Upstream does the same and gets away with it, because there a
too-wide line merely wraps; **`TuiBase::checkWidth()` throws instead**, deliberately — a wrapped line
puts every cursor move below it one row low, and silent screen corruption with no visible cause is
worse than a loud stop. That choice is what turns a cosmetic overflow in a ported component into a
crash, and `render()` runs inside the loop's own callback, so there is nothing above it to catch.

Three of that component's lines could not fit, and the third is the one worth remembering: the rows
themselves. Upstream caps the label column at 30 columns and then **writes the label out in full
anyway**, so the cap does nothing and a row is as wide as its longest name; the column is clamped to
the terminal here, keeping one column of value, because the value is the point of that screen.
The count (`(3/30)`) is truncated as `SelectList` and upstream both truncate it, and the hints are
**wrapped**, which is how every other hint in pig is drawn — `TerminalUi` puts each one in a `Text`
— because `Esc when don` is worse than two rows. The indent goes on after the wrap, or the second
row hangs in the margin.

**Then the same question was asked of everything else that builds its own lines**, which is the half
that makes this worth an entry rather than a fix: `FooterComponent` and `TreeList` measure and
truncate, `DiffView` hands back a string that goes through `Text`, and `ToolExecutionComponent`,
`UserMessageComponent`, `HookMessageComponent`, `BorderedLoader` and the banner are
`Text`/`Markdown`/`Container` all the way down, which wrap and pad by themselves.

**That sweep named `BashOutputComponent` as one of the safe ones and it was not**, which is worth
more than the fix: it pads every line to the width and truncates none of them, and the line it adds
itself — `... (35 earlier lines)` — is 22 columns whatever the terminal is. So a `!` command whose
output was cut took the session down on any pane narrower than that. *Measuring is not truncating,
and a sweep that reads for `Width::` finds both.* Checking a claim like that means running the probe
per component, not grepping for the function name — `MarkdownTest` and
`ToolExecutionTest::testTheEarlierLinesNoteFitsANarrowTerminal` are what hold the two of them now.

So the rule for any new component: **the lines it returns are a contract with the renderer, not a
suggestion.** Anything assembled by concatenation rather than handed to `Text` has to truncate or
wrap it itself. A probe over widths 1–60 is the cheap way to ask — everything in the package
overflows at four columns or fewer, because `max(1, $width - $padding * 2)` is the floor everywhere
and upstream's is the same, and that is the only band where a difference is not a bug.

Regression tests: `SettingsListTest::testNoLineIsWiderThanTheTerminal` (widths 6 to 60),
`testTheHintFitsANarrowTerminal`, `testTheScrollCountFitsToo`. Found in the same read: the hint
named Enter and not Space, while `handleInput()` accepts both — upstream's own hint names both, and
the Ctrl+G rule the other way round.

### An empty document has no lines, and `explode("\n", '')` has one

An edit that emptied a file drew this:

```
-1 one
-2 two
-3 three
+1
```

That last line claims a blank line was added. Nothing added one: `explode("\n", '')` answers
`['']`, so `EditDiff::render()` saw the new file as one empty line rather than as no lines, and the
mirror case — an edit that fills an empty file — drew a `-1` for a line nobody deleted. Fixed by
treating `''` as zero lines, and the test beside it exists to stop that being over-applied: the last
element of `"a\nb\n"` **is** a real empty line, so losing a trailing newline is still a removed line
and is still shown as one.

Found by running `EditDiff` against upstream's `generateDiffString` over 515 pairs, which is also
the only reason the other differences are now written down rather than guessed at: 259 pairs differ
only in trailing context being numbered in the new file (deliberate — upstream's old numbers point
into a file that no longer exists), 2 in pig treating a trailing newline as an empty last line
consistently (deliberate, and upstream's alternative renders `"a\nb\n"` → `"a\nb"` as `-2 b` then
`+2 b`, the same text removed and re-added), and 152 are several separate edits in one file, which
is the documented single-block limitation and which `EditTool` cannot produce.

`Truncate` went through the same run — 777 documents with varied limits — and `head()`, `tail()` and
`size()` agree with upstream field for field, byte counts, `truncatedBy`, the partial-line edge case
and all. `line()` is the one that differs, and by unit rather than by intent: upstream counts UTF-16
code units, this counts characters.

**`EditTool` itself went through a third run — 307 cases, comparing the bytes left on disk — and
found nothing.** That is worth recording because it is the one file in the tree that changes somebody
else's work, so "probably fine" is not good enough: BOM, every mix of line endings, a lone CR,
overlapping matches, `$1` in the replacement, invalid UTF-8, and 260 random files with a random slice
of each as `oldText`. Four deliberate deviations, all now in the class docblock with this run beside
them, and the sharpest is one where upstream is the one doing damage: it reads the file as UTF-8, so
**an edit to line 90 of a latin-1 file rewrites line 3 as U+FFFD**. This reads bytes and splices
bytes. `testAFileThatIsNotUtf8KeepsItsBytes` is what stops that "fidelity" being restored later.

**`ReadTool` and `WriteTool` went through a fourth run — 193 cases — and found nothing either.**
Every field of `details.truncation` agrees in all 62 cases that have one, and the bytes `write` puts
on disk agree everywhere. Five deliberate deviations, in the two docblocks: the errors are pig's
words (upstream hands the model Node's `ENOENT` with an absolute path it never wrote), an empty
read's notice has no blank line in front of it, bytes that are not UTF-8 stay as they are, a bare
`GIF` magic is not a GIF here, and — the two worth knowing —

- **`write`'s byte count is bytes.** Upstream reports `content.length`, UTF-16 code units, in a
  sentence that says "bytes": `你好世界` is reported as 4 into a file of 12. The third shape from the
  index, in its sixth guise.
- **An offset or limit that is not a whole count is made one.** The schema says `number`, so a model
  can send `2.7` or `-1`; upstream carries the fraction into its own notice (`Use offset=2.5 to
  continue`) and turns `limit: -1` into `slice(0, -1)`, reading all but the last line. Here the cast
  is load-bearing rather than tidy — `array_slice()` takes `int`, so a float is a `TypeError` out of
  the tool, which the mutation check is what showed.

**`ls`, `find` and `bash` were read against their upstream files rather than run**, because the two
search tools are `fd` and `rg` with flags — where the differences that matter are the three traps
below about globs and exit codes — and `bash` is a process, not a function. What that reading found
is one bug (the signal number, below) and a row of places where **upstream mishandles a bad argument
and pig clamps it**: `ls` with `limit: 0` answers `(empty directory)` for a directory full of files,
`find`'s non-zero-with-partial-output branch is dead code for every exit code `fd` actually produces,
and `ls` skips an entry it cannot `stat` where pig lists it — a broken symlink is a real entry, and
hiding it is how the model ends up unable to explain what it is seeing. `ls`'s sort adds a `strcmp`
tiebreak upstream has no equivalent of, so two names differing only in case have an order.

**`Keys` went through a fifth run — 35 predicates over 1,568 inputs, 54,880 answers compared — and
found nothing either.** The corpus is every single byte, every `\e`+byte, the CSI finals and the
tilde family, SS3, CSI with one and two parameters over the finals that matter (including lock-bit
modifiers, 65/66/129/130), two dozen malformed shapes (`\e[`, `\e[;u`, `\e[1;2;3u`, `\e[999999999u`)
and some multi-byte text. The generated kitty table was compared too: pig rebuilds upstream's
eighteen `Keys` constants from `Keys::kitty()` and all eighteen match byte for byte.

What the run could not cover, and was read instead: `isCtrl()` is **wider** than upstream's
`isKittyCtrl()` — it takes the raw byte as well, by arithmetic, where upstream has a thirteen-entry
table each `isCtrlX()` consults separately — and it **throws** on a letter that is not lowercase
where upstream answers false. Both are now on the method; the second is the interesting one, since
`isKittyCtrl(data, 'C')` is silently false upstream for a caller who meant Ctrl+C.

**The lesson of the five runs is the same one: a hand-rolled replacement for a package is worth a
corpus, and the corpus is worth keeping the count of.** "It matched on the cases I thought of" is
what reading gives you — and a run that finds nothing is only worth having if the count is written
down, or the next reader has to take it on trust. The sixth and seventh are the two entries below,
and they add a second half to it: **a corpus is worth re-reading for the question it never asked** —
which is where "two pastes in one read" came from — and a file of pure functions is worth one even
when reading it finds nothing, which is where the 150,000-line PNG came from.

**The eighth found the package wrong rather than pig**, and it is the one to reach for the argument
from: `DiffView::words()` replaces `diffWords`, and reading it against `diff.ts` found nothing at all
— the two are line for line. Run against `diff@8` over 436 edited line pairs, **106 of upstream's
removed lines are drawn with text the file does not have.** `diffWords` ignores whitespace, so a
whitespace-only change comes back as one unchanged part holding the *new* string and upstream
appends it to both lines: re-indent a block and its `-` line shows the `+` line's indentation, with
nothing marked. pig's is 0, and the run also paid for itself in the other direction — pig's
tokenizer was splitting on whitespace alone, so `foo(bar, ⟨baz)⟩` marked a paren that did not
change, and matching `diffWords`' granularity took the disagreements from 279 pairs to 177. *A
corpus is worth running even when the reading found nothing, and the answer can be that the package
is the one that is wrong.*

**The ninth is upstream against itself, and it is the entry below.** `mom` has its own copy of
`generateDiffString`, so the question was not "does pig match upstream" but "do upstream's two copies
match each other" — and the answer is no, in the line numbers. Nothing here changed; what changed is
that pig's numbering is now known to be the right one of two rather than the only one on offer.

**The tenth is `Fuzzy`, and it found a live bug in the one place the corpus was overdue.** 3,078
comparisons against `fuzzy.ts`; the four notes on that file had all been read rather than run, and
one of them was a claim the run refuted. What it cost until then was the `/resume` search box going
empty on a full-width space — see [A space that is not U+0020 emptied the search
box](#a-space-that-is-not-u0020-emptied-the-search-box). *Reading that file found nothing, twice.*

### `--continue` opened the conversation you were not working on

The most-used flag in the tool, wrong in the most ordinary way there is. `listFor()` sorted the
session files by **name**, with a comment arguing that the names sort themselves because they begin
with an ISO timestamp. They do — the timestamp the conversation *began* at, and a session file is
never renamed. So "newest" meant **most recently started**, where `--continue` asks for the one last
worked on.

Start A on Monday, start B on Tuesday, spend Wednesday in A. Measured through `latestFor()`:

```
A  2026-09-28T03-27-14-295Z_…jsonl   mtime=1790566095   ← worked on today
B  2026-09-28T03-27-15-296Z_…jsonl   mtime=1790566035
pig --continue picks:  B
upstream would pick:   A
```

Upstream's `findMostRecentSession` stats each file and sorts by `mtime`. It also decides the order of
the `--resume` picker, so the row under the cursor was the stale one — and `Tui\Fuzzy`'s own note
leans on that order ("the list is sorted newest-first before it gets here, and ties coming back
shuffled would look like the list had lost its order").

Three things worth keeping:

- **The name breaks a tie**, so two files written in the same second have a fixed order rather than
  whatever the directory hands over. `usort` is stable, so mtime alone would fall back to glob order
  — which is alphabetical, which is the bug again for exactly the files a tie affects.
- **A path that has gone between the `glob()` and the `filemtime()` is skipped**, not stat-ed. `@` is
  not allowed here and a file that vanished is not a session to list.
- **Both READMEs already said the right thing** — "`--continue` picks up the last one", "pick up
  where you left off", 「接上最近一次」 — so nothing there needed changing. *A sentence in the README
  describing intent is a test nobody runs*: it had been false since the flag existed.

Found by reading `session-manager/file-operations.test.ts` as a specification — the `it()` named
"returns most recently modified session", which is a claim about `mtime` and was the one line in that
file pig had no counterpart for.

Regression tests: `SessionManagerTest::testContinueOpensTheOneLastWorkedOnRatherThanTheOneStartedLast`
and `testSessionsWrittenInTheSameSecondDoNotShuffle` — one per end, and the second is what stops the
fix becoming "sort by mtime and let ties shuffle".

### A handover carried the files it was supposed to be summarising

`BranchSummarization::prepare()` drops tool results, because a result's context is in the assistant
message that asked for it and results are the bulk of a long branch. It dropped them **inside the
budget walk**, and the method short-circuits when the budget is 0 — which means "no limit" — so a
model that declares no context window got the whole branch *plus* every file it had read. Measured on
one 28KB `read`:

```
budget=0       kept=4  tool results kept: YES  request chars=28808
budget=100000  kept=3  tool results kept: no   request chars=792
```

36× the request, and with a real file the whole of it: the model is asked for a handover and handed a
file dump to write it from. Upstream drops them in `getMessageFromEntry()`, before any budget is
looked at, which is where the reason applies — **it is a rule about what a handover is made of, not a
way of saving room.** A rule gated on a budget is a rule in the wrong place.

Not reachable from `bin/pig` today: `CustomModels` refuses a `contextWindow` that is not positive and
no built-in has one, so this is latent on public surface — the same standing as `Input::setValue()`'s
cursor and the three image-encoder falsiness sites. The reason to fix it rather than note it is that
`Compaction::shouldCompact()` already carries a `contextWindow > 0` guard *because* a model declaring
no window is a case pig has acknowledged as real.

**And upstream has the mirror bug in the same function, which pig does not.** `prepare()`'s docblock
has always said the file lists come from every message while the prose comes from as much as fits.
Upstream's first pass collects only a nested branch summary's carried-forward lists;
`extractFileOpsFromMessage()` is in the **second** pass, inside the loop that `break`s at the budget
— so every ordinary message older than the cut contributes no files at all, while its own comment
says "collect file ops from ALL entries". A long branch there reports the files touched at its recent
end and silently forgets the rest, which is exactly the failure pig's arrangement was written to
avoid: a summary that forgets a file it edited sends whoever reads it to a file on disk that no longer
matches.

`prepare()` had **no direct test at all** — the same size of unchecked unit as `Config`'s path
arithmetic and `Process`'s 370 lines. Regression tests:
`CompactionTest::testAToolResultIsNeverPartOfAHandover` over three budgets including 0, and
`testTheFileListSurvivesABudgetThatDropsTheMessageThatNamedIt`, which is the one that goes red if the
file lists are ever moved behind the budget into upstream's arrangement.

### Upstream has two diff renderers and the second one numbers the wrong lines

Found by asking what the documented file-list sweep leaves out: it names four of upstream's seven
packages, and `mom` — the Slack bot — carries its own copy of `generateDiffString` inside
`tools/edit.ts`, beside second copies of `read`, `write`, `edit`, `bash` and `truncate`. Two copies of
the function pig ran a 515-pair corpus against, by the same author in the same repository, which is
the first shape from the index with upstream on both ends of it.

They differ in **where the skipped leading context is added to the line counter**. `coding-agent`
bumps `oldLineNum`/`newLineNum` by `skipStart` *before* printing the context lines; `mom` bumps them
*after*. So mom's leading context is numbered from 1 however far into the file the edit is:

```
=== mom ===            === coding-agent ===
    ...                    ...
  1 line 17             17 line 17
  2 line 18             18 line 18
  3 line 19             19 line 19
  4 line 20             20 line 20
-21 target             -21 target
+21 TARGET             +21 TARGET
```

Measured rather than reasoned about — both functions run side by side over 200 single-line edits at
every position in files of 5 to 44 lines: **151 of 200 disagree**, which is every edit far enough in
for an ellipsis. The 49 that agree are the ones near the top, where `skipStart` is 0 and the two
arrangements are the same arithmetic. What it costs mom is the one thing a diff has to get right for
the reader it is shown to — pointing at the line — and the `-`/`+` lines are correct throughout,
which is exactly what makes it hard to notice.

**pig ported the right one and it is pinned**, which was checked the only way that counts: mutating
`EditDiff::render()`'s leading-context loop to number from 1 turns
`EditToolTest::testDistantContextIsElidedWithAnEllipsis` (` 11 line11` → `  1 line11`) and
`testLineNumbersArePaddedToTheWidestOne` red. So this is a find with no fix and no new test, and the
reason it is written down is the method: **a sweep command is a claim like any other, and upstream's
own second implementation of something is the cheapest corpus there is** — it was written by somebody
who knew the problem, so where it disagrees, one of the two is wrong and the diff is short enough to
read.

### Two pastes in one read, and only the first one arrived

`Components\Input` was read against `input.ts` with the corpus that had already been built for it —
and **extended, which is where the find came from**. The first run asked about keys: every editing
key from every cursor position, 500 random walks, wide text, emoji, and a paste split across chunks.
It did not ask what happens when a chunk holds *two* pastes, and that is the shape a terminal
produces on its own: paste twice in quick succession and both bracketed blocks are written before
anything reads.

`buffer()` took the start marker off with `str_replace`, which replaces **every** occurrence. So the
two blocks ran together — `first ␛[201~second␛[201~` — the first end marker closed the pair, and the
rest went back through `handleInput()` as `second␛[201~`, which is not printable and was dropped. A
paste the person watched themselves make, gone, with nothing on screen to say so. Removing only the
first marker leaves the second block whole for the `$rest` recursion that already exists for input
typed after a paste, and pig then agrees with upstream on the case.

**And the two shapes where upstream is the one losing text are kept as pig's own**, because xterm
does not escape the markers in what it sends, so a paste really can carry one:

| in one chunk | upstream | pig |
|---|---|---|
| `␛[200~abc␛[200~def␛[201~` | pastes `abc␛[200~def` — an escape sequence into the prompt | `abcdef` |
| `␛[200~one` then `␛[200~two␛[201~` | `two`: its start check runs in front of its in-paste branch, so the marker **resets the buffer** and `one` is thrown away | `onetwo` |

An escape sequence in the prompt is the one thing `Chars::isPrintable()` keeps out of every other
door, and discarding half of what somebody pasted is worse than keeping both halves.

**The second find is a promise the class made and did not keep.** Its docblock says the cursor
"only ever lands on a grapheme boundary", and every method there leans on it — and `setValue()`
clamped with `min($cursor, strlen($value))`, which is upstream's line and cannot hold in bytes.
Cursor 4 in `abcdef`, then `setValue('你好')`: four bytes in is the middle of `好`,
`Graphemes::split()` of half a character answers false, and what comes out is
`Grapheme split failed` — from `render()`, inside the renderer, or from the next keystroke, inside
the loop's input callback, where nothing catches it. Measured rather than reasoned about: the probe
prints `valid utf-8: NO` and then three throws.

Upstream clamps UTF-16 code units, where the same slip lands between surrogate halves and
JavaScript carries a lone surrogate around without complaint. **`setValue()` has no production
caller in either tree**, so this is a latent hazard on public surface rather than a live bug — and
the reason to fix it rather than note it is that the invariant is what the other twelve methods are
written against. `boundaryAt()` snaps back to the start of the character the offset fell inside,
because the cursor sits *in front of* a character and Backspace there should take the whole of it.

The rest of the file matched, and the numbers are the point of saying so: 1,714 sequences, both
sides' value, cursor, submissions and rendered lines compared at nine widths — 15,426 renders. What
survives is five differences, all on the methods they belong to, and one of them is upstream's bug
rather than a difference of opinion: **upstream draws lines of the wrong width on wide text**,
because it slices its scroll window by character index — 79 of those renders at the three ordinary
widths and 211 more at narrow ones, against pig's 0 at every width above one column, where the
`> ` prompt is wider than the terminal in both and the whole package overflows anyway.

Regression tests: `InputTest::testTwoPastesInOneReadBothArrive`,
`testAStartMarkerInsideAPasteIsNotTextAndIsNotTheEndOfIt`,
`testSettingAShorterValueLeavesTheCursorOnACharacterAndNotInsideOne`.

### A PNG that said it was nothing wide asked for 150,000 lines

`terminal-image.ts` is six pure functions and four header readers, which is the shape a corpus
answers completely, so it got one: 136 payloads — every format at its edges, truncated, wrong-magic,
zero-sized, random bytes, and base64 that is not base64 — plus 462 row calculations over a grid of
image, cell and target sizes, and 1,384 encoder calls across both protocols. Two finds, and they
are the same question asked of two different zeroes.

**The first is a hang from a file somebody else wrote.** A PNG's IHDR is four bytes of width and
four of height, and nothing says they cannot be zero. Upstream hands back `{0, 0}`; pig did the
same, and `rows()` then divides by the width. Measured through the real component:

```
png     0x0     header=0x0      lines in the frame=1
png     0x100   header=0x100    lines in the frame=3000
png     0x5000  header=0x5000   lines in the frame=150000
```

150,000 lines, handed to a renderer that diffs every line against the last frame, **on every frame
for as long as that tool result is on screen**. And the bytes come from outside — a model's image, a
hook's screenshot, a custom tool's result — so this is a hang somebody else gets to choose. Upstream
divides by zero instead and gets `Infinity`, which its own component turns into an array of that
length.

The fix is at the source rather than in the arithmetic: **a header that says zero is read as no
header at all.** `Components\Image` already had the path for it — the size it assumes for a header it
could not read — so a malformed PNG now draws at 800×600 and takes 23 lines. One private `size()`
helper for all four readers, because a rule about what counts as a size belongs in one place; the
lossless WebP shape cannot produce a zero at all and goes through it anyway, since a reader that is
the exception is a reader somebody has to check before trusting.

`rows()`' two `max(1, …)` divisors stay and now say what they are for, which the conventions ask of
every clamp: the method takes an `ImageSize` rather than reading one, so a caller can still build a
zero, and dividing by it is a `DivisionByZeroError` thrown out of a render.

**The second find is JavaScript's falsiness, in three places at once** — the third shape from the
index, and the one this file already has an entry for under `getenv()` answering `''`. Upstream
guards its optional parameters with `if (options.x)`, which skips `0` and `''`; pig had `!== null`,
which does not:

| | upstream | pig had |
|---|---|---|
| `kitty(columns: 0)` | no `c` at all | `c=0` — and `columns: -5` gave `c=-5`, which is not a size |
| `iterm2(name: '')` | no `name` | `name=`, a base64 field holding nothing |
| `fallback(filename: '')` | `[Image: [image/png]]` | `[Image:  [image/png]]`, which reads as a name that went missing |

None of the three was reachable from pig's own callers — `Image::render()` clamps the cell count to
at least one, and nothing passes a name or a filename — so this is fidelity on public surface rather
than a live bug, and the corpus is what found it: **1,384 encoder calls, 320 of which differed, all
of them one rule.** With it fixed the encoders and the fallback agree with upstream on every one.

What is left differing is four rows, each pig's own with the reason on the method: the zero rule
above (nine payloads), the two divisor guards (147 row calculations, all of them a zero on one side
or the other), strict base64 where upstream's `Buffer.from` salvages what it can from junk (two
payloads), and the GIF signature compared byte for byte where Node's `toString("ascii")` **masks the
high bit** — so `\xc7IF87a` is a GIF to upstream and reports a size.

Read rather than run, and both are dead ends in upstream: `maxHeightCells` is declared in
`ImageRenderOptions` and in `image.ts` and read in neither, and the `offset + 3 >= buffer.length`
guard inside the JPEG segment walk cannot fire, because the loop it sits in already stops at
`length - 9`. Neither is ported, and the second is worth knowing before somebody adds it back as a
missing check.

Regression tests: `ImageTest::testAHeaderSayingZeroIsNotASize`,
`testRowsStillAnswersForASizeACallerMadeUpItself`,
`testASizeThatIsNotASizeIsLeftOutRatherThanSentAsOne`, `testAnEmptyNameIsNotAName`,
`testAnEmptyFilenameDoesNotLeaveASpaceWhereANameWouldBe`.

### An edit was approved with nothing on screen but a path

`edit-diff.ts` has a second reader pig did not have: `computeEditDiff()`, which upstream calls from
`setArgsComplete()` the moment the assistant message ends — so the diff is drawn from the file **as
it still stands**, before the tool runs. `EditDiff::preview()` is it, and `ToolExecutionComponent`
now computes it in the same place.

**The reason for porting it is not that the diff arrives a second early.** The gap between "the model
finished writing this call" and "the call ran" is normally nothing — and is however long a
`tool_call` hook takes to ask whether the edit may run. That guard is the whole reason hooks can ask
anything, and until this it asked *"Let edit run?"* with a path and no diff: approving a change to a
file without being shown the change.

The comment that used to sit in `ToolExecutionComponent::edit()` said this was left out because a
preview "would mean a second copy of the replace logic". That was wrong twice, and both halves are
worth keeping: upstream does not have a second copy either — `edit.ts` and `computeEditDiff()` share
`edit-diff.ts` — and the sharing was available here too, which is what `EditDiff::apply()` is. **A
reason written next to a deviation is only worth what is in it**, and "we would have to duplicate it"
should always be read as a question about whether it can be shared.

Three things decided while porting it:

- **The result's diff still wins once the tool has run.** Upstream keeps showing the preview, which
  is the same text in every case but one — somebody changed the file in between — and there the file
  on disk is the honest answer.
- **`apply()` is shared, so a refusal cannot be worded twice.** Upstream's two readers have already
  drifted: `No changes made to` against `No changes would be made to`, one fact in two sentences.
  `testAPreviewRefusesInTheWordsTheEditWouldHaveUsed` asserts the two messages are the same string.
- **No cwd means no preview**, rather than resolving a relative path against whatever directory the
  process happens to be in. The two `!command` call sites pass none and neither is ever an edit.

**And the harness had two gaps that this could not have been tested through**, which is the part
worth remembering. `InteractiveModeTest`'s provider stub pushed `StartEvent` and `DoneEvent` and
nothing between, so no `MessageUpdateEvent` was ever emitted and a tool call's component was created
by `tool_execution_start` — *after* the message had ended. And it called `$agent->setTools()` without
`HookedTool::wrap()`, so **no `tool_call` hook could fire in any test in that file**: a guard that
works in production was unreachable from the one place that drives the whole front end. Both are
fixed, and the second is the same shape as the settings that were "live in production and inert in
every test" a few entries down.

Regression test: `InteractiveModeTest::testAnEditIsOnScreenBeforeAHookIsAskedWhetherToAllowIt`,
which parks a real turn on a real dialog and checks the diff is up and the file is not yet changed.
It fails if either end of the wire is removed — the `setArgsComplete()` loop or the cwd. The
component's own end is the five `ToolExecutionTest` cases under "an edit shown before it happens".

### A hook's message was the one long thing in the transcript ctrl+o could not fold

`setExpanded()` exists on `ToolExecutionComponent`, `CompactionComponent` and
`BranchSummaryComponent`, and `InteractiveMode`'s ctrl+o handler names those three. It could not
name `HookMessageComponent`, because that class had no such method: a hook's message was drawn
whole, for ever, while ctrl+o folded every tool call around it. Upstream cuts it to five lines with
a `...`.

What that costs is not cosmetic, and it is visible in what the method is *for*: a hook's
`sendMessage()` is how a build failure, a lint report or a diff reaches the model, so the long
message is the common case rather than the edge one. This file's own example for the feature is "a
build that just failed".

The instructive part is the second commit. Folding the component made the four tests pass and **the
`instanceof` line was still untested** — removing it changed nothing, because the tests drove the
component directly. That is the same *wired at one end only* shape as the bug, made again in its own
fix, and it took a test that presses ctrl+o through `InteractiveMode` to close:
`testCtrlOOpensALongHookMessageTheWayItOpensEverythingElse`. **When the find is a missing
connection, a test of the two ends is not a test of the wire.** The mutation check is what says
which of the two you have written.

### `pig @notes.txt` on a latin-1 file was a dead session, and the reason is a platform difference

The same killer as the three entries below, on the one input that looked obviously safe: **what the
person said.** Four transcript paths had been found and guarded — a tool's output, a diff, a hook's
message, a typed command's output — and `UserMessageComponent` was not among them, because a
keystroke is a keystroke. It is not:

```
pig @notes.txt "what is wrong here"        # a latin-1 file, or anything saved from an editor
pig $'ask about \xe9 this'                  # a shell hands over bytes, not text
```

Either one throws `Grapheme split failed` out of `render()` on the **first frame**, before a word of
it reaches the model. Traced twice, because fixing the transcript uncovered a sixth path:
`TreeList::oneLine()` — so `/tree` on such a conversation was a second dead session.

**The root of it is a platform difference, not an oversight.** Upstream reads an `@file` with
`readFile(path, "utf-8")` and gets its argv from `process.argv`; Node decodes both as UTF-8 and
substitutes U+FFFD for a byte it cannot read, so upstream's `<file>` element and its messages are
**always text**. `file_get_contents()` and PHP's `$argv` hand over bytes. That is the mirror of the
usual case in this file — where PHP has what JavaScript reaches for a package for — and it is worth
stating as its own question: **what does upstream get for free from its runtime that PHP does not?**
Both doors are decoded where they read now, which is where upstream's decoding happens.

And the display paths are guarded too, because the entry doors are not the only way in — a **paste**
goes through the editor, and `mb_str_split()` keeps a malformed byte as it finds it. That is
`UserMessageComponent` (the fifth path) and `TreeList::oneLine()` (the sixth).

**Then the same read found four implementations of "fold a message into one line for a list", each
wrong in its own way** — the first shape from the index, at its largest so far:

| | pattern | what a malformed byte did |
|---|---|---|
| `SessionManager::opening()` | `/\s+/u` | nothing: it only ever reads what `append()` wrote, and that went through `JSON_INVALID_UTF8_SUBSTITUTE` |
| `HtmlExport::opening()` | `/\s+/u` | `preg_replace` answers **null**, `(string) null` is `''`, so the heading fell through to "a conversation" and the page lost what it was about |
| `TreeList::oneLine()` | `/[\r\n\t]+/`, no `/u` | never null and never cleaned either, so the bytes reached `Width::truncate()` — the dead session above |
| `InteractiveMode::describe()` | `/\s+/u` | nothing, because **it has no caller**: `/tree` uses `TreeList::describe()`. Dead code, left alone pending a decision — see below |

The rule the second and third rows share: **a `/u` pattern over text from outside is a silent
truncation**, exactly as `htmlspecialchars()` and `json_encode()` are, and the same family this file
already names. `preg_last_error()` *does* say 4 afterwards, and nobody asks it; the `(string)` cast
turns a failure into an empty string one character later.

`SessionManager::opening()` is the interesting row: it is right, and only because a guard one method
away happens to cover it. `testASessionThatOpensWithBytesThatAreNotUtf8StillHasALabel` pins that
rather than the code, so the day somebody reads the label from memory instead of from the file, it
goes red.

Regression tests, one per end, each of which the other four leave green:
`FileArgumentsTest::testAFileThatIsNotUtf8ArrivesAsTextRatherThanAsItsBytes`,
`ArgumentsTest::testAMessageFromTheShellArrivesAsTextRatherThanAsBytes`,
`InteractiveModeTest::testATranscriptDrawsAMessageWhoseBytesAreNotUtf8`,
`testATreeRowSaysWhatWasSaidEvenWhenItsBytesAreNotUtf8`,
`HtmlExportTest::testATitleSurvivesAFirstMessageWhoseBytesAreNotUtf8`.

### One byte that is not UTF-8 in a tool's output took the session down

`cat` on anything that is not text — a binary file, a latin-1 log, `head /dev/urandom` — ended the
session:

```
Pig\Tui\TuiError: Grapheme split failed: Malformed UTF-8 characters, possibly incorrectly encoded
```

Everything that measures or cuts a line goes through `Graphemes::split()`, which is
`preg_match_all('/\X/u')`, and `preg_match_all` **answers false on malformed UTF-8**. So one stray
`0x80` in what a tool said threw out of `render()`, inside the loop's own input callback, where
nothing catches it. Reproduced from four shapes: a stray continuation byte, a truncated multi-byte
sequence, a lone `0xff`, and a surrogate code point encoded as UTF-8.

Upstream has `sanitizeBinaryOutput` for exactly this, applied in the same method, with a comment
saying it is there because its width function crashes otherwise. **And pig already had the function
— `Ai\Utils\Utf8::sanitize()` — with no caller on this side of the tree**, because it was written for
the other end of the same problem: making a request body encodable. One end of the pipe sanitised and
the other did not, which is this file's commonest shape with an unusually bad consequence.

Two things worth keeping:

- **The session file was already safe and that is why nothing noticed.** `SessionManager` encodes
  with `JSON_INVALID_UTF8_SUBSTITUTE`, so a binary tool result persists as U+FFFD rather than
  failing the write. The display path was the only one without a guard, and the display path is the
  one that must never throw.
- **`Graphemes::split()` returning false is a whole class, not one site.** Anything that reaches it
  with bytes from outside — a hook's message, a custom tool's text, a pasted line — has the same
  ending. Sanitising at the display boundary is what was reproduced and fixed; if another source
  turns up, this is the entry, and `Utf8::sanitize()` is the call.

Regression tests: the four `ToolExecutionTest::testOutputThatIsNotUtf8IsDrawnRatherThanFatal` rows,
which assert the frame is drawn *and* is UTF-8 rather than merely not throwing.

### One `read` of a binary file ended a gateway session for good

Five places encode a conversation, and four of them had an answer to bytes that are not UTF-8: the
providers sanitise each text block, `SessionManager` and `RpcMode` pass
`JSON_INVALID_UTF8_SUBSTITUTE` — and `RpcMode`'s docblock says exactly why, which is the sentence
that names the bug in the fifth:

> a tool that read a binary file must not be able to stop the protocol dead

`Agent\StreamProxy::request()` was the fifth, and it encoded the whole conversation with neither.
So `read` or `bash` on one latin-1 log made `json_encode` answer false and the turn died with
`The conversation could not be encoded for the proxy` — **and then so did every turn after it**,
because the result stays in the conversation, and compacting it away needs a model call through the
same encoder. A gateway session was over, permanently, with a message that names nothing the person
did.

Fixed with the flag rather than `Utf8::sanitize()` per field: this encodes the whole request in one
call, so a flag cannot miss a field somebody adds later. The `=== false` guard stays for what the
flag does not cover — a recursive structure, a float that is not a number.

**The shape is the first one in the index, at its purest**, and worth noting how it was found: not
by reading `StreamProxy`, but by asking of `read`'s output "where does this end up, and who deals
with it there" — which is the same question the `edit` diff entry above came from, pointed at a
different pipe. `HtmlExport` is a sixth site and was checked: it keeps every byte.

Regression test: `StreamProxyTest::testAToolResultThatIsNotUtf8StillReachesTheGateway`.

### A control character in tool output measures as nothing and moves the real cursor

`TuiBase::checkWidth()` throws on a line wider than the terminal, because a wrapped line puts every
later cursor move one row low and silent screen corruption is worse than a loud stop. **This is that
same failure arriving by the one route the check cannot see**: `\p{Cc}` is *zero columns wide*, so a
line carrying a form feed measures exactly right, passes, and is written to a terminal that then
drops a row anyway.

What reaches it: `\x0c` and `\x0b` drop a row, `\x08` leaves the padding measuring a column that is
no longer there, `\x00` is swallowed by some terminals and shown by others — and `\x07` is the one
that is worst to live with, because the transcript is redrawn as the conversation grows, so one bell
in a build log beeps again on every redraw. All of them are ordinary: `cat` a binary file, a
progress display that backspaces over itself, a `make` that rings.

Upstream has `sanitizeBinaryOutput` for exactly this and applies it in the same method. **pig had the
UTF-8 half of that function and not this one** — because the UTF-8 half arrived through
`Utf8::sanitize()`, which was written for the request body and pressed into service here, so the
control-character half was never missed.

Three transcript paths carry text that is not pig's own words, and **all three were different**: the
tool view sanitised UTF-8, `DiffView` gained it a batch later, and `HookMessageComponent` had
neither — so a hook that sent a build log with one stray byte in it took the session down, which is
the same killer a third time. `Tools\Shell::sanitize()` is the one rule now: escape sequences out,
malformed UTF-8 out, every control character but tab and newline out.

**There were three more, and counting them as three is how the fourth stayed hidden for four
batches** — the fifth and sixth are the entry above, and the lesson repeats: a count in this file is
a count of what somebody looked at.

**The fourth:**
`BashOutputComponent` — what a typed `!command` is drawn with — has no guard of its own, so
`!head -c 400 /dev/urandom`, or a `!cat` of anything that is not text, took the session down in
exactly the way above. Measured, with and without the fix: 200 bytes in, `valid utf-8 = false`,
`TuiError: Grapheme split failed`. It is not a missing guard, though, and that is the useful part —
**upstream's `bash-execution.ts` has no guard either, and is safe, because its command runner cleans
at the source.** Two files, one design: the entry on cleaning at the source is the other half of this
one, and until it landed pig had copied the component's half and not the runner's.

Regression tests: `BashOutputTest`, which is that component's first — three cases, and removing the
cleaning in `Run::read()` turns two of them red.

Two deliberate differences from upstream's version, and the second is the more useful one to
remember:

- **`\r` goes here and stays there.** A bare CR returns the cursor to column 0 and the rest of the
  line overwrites what was drawn. Upstream gets away with keeping it because its renderer is not
  differential.
- **U+FFF9–FFFB stay**, though upstream strips them. That is a crash in `string-width`; `Width`
  reads them as `\p{Cf}` and measures them at nothing, which is what they are. Same rule as
  `version_compare()` replacing upstream's arithmetic: **where upstream is working around a bug in
  its own dependency, the port is not the workaround.**

Regression tests: the five `ToolExecutionTest::testAControlCharacterInToolOutputNeverReachesTheTerminal`
rows, `DiffViewTest::testADiffOfAFileWithAFormFeedInItDoesNotMoveTheCursor` — a form feed is legal in
C and ordinary in Emacs-era source — and
`HookMessagesTest::testABuildLogAHookSentIsDrawnWhateverBytesAreInIt`.

### Editing a file with one byte that is not UTF-8 in it took the session down

The same killer as the entry below about `cat`, through the one path that fix did not cover, and
found by writing down *why* pig keeps a file's odd bytes where upstream rewrites them:

```
Pig\Tui\TuiError: Grapheme split failed: Malformed UTF-8 characters, possibly incorrectly encoded
```

`edit`'s display text is not the tool's output — it is a **diff, built from the file's own bytes**.
So `edit` is the one tool that can hand the renderer something that is not UTF-8 with no tool output
involved at all, and it is the only display path that never went through `Utf8::sanitize()`:
`ToolExecutionComponent::output()` sanitises, `edit()` handed `details['diff']` straight to
`DiffView::render()`. One latin-1 file, one log with a stray byte, and the next frame threw out of
`render()` inside the loop's own input callback.

The fix is at the diff's display boundary — `DiffView::render()`, whose docblock already said it is
the thing that paints a diff, and which is now where the bytes are made safe for every future caller.
The tool's own text is left alone: it goes to the model and into the session file, and both of those
have their own answers (`JSON_INVALID_UTF8_SUBSTITUTE` for the file).

**The shape, and it is the second time this exact question has been worth asking:** a sweep for
"which display paths sanitise" is not the same sweep as "which display paths are built from *text a
tool printed*". This one is built from a file, and that is why it was not on the first list.

Regression tests: the four `DiffViewTest::testADiffOfAFileThatIsNotUtf8IsDrawnRatherThanFatal` rows
at the boundary, and the four `ToolExecutionTest::testAnEditDiffThatIsNotUtf8IsDrawnRatherThanFatal`
rows through the component, which are the ones that reproduce the session ending.

### A budget handed out by flooring has to be corrected in both directions

A markdown table with three short columns and one prose column — an everyday shape in an answer —
came apart at **every width from 17 to 115**: the top border, the separator and every row each one
or two columns too wide, so the wrap in `Markdown::lines()` broke each of them onto a second row.

`columnWidths()` shares the budget out in proportion to what each column wants, floors each share,
and then hands back the columns that flooring lost. What it did not do is take back what
`max(1, …)` *added*: a column whose share rounded to nothing is given 1 anyway, and three of those
put the total three over the budget with nothing to remove it. Upstream has the same arithmetic and
the same half-correction.

```php
$widths = array_map(static fn (int $want): int => max(1, (int) floor($want / $total * $available)), …);
// hand back what flooring lost … and then take back what max(1, …) added, from the widest column
```

**The shape to remember: a proportional split with a floor *and* a minimum overshoots in both
directions, so both need correcting.** Flooring is the one everybody writes; the minimum is the one
that bites, because it only fires on the inputs where one column dwarfs the others — which is why a
three-prose-column table was fine and a table with a path and two tick marks was not.

It stayed invisible because the symptom is not an error: `TuiBase::checkWidth()` never fires, since
`lines()` wraps every rendered line before padding it. A line that is too wide is therefore *wrapped*
rather than refused, and a wrapped table border does not look like a width bug, it looks like the
renderer cannot draw tables. The test asserts the user-visible invariant instead — the top border is
one line and ends in `┐`, at every width the table claims to be drawable at:
`MarkdownTest::testATableIsOneTableAtEveryWidthItDrawsAt`, over four shapes and 109 widths each.

### A subprocess whose standard error nobody reads blocks forever with nothing on standard output

Pressing `@` froze the terminal for good. `CombinedAutocompleteProvider::runFd()` opened `fd`
itself, read standard **output** to EOF, and closed standard error unread — and `fd` writes a
warning per path whose metadata it cannot read. On a tree with enough restricted directories that
fills the stderr pipe, `fd` blocks writing to it, so it never closes stdout, so the read never
returns. There was no timeout either, and this runs from a keystroke inside the loop's own input
callback, so the whole UI went with it. Reproduced with a stand-in for `fd` that writes 360KB to
stderr before answering: the call never came back.

**`Process::run()` already drains both pipes, and its own comment names this exact failure** —
*"Read stderr too, or a command that writes a lot to it fills the pipe and blocks forever with
nothing on stdout to show for it."* So this is the first shape from the index above at its purest: a
rule written down in one place, with the reason beside it, and a sibling forty lines long that
re-implemented the same subprocess worse and did not follow it. The fix is not the missing drain
added a second time, it is the sibling deleted — `runFd()` now calls `Process::run()`, which brings
the timeout it never had.

The timeout is short (2s) and that is the interesting half: the query re-runs `fd` on **every
keystroke**, so a picker able to freeze the terminal for longer than a moment is worse than one that
comes up empty on a very large cold tree. Upstream blocks indefinitely, because `spawnSync` does.

**The general rule: `proc_open()` with a pipe nobody reads is a deadlock waiting for a noisy
command.** Either read every pipe you opened, or do not open it — and in this tree the answer is
almost always `Process::run()`, which is what the other callers use.

Regression tests: `AutocompleteTest::testFdFloodingStandardErrorDoesNotHangThePicker` — which hangs
rather than fails if the old read goes back, so it is worth knowing that a hanging suite here means
that line — and `testAQuietFdStillAnswers` beside it, so the fix cannot become "stop running fd".

### `getenv()` answers `''` for a variable exported without a value, and `!== false` calls that present

`Capabilities::detect()` decides whether this terminal can draw a picture, and four of its seven
tests are presence tests: is `KITTY_WINDOW_ID` / `GHOSTTY_RESOURCES_DIR` / `WEZTERM_PANE` /
`ITERM_SESSION_ID` there at all. Upstream reads each through JavaScript's truthiness, where `''` is
falsy; `getenv()` answers `false` for absent and `''` for set-but-empty, so `!== false` said yes to
an empty one.

```php
getenv('ITERM_SESSION_ID') !== false        // true  for `export ITERM_SESSION_ID`
(string) getenv('ITERM_SESSION_ID') !== ''  // false, which is what upstream reads
```

A bare `export ITERM_SESSION_ID`, a `docker run -e ITERM_SESSION_ID`, or an ssh or tmux environment
forwarding the name without a value therefore made pig send iTerm2 inline-image sequences to a
terminal that cannot draw them — **not a missing picture, tens of kilobytes of base64 printed into
the transcript**, plus a `CSI 16 t` whose answer never comes.

**`detect()` had no test at all** — twenty-five lines of environment arithmetic, which is the size
that goes unchecked, exactly as `Config`'s sixty lines of path arithmetic were. `CapabilitiesTest`
states all twelve terminals as well as the four empty-variable cases, and the twelve passing first
time is what says the rest of the detection is upstream's.

**`Tui\Env::isSet()` is the one implementation, and getting there took two goes.** The first fix put
it in `Images\Capabilities` as a private method and wrote the same comparison out again in
`Theme\Colour::truecolor()` — two copies with a comment between them — and left **four more sites
reading `!== false`** in `Clipboard\SystemClipboard`: `WAYLAND_DISPLAY`, `TERMUX_VERSION`,
`WSL_DISTRO_NAME`, `WSL_INTEROP`. Those are pig's own, with no upstream counterpart to be faithful
to, and what they cost is milder by a lot — the wrong clipboard command is chosen,
`Process::capture()` answers null for it, and the result is no clipboard rather than a screen full
of base64. They were left for a decision about public surface in `pig/tui`, which the developer has
since made: one `Env`, and a rule with six readers has one answer rather than two right ones, one
written-out one and four wrong ones. `EnvTest` states the rule and reaches three of the clipboard's
four through the methods that read them; the fourth is `linuxWrite()`'s command order, which has no
seam that does not involve running `wl-copy` for real.

`Env::home()` is the other half of that file and answers **null** where `Config::homeDirectory()`
answers the temp directory. That split is the point: pig's own files have to go *somewhere*, while
`Paths::expand()` leaves a `~` alone and the footer leaves a path unshortened — so a
`sys_get_temp_dir()` underneath all three would resolve `~/notes` into `/tmp`, which is exactly what
two hook loaders were caught doing. It also trims the trailing slash, which none of the four copies
did: with `HOME=/Users/dev/`, `~/notes` was `/Users/dev//notes` and the footer compared a path
against a prefix one character too long.

### The `@` picker had the third copy of `Paths::expand()`, and it was the worse one again

Word for word the same find as the two hook loaders below — `~` expanded, spaces not normalised —
one package over, which is why the sweep that caught those two did not see this one.
`CombinedAutocompleteProvider::expandHome()` is in `pig/tui`, and `Tools\Paths::expand()` is in
`pig/coding-agent`, so the picker could not have called it. What it cost: a path pasted out of
Finder has U+202F where the name on disk has a space, `is_dir()` says no, and the picker answers
**nothing at all** — the same silence, on the one input that arrives by paste rather than by typing.

U+202F is not whitespace to `\S`, which is why this is reachable: a pasted path with spaces in it
arrives as **one word**, where the same path typed with ordinary spaces is cut at the first one by
`pathPrefix()`. So a path with real spaces is not completable by either spelling — upstream's
behaviour too, and the one thing in that method that is a limit rather than a rule.

`Tui\Paths::expand()` is the implementation now and `Tools\Paths::expand()` calls it, which is the
only direction that works. Two things about the fix are worth more than the move:

- **Normalising is not enough on its own, because it breaks the other direction.** A macOS
  screenshot really is called `Screenshot … 4.31.05<U+202F>PM.png` on disk, and a folder somebody
  named `Q1<U+202F>2026` really has that space in it. So the directory is looked for under the
  normalised spelling **and then under the one that was typed** — the pair of answers
  `Tools\Paths::resolveForRead()` already arrives at by retrying — and the name comparison
  normalises **both sides**, so a pasted needle finds the file with the ordinary space and a real
  U+202F still matches itself.
- **Half of that second point was dead code until a test was written for it.** Mutating the
  name-side normalisation away broke nothing: the pasted-needle case passes with the needle alone
  normalised, and the reverse direction cannot be reached from here at all. What makes it
  load-bearing is the case neither test covered — a name holding U+202F, typed or pasted with
  U+202F — which matches only if both sides are normalised. Same as the whole-float branch in
  `JsonSchema::identity()`, except that one really was doing nothing and was deleted; **the
  mutation check is what tells those two apart, and only if you go looking for the missing case
  rather than deleting on the first silent mutation.**

Regression tests: `AutocompleteTest::testAPathCopiedOutOfAFileManagerStillCompletes`,
`testAPastedNameMatchesTheFileWhoseNameHasTheOrdinarySpace`,
`testAFileWhoseRealNameHoldsThatSpaceStillMatchesItself` and
`testADirectoryWhoseRealNameHoldsThatSpaceIsStillListed` — four, because there are two spellings on
each of two sides and no one case covers a second.

### A JavaScript string offset carried across as a byte offset lands inside a character

Four keystrokes killed the session, and all four of them are ordinary in a Chinese prompt: type
`你`, Shift+Enter, `ab`, Up. The next frame threw `Grapheme split failed: Malformed UTF-8` — out of
`Editor::render()`, which runs inside the loop's own input callback, so there is nothing above it to
catch. Typing anything before the frame was worse: it wedged the letter between 你's first and
second byte and left two invalid bytes in the buffer.

`Editor::moveVertically()` is a literal port of upstream's `moveCursor(deltaLine)`: take
`cursorCol - startCol`, clamp it, add it to the target row's `startCol`. **Upstream's offsets are
UTF-16 code units**, so for everything either project edits that arithmetic is a character count
and lands on a character. `substr()` counts bytes, so the same arithmetic aimed two bytes into a
three-byte character:

```php
// cursor two characters along `ab`, moving up onto `你` (e4 bd a0)
$this->cursorCol = $target->startCol + min($column, $target->length);   // 2 → between e4 and bd
$this->cursorCol = $target->startCol + self::lengthOfFirst($row, $offset);   // 2 graphemes → 3
```

**The rule: when upstream indexes a string, port the *unit* and not the expression.** A JS
`length`, `slice`, `indexOf` or `charCodeAt` offset is a code-unit count; `strlen`, `substr` and
`$s[$i]` are bytes, and the two agree only while the text is ASCII — which is exactly how long the
bug stays invisible. Where the offset is a cursor, counting graphemes is the same answer one step
safer than counting codepoints, because it also refuses to separate a combining mark or one person
of a family emoji from the rest.

This is the third shape from the index above — *PHP's value model is not JavaScript's* — in its
fourth guise, and the first one that crashes rather than misreporting: numbers split finer, arrays
split coarser, UTF-8 counts bytes where UTF-16 counts units. Anywhere a number crosses from one
string into another, ask what it is counting.

Found by a differential run against `editor.ts` — 1308 keystroke sequences — which crashed pig on
case 1258 before it could compare anything. With it fixed, the two agree exactly on text, lines,
cursor and submitted value, and every one of the 9990 drawn lines is exactly the terminal's width.
Regression tests: `EditorTest::testUpFromALongerLineLandsOnACharacterAndNotInsideOne`,
`testACursorMovedUpOntoAWideCharacterCanStillBeDrawn`,
`testTypingAfterMovingUpDoesNotSplitTheCharacterUnderTheCursor`,
`testUpAndDownKeepTheSameCharacterOffsetAcrossWideLines`.

### PCRE's `$` matches before a trailing newline, so `^…$` is not "the whole string"

`Width::visible()` starts with a fast path — almost every line is printable ASCII, where one byte is
one column — and it was guarded by `/^[\x20-\x7e]*$/`. That pattern **accepts `"abc\n"`**, because
`$` matches at the end *or* just before a final newline, so the newline went through `strlen()` and
the string measured four columns for three. The slow path disagreed: a newline is `\p{Cc}`, which is
zero, so the same characters measured 3 inside a longer string and 4 at the end of one. Anything that
pads a line to a width — `Width::background()`, and every box and selected row through it — was a
space short whenever the line ended in a newline.

```php
preg_match('/^[\x20-\x7e]*$/',  "abc\n");   // 1  — "$" forgives the newline
preg_match('/^[\x20-\x7e]*\z/', "abc\n");   // 0  — what was meant
```

**`\z` is the fix and the rule**: in a pattern that means "the whole string is nothing but this", the
end anchor is `\z`, never `$`. (`/D` does the same for the last `$` in a pattern; `\z` says it at the
place it applies, which is what a reader needs.) Found by a differential run against upstream's
`utils.ts`, not by anybody noticing a missing space — `truncateToWidth` disagreed on five multi-line
inputs and the width function underneath it was the reason.

The same shape is elsewhere in the tree and **was left alone**: `HookApi`'s `customType` and tool
names, `Skills`' name check, `Editor`'s "is this word completable" — each is `^…$` against a string
that would have to end in a newline to slip through, and none of them can be reached with one today.
Changing them tightens what is accepted, which is a behaviour change with no reproduced bug behind
it. If one of those inputs ever comes from somewhere new, this is the entry to remember.

### Apple Terminal's Shift+Enter is `\e\r`, which pig read as Alt+Enter and sent the prompt

Shift+Enter sent the message instead of breaking the line. `php /tmp/keyprobe.php` in the
developer's terminal: Shift+Enter is `"\u001b\r"`, Enter is `"\r"`. Apple Terminal does not speak
the kitty protocol, so it never sends `\e[13;2u`; it sends ESC-prefixed CR, which is **also** the
ESC-prefix spelling of Alt+Enter that `Keys::isAltEnter()` accepted and `Keys::spec()` generated for
`alt+enter`. `CustomEditor::claimed()` asks `Keybindings` first, `app.message.followUp` matched, and
the key never reached `Editor::isNewLine()` — which has read `\e\r` as a new line all along.

One byte string, two keys with opposite meanings at a prompt; pig cannot tell them apart and has to
pick. Shift+Enter is the one pressed a hundred times a day and Alt+Enter has `command+enter` beside
it, so `\e\r` goes to the editor: `isAltEnter()` is the kitty form only, and `spec()` builds no
ESC-prefix legacy for `alt+enter` (it still does for `alt+<letter>`). The three `InteractiveModeTest`
cases that typed `\e\r` for Alt+Enter now type `\e[13;3u`.

Regression: `KeysTest` (`isAltEnter("\e\r")` false, `matchesName("\e\r", 'alt+enter')` false) and
`KeybindingsTest::testWithNoFileTheDefaultsAreUpstreams`, which asserts `\e\r` is nobody's action.
*When a key does the wrong thing, measure the bytes before reading the table* — the table was right
about every key it named and wrong about which key the bytes were.

### Erasing vanished rows with `\r\n` scrolled the screen, one row per vanished line

Blank rows under the footer from the moment a turn started: rows a shrinking frame leaves behind were
erased with `\r\n\x1b[2K`, and **a newline on the terminal's last row scrolls**. Upstream's
`TuiMainScreen` has a separate "all changes are in deleted lines" path that moves with `\x1b[1B` and
clears without scrolling; pig now runs that literally. `PIG_TUI_TRACE=<file>` on
`ProcessTerminal::write()` records every byte for replaying a real session through a VT emulator —
a hand-rolled emulator that does not scroll on a newline at the last row will agree with the bug.

Regression tests: `TuiTest::testErasingVanishedLinesMovesDownRatherThanScrolling`,
`TuiMainScreenTest::testClearsAllRenderedLinesWhenContentShrinksToZero`.

### The Antigravity extension read the stored token raw, and it lasts an hour

`/antigravity.usage` an hour after signing in: `401 Request had invalid authentication
credentials. Expected OAuth 2 access token`. The extension's `$getAuthAndToken` read
`$auth->credentials(Provider::Antigravity)->access` — the token **as stored**, which Google issues
for an hour. The renewal lived in one place, `Auth::apiKey()` → `fresh()`, and the extension could
not use that because `apiKey()` joins token and project into one string for `Providers\Antigravity`
to split, where the quota endpoint wants them apart. So it took the other door, and the other door
had no renewal behind it. **Two readers of one credential, one of which knew it expires** — the
first shape from the index, on a token.

`Auth::freshCredentials()` is `credentials()` plus the renewal `apiKey()` does, for a caller that
needs the parts. The extension goes through it; `Doctor` keeps `credentials()` because it only
counts. Both take a provider *name* now as well as the enum, since the provider is an extension's. Regression tests: `AuthTest::testFreshCredentialsRenewsTheWayApiKeyDoesRatherThanHandingBackTheExpiredHalf`
and `testFreshCredentialsIsTheStoredOnesWhileTheyStillHold`.

### A browser opens a connection it never uses, and closing it under its watcher killed the sign-in

The first real Claude browser sign-in ended with the tokens in hand and pig dead:
`pig crashed: Reader r505 watches a closed stream; cancel the watcher before closing it`. Chrome
opens a **speculative second connection** beside the callback — a preconnect — and never writes to
it, so its reader was still armed when the flow's `finally` called `CallbackServer::close()`, and
`close()` `fclose()`d every connection without cancelling the readers. The trap below about
closing a stream without cancelling its watcher, in a class whose `accept()` already knew the rule
for the *finished* path and not for the abandoned one. `drop()` cancels first now, `close()` goes
through `drop()`, and a reader that sees EOF drops its connection rather than staying armed on a
stream that will never be readable again.

Two things in the fix: the reader ids are kept in a map rather than captured by reference in the
closure, because the one place that needs them all is `close()`, which the closure cannot reach;
and the `(string)` on `array_keys()` is the hex-id trap — a numeric key comes back as an int.
Regression tests: `CallbackServerTest::testAConnectionThatNeverSaysAnythingDoesNotTakeTheLoopDownWhenTheServerCloses`
(red on the old `close()`, with the same message) and `testAConnectionTheBrowserHangsUpOnIsLetGo`.

### A signal makes `stream_select()` return `false`

Terminal resize sends SIGWINCH. With a handler installed, an in-flight `stream_select()` is
interrupted and returns `false` with errno 4 — reproduced with `pcntl_fork` + `posix_kill`, and
the whole agent dies on the next resize. EINTR is not a failure: the handler already ran, so
return and let the next tick re-arm. Match the errno, not the message text, which is localized.

```php
error_clear_last();                        // so error_get_last() cannot report a stale warning
$ready = @stream_select($read, $write, $except, $seconds, $microseconds);

if ($ready === false) {
    $message = error_get_last()['message'] ?? 'unknown error';
    if (str_contains($message, 'Unable to select [4]')) return;   // EINTR
    throw new AsyncError("stream_select() failed: {$message}");
}
```

Regression test: `LoopTest::testASignalDuringSelectDoesNotKillTheLoop`.

### A tick polls after its callbacks, even when they ended the program

`tick()` runs deferred callbacks and only then polls. A coroutine always resumes in that first
phase, so the root coroutine can *finish* there — and the same tick then settles into
`stream_select()` with no timeout, waiting on watchers whose only interested party is gone.
Found as a hang in a test whose server socket stayed armed and never became readable again;
other tests survived only because a timer or a readable stream happened to break the poll.

`Async::run()` therefore wakes the loop from the fiber's `finally`. Anything else that finishes
work during the callback phase and leaves nothing to poll for must do the same.

```php
$fiber = new Fiber(static function () use ($main, $loop): mixed {
    try { return $main(); } finally { $loop->wake(); }
});
```

Regression test: `FutureTest::testRunReturnsWhenAWatcherOutlivesTheCoroutine` — with a watchdog,
because a regression here hangs the suite rather than failing it.

### Closing a stream without cancelling its watcher

`stream_select()` silently drops closed streams from the array, then fails with
`ValueError: No stream arrays were passed` — which names nothing useful. `Loop::poll()` checks
`is_resource()` per watcher and throws naming the watcher id instead. On EOF: `cancel()` first,
then `fclose()`.

### Resuming a Fiber that has not suspended yet

`$fiber->resume()` on a running fiber throws `FiberError: Cannot resume a fiber that is not
suspended`. So `FutureState` never invokes callbacks synchronously — every one goes through
`Loop::defer()`, which also keeps completion callbacks out of the completer's own stack.

### Forcing a render on resize skips the clear it needs

`TuiBase::requestRender(force: true)` empties `previousLines`, and `TuiMainScreen::doRender()` reads an empty
`previousLines` as "first frame ever" — which writes the new lines with no clear at all. A resize
needs the opposite: the previous frame must be remembered so the width change is *noticed*, and
then the screen and the scrollback are cleared before redrawing. So the resize handler calls
`requestRender()` plain, like upstream, and force stays for callers who know the screen was
overwritten by something else.

Caught by `TuiTest::testAResizeRedrawsEverythingAndClearsTheScrollback`, which asserts the
`\e[3J\e[2J\e[H` is there.

**And there was a second caller, which is the reason this entry is longer than the fix.** Having
written down that force is for "callers who know the screen was overwritten by something else",
nobody went and read the other one: `takeCellSizeReply()` forced the render after the terminal
answered `CSI 16 t`. So on **every terminal that draws pictures** — which is every terminal that
answers that query, and the only kind pig asks — the startup screen came out twice: frame one drew,
the reply arrived as input a moment later, force emptied `previousLines`, and `doRender()` wrote the
whole frame again with no clear from wherever the cursor already was, which is the last line of
frame one. Upstream asks for a plain render there, for the same reason the resize handler does.

Two things about the shape of it. It is **the trap this file already described, in the caller it did
not name** — a written-down rule is only as good as the audit of who obeys it, and "force stays for
callers who know" is a sentence about callers that lists none.

**It happened a third time, and then the rule itself turned out to be wrong.** `/theme` forced the
render too, and nothing had overwritten that screen: a theme switch changes what components draw, so
there is a previous frame to diff against and only the lines whose colours changed need rewriting. It
asks for a plain render now. Finding it took a byte-level assertion rather than a visible one, because
`requestRender()` **coalesces** — the plain render `say()` asked for and the forced one become a
single draw, so counting how many times the banner is written cannot tell the two paths apart. What
can: a differential update clears each line it rewrites (`\e[2K`) and the first-frame path emits
none.

And with `/theme` gone, every caller left says the same thing — coming back from `$VISUAL`, from a
suspend, from a hook's editor — so **`force` means "the screen is not ours any more", which is not
what a resize needs and is what the docblock claimed.** That meaning wants a clear, and emptying the
record does not give one: the renderer reads an empty `previousLines` as the first frame ever and
writes from wherever the cursor is with nothing cleared. A full-screen editor restores what it found
on the way out, so what is there is pig's own last frame and the new one lands underneath it — the
same doubling, arriving by the one door nobody had checked. Upstream's `resetRenderState()` sets the
remembered size to -1, so the next frame is a width change and clears — the second fact
that an empty record cannot carry: the very first frame has an empty record too, and clearing *there*
would wipe whatever the shell had printed before pig started. Both halves have a test
(`TuiTest::testAForcedRenderClearsTheScreenItNoLongerOwns` and
`testTheVeryFirstFrameDoesNotClearWhatTheShellPrinted`), and
`InteractiveModeTest::testSwitchingThemeDoesNotWriteTheWholeFrameUnderItself` holds the caller. And it was invisible to every test in
the suite, because `FakeTerminal` records what was written and nothing compares two frames drawn
over each other; what a test can see is that *nothing at all* should be written when the answer
changes nothing on screen, which is what
`TuiTest::testTheCellSizeReplyDoesNotRedrawTheWholeFrameOverItself` asserts, with
`testThePictureIsRedrawnWhenTheCellSizeArrives` holding the other half so the fix cannot become
"stop rendering".

### An image is drawn from the cursor downwards, so the cursor goes up first

Both image protocols put the picture where the cursor is and grow it *down*. The renderer
works by comparing lines, so it has to know how many rows the picture occupies before the
terminal draws it. `Image::render()` therefore returns that many lines: the first `rows - 1`
empty, and the last one `\e[<rows-1>A` followed by the image sequence. By the time the
terminal draws, the cursor is back at the top of the block and the picture fills exactly the
rows already accounted for.

`TuiBase::checkWidth()` skips any line holding `\e_G` or `\e]1337;File=`: an image line is tens
of kilobytes long and zero columns wide, and measuring it would fail the width check.

### The terminal's reply to a query arrives as keystrokes

`CSI 16 t` asks how many pixels a character cell is, which is the only way to know how tall a
picture will be. The answer comes back on **stdin**, so it has to be sifted out of the input
before a component reads it as typing. It can also arrive split across reads, so a partial
escape sequence is held back — but only until something that looks finished turns up, because
a terminal that never answers must not swallow the user's typing for the rest of the session.
Only asked on a terminal that draws images, since nothing else uses the answer.

Regression tests: `ImageTest::testTheReplyIsTakenOutOfTheInputAndTheRestStillArrives` and
`testATerminalThatNeverAnswersDoesNotSwallowTyping`.

### Killing the shell does not kill what the shell started

`bash -c 'npm test'` makes the test runner a *grandchild*. Kill the shell and the runner keeps
going, holding the port it bound and writing to a terminal that has moved on — which is what
Escape looks like when it does not work.

Upstream puts the child in its own process group (`detached: true`) and kills the group. PHP
cannot do that through `proc_open`, and macOS has no `setsid` binary to borrow. So `Shell::killTree()`
reads the whole process table once with `ps -eo pid=,ppid=`, walks down from the shell, and kills
children before their parents so nothing gets a chance to start more. One `ps`, not one per node:
this runs while someone is waiting for Escape to take effect.

Regression test: `BashToolTest::testAbortingKillsWhatTheCommandStartedToo`, which starts a
grandchild that would write a file half a second later and asserts the file never appears.

### `proc_close()` reports the signal number where every shell reports 128 plus it

A command something killed came back as `Command exited with code 9`. Nine is not a number any shell
ever prints for a SIGKILL — `$?` says **137** — and 137 is the number a model has seen a thousand
times and reads as "something killed this, probably for memory". Told "code 9" it goes looking for an
exit code the program chose, in a program that never got to choose one. An out-of-memory kill during
a build is the common case, and `139` for a segfault is the next one.

**Upstream has the opposite bug and it is worse.** Node reports `code: null` for a signal death, and
`bash.ts` guards with `code !== 0 && code !== null` — so a segfaulting build resolves as a command
that *worked*, with whatever it printed before dying.

The first fix here asked `proc_get_status()` and then `proc_close()`, on the grounds that the status
is the last thing the process resource knows. Both halves of that turned out to be wrong about how
PHP behaves, and **the sentence that was here — "`proc_close()` hands back the signal number" — is
only true when nothing asked for the status first.** What was measured, identically on 8.3 and 8.4,
by `proc_get_status()` in a loop and printing every call:

```
first call that finds it gone   running=false exitcode=-1 signaled=true  termsig=15
second call                     running=false exitcode=-1 signaled=false termsig=0
third call                      running=false exitcode=-1 signaled=false termsig=0
proc_close=-1
```

So the status of a command something killed is **readable exactly once** — the call that finds the
process gone is the call that reaps it, and after that every reader is told the command chose its own
exit code, `proc_close()` included. Two rules come out of that, and `Process::awaitFinish()` is where
both of them live:

- **The answer is kept, not asked for again.** `codeOf()` takes the status that carries it; nothing
  reads `proc_close()`'s return any more. `run()`, `stream()` and `feed()` already had the right
  status in hand from their own loops and now keep it — `stream()`'s loop goes round again after the
  process has gone whenever there is still output on the pipe, so the last status it read was not
  the one that mattered.
- **A command finishes twice, and the second time is the one to wait for**: its pipes close, and
  then it exits. See the entry below.

Regression tests: the three `BashToolTest::testACommandKilledBySignalReportsWhatTheShellWouldHaveSaid`
rows, `ProcessTest::testTheSameKilledCommandIsReportedTheSameWithAFiberAndWithout` — the two doors,
since the translation used to be in `runAsync()` and `Run::close()` and nowhere else, so the same
command reported 137 during a turn and 9 from a hook factory at startup — and
`testAKilledCommandStreamingItsOutputReportsTheSignalToo`, which is the number `grep` puts in front
of the model.

### A command finishes twice, and the pipes closing is the first of them

The three rows above failed on 8.3 in one full-suite run and passed on 8.4 and in twenty-five runs of
their own file:

```
expected "Command exited with code 15" to contain "exited with code 143"
```

The first hypothesis was a difference between the two PHP versions, and a probe disproved it —
worth recording, because the probe was written to confirm rather than to test, and it reported
`running=false signaled=true termsig=15` six times out of six on both. What it did not do was
reproduce the conditions: the failing run had the other version's suite beside it.

**The two events are the pipes reaching EOF and the process exiting, and they arrive in that
order.** Both `Run` and `Process::runAsync()` complete their `Deferred` on the first, so the status
was asked for in the window between — where `running` is still true, so `signaled` is false and the
number that comes back is the bare signal. Under four spinning CPUs that window caught **23 of 120
runs** of `kill -15 $$`, on 8.3 and 8.4 alike.

`Process::awaitFinish()` is the wait for the second event, polled at `POLL_MICROSECONDS` because
PHP offers no waiting that leaves the status readable — `proc_close()` is the blocking wait and it
is also the end of the resource. Three things about it are load-bearing:

- **It is on the loop.** By the time it is reached the pipes are shut, so nothing else would wake
  the loop, and `proc_close()` holds the one thread for as long as the command has left. That was a
  second, worse bug hiding behind the first: `bash -c 'exec 1>&- 2>&-; sleep 30'` froze pig for
  thirty seconds — no keystrokes, no spinner, no escape — which is exactly the freeze
  `runAsync()` was written to remove, arriving by the one path that skipped it.
- **It happens while the timer is still armed**, inside the `try` rather than after the `finally`
  that cancels it. Otherwise a command that outlived its output is waited out in full and then
  reported as having *worked*: the timeout it blew through was cancelled before the wait began.
  That is what `testATimeoutStillReachesACommandThatOutlivedItsOutput` and its escape twin hold
  down, and moving the call one block out is what makes them fail.
- **There is no deadline of its own**, for the same reason: the caller's is still running, and a
  second one underneath would cut a command short on a rule nobody asked for.

A command that shuts its own standard output and then keeps working is what makes all of this
reproducible without load — the window becomes the whole of its remaining life.
`testACommandWhoseOutputEndsBeforeItDoesStillReportsTheSignal` exists in both files for that
reason: *a race is worth a test that is not one.*

### A command's output was cleaned on the way to the screen and not on the way to the model

`bash-executor.ts` has no counterpart file here and that is right — it is upstream's *second*
command runner, the one `AgentSession.executeBash()` uses for `!command`, where `core/tools/bash.ts`
is the one the tool uses. pig has one `Tools\Run` for both, which is the better arrangement and is
also how this hid: reading the sweep as "one file, no counterpart, because pig shares" stops one
line too early. The line after it is upstream's own comment:

```ts
// Sanitize once at the source: strip ANSI, replace binary garbage, normalize newlines
const text = sanitizeBinaryOutput(stripAnsi(data.toString())).replace(/\r/g, "");
```

pig cleaned the same text, with the same three rules, in what was then `Interactive\SafeText` — **at the display
boundary**. So the screen was right and the two readers nobody looks at until later were not: the
**model** got `\e[32m` and the `\r` that redraws a progress line, and so did the **session file**,
which is pi's file. `npm`, `cargo` and `docker` colour their output whether or not anybody is
watching, and anything with a progress bar writes `\r` by the hundred — so what the model read of a
failed build was a line overwritten twenty times and a third of its tokens spent on escapes.

`Run::read()` cleans each chunk now, through the same one rule, so the model, the session file,
the spill file and the screen all get one text. Three things about it:

- **It is a deliberate step past upstream for the `bash` *tool*.** Upstream cleans at the source in
  `bash-executor.ts` and not in `bash.ts`, where only the component cleans — so there the model
  reads the escapes from a tool call and not from a `!command`. Two answers to one question, and
  this takes the one with the reason written beside it, the same way the image token count did.
- **An escape split across two reads survives**, because each chunk is cleaned on its own. Upstream
  has that too, and the alternative is holding bytes back in case the rest of a sequence arrives,
  which would stop the output being live. Same for a multi-byte character cut in half, which
  `Utf8::sanitize()` turns into U+FFFD at the seam.
- **The spill file is cleaned too**, as upstream's is. It is named in the truncation notice so the
  model can go and read the part that was cut, so it has to be the same text as the part that was
  not.

**Where upstream keeps this rule is what decided where pig keeps it.** `utils/shell.ts` exports
exactly three things — `getShellConfig`, `killProcessTree` and `sanitizeBinaryOutput` — and the first
two are `Tools\Shell::bash()` and `Tools\Shell::killTree()`. So pig's `Tools\Shell` **is** that file,
and it was missing the third: it sat in `Interactive\SafeText`, which was the right home for as long
as every caller drew on a screen and one namespace too narrow the moment `Run` needed it. It is
`Tools\Shell::sanitize()` now, and `Run` was already using `Shell` for the other two.

That the two belong together is not a filing convenience. Killing a command's process tree and
reading what the command printed are the two things you cannot do by asking the shell politely, and
the second is only safe to skip downstream **because** it happened at the source — which is the same
sentence as the entry above and as the fourth killer path below.

What upstream does *not* have is one composition: each of its three readers writes the steps out, and
the three do not agree. `bash-executor.ts` and `tool-execution.ts` both spell
`sanitizeBinaryOutput(stripAnsi(x)).replace(/\r/g, "")`, while `bash-execution.ts` is
`stripAnsi(chunk).replace(/\r\n/g, "\n").replace(/\r/g, "\n")` — no `sanitizeBinaryOutput`, and `\r`
turned into a newline rather than dropped. That third spelling is safe only because the runner had
already cleaned what it was handed, which is worth knowing before anybody reads it as the intended
rule.

| pig had | now |
|---|---|
| `Interactive\SafeText::of()` | `Tools\Shell::sanitize()` |

Regression tests: `AgentSessionTest::testWhatACommandPrintedIsCleanedAtTheSourceAndNotOnlyOnScreen`
for `!command`, `BashToolTest::testWhatTheModelReadsHasNoEscapesOrProgressLinesInIt` for the tool,
and `testTheFullOutputFileIsCleanToo` for the file the notice points at.

### The model was told the command was cut and the person watching it was not

`bash-execution.ts` is upstream's component for a typed `!command` — its own bordered block, its own
`$ cmd` header, its own loader, and a status line it builds out of up to four parts: how many lines
are hidden, `(cancelled)`, `(exit N)`, and `Output truncated. Full output: …`. pig draws a typed
command with the **same** `ToolExecutionComponent` the model's `bash` calls use, which is the
arrangement the note about the two preview sizes describes and is worth keeping.

What it cost is the last three of those parts. `AgentSession::executeBash()` returns a
`BashExecution` carrying the exit code, whether escape stopped it, whether the output was cut and
where the whole of it was spilled — and `runCommand()` handed the component the output text and one
boolean. So:

- **`!exit 3` was the right colour and silent about the 3.** Which number it is, is the entire point
  of the entry on signal numbers: 137 is a thing a person recognises and `1` is not.
- **A command escape stopped looked like a command that failed**, same colour, no word.
- **`!seq 2500` was cut on screen with nothing saying so**, while `BashExecution::toText()` appended
  `[Output truncated. Full output: /tmp/pig-bash-…]` for the **model**. The notice has two readers —
  this file says so a few entries up — and here exactly one of them was being served, the one that
  cannot ask.

The facts are read out of `details` rather than taken as new constructor arguments, because the
model's `bash` tool already writes `fullOutputPath` there under pi's own name; so its truncated calls
gain the same line, which is what upstream shows for both.

**And the first version of the fix lost two of the three, in a way the mutation check named
exactly:** `drawBash()` returns early when the output is empty, and `exit 3` and an escape-stopped
`sleep` are precisely the two runs that print nothing at all — so the status went above that return.
Upstream keeps its status parts outside its own output branch for the same reason.

**The harder half was the test, and it is the "ask whether the assertion could fail" rule again.**
The cancellation case ended in `settle()` before pressing escape, and `settle()` polls with no
timeout: with the command's pipes the only thing to wait on, it sat through the whole five seconds,
so escape arrived at a command that had already finished cleanly — `cancelled: false, exit: 0`, and
a test that could never have gone red for the right reason. `turnTheLoop()` arms an expired timer of
its own, so the poll comes back at once and the command is still running when the key goes in; the
test asserts that too, before it presses anything.

Also worth knowing, since the file was read for this: the existing `testAFailingCommandIsMarkedAsOne`
checked the error colour on screen and then read the exit code off the **message**. That is the
shape that let this survive — *a fact checked where it is present is not a fact checked where it is
read.*

Regression tests: `InteractiveModeTest::testTheExitCodeIsOnScreenAndNotOnlyInTheConversation`,
`testACancelledCommandSaysItWasCancelledRatherThanJustFailing`,
`testTruncatedOutputSaysSoAndWhereTheRestIs`.

### Output truncated by line count also needs somewhere to look

Upstream's bash tool writes the full output to a temp file only once it passes the **byte**
limit, and the truncation notice then names that file. Two thousand short lines is nowhere near
50KB and is still truncated by the line limit, so upstream's notice in that case says where to
find a file it never wrote. Here the spill starts when *either* limit is passed, which is the
same pair of limits truncation itself uses.

Regression test: `BashToolTest::testTruncatedOutputSaysWhereTheWholeOfItIs`.

### `--ignore-file` applies a nested `.gitignore` in the wrong place

Upstream's `find` collects every `.gitignore` below the search root and passes each one to
`fd` as `--ignore-file`, so that nested ones apply outside a git repository too. They do not
apply *where they sit*: `--ignore-file` patterns are matched against the whole search root, so
a `src/.gitignore` containing `generated/` also excludes `other/generated/`, and files that
should have been found are silently missing.

Both tools already have the flag for this. `--no-require-git` makes `fd` and `rg` read
`.gitignore` outside a repository with the ordinary nesting rules, which is what was wanted.
So the collection is not ported and the flag is passed instead.

Regression test: `SearchToolsTest::testANestedGitignoreAppliesOutsideAGitRepositoryToo`.

### `fd --glob` matches the file name, so every pattern with a directory in it found nothing

`fd --glob '*.php'` matches the **base name**, not the path. `src/*.php` therefore matches no
file that has ever existed, and fd says nothing about it — so `find` answered "No files found
matching pattern" for every pattern the model wrote with a `/` in it, and the model, believing
the answer, walked the tree with eight `ls` calls instead.

Two flags fix it, and both are needed. `--full-path` matches against the path; a `--full-path`
glob is then anchored at the search root, so an unanchored pattern also needs `**/` in front or
`src/*.php` only means the `src` directory at the top. `--full-path` is added only when the
pattern contains a `/`, because matching a bare `*.php` against the path would be worse.

This shipped broken because the PHP walk that preceded `fd` was deleted along with its tests —
including the only ones that passed a pattern with a slash. **A test deleted with the code it
covered takes its coverage with it; the replacement needs its own.**

Regression tests: `SearchToolsTest::testAPatternWithASlashMatchesThePathNotTheName`,
`testASingleStarDoesNotCrossADirectoryButTwoDo`, `testASlashPatternMatchesAtAnyDepth`,
`testAnAlreadyAnchoredPatternIsNotAnchoredTwice`.

### `fd` exits 0 when it found nothing, so failure cannot be read off an empty result

`rg` exits 1 for "no matches" and 2 for an error; `fd` exits **0** either way. So a non-zero
exit from `fd` is always a real failure — usually a glob it would not parse — and `find` was
reporting all of them as "No files found matching pattern", which is a silent fallback of
exactly the kind this project forbids: the model is handed a plausible answer and has no way
to tell it apart from the truth.

`Process::capture()` cannot express this, since it folds "ran and said nothing" into "did not
run". `Process::run()` was added to return the exit code and stderr separately, and `find` now
throws with fd's own message.

**And `grep` was the sibling that did not follow it**, found by auditing `grep.ts` long after this
entry was written. It streams rather than collecting, so it used `Process::stream()` — which read
standard error only to stop the pipe filling and **threw the text away**. A bad pattern therefore
reached the model as `ripgrep exited with code 2`, where rg had said:

```
regex parse error:
    (?:[unclosed)
       ^
error: unclosed character class
```

One of those names the character to fix. `stream()` now hands back `[exit, stderr]` like `run()`
does — one caller, and being the odd one out of the two was the bug. Worth noticing *how* it hid:
there was a regression test, `testABadRegexIsReportedRatherThanReadAsNoMatches`, and it asserted
`'ripgrep exited'` — **it pinned the shortfall**, because that string was what there was when it was
written. A test written against what the code says rather than against what the caller needs will
hold a half-fix in place for as long as nobody re-reads it. It asserts rg's own words now, and that
the code number is *not* there.

Regression tests: `SearchToolsTest::testABadPatternIsReportedRatherThanReadAsNoMatches` for fd and
`testABadRegexIsReportedRatherThanReadAsNoMatches` for rg.

### An input method draws where the terminal's cursor is, not where the caret is drawn

A component paints its cursor as an inverted cell; the terminal has a cursor of its own, and macOS
draws the composing text and the candidate list there. Upstream's mechanism, which pig now uses:
a `Focusable` component (`public bool $focused`, set by `TuiBase::setFocus()`) puts
`TUI::CURSOR_MARKER` (`\e_pi:c\a`, zero-width APC) just before its drawn cursor while focused; the
renderer finds it with `extractCursorPosition()`, strips it, and moves the hardware cursor there
(`positionHardwareCursor()` in `TuiMainScreen`, absolute in `TuiAltScreen`). The cursor stays hidden
unless `showHardwareCursor` is on.

**A wrapper must pass focus on.** `CustomEditor` and `SessionList` hold the real `Editor`/`Input` and
set `->focused` on it in `render()`; a focusable wrapper that does not stays silent and the candidate
list goes back to the bottom of the frame.

Regression tests: `TuiMainScreenTest::testTheHardwareCursorGoesWhereTheFocusedComponentPutItsMarker`,
`EditorTest::testTheCaretIsMeasuredInColumnsNotCharacters`, `InputTest::testTheCaret…`.

### A line starting with `/` is not a command, and neither is one that merely looks like a name

Reported from a real session, and the screen said the whole story:

```
Error: No command called /var/folders/mk/…/T/pig-clipboard-bf6615a630ccc5f2.png. Try /help.
```

Pasting a picture at the prompt writes it to a temp file and puts **the path** in the editor —
that is `Clipboard\ClipboardFile`, and it is deliberate, because a path works with every tool
that already takes one. So the line the person sends begins with `/`, and the submit handler
tested exactly that: `str_starts_with($text, '/')` → treat as a command. The path was reported
as an unknown command, and **the question typed after it was swallowed with it** — the model
never saw either half.

**The first fix was still a guess, and that was the real lesson.** It borrowed the
autocomplete's rule — a `/` at the start with no second `/` in the first word — which does tell
a path from a name, and kept pig's "No command called /setings" for the typo case. But "looks
like a name" is not "is a name": it is a second, looser notion of what a command is, living
beside the real one, which is the shape of the original bug rather than a fix for it.

So dispatch is **exact, against the names that exist** — `COMMANDS`, the hooks' registered
commands, and the ones kept as files, matched on the first word. `InteractiveMode::commandName()`
answers with a name or null, and null means the line is a message. Upstream's rule, which it
spells eighteen times (`text === "/settings"`); once here, because the hook and file names are
only known at runtime.

What follows, and is the price: **`/setings` goes to the model as text.** There is no error and
no "did you mean", because deciding a typo is a typo means guessing at what somebody meant, and
that is the thing being removed. `/nonsense` is a message too.

**The same rule was spelled by hand in three places**, and each hand-spelling was wrong in its
own way:

| Where | Question it is really asking | Was |
|---|---|---|
| the submit handler | is this line a command? | `str_starts_with($text, '/')` → read a path as one |
| `apply()` | should the completion insert `/name ` or a path? | right already — `couldBeACommandName()` |
| `shouldCompleteFiles()` | does Tab mean "complete a path"? | `starts with / and has no space` → **would not complete an absolute path typed at the start of a line**, while completing the same path fine one space to the right |

The third was found by asking the other two's question of it, and it is fixed the same way:
Tab belongs to the command list only while the cursor is still in the first word *and* that word
could still become a name. Past the first space Tab is a path again, which is what
`/export ~/out.html` needs.

**Two callers, two questions.** `couldBeACommandName()` is about *typing* and is private to the
autocomplete: a bare `/` counts, and so does a half-typed `/mod`, because that is what a
completion list is for. Dispatch is about *a finished line* and is exact. Sharing one predicate
between them is what made the first fix wrong, and there is a smaller version of the same trap
inside the autocomplete: requiring the name to be non-empty — correct for a submitted `/`, which
names nothing — silently broke command completion, because a bare `/` is the prefix every
command completion starts from, so picking `compact` off the list inserted `compact` with the
slash eaten. **A predicate two callers share is answering two questions, and the stricter caller
does not get to tighten it for the other.**

Regression tests: `InteractiveModeTest::testAPastedPathIsAMessageAndNotABadCommand`,
`testALineThatNamesNoCommandIsSentAsTheTextItIs`,
`testANearMissIsNotTreatedAsTheCommandItResembles`,
`testAFileCommandsNameIsMatchedExactlyLikeAnyOther`; and in `AutocompleteTest`, the eight
`testACommandNameIsToldFromAPathWhileTyping` rows,
`testTabCompletesAnAbsolutePathTypedAtTheStartOfTheLine`, and
`testCompletingFromABareSlashKeepsTheSlash` — the last being the one that fails if the
non-empty check goes back in.

### A slash command only worked in front of a screen

The whole of `commandName()` above lived in `InteractiveMode`, and so did what happened next: a
hook's command was *run* there, and a command kept as a file was *expanded* there. So both worked
in the terminal and nowhere else — `pig -p "/deploy"` and RPC's `prompt` handed the model the line
as text and left it to guess what `/deploy` meant. Reproduced from both doors before it was fixed:
`-p "/deploy staging"` never called the handler, and `-p "/review src/Foo.php"` sent eight
characters where a stored prompt should have gone.

Upstream does both inside `AgentSession.prompt()`, and the reason the fix waited a batch is that
the *question* looked split: pig's exact-match dispatch also knows the built-ins, so moving one
half in would put one decision in two places. It is not split. **The built-ins are the screen's and
the other two are the conversation's**, which is upstream's line exactly: `/help` draws on a
terminal and has nothing to do in `-p`, where a hook's command is code the session should run and a
file command is text the session should send. So `prompt()` gained both halves and
`InteractiveMode` kept only which door the text goes in by.

Four things decided on the way, and the first is the one to remember:

- **The hook half goes in front of the "already working" throw.** Upstream's comment says *"Hook
  commands always run immediately, even during streaming"* and its code throws there, so the two
  disagree — and pig takes the half with the reason attached, as it does for the image token count
  and the `data:` prefix. A hook's command is not a message; there is nothing for it to wait behind.
  `testAHookCommandStillRunsWhileTheAgentIsWorking` is the only test that fails when the dispatch
  moves one block down.
- **The expansion is on all three doors** — `prompt()`, `steer()` and `followUp()` — because a
  stored prompt typed during a turn goes in by one of the other two, and upstream queues the raw
  line at both, so `/review foo.php` reaches the model there as `/review foo.php`. Two of those
  three ends were silent to the mutation check until a test typed a file command mid-turn.
- **The queue keeps the line as it was typed and the agent gets the expansion.** `clearQueue()`
  hands that list back to the editor, and putting a forty-line stored prompt in front of somebody
  who typed `/review foo.php` is not putting their text back.
- **There is no `expandSlashCommands: false`.** Upstream declares the option and nothing in either
  tree passes it — a branch for a caller that does not exist, which is the standard the whole
  `rpc-types.ts` note is held to.

And the harness had the gap this file has now named twice: `InteractiveModeTest` handed the file
commands to the **mode** and not to the **session**, exactly as it once handed the settings to one
and not the other. The expansion would have been live in production and inert in every test.

Regression tests: `PrintModeTest::testAHookCommandRunsHereRatherThanReachingTheModelAsText`,
`testAFileCommandIsExpandedHereTooRatherThanSentAsItsOwnName`,
`testATextThatOnlyLooksLikeACommandIsSentAsTheTextItIs`,
`RpcModeTest::testAHostsSlashCommandsReachTheSameDispatchTheTerminalDoes`, and in
`InteractiveModeTest` the two above plus
`testAFileCommandQueuedMidTurnReachesTheModelAsItsPromptAndComesBackAsItsName`.

### Whatever holds the focus must be in a container only its own opener clears

Found by being asked the right question about a field I had just deleted. `InteractiveMode` had
a `private ?SelectList $picker` written by four callers and read by none, and "written and never
read" looked like a complete answer: the object is kept alive by the container that draws it, so
the field could go. It was the wrong answer. **The field was a missing reader, not a redundant
write** — and what was missing was reachable in three keystrokes.

`$this->status` had three kinds of writer. The pickers and `/settings` put a focused component
in it. The progress loaders — `onStart()`, `onEnd()`, `onRetryStart()`, `onOverflow()` — clear it
and put a `Loader` in, and those run from **agent events, which arrive without anybody pressing a
key**. `TerminalUi` puts a hook's dialog there as a third. So:

```
/settings while a turn is running   → the screen is drawn, focus is on it
the turn ends, onEnd() clears       → the screen is gone
                                    → THE FOCUS IS STILL ON IT
next keystroke                      → goes to an invisible list
```

Reproduced before it was fixed, and the second half is the part worth remembering: after the
screen vanished, Down and Enter still changed a setting. Somebody typing what they thought was a
message into the editor was walking down an invisible list changing things, with nothing on
screen to say so. `/settings`, `/login` and `/logout` reach it directly — the three pickers that
refuse while streaming refuse for a different reason (they would change the conversation
underneath it) and are covered by accident.

The fix is a second container, `$overlay`, drawn between `$status` and the editor: what is
*happening* and what is being *asked* have different writers, and the writers do not know about
each other. Upstream never had this bug because its arrangement already separates them — a
selector replaces the **editor** (`editorContainer`) while progress lives elsewhere.

**Not a flag.** Restoring `$picker` as something the loaders check was the cheaper option and the
wrong one twice over: it makes every *other* writer responsible for remembering, which is the
arrangement that just failed, and the only behaviour it can offer is hiding the retry countdown
while a picker is open — a countdown that exists so eight silent seconds do not look like a hang.
Two containers means both are drawn, which is the honest answer, because both are true.

The rule, and it generalises past this screen: **the two halves of closing something — the
container letting go and the focus moving — have to happen in the same place.** Every clear of a
container that can hold the focus sits next to a `setFocus()`: `closePicker()` and
`TerminalUi::close()` are the only two, and that is the invariant.

Splitting the containers left one writer still able to collide, from the other side: a hook's
dialog and a picker both belong in the overlay, so a tool call arriving while `/settings` was
open cleared it. Milder — the dialog *takes* the focus and hands it back to the editor, so
nothing is invisible-and-focused — but the person's screen still vanished without a word.

`TerminalUi` already had the answer for its own half of this and it generalised: **a second
dialog is refused with its safe answer rather than stacked**, because taking focus from the
first would park that fiber forever. So the guard now asks two questions rather than one —
`$busy` for a dialog of its own (which covers the moment a `custom()` factory is still
building, when nothing is drawn yet and the overlay looks free), and `$overlay->children()`
for anything else holding the focus. A refusal answers `false` from `confirm()` and `null`
from the rest, which is exactly what escape answers, so it travels as an answer somebody
could have given and no turn is parked.

And it is **said**. `notify()` puts a line in the transcript naming why the question was not
asked, because a tool quietly denied is a tool that looks broken — the same rule as no silent
fallback, applied to a UI.

The overlay's contents are the state here, not a flag: `canAsk()` asks the container what is
in it. A flag would be a second copy of the same fact, and a second copy that can disagree is
the shape of the bug this whole entry is about.

**And the dialog says which keys work**, which for a while it did not. Upstream's three components
each end with a hint line — `↑↓ navigate  enter select  esc cancel`, `enter submit  esc cancel`,
`ctrl+enter submit  esc cancel  ctrl+g external editor` — and pig folded all three into
`TerminalUi`, hint lines included, except that the hints did not come with them. `/tree`,
`/resume`, `/model` and `/settings` all carry one, so a hook's dialog was the one screen here that
did not say how to answer it — **in front of a parked turn**, where the line saying escape works is
the one that matters: the alternative to reading it is guessing, and guessing wrong on a screen
holding a turn is how a session gets killed. It is one argument to `open()` now, because one place
opens all four. `custom()` passes none: a hook that drew its own component knows its own keys and
pig does not. Ctrl+G is named only when there is an external editor to hand the text to, which is
upstream's rule for the same hint — a key in the list that does nothing is worse than a key missing
from it.

Regression tests: `InteractiveModeTest::testATurnEndingDoesNotEraseAnOpenScreenFromUnderTheCursor`
(fails if `showSettings()` is pointed back at `$status`),
`testAWorkingLoaderAndAnOpenScreenBothFitOnTheScreen`, and
`HookUiTest::testADialogIsRefusedWhileSomethingElseHoldsTheOverlay`,
`testARefusedDialogSaysWhyRatherThanFailingQuietly`,
`testTheOverlayBeingFreeAgainLetsTheNextOneThrough` (all three fail with the overlay half of
`canAsk()` removed).

### `Container` is a `Component` and not an `InputHandler`, so a container given the focus eats every key

The same family as the one above, and it bit in the opposite direction: not a component that
should have implemented an interface, but a component that looked complete because the
interface it was missing is optional.

`/settings`' thinking row opens a submenu, and a submenu wants a title and a hint above and
below the list — so it was a `Container` with a `Text`, the `SelectList` and another `Text`
in it. That compiles, `setFocus()` takes it (focus is typed `Component`), and it draws
correctly. Then every key went nowhere: `Container` forwards drawing to its children and
forwards nothing else, so the arrows, Enter and Escape all landed on an object with no
`handleInput()`. The screen opened and could not be navigated *or closed*.

`Interactive\SettingsSubmenu` is that container with the keys wired through — upstream's
`SelectSubmenu`, which exists for exactly this reason and says so in one line.

**Anything that can hold the focus has to be asked whether it handles input, and a container
never does.** `SettingsList::handleInput()` checks `instanceof InputHandler` before
forwarding to a submenu, which is what keeps this a screen with nothing to press rather than
a crash — and is why the bug was silent.

Regression tests: `InteractiveModeTest::testAThinkingModelGetsARowThatOpensTheLevels` and
`testChoosingALevelSetsItOnTheSessionAndRemembersIt` navigate *inside* the submenu, which is
what a test asserting only that it opened would have missed;
`SettingsListTest::testASubmenuThatCannotTakeKeysIsDrawnRatherThanCrashedOn` holds the guard.

### A window being dragged is a SIGWINCH per pixel, and three things each did too much per signal

Reported as "resizing is slow, pi is smooth", and the measurement that settled it was a pty with a
60Hz drag over 30 widths and a thread reading frames as they land. Both tools draw 30 frames for it
— one per distinct width — so the cadence was never the problem. Three things were:

- **`stty size` in the signal handler.** 4ms a fork, once per signal, serialised, with the frame
  queued behind. Node's `process.stdout.columns` is an `ioctl` for nothing; PHP has no `ioctl`, so
  the handler marks the size stale and `columns()`/`rows()` measure **once per frame** when first
  asked, however many signals arrived since.
- **A frame per signal, including the ones that changed nothing.** A resize-only render request
  whose width and height match the last frame draws nothing — no `\e[3J\e[2J`, no flash.
  `requestRender(resize: true)` is how the resize handler says so, and a content change arriving
  while that request is pending turns it back into a real one.
- **EINTR in `ProcessTerminal::write()`.** A SIGWINCH during the `select()` that waits for the tty
  buffer to drain is a PHP *warning*, and a warning goes to the terminal — in raw mode, mid-frame.
  `Loop::poll()` already had the handler for exactly this (the trap entry above); its sibling in
  `write()` did not. First shape from the index, on a `select()`.

`MIN_RENDER_INTERVAL` is upstream's 16ms: a request inside the interval waits for the rest of it,
and a burst in that window is one frame. The test for it measures *time*, because `frame()` ticks
once and a tick blocks in `select()` until the timer fires — the held-back frame is visible as a
delay, not as an empty tick.

**What did not change: the 89ms per frame on a wide pane**, which is `write()` waiting for the tty
to drain 10KB in 1KB pieces, each `select()` wait ~2ms. pi pays the same (its median gap was
106ms in the same harness); it is the terminal's read rate, not pig's.

### Widening the window left the right-hand side blank, and the terminal was innocent

Reported as "pi fills the new width at once, pig's right side stays blank for a while". The first
measurements said both tools emit the same 24 frames at the same cadence on a short transcript, and
the Terminal.app write cost is the same for both — so the drag entry above was true and this was a
different bug. What separated them was the session: on a resumed 1,144-message conversation, one
widen to 169 columns against pi on the **same file**:

```
pi:   first byte 125ms after the signal, frame complete at 146ms, 2170KB
pig:  first byte 823ms after the signal, frame complete at 915ms, 2529KB
```

Nothing a terminal does explains 700ms before the first byte; that is the frame being *built*.
Profiled component by component — 1,144 children, 560ms of render, 240ms of width checks, the rest
in `write()` — and it was four things, each of which looked cheap in the place it was written:

- **`BashOutputComponent` wrapped the whole output to show its tail.** Five rows kept out of a 50KB
  build log meant a 50KB wrap at every new width, and this session has 280 of those: 460ms of the
  560. The entry on `BashOutputComponent`'s missing cache three batches up fixed the *per keystroke*
  cost and left the *per width* cost exactly as it was, because a cache keyed on width is empty at
  every new width. It wraps from the end now and stops when it has the rows; `TextWrap::rows()`
  counts what the dropped head would have made, by arithmetic for a plain line — held to the real
  wrap over 20,000 random lines — and by wrapping for anything styled or wide.
- **`Width::visible()` segmented every styled line.** The fast path is "printable ASCII → `strlen`",
  and every line of a transcript carries a colour, so every one of them failed it and went through
  `\X`. 12,800 lines, measured once each by `checkWidth()` before writing: 236ms. Upstream's
  `asciiVisibleWidth` skips the escapes and counts the rest; pig does the same **after**
  `Ansi::strip()`, where the cache above it cannot help because a frame at a new width is 12,800
  strings it has never seen.
- **`TextWrap::breakWord()` cut a minified line grapheme by grapheme**, 10ms for 46KB and again at
  every width. A word of printable ASCII is one column a byte; it is `str_split` now, with the
  opening codes on the first piece and the closing codes on the last, which is where the tokenizer
  hangs them — and the two paths were diffed byte for byte over 3,000 random styled words before
  the first draft, which put the opening codes on *every* piece, was replaced.
- **`ProcessTerminal::write()` copied the rest of the frame on every short write.**
  `substr($data, $written)` with a tty taking a kilobyte at a time is 2,400 copies of 2.5MB — 72ms
  of `memcpy` for the one loop whose whole point is waiting on something slower than `memcpy`. A
  64KB slice.

```
pig now:  first byte 194ms after the signal, frame complete at 227ms
```

**What is left is the bytes.** pig's frame is 2.5MB to pi's 2.2MB and the terminal drains both at
the same rate, so the remaining gap is in what the components emit — and that is not something a
profile of the render finds. Two things worth knowing before anybody chases it: the per-frame
`write()` on a wide pane is tty drain and pi pays the same (measured, 89ms against 106 in the same
harness); and pi's transcript for that session is 10,246 lines to pig's 12,805, so a line count is
where to start.

The method is the entry, more than the fixes: **a profile of one frame, by class and then by
instance, against a real session** — not a synthetic transcript. The synthetic one three batches
ago was built from `Text` components and reproduced none of this.

Regression tests, one per fix, each red on its own mutation:
`BashOutputTest::testCollapsingALongOutputCostsTheTailAndNotTheWholeOfIt`,
`testTheDroppedCountIsCountedInRowsWhetherOrNotTheHeadWasWrapped`,
`testATailThatWrapsPastTheRowsIsCutAndCountedToo`; `TextWrapTest::testRowsCountsWhatWrapWouldHaveMade`,
`testAPlainWordIsCutToTheSameBytesWhicheverPathCutsIt`;
`WidthTest::testAStyledAsciiLineIsMeasuredWithoutSegmentingIt`. The slice has no test of its own:
`ProcessTerminalTest::testAWriteBiggerThanTheBufferStillArrivesWhole` already drives a megabyte
through a pipe and holds the bytes whole, and the slice changes only how often that loop goes round.

### `fwrite()` to a terminal returns short, and STDOUT is non-blocking whether you asked or not

`ProcessTerminal::write()` called `fwrite()` once and ignored what it returned. A frame is
tens of kilobytes; a tty buffer is a few. Measured: a 1,000,000-byte write put **65,536**
bytes on the wire and dropped the other 93%, and an 11,330-byte frame — the real first
frame at 178 columns — lost 3,138 bytes. What gets dropped is the middle of an escape
sequence, so from there every cursor move is against a screen holding something else. On
screen it looks like frames stacking up instead of replacing each other.

The second half is why it is easy to miss: **`stream_set_blocking($input, false)` makes the
output non-blocking too.** STDIN and STDOUT are dups of one open file description when both
are the terminal, and `O_NONBLOCK` belongs to the description, not the descriptor. So the
input watcher puts the output in a mode where short writes are normal, at a distance, in
another method.

`write()` now loops until everything is out, waiting on `stream_select()` for the writable
side when the buffer is full, and throws after five seconds rather than spinning. Anywhere
else this codebase writes to a stream it may not own, check the return value the same way —
a partial write is the quietest failure there is.

Regression test: `ProcessTerminalTest::testAWriteBiggerThanTheBufferStillArrivesWhole`. It
writes through a real pipe, because `FakeTerminal` accepts whatever it is given and so can
never reproduce this — which is exactly why it shipped.

### Closing a style with `\e[0m` closes whatever it was nested inside

`Style::bold()` and friends used to end with a full reset. A full reset turns off
*everything* — including a colour somebody else opened around that text. So a bold word
inside a red line left the rest of that line un-red, and the coding agent, which wraps
everything in a palette colour, hit it on the first component that used both.

Each attribute now closes with its own off-code: `22m` after bold or dim, `23m` italic,
`24m` underline, `27m` inverse, `29m` strikethrough, and `39m`/`49m` after a foreground
or background colour. The one exception is `Style::of()`, which is handed arbitrary
codes and cannot know which off-code goes with each — so a combination built that way is
a leaf style, and nesting is done with the named methods.

Regression tests: `ComponentsTest::testEachStyleClosesOnlyWhatItOpened` and
`testAStyleNestedInAColourLeavesTheColourStanding`.

### A style must not be left open at the end of a line

`TuiMainScreen` compares frames line by line, so a line is the unit that has to be self-contained:
a colour opened on one line and closed on the next means the second line carries a style
it never asked for, and a differential redraw of only the first line leaves the rest of
the screen tinted.

This bites anything whose tokens can span a newline — a block comment, a triple-quoted
string. `Highlight` therefore splits every token on `\n` and styles each piece on its own
line, rather than styling the token once and splitting afterwards.

Regression test: `HighlightTest::testEachLineClosesItsOwnStyles`.

### Inverse and strikethrough paint a blank cell, and the rule said only underline does

The third entry in this family and the one where the reason was written down and was wrong.
`AnsiTracker::lineEndReset()` turns off what must not run past the text on a wrapped line, and it
turned off **underline only** — upstream's rule, with upstream's reason on it: *"Only underline
causes visual bleeding into padding. Other attributes like colors don't visually bleed to padding."*

True of a colour. False of two others, and the test is simply **whether the attribute paints an empty
cell**:

| | on a space |
|---|---|
| underline (4) | a rule drawn through it |
| **strikethrough (9)** | **a rule drawn through it** |
| **inverse (7)** | **the foreground swapped in — a solid block of colour** |
| bold, dim, italic | nothing |
| blink, conceal | nothing visible |
| foreground | stops where the characters stop |
| background | *has* to survive — a padded row should reach the edge |

Both of the two are reachable: `DiffView::mark()` wraps a changed run in `Style::inverse()`, and
`Markdown` strikes through `~~text~~`. Reproduced through the real components rather than the
primitive — a diff of one rewritten line, drawn in a 44-column `Text`:

```
0 !!  \e[38;5;167m-12 return $this->\e[7moldCall($a, $b) +
1     \e[7;38;5;167m$this->somethingElseEntirely($c) ?? 0\e[27m; //
3 !!  \e[38;5;143m+12 return $this->\e[7mnewThing($x, $y) +
```

`!!` is a line handed to the terminal with inverse still on, so every padding space after it is
filled to the right edge — two rows per edited line, on the ordinary case of a line whose middle was
rewritten. The marked run only has to be long enough for the wrap to fall inside it.

`PAINTS_A_BLANK_CELL` is the table now, in SGR order so the sequence reads `24;27;29`, and only the
attributes actually on are emitted — so the underline-only case is still exactly `\e[24m`, which is
what upstream's own test asserts with `endsWith`.

**And the rest of `wrap-ansi.test.ts` passes as written**, which is worth the words because that file
is a specification of this whole family: no underline code on the line *before* the styled text, a
background on every continuation line, a background surviving an underline that closes per line, and
a colour re-opened at the start of each continuation — all four run against pig and all four agree,
byte for byte on the one upstream asserts exactly.

Regression tests: `TextWrapTest::testInverseIsClosedAtEachLineEndToo`,
`testStrikethroughIsClosedAtEachLineEndToo`, and
`testOnlyTheAttributesThatPaintABlankCellAreClosed` — which is the one that stops the fix becoming
"close everything", since closing bold or a foreground would cost a code per line for nothing, and
closing the background is the bug the whole mechanism exists to avoid. Dropping any one of the three
rows turns exactly its own test red.

### Every run of the test suite opened the developer's browser on Anthropic's authorize page

Reported as *怎么每次 phpunit 就打开浏览器 Claude oauth，这样会很容易被封号的*. Since the Anthropic
1.0.3 sign-in landed (v0.3.56), `/login`'s `onAuth` says the URL and then hands it to `open` —
and `InteractiveModeTest` drives `/login` through a `FakeTerminal` in four tests, one of which
picks the copy-code way, whose flow calls `onAuth` at once. So `vendor/bin/phpunit` ran
`open https://claude.ai/oauth/authorize?…` on the Mac every time: a real sign-in page, with real
state, against an account that can be suspended for exactly that pattern. **The fake terminal
fakes the terminal and nothing else** — a subprocess the code starts is as real under a test as
under a person.

`openInBrowser()` honours `PIG_OFFLINE=1` now, the switch `ToolInstaller` already reads, and
`InteractiveModeTest::setUp()` sets it. The regression test puts a stand-in `open` on the PATH
and proves **both ends**: offline it is never run, and with the variable cleared it *is* — so the
guard cannot become "never open", and the online half runs against the stand-in rather than a
browser. Mutating the guard away turns the offline half red.

Three places start a browser and they are three answers to one question: this one reads
`PIG_OFFLINE`, `extensions/pig-mcp` reads `PIG_TESTING` (which `phpunit.xml.dist` sets and the
shim does not), and `WebMode` takes `openBrowser: false`. One `Browser::open()` honouring one
switch is the fix if a fourth appears; it is new surface, so it was not done here.

### The last block of a message left a blank line under itself, and pi does not

Reported with a screenshot: a one-line user message sitting on **two** empty rows of its own
background, where pi draws one. `Markdown::block()` adds the blank line that separates one block
from the next, and it added it after the **last** block too 
dash `$next === null` fell through to
the `$lines[] = ''`. Upstream guards every one of its eight `lines.push("")` with
`nextTokenType && 
dash`, so its last block ends where its text ends and the gap after a message is
whoever placed it: a `Spacer`, or the component's own `paddingY`. One row per user message, per
assistant message, per tool result 
dash invisible on any single frame and a transcript a few
percent taller than pi's on every one.

Two tests were pinning it (`['```php', '  $x = 1;', '```', '']` and "three rows of text, then the
blank line a paragraph leaves after itself"), which is the shape this file keeps naming: *a test
written against what the code says*. Regression test:
`MarkdownTest::testTheLastBlockLeavesNoBlankLineUnderItself`, which also asserts the blank
**between** two blocks stays, so the fix cannot become "never add one".

### A per-line prefix has to go on after the wrap, not before

`Markdown` styles a block, then wraps it. That is right for *inline* styling — the wrapper
carries escape codes across its own breaks — and wrong for anything that must appear at the
start of every line. A block quote built as `"│ " . $text` and wrapped afterwards comes out
with the border on its first row and the remaining rows hanging in the margin, which reads as
the quote having ended.

So `quote()` wraps its children to `width - 2` itself and prefixes each resulting line. Found
by looking at rendered output, not by a test — every assertion about widths and codes passed.

Regression test: `MarkdownTest::testEveryLineOfAWrappedQuoteKeepsItsBorder`.

### `Highlight` hands back the code unchanged when it has no grammar

Which is right in a terminal — an unknown language is printed as it is — and is an injection
hole on a page. `MarkdownHtml::code()` therefore asks `Grammar::for()` first and escapes the
lines itself when there is none, rather than trusting the highlighter to have touched them.

A tool result is the obvious way in (a `<script>` in a file the model read), but so is any
fenced block in an answer whose language pig has no grammar for, which is most of them.

Regression test: `HtmlExportTest::testAToolCallSaysWhichToolAndWhatOn` asserts `&lt;?php` in
an un-languaged result. `testAJavascriptLinkIsNotALink` covers the other half: a
`javascript:` href in a transcript is a script the model wrote, running when someone opens
the file, so only `http(s)`, `mailto` and `ftp` become links at all.

### A tool result outliving the call it answers

`OpenAiResponses` drops a tool call from a turn that ended in an error, because an aborted
call has half-parsed arguments and asking the model to continue from one is worse than
dropping it. `TransformMessages` runs *first* and, seeing a call with no result, invents one.
Put together, the request carries a `function_call_output` addressed to a `call_id` that was
never sent — which OpenAI rejects outright, so the conversation cannot be continued at all.

Upstream has the same two halves and the same gap. Found here by building the case rather than
by a 400: the shape is visible in the assembled request, which is why the provider tests assert
on what went out and not only on what came back.

The fix is in `input()`: it records the calls it actually emitted and drops a result whose call
is not among them. Both halves of a dropped turn go, or neither.

Regression test: `OpenAiResponsesTest::testAnAbortedTurnsThinkingAndCallsAreNotSentBack`.

### A token count measured before a compaction describes a conversation that no longer exists

`shouldCompact()` reads the last completed turn's usage, because the provider's own count
beats an estimate. Compaction keeps the most recent messages — usage and all — so straight
after one, the newest usage in the conversation is still the reading that *asked* for the
compaction. Nothing recomputes it until the next turn comes back.

Left alone, that is a loop: every turn starts by auto-compacting, the second one fails with
`Already compacted`, and the person gets a red error before everything they type. It is not
visible in any single-step test, because one compaction does work correctly.

`Compaction::lastUsage()` therefore returns null when the newest `CompactionSummary` is at
least as new as the assistant message it would otherwise use — by timestamp, not position,
since the summary sits in *front* of the messages it is newer than. `<=` rather than `<`:
the summary is written straight after the turn it replaces and a millisecond holds both.
Discarding a good reading costs one turn without one; keeping a stale one costs every turn
after it.

Regression test: `AgentSessionTest::testCompactingDoesNotLeaveTheSessionAskingToCompactAgain`.

### A command run with `usleep()` freezes everything a person can see

`Process::run()` polls with `usleep()` while it waits. That is right for what it was written
for — a two-second `wl-paste`, a `which` — and wrong for anything a person waits through, because
the one thread is inside the sleep: no keystrokes, no spinner, no redraw, no escape. A hook's
`$pi->exec(['npm','test'])` went through it, so a thirty-second command was thirty seconds of a
terminal that would not answer, and the only thing that ended it was the timeout.

`Process::runAsync()` is the same function with the pipes on the loop — the same `select()` that
waits on the model's socket — parked on a `Deferred` until they close. `HookApi::exec()` and
`CustomToolApi::exec()` use it. `run()` is untouched, because its thirty-odd other callers are
probes where blocking is the honest thing to do and some of them run before there is a loop at all.

Four things in it are load-bearing rather than tidy, and three are rules this file already had:

- **The watcher is cancelled before the pipe is closed**, or `stream_select()` drops a closed
  stream and then fails with an error that names nothing.
- **The timeout timer is cancelled in a `finally`.** A pending timer keeps `Loop::isIdle()` false
  for the rest of its span and `bin/pig` waits for the loop before it exits, so a leak here is a
  program that does not quit. `ProcessTest::testTheLoopHasNothingLeftToWaitForAfterwards` fails on
  exactly that, and it asserts the elapsed time too — otherwise it would pass by waiting.
- **A signal death is 128 + the signal**, and it is `Process::codeOf()` rather than a translation of
  its own. Having two — this one and `Tools\Run::close()`'s — is how `run()` came to report 9 for
  the command `runAsync()` reported 137 for; see the entry on that above.
- **With no fiber to suspend, it is `run()`.** That is not a fallback papering over a failure:
  blocking is correct when there is no loop being kept alive. It is also not speculative — taking
  the branch out turns **five existing tests** red, among them
  `CustomToolsTest::testTheFactoryGetsTheWorkingDirectoryAndCanRunThings`, because a factory runs
  at startup and a factory may run a command.

- **A killed command stops being waited for**, which is not the same as waiting for its pipes to
  close. `proc_terminate()` kills the command and not its children, so `sh -c 'echo; sleep 5'`
  leaves `sleep` holding the pipe open and EOF never comes: the first version of this waited the
  full five seconds after the kill, for both the timeout and the abort. A command that was not
  allowed to finish has finished as far as the caller is concerned, and what the watchers have
  already read is what there is. **Every test of a kill asserts the elapsed time**, because the
  outcome (`STOPPED`, plus whatever it printed) is identical either way — the first draft of those
  tests passed while waiting out the timeout, which is how the defect got written in the first
  place.

- **And then it waits for the process**, which is the one thing the list above was missing and which
  the pipes closing does not tell you — see the entry on a command finishing twice.

`Process` had **no test file at all** before this — 370 lines of process handling, which is the size
that goes unchecked, exactly as `Config`'s sixty lines of path arithmetic were. `ProcessTest` covers
`runAsync` in nineteen cases; the rest of the class is still only covered through its callers.

**Then escape was wired through, and the mutation check caught the missing end.** Removing
`signal:` from `InteractiveMode::initialize()` broke **no test at all** — the runner-level tests
hand the runner a signal directly, so they prove the runner and say nothing about who feeds it.
That is the *wired at one end only* shape from the index, made inside its own fix, for the second
time (the first was the ctrl+o handler that named three classes). What closes it is a test that
presses escape for real:
`InteractiveModeTest::testEscapeStopsACommandAHooksGuardStarted` — a `tool_call` guard runs
`sleep 5`, escape arrives while the guard is parked on it, and the command comes back stopped. Note
*why* the key can arrive at all: the command is not blocking the loop, which is the previous fix
holding the door open for this one.

### An HTML export coloured its code with a palette from neither theme

`HtmlExport::style()` had the six `hl-*` colours written out as hex — `#b5bd68`, `#b294bb`,
`#81a2be` and three more — and they belong to **neither of pig's two palettes**. What that costs
is worst on the light theme, where the export is a white page and those are colours chosen for a
dark one: pale blue keywords and mauve numbers, at a contrast nobody would pick. On the dark theme
it is milder and still wrong — an export that does not match the terminal the person had just been
reading.

The colours were right there: `Themes::getResolvedThemeColors()` hands every token of a theme over
as hex (upstream's export path), and `style()` generates the seven rules from `SYNTAX`, which is
the one place that has to know what `Highlight`'s class names mean.

The two *page* palettes stay written out, and that is not the same decision: a document needs a
card, a border and a rule that no terminal theme has an opinion about. What is shared is what both
media are colouring the same thing for.

Regression test: `HtmlExportTest::testTheSyntaxColoursAreTheThemesOwnInBothThemes`, which asserts
each theme's own `syntaxKeyword` **and** that the old hex is gone.

### `AGENTS.md` that could not be read was skipped without a word

Every loader in this repository hands a problem it cannot fix back to `bin/pig` to print before
the UI starts — `Skills`, `HookLoader`, `CustomToolLoader`, `Settings`, `CustomModels`. `ContextFiles`
was the one that swallowed it. Its comment said *"skipped rather than fatal"*, which answers
whether to **stop** and says nothing about whether to **speak**; upstream prints a yellow warning
here. Permissions and a broken symlink are the two ways in, and what it costs is that the agent
works without instructions the person wrote and nobody ever finds out.

Two things about the shape of the fix:

- **`load()` keeps its signature and `loadWithWarnings()` is the new one**, which is upside down
  from its three siblings — `Skills::load()`, `HookLoader::load()` and `CustomToolLoader::load()`
  all return `[things, problems]`. The reason is not a design preference: `Prompt\SystemPrompt`
  calls `load()` and is a file the developer owns, so the tuple cannot be pushed into it from
  here. Aligning it is a one-line change the next person to touch that file can make.
- **An unreadable `AGENTS.md` no longer hides the `CLAUDE.md` beside it.** The fall-through to the
  second name is upstream's behaviour and the useful one, since the second name exists precisely
  for a project that has one and not the other.

**And the mutation check caught the missing end again.** Dropping the warnings where
`CodingAgent::session()` collects them broke no test — `ContextFilesTest` proves the loader and says
nothing about who reads it. That is the third time in four batches, which is worth saying plainly:
**after wiring anything through, mutate each end separately, not the middle.** Both ends have tests
now; they skip as root, so they run where the suite is actually run rather than in the container.

### A skill whose description was not UTF-8 went into the prompt with no description

`htmlspecialchars()` answers the **empty string** for input that is not UTF-8. `Skills::escape()`
is the one thing between a `SKILL.md` and the prompt, so one stray byte in a description put the
skill in front of the model as:

```xml
  <skill>
    <name>logs</name>
    <description></description>
    <location>/home/dev/.pig/skills/logs/SKILL.md</location>
  </skill>
```

Listed, loaded, warned about for nothing, and **impossible for the model to ever choose** — the
description is the only thing it sees. A `SKILL.md` is a file somebody wrote in whatever their
editor saved, so this is not an exotic input, and the failure says nothing anywhere.

`Utf8::sanitize()` first. `ENT_SUBSTITUTE` was the other candidate and is worse here: it would put
U+FFFD in front of the model where dropping the byte leaves the sentence readable. **The rule this
adds to the family:** a function that answers `''` on bad input is a silent truncation, not an
error — `htmlspecialchars()`, `json_encode()` and `preg_match_all()` each fail that way, and the
tell is that the caller has no way to know.

Found in the same read, and the same family in its seventh guise: **both length ceilings were
counted in bytes.** The spec says characters and upstream counts UTF-16 code units, which for
anything either project reads is the same number; `strlen()` is neither. An 800-character Chinese
description — comfortably inside the spec's 1024 — was complained about at startup for being 2400
long, with a number that counts nothing anybody asked about. `mb_strlen()` now.

And the third hand-rolled `~` expansion went the way of the two in the loaders: `Skills::expand()`
is `Paths::expand()`, so `--skills-dir` pasted out of Finder resolves; `Skills::userHome()` is
`Config::userHome()`, which is where those four lines already lived.

Regression tests: `SkillsTest::testASkillWhoseDescriptionIsNotUtf8StillHasOneInThePrompt`,
`testADescriptionInChineseIsMeasuredInCharactersNotBytes`,
`testANameInTheWrongAlphabetIsCountedInCharactersToo`.

### `-p` handed a custom tool a context with no session in it

`CustomToolSet` needs two things once a mode is running: the UI and the session context.
`InteractiveMode` and `RpcMode` wire both. `PrintMode` wired the UI, under a comment reading
*"the same wiring as the other two modes, with the one difference that matters: no UI"* — which
was counting one difference too few.

What a custom tool got in `-p` and `--mode json` was therefore `CustomToolSet::contextFn()`'s own
stub: no model, no conversation to reconstruct its state from, an `abort()` that does nothing,
and a **working directory of `.`** — so a tool resolving a path against `$ctx->cwd` read a
different file there than the same tool in the terminal, which is the kind of difference somebody
debugs for an hour.

Three things about it are worth keeping:

- **The stub is why it went unnoticed.** It exists so a tool asked something legitimately early
  gets an answer rather than a `TypeError`, and nothing distinguishes that from a mode that
  forgot to wire. It says so on itself now.
- **The `?? new HookContext(…)` fallbacks in the modes are unreachable from `bin/pig`**, which
  builds a `HookRunner` unconditionally — `--no-hooks` gives an *empty* runner, not a null one. So
  the only live path was the one `PrintMode` did not take.
- **`AgentSession::cwd()` is why the fix needed no new constructor parameter.** The session
  already holds the project directory, and a mode holding the session being handed it a second
  time is the two-copies shape on a value that cannot differ.

Found in the same read and corrected rather than annotated: `CustomToolSet`'s own docblock said
`notify()` is called "from the interactive mode", which two other modes had already made false —
the fourth shape from the index, a docblock asserting something about the rest of the repository.

Regression test: `PrintModeTest::testACustomToolGetsTheRealSessionHereToo`.

### Two loaders each had their own worse copy of `Paths::resolve()`

`HookLoader` and `CustomToolLoader` both take extra file paths from the settings, and both had
their own private `resolve()` — the same fourteen lines, pasted, doing a worse job than the
function three directories away that exists for this:

```php
// what both had
if (str_starts_with($path, '~')) {
    $home = getenv('HOME');
    $home = $home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/');
    return $home . substr($path, 1);
}
```

Three things wrong with it, in order of how much they cost:

- **It does not normalise Unicode spaces**, which is the whole reason `Paths::expand()` exists:
  the space in a path copied out of Finder is U+202F. So a hook that is right there was reported
  as `not a readable file`, naming a path that looks identical to the one on disk. Reproduced.
- **A missing `HOME` becomes `/tmp`**, which is a silent fallback of exactly the kind the
  conventions forbid — `~/.pig/agent/hooks/x.php` resolves to a file under the temp directory and the
  complaint names a path nobody wrote. `Paths::expand()` leaves the path alone.
- **It does not collapse `..`**, which `Paths::resolve()` has done since the `grep` prefix fix.

Both now call `Paths::resolve()`. **The lesson is the one this file keeps arriving at from the
other direction:** the question is not "is this fourteen lines correct" but "who else answers
this question, and do they agree" — and the two copies could not have diverged from `Paths`
without diverging from each other too, which is why neither looked wrong on its own.

**And there was a third copy, which this sweep could not see: `CombinedAutocompleteProvider`, one
package over.** A grep across `coding-agent` finds the two loaders and stops there, so the rule to
take from this entry is that the sweep is **every package** — the same fourteen lines, the same half
missing, the same silence. See the `@` picker entry above.

Found in the same read and *not* changed, because checking came first: both loaders use
`glob('*.php')`, which skips a leading dot where upstream's `readdirSync` does not — and here
that is protective rather than a gap. A hook directory is a directory somebody edits, and Emacs
writes a `.#name.php` symlink beside the open file; upstream picks that up and reports a load
error for as long as the editor is open. `Migrations` scans instead, for the opposite reason:
missing a session file there loses a conversation. Both now say so where the code is.

Regression tests: `HookLoaderTest::testAConfiguredPathCopiedOutOfAFileManagerStillResolves` and
its twin in `CustomToolsTest`.

### One condition for two independent facts wired neither

`HookRunner::initialize()` handed each hook its `sendMessage()` and `appendEntry()` writers
behind `if ($send !== null && $note !== null)`. Both are optional and independent, so a mode
offering only one got **neither** — and what the hook would then read is `sendMessage() needs a
session — call it from a handler`, which is a confident explanation of something that did not
happen. All three modes pass both today, so this was the next mode's bug rather than a live one;
upstream sets the two independently, which is what it does now.

The shape, and it is worth having a name for: **an `&&` over two unrelated conditions is a
silent disabling waiting for a caller who only satisfies one.** The tell is that the two halves
have separate error messages — if one fact can be missing on its own, it has to be *wired* on its
own.

Regression test: `HookMessagesTest::testAModeThatWiresOneWriterGetsThatOne`.

### A hook that fails while being asked for permission has not given it

`tool_call` is the one event whose handler is not wrapped in a `try`. Every other event
catches a throwing handler, reports it and carries on, because a hook that watches must not
be able to stop what it watches. Permission is the opposite: a hook asked whether `bash` may
run `rm -rf` and answering with a `TypeError` has said nothing, and reading nothing as yes is
how a guard stops working on exactly the day someone leaves a typo in it.

So `HookRunner::emitToolCall()` lets the throwable out and `HookedTool::guard()` turns it into
a blocked call — `Blocked: a tool_call hook failed, and a hook that cannot answer is not
consent`, which the model reads as a tool error and can work around. Upstream does the same
thing in `tool-wrapper.ts` and calls it fail-safe.

Tests: `HookRunnerTest::testAToolCallHandlerThatThrowsIsNotCaughtHere` and
`testAToolCallHookThatThrowsBlocksTheCall`.

### Two hook files reaching the same path is a fatal error, not a doubled handler

`~/.pig/agent/hooks` symlinked into a project's `.pig/hooks` is a normal way to keep one copy of a
hook, and the obvious reading is that loading it twice just registers its handlers twice.
It is worse than that: `require` on a file that declares a function a second time is a fatal
`Error`, so the second load kills the *first* hook as well and neither works.

`HookLoader` therefore deduplicates on `realpath()` before loading, the same way `Skills`
does — and for a harder reason. Test:
`HookLoaderTest::testTheSameFileNamedTwiceIsLoadedOnce`.

### A hook that prints corrupts the screen it printed onto

Hooks load before the UI starts, so an `echo` or a stray `var_dump` in a hook file lands on
the terminal a moment before the TUI takes it over and redraws across it. What the person
sees is a session that came up wrong, with nothing to read and nothing to search for.

`HookLoader` wraps the `require` in `ob_start()` and turns anything printed into a load
complaint naming the file and quoting what it printed. Test:
`HookLoaderTest::testAFileThatPrintsIsAComplaint`.

### A fixed unpack directory plus a glob installs last week's binary

`ToolInstaller::extract()` unpacks into `<tools>/extract_tmp`, which is upstream's fixed
name and is kept on purpose — see the note on the method for why a unique name was tried
and reverted. The fixed name brings one hazard upstream does not have: `finally` removes
the directory, but a killed run leaves it behind, and `findBinary()` looks for the binary
with `glob('*/fd')` rather than at one exact path. An interrupted v8 download therefore
sits in `extract_tmp/fd-v8…/fd` waiting to be installed as v9.

So the directory is removed *before* it is created as well as after. One line, and the
reason is only visible if you know the discovery globs; a future change back to a unique
name can drop it, and anything that keeps the fixed name must keep it.

Not covered by a test: reaching it needs a killed process and a real archive, and
`extract()` is private — the seam would be a public method existing for the test alone.

### Typing mid-turn had one door, and the second one upstream has is Alt+Enter

Reported from a pi session the developer had open beside pig:

```
 Steering: 不错
 ↳ Option+Up to edit all queued messages
```

pig had the steer — Enter during a turn went to `AgentSession::steer()` and drew `Queued: …` — and
nothing else: no follow-up key, no way to take the queue back short of escape (which also stops
the turn), and a label that did not say which of the two queues the line was in. The two mean
different things to the person watching, and they are upstream's `app.message.followUp`
(`alt+enter`, `ctrl+q` on Windows) and `app.message.dequeue` (`alt+up`, `alt+q` on Windows):

| key | upstream | now |
|---|---|---|
| Enter mid-turn | `prompt(text, {streamingBehavior: "steer"})` | `steer()`, drawn `Steering:` |
| Alt+Enter mid-turn | `prompt(text, {streamingBehavior: "followUp"})` | `followUp()`, drawn `Follow-up:` |
| Alt+Enter idle | `editor.onSubmit(text)` | the Enter byte, through the same submit path |
| Alt+Up | `restoreQueuedMessagesToEditor()`, no abort | `clearQueue()` into the editor, turn carries on |

Four things decided on the way:

- **The editor read Alt+Enter as a new line**, in `Editor::isNewLine()` — `"\e\r"` is one of the
  spellings particular terminals send for Shift+Enter, and upstream's editor has the same line.
  Nothing in `Editor` changed: `CustomEditor` claims the key through `Keybindings` before the text
  field sees it, which is the arrangement every other application key already uses, so a
  `keybindings.json` that unbinds `app.message.followUp` gives the new line back. `EDITING_KEYS`
  stopped saying `shift+enter` means "a new line, and alt+enter".
- **Idle Alt+Enter is handed to the editor as `"\r"`** rather than through a second submit door.
  `Editor::submit()` is private and does two things Enter does — expands the paste markers and
  honours `disableSubmit` — and a second route to "send this" is the first shape from the index.
- **The hint says the key the way it is printed on it.** `Keybindings::display()` is upstream's
  `keyDisplayText()`: capitalised, `alt` said as `option` on a Mac. `label()` stays the help
  table's spelling; a sentence on screen wants the other.
- **A file command typed mid-turn was a follow-up and is a steer**, which is upstream's
  `handleSubmit` for every line of text: `/review foo.php` typed during a turn means "and look at
  this now", not "after you finish". It was the one place the two queues were still swapped, and
  `testAFileCommandQueuedMidTurn…` had been asserting `Queued:`, which could not tell them apart.

Five mutations, five kills: each binding, `followUp()` steering instead, the hint line, and the
idle-is-Enter branch. `AgentSession::queuedByKind()` is the one new accessor, because the screen
has to know which list a line is in and `queued()` flattens them.

### Ctrl+L was the third key taken off the prompt and bound to nothing

The last find of the audit, out of a 106-line component nobody expected anything from.
`custom-editor.ts` is eleven `if`s and pig's `CustomEditor::claimed()` is the same eleven as a
`match` — and one of the eleven, `ctrl+l`, was claimed and never bound. Upstream's `onCtrlL` opens its
model selector; pig had the key reserved for a handler it never registered, so pressing it took the
byte off the text field and did nothing at all.

**Third time, and the worst of the three**, because Ctrl+L is the one key in that set a terminal
already has a meaning for: pressing it out of habit to clear the screen did not even do that.
Ctrl+G was invisible (nothing had ever worked), ctrl+p was invisible, and this one is a key people
press expecting a specific thing. It is bound to `showModels('')` now — `/model` with nothing after
it, which is upstream's selector — and it has a row in `KEYS`, because the entry on the eleven
editing keys is about exactly this: a key that works and is named nowhere is half of a key.

The rule, stated for the third time because three is a pattern: **a component that claims a key is
making a promise the application has to keep, and nothing checks it.** The list to compare is
`CustomEditor::claimed()`'s arms against the `$this->editor->on(...)` calls in `bindEditor()` — one
grep, and it would have caught all three at once.

Regression test: `InteractiveModeTest::testCtrlLOpensTheModelPicker`.

### Ctrl+G was being reported to nobody

`CustomEditor` has recognised Ctrl+G since it was ported — `claimed()` turns it into the
named key `'ctrl+g'` — and `InteractiveMode` never bound it. Pressing it did nothing at all,
while the component behaved as though someone were listening. A forwarded key with no handler
is invisible: there is no error, no warning, and nothing to search for.

It is bound now, to upstream's `openExternalEditor()`: write the prompt to a temp `.md`, stop
the TUI, hand the terminal to `$VISUAL` or `$EDITOR` through `Process::interactive()`, read it
back, start the TUI again. Non-zero exit keeps the original, which is what `:cq` means.

**One difference from upstream, and it is pig doing more rather than less: the loop keeps
turning while the editor is open.** Upstream blocks on `spawnSync`, which stops libuv for as
long as the person takes; a model streaming into a socket nobody reads fills the receive
buffer, goes zero-window, and eventually has its connection reset. So a long edit during a
turn can cost the turn.

The first version here guarded against that by refusing Ctrl+G while the agent was working.
That was wrong, and it is worth saying why: the case Ctrl+G is *most* wanted for is "the model
is busy, my next message is three paragraphs" — which is the same thing as typing while it
streams, pig's own headline feature, only in vim. The guard forbade exactly the best use, and
upstream not guarding it is very likely because that is how it is used. Upstream does guard
other things on `isStreaming`, so it is not an oversight of the pattern.

pig can have both, because it has an event loop where upstream has a blocking call:
`Process::interactive()` takes a `$yield` closure, polls the child, and yields between looks,
so the socket is read and the turn is appended exactly as it would have been. The TUI is
stopped, so none of it is *drawn* until the forced redraw on the way back — nothing can be
done about that while vim owns the screen, but there is nothing to lose by not reading.

Two things that make it safe, both checked rather than assumed:

- `ProcessTerminal::stop()` cancels the stdin watcher and restores the saved `stty`, so pig
  and the editor are never both reading the keyboard.
- A pending timer keeps `Loop::isIdle()` false, so the polling delay is itself what stops
  `run()` from returning — and the program exiting — while the editor is open.

Both callers must spawn a fiber: the key handlers run inside the loop's input callback, and
suspending there suspends the loop that called them. Same reason `send()` and
`startCompaction()` spawn.

`Process::interactive()` has **no timeout**, alone among that class's methods. That is not an
upstream difference — `spawnSync` takes one and `openExternalEditor()` does not pass it either
— it is a difference from pig's own convention, where every other method there has one so a
hung command cannot hang pig. Here a timeout would kill the person's editor mid-sentence.

Barely covered by a test: `interactive()` opens `/dev/tty` for all three streams, and a test
runner has no tty to open. What is tested is the empty-command guard and that a run with no
controlling terminal answers STOPPED without polling — which is the branch a session started
from a script takes.

### The override for pi's directory was a variable name nothing sets

Twenty-first, and the whole of it is one string. Upstream does not write its environment variable
down: `ENV_AGENT_DIR = \`${APP_NAME.toUpperCase()}_CODING_AGENT_DIR\`` with `APP_NAME` read from
`package.json`, so the name is **`PI_CODING_AGENT_DIR`** and the only place it appears literally is a
comment ("e.g., PI_CODING_AGENT_DIR or TAU_CODING_AGENT_DIR") and the help text. Reading
`getAgentDir()` alone suggests `PI_AGENT_DIR`, and that is what `Config::piHome()` looked for — a
name nothing sets, with a docblock calling it "upstream's own override".

So somebody who had moved pi's directory the documented way was read from `~/.pi/agent` anyway, and
finding pi's files is the promise everything else about interop rests on. `PI_CODING_AGENT_DIR` is
read first now, `PI_HOME` stays as pig's own, and `PI_AGENT_DIR` is gone rather than kept as a third
spelling of one thing.

**`Config` had no test at all** — 60 lines of path arithmetic, which is exactly the size that goes
unchecked. `ConfigTest` states all six answers, including that the invented name is not read any
more.

### One field in the session file was not pi's shape

Nineteenth, and the only one found in the file format — which matters more than its size, because a
conversation opening in either tool is the promise `PiFormatTest` exists to keep. Upstream writes a
branch summary's origin as `fromId: branchFromId ?? "root"` — **a string either way**. pig wrote
null when there was no leaf. A pi reading a pig file finds null where its own type says string, and
a pig reading a pi file gets `"root"` as though it were an entry id.

`SessionEntries` now writes `root` and reads it back as null, so pig's own meaning is unchanged and
the line on disk is pi's. The wire codec keeps null on purpose and says so: that is pig's own
protocol, where "there was no leaf" is an answer, and one translation in one place is the point.

**`PiFormatTest` had no branch-summary case at all** — the file's newest entry type was the one the
fence did not cover. It has two now, one for each direction.

### Hooks: what upstream walls off for commands is not here, and the docs said "all of it"

Twentieth, and a documentation find rather than a code one. The hooks section claimed `Hooks\` was
upstream's `core/hooks/` "all of it", and then said — correctly — that sixteen of eighteen events
are fired. What it never mentioned is `HookCommandContext`: upstream gives a slash command's handler
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()`, walled off from event handlers
because calling them inside the agent loop deadlocks. pig has one `HookContext` for both and none of
the four.

Left unported, deliberately and now in writing: `branch()` is the session-file fork pig does not do,
`navigateTree()` is `goTo()` — whose answer is a summary, an abort or text for the prompt rather than
a yes — and the other two would need a second context class to be safe from the deadlock upstream
avoids by having one. Two context types so that a hook command can restart the session is more
machinery than the thing is worth. A hook that wants a handoff can say so with `sendMessage()`.

Found in the same read: **`isError` on a `tool_result` result**. Upstream declares the field
("Override isError flag") and its wrapper reads neither direction, so a hook setting it there changes
nothing. pig honours `true` by raising — the verdict still comes from the one place the loop reads,
`execute()` throwing — and deliberately ignores `false` on a tool that did throw, because rescuing it
would be a second route to "this succeeded". The class's own docblock claimed `false` meant
"not an error after all", which was a promise the code never kept; it says what happens now.

### The model had seven tools where upstream gives it four, and no way to say otherwise

Eighteenth, and the one the developer decided rather than the code: `CodingAgent::session()` started
with `ToolSet::ALL`, so every run handed the model `read, bash, edit, write, grep, find, ls`.
Upstream's default is `codingTools` — the first four — and its `--tools read,grep,find,ls` names the
set when something else is wanted. **pig had the wider default and not the flag.**

Both are fixed the same way round: the default is upstream's four, and `--tools` is ported, with a
name that matches nothing refused by name. `--read-only` still *swaps* the set for the read-only
four rather than narrowing whatever was asked for, which is what it always did.

**Then pi 1.0.3's shape of the flag was taken too**: an entry is a name or a `*` pattern
(`Tools\ToolSelection`, upstream's `createToolNameMatcher()`), `--exclude-tools` takes away after
`--tools`, and `--no-mcp` leaves the MCP extension unloaded. Three decisions in it:

- **An MCP tool is kept unless an entry starts with `mcp__`.** `--tools read,codemode` means those
  two and must not also disconnect every server codemode reaches through; upstream fixed exactly
  that in 1.0.3. `--exclude-tools` has no exception, because a denylist means what it names.
  `allows()` and `matches()` are the two questions, on purpose two methods.
- **The typo check moved from `bin/pig` into `CodingAgent::session()`**, because which names
  exist is only known once the custom tools have loaded, and the refusal now lists them too. The
  list is read *before* the filter is applied, or it would be whatever the typo left — the first
  draft got that backwards and `testAToolsEntryThatNamesNothingIsRefusedByName` said so.
- **The filter lives on `CustomToolSet` (`keep()`), not in the `onChange()` listener.** An MCP
  server's tools are adopted after startup, and a filter the listener had to remember to apply is
  the "wired at one end only" shape; mutating `adopt()` to skip it turns one test red.

**The reason is the rule at the top of this file, in the developer's own words: more tools is more
confusion.** A model with seven spends part of every turn choosing between them, and searching
through `bash` with `rg` is what the system prompt already asks for — `grep`, `find` and `ls` stay
ported, tested and one flag away. The test that asserted the old behaviour was called
`testTheDefaultIsEveryTool`; it now asserts the four and is called
`testTheDefaultIsUpstreamsFourAndNotEveryToolPigHas`, with a sibling for each of `--tools` and
`--read-only` so the three answers cannot drift apart.

`--tools` had to join `Arguments::TAKES_A_VALUE`, which is the trap that list exists for — and it is
one letter from `--no-tools`, which is about the tools **somebody wrote** in `~/.pig/agent/tools` and not
about this. `ArgumentsTest` states both, next to each other.

### DeepSeek accepted the field and ignored it, so every request to it was unbounded

`OpenAiCompat` is the table of ways an "OpenAI-compatible" endpoint is not one, and its own note
says how a row gets added: *"None of that is documented anywhere as a difference; it is what a 400
looks like after you have sent it."* **This row is the one that never produced a 400.**

`api.deepseek.com` falls in `detect()`'s default arm, so pig sends `max_completion_tokens`. DeepSeek
takes it, ignores it, and answers. Measured through `test/live.php` against a `models.json` provider:

```
NO  max_tokens stops it   it came back as stop after 145 output tokens against a budget of 16
                          — the budget was ignored, so the field name is wrong for this endpoint
```

So **no pig request to DeepSeek had ever been bounded**: not `SimpleStreamOptions(maxTokens: …)`, not
the model's declared `maxTokens`, not the compaction summariser's. Nothing failed, nothing was
logged, and the only symptom is a bill. `deepseek.com` joins `mistral.ai` and `chutes.ai` on the
`max_tokens` row.

**An endpoint that quietly drops a field is worse than one that refuses it, and only a number tells
them apart.** The first run reported `it came back as stop rather than length`, which reads like a
stop-reason mapping that missed a word — and the two have opposite fixes. What separated them was
reporting the output count beside the budget: 145 against 16 is a limit that was never applied, and
about 16 would have been a limit applied and misreported. *A live scenario that reports a verdict
instead of a measurement can send you to the wrong file.*

Reachable only through `models.json`, since pig ships no DeepSeek entry — so the harness offering
declared providers is what found it, and the developer having a key for a provider pig does not carry
is the only reason anybody looked.

**And the same run found the silent overflow is not one provider's quirk.** `Overflow::happened()`
carries a usage-based branch for z.ai, which takes an oversized request, answers, and bills past the
window. DeepSeek does it too — a prompt sized past a declared 65,536-token window came back
**answered and billed at 98,315 input tokens**, and the branch caught it. Two examples is what makes
it a shape rather than a special case: a new endpoint is worth asking the question of rather than
assuming it refuses. That branch is also the reason the scenario had to be rewritten before it could
see any of this — it returned on "the provider answered" without ever consulting the usage, so a
provider of that kind was reported as a failure with no numbers in it.

**This entry used to say "upstream has it for that one endpoint", and that is false.** Checked
because the claim was about to be repeated out loud: upstream's `isContextOverflow` guards the branch
with `if (contextWindow && message.stopReason === "stop")` and its caller `_checkCompaction` passes
`this.model?.contextWindow ?? 0` — **provider-agnostic, exactly as pig's is**. Only its *comment*
names z.ai, as the one provider somebody had measured. So the two tools detect a silent overflow
equally well, and what decides whether either of them does is something else entirely: **whether the
declared `contextWindow` is right.** For a provider pig ships no entry for — DeepSeek is one — that
number comes out of a hand-written `models.json`, where `CustomModels` checks it is a positive whole
number and cannot check it is *true*. Declared larger than the real window, the comparison never
fires in either tool; declared smaller, both compact early. That is the first thing to ask about when
a silent-overflow provider misbehaves, and the fourth shape from the index is why this paragraph
exists: a claim about a repository is worth a grep, upstream's included.

Regression tests: `OpenAiCompletionsTest::testDeepSeekGetsItUnderTheOlderNameToo`, which also pins
that nothing *else* about the endpoint is treated as strict — it takes `store`, the `developer` role
and `reasoning_effort` without complaint, so the one row is the whole difference. Taking
`deepseek.com` off the row turns it red.

### 1,633 mutations over the five providers, and an option none of them sent

The worry the provider-SDK section states — 4,977 lines of hand-written protocol whose every test
answers a canned server pig wrote itself — has been answered by corpora for the pure functions and
by `test/live.php` for the wire. This is the third instrument: **change one decision at a time and
see whether any test notices.** Every file under `packages/ai/src/Providers`, one mutation at a
time, against the 423 tests that could possibly catch one.

Eleven operators (`===`↔`!==`, `&&`↔`||`, the four relational ones, `null`→`''`, `true`↔`false`)
plus the one that earns its place — **delete a whole statement and ask whether it was load-bearing**:

| | |
|---|---|
| mutations run | 1,633 |
| killed outright | 1,292 |
| hung the suite | 150 |
| **survived** | **191** |

A hang is a detection too — a mutant that stops the suite finishing is not a mutant that got past
it — so the score is 1,442 of 1,633, and the 191 are the list worth reading. **Two of those rows
were later found to be partly contamination**; see the second harness note at the bottom of this
entry, and the entry after it for what the re-run found. Most are equivalent
mutants: `?? null` → `?? ''` in front of an `is_string()`, a `$signal =` line whose absence is the
same as the null nothing passes, a `$stream->end()` whose consumers stop at the body's end anyway.
**Eleven were not**, and they divide into three findings.

**The big one: `temperature` reached no provider in any test.** Deleting the line that sets it
passed the whole suite in **all five** — Anthropic, both OpenAI shapes, Gemini and Code Assist —
and a grep confirms it: the word `temperature` appeared nowhere in `packages/ai/test`. So the one
option that changes what every model does could have stopped reaching every endpoint without a
single case going red. Nothing was broken; the point is that nothing would have said so.

**The second is the first shape from the index, on a request body.** `GoogleGeminiCli` had its
thinking budget, its output cap and `includeThoughts` pinned; `Google`, the sibling that shares
`GoogleShared` with it, had **none of the three**. Deleting `maxOutputTokens` from the public
endpoint's request passed — and an output cap nobody sends is an unbounded answer, which is
precisely the DeepSeek `max_completion_tokens` bug this file records finding live. `includeThoughts`
flipped to `false` passed too: the thoughts are then billed and never arrive.

**The third is a documented trap with its own provider unpinned.** The entry on an empty arguments
list says `input: []` is a list to Anthropic and that Anthropic **refuses the request**. Mutating
`$content->arguments === []` to `!== []` in `Anthropic::messages()` — which sends `{}` for a call
that has arguments and `[]` for one that does not, exactly inverting the fix — passed. The test for
it had to assert on the **raw body**, because `json_decode(assoc: true)` turns `{}` and `[]` into
the same PHP value: the third shape from the index, arriving in the test for a bug that was itself
the third shape.

Also fixed, smaller: `Copilot::headers()` reads the last message to decide who asked, and its own
comment calls "nothing said yet counts as the person" upstream's default. No test sent it an empty
conversation, so both mutations that break that answer survived.

Regression tests, and each of the eleven mutations was re-applied afterwards to confirm it now
dies: `AnthropicTest::testATemperatureSomebodySetReachesTheRequest`,
`testAToolCallWithNoArgumentsGoesOutAsAnObject`, `GoogleTest::testWhatTheTurnAsksForReachesTheRequest`
and `testNoneOfThemIsSentWhenNobodyAskedForOne`,
`GoogleGeminiCliTest::testTemperatureAndTheOutputCapReachTheRequest`, the same temperature case in
both OpenAI test classes, and `OpenAiResponsesTest::testNothingSaidYetCountsAsThePerson`.

**Two things about the harness, because a measuring instrument is a claim like any other.**

The first version bound the worker tree to the job index — `worker = i % 8` with eight threads — so
job 8 could start in the tree job 0 was still using and two mutations shared one file. It
manufactured **three false survivors out of fourteen** on the trial run, and one of them was a
header `OpenAiResponsesTest` demonstrably asserts. Reproducing that one by hand is what exposed it;
the worker now comes from a queue. *A survivor list is only as good as the isolation under it.*

**And the second flaw was worse, because it produced a whole row of numbers that meant nothing.**
`TransformMessages.php` came back `0 killed, 0 survived, 77 timeouts` — every mutation apparently
hanging the suite, including replacing the whole of `apply()` with `return $messages;`. Chasing it
found the cause was not in pig at all: the worker trees were **reused between runs**, an outer
`timeout` had killed one run mid-mutation, and the worker threads died without running the `finally`
that restores the file. The next two runs measured against trees with somebody else's mutation still
applied. The pass-through takes six seconds and fails four tests when actually run.

So `TransformMessages.php`'s 77 timeouts and `AssistantMessageBuilder.php`'s 47 were both suspect,
and re-running them with trees recreated from scratch separated them: the transform's were entirely
contamination, and **the builder's 47 are real** — mutating the accumulator genuinely stops the
stream terminating. The trees are rebuilt every run now. *Why* it stops terminating, and why that
makes the 47 detections of a state no provider can reach rather than a gap, took its own read and has
its own entry below.

The rule, for the third time in this session and the second in this entry: *a measuring instrument
is a claim like any other.* Both of this harness's bugs produced **confident wrong output** rather
than an error — three false survivors from the first, seventy-seven false timeouts from the second —
and both were found by picking one number off it and reproducing that number by hand.

### `TransformMessages` measured properly, and the divergence pig is proud of had no test

The file the contaminated row above said was unmeasurable. Re-run with clean trees: **77 mutations,
56 killed, 21 survived, no timeouts** — so it was measurable all along, and 21 of its decisions were
not held down by anything. Writing four tests took that to **71 killed and 6 survivors, five of them
genuinely equivalent**. Three findings, and the first is the one to remember.

**The one place this file deliberately does more than upstream had no test.** The compaction section
states it plainly: *"pig flushes pending calls at the end of the conversation as well as before each
assistant or user message — upstream only inserts synthetic results when a later message arrives, so
a conversation that ends on a dangling call is still unsendable there."* Deleting that trailing
`$flush();` changed nothing in 2,480 tests. Every existing case put a user message *after* the
dangling call, which exercises the in-loop flush and says nothing about the end of the list — and
the end of the list is exactly where an interrupted turn leaves one. Escape during a tool call, then
`/model`, and the conversation goes out as it stands.

**A second flush bug the same tests could not see: `$pending` and `$answered` are emptied by
`$flush()`, and nothing noticed when they are not.** Every case had one turn with a call in it. A
conversation with two interrupted turns re-flushes the first turn's call on every later turn —
three invented results for one call by the end of a four-message conversation.

**The Copilot cross-API id rename had no coverage at all**: all of lines 86–110 survived, including
the `$renamedIds` map that keeps the tool *result* pointing at the call it was renamed from. The
rule is real — Copilot serves both OpenAI shapes and the ids its Responses API mints are refused by
its own Completions API, so crossing between them an id is stripped to `[a-zA-Z0-9_-]` and cut to
forty characters. `/model` from `github-copilot/gpt-5` to a Copilot model on the other shape,
mid-tool-use, is the whole scenario, and nothing checked any of it: not the rename, not the
truncation, not the result following it, not that the call is renamed *rather than duplicated*, not
that the rest of the message survives the crossing, and not that a crossing which is **not**
Copilot-to-Copilot leaves the id alone.

Regression tests: `AnthropicTest::testAConversationThatEndsOnADanglingCallGetsOneToo`,
`testTwoInterruptedTurnsGetOneInventedResultEach`,
`OpenAiCompletionsTest::testCopilotsOwnIdsAreRemadeForItsOtherApiAndTheResultFollows` and
`testOnlyCopilotsOwnTwoApisRenameAnything`. The second of those had to be sent by hand rather than
through the `send()` helper, and the reason is on it: with a non-empty key `endpoint()` asks
`GithubCopilot::baseUrl()` where to go and the request leaves for the real Copilot API — which the
first version did, then failed reading a body nothing had received.

**The five that survive have a reason, and the reason was measured rather than assumed.** Four are
`$x = [];` initialisations and one is `$answered[…] = true` → `false`: `$x[] =` and `$x[k] =` create
the array without complaint, and `$answered` is only ever read through `isset()`, so the value
is irrelevant and undefined behaves as empty. `$answered`'s reset inside `$flush()` only matters if
a tool call id repeats across turns, which providers do not do.

*And the interesting part of checking that* is that the project's own runner is **stricter than the
shim these numbers came from**: `phpunit.xml` sets `failOnWarning="true"`, so a mutation that only
warns is killed there and survives here. Probed one by one rather than assumed — of the six, exactly
one warns (`$pending`'s init, because `foreach` reads it), so PHPUnit kills 72 of 77 where the shim
kills 71. Worth knowing in both directions: a survivor list from the shim is the pessimistic one,
and a shim green is not quite a PHPUnit green.

### The sweep's filter decided which decisions counted as unpinned

`packages/ai/src/Http` swept next — the three files this document calls **load-bearing in a way they
are not upstream**, because "a bug in them shows up as tokens going missing rather than as an error,
and there is no vendor implementation to fall back to". 530 mutations, and the loudest row read
`HttpClient::follow()` is entirely unpinned: the loop bound, both statement deletions, all six
mutations of the 3xx condition, the whole 307/308 block, and every line of `absolute()` — every
decision in the redirect logic, surviving.

**It was the harness, for the fourth time, and this is the fourth shape it has taken.** The sweep runs
the tests once per mutation through one filter, and that filter is a list of class-name prefixes
picked when the sweep was pointed at `Providers`. `follow()`'s four tests live in
**`ToolInstallerTest`** — the method was added for the download and its tests went where the caller
was — and `ToolInstaller` is not on the list. So the sweep was measuring `follow()` against a suite
that never ran a single redirect. Adding the name to the filter turned 15 of those survivors into
kills on the spot, with nothing in the repository changed.

*A filter is an argument about what is being measured, and a name left out of it manufactures
survivors exactly as a shared worker tree manufactures them.* The three earlier ones produced
confident wrong output too — three false survivors from a worker race, seventy-seven false timeouts
from a reused tree, one mutation whose replacement text never matched the file. **What separates them
from a real find is the same one step every time: take one number off the harness and reproduce it by
hand.** Here that step was `grep -rn -- '->follow(' --include='*.php' .`, which names the tests in
thirteen characters.

So the rule the instrument now carries, and the reason the Utils sweep below has a filter per file
rather than one for the directory: **derive the filter from the file's consumers, never from the
directory being swept.** `grep -rl` for the class over `packages/*/src` gives the consumers; their
test classes are the filter. And check the cost of the widest one — a filter covering everything runs
in 22 seconds here against a 30-second timeout, which is the *other* way to manufacture a row of
detections that are nothing of the kind.

**What survived the corrected run was worth having, and none of it was what the false row said.**
Four rules with no test, in a method whose docblock states three of them:

- **The 301/302/303-becomes-a-GET and 307/308-keeps-the-method rule had no coverage at all** — not
  the method, not the body, not the `content-length` that goes with it. Five mutations and a
  statement deletion, all silent. This is the one that matters: a redirected POST that keeps its
  body is a request the far end answers twice.
- **A `Location` with no slash on it was never resolved.** `absolute()`'s directory branch is the
  one a real CDN uses — `Location: real.tar.gz` beside the file that was asked for — and deleting
  the line that computes the directory changed nothing. The test named
  `testARelativeLocationIsResolvedAgainstWhereItCameFrom` does not follow a redirect at all: its
  canned server answers 200 with no `Location`, so `follow()` returns on its first line. *A test's
  name is not a claim anybody checks.*
- **`MAX_REDIRECTS` had no meaning.** `<=` and `<` both pass, so whether five means five redirects
  or four was not written down anywhere the code could be held to.
- **Three of `HttpClient`'s own error paths were unreachable from the suite**: a head past
  `MAX_HEAD_BYTES`, a connection closed mid-head, and a header line with no colon in it. The
  scheme check as well — mutating it to accept `ftp://` broke nothing.

The boundary between the first two of those is one test rather than two: exactly `MAX_HEAD_BYTES` of
head and then the hang-up must be refused for being **truncated** and not for being too big, which
pins the guard's `>` and reaches the truncation message in the same breath.

Two survivors stay, with the reason measured rather than argued. `$parts === false` in both
`absolute()` and `resolve()` is redundant — `isset($parts['scheme'])` on `false` is already false, so
the clause beside it throws anyway — though `parse_url()` does return false for real input
(`http://x:8O8O`, a port typed with a letter in a hand-written `models.json`), so the guard is not
dead, only doubled. And `$response->body->close()` between hops **is not a leak**: measured over four
runs of a two-redirect chain, the open-stream count after `follow()` returns is the same with the
call and without it, because reassigning `$response` drops the last reference and PHP closes the
stream. What the line buys is what its comment says and nothing more — the old socket is shut
*before* the next one is opened, rather than one line after, so a five-hop chain holds two sockets at
a time instead of six. There is no seam to observe a peak through, so it is recorded here instead.

Regression tests: eleven in `HttpClientTest`, and each of the twelve mutations above was re-applied
afterwards to confirm it now dies — ten killed outright, two (the closed-mid-head read and the
colonless header line) by hanging the suite, which is a detection. The four cases in
`ToolInstallerTest` are left where they are; a method's tests living beside its first caller is worth
raising rather than quietly moving, and putting the new ones under `HttpClientTest` is also what
keeps the default filter honest about this file from here on.

### A float took every argument off the screen for one byte

`packages/ai/src/Utils` swept with a filter per file, derived as the entry above says. 456 mutations,
354 killed, 13 detected by hanging the suite, 89 surviving — and the survivors are concentrated in
the two files this document describes as hand-written stands-in for npm packages: `PartialJson` (32
of 97) and `JsonSchema` (40 of 171).

**Both are files whose verification was a corpus that was never kept.** `PartialJson`'s docblock
records a run against `partial-json@0.1.7` "over every prefix of a corpus of realistic tool
arguments", and `JsonSchema`'s records ~190 pairs against `ajv@8.20.0`. Those runs found real bugs
and are why both files are trusted. What is in the suite is three test methods for the first and 38
for the second. *A corpus is evidence about the day it ran; a test is evidence every day.* The traps
above say a corpus is worth running and worth keeping the count of — this adds the third clause:
**worth keeping as a test**, or the code it verified goes back to being unpinned the moment the
corpus is deleted.

Rebuilding `PartialJson`'s as a test found a live bug on its first run, at one byte of every float a
model has ever sent:

```
{"path":"/tmp/a.php","temperature":0     => {"path":"/tmp/a.php","temperature":0}
{"path":"/tmp/a.php","temperature":0.    => []
{"path":"/tmp/a.php","temperature":0.7   => {"path":"/tmp/a.php","temperature":0.7}
```

**`is_numeric()` is not JSON's number grammar.** `isCompleteLiteral()` asked PHP whether a bare token
was a number, and PHP says yes to `0.` and `-12.` where JSON says no — so the scanner recorded the
safe end at the end of the input, built the repair `…"temperature":0.}`, `json_decode()` refused it,
and with no candidate left the whole object came back empty. Not the value: **the object**, including
the keys that had arrived thirty bytes earlier. On screen that is a blank frame in the middle of
every tool call carrying a float, and if the turn is interrupted on that byte it is worse than a
frame, because what a provider is then sent is `[]` — the empty arguments list that has an entry of
its own two screens up.

The grammar is written out (`/^-?(0|[1-9]\d*)(\.\d+)?([eE][-+]?\d+)?\z/`, `\z` for the reason that
trap gives). The other places PHP and JSON disagree — `+12`, `.5`, `012`, whitespace on either side —
are not prefixes of any valid JSON number, so no stream arrives at one, and refusing them leaves the
earlier keys standing instead of building a repair that cannot parse.

**Then the same test said the fix was half of one.** With the grammar corrected the object no longer
collapses, and the pair still drops out for that byte — `{"a":1,"temperature":0.` read as `{"a":1}`
where `{"a":1,"temperature":0}` is available and is what the byte before and the byte after both say.
The end-of-input branch keeps a bare token only if the whole of it reads as a value; it walks back to
the longest prefix that does now. Being briefly wrong about a value is this method's stated trade —
its own comment says so about `12` of `125` — and taking a key off the screen and putting it back is
not the same thing.

**The corpus had "numbers cut mid-digit" in it and missed this**, which is the entry on two pastes in
one read arriving in a second file: *a corpus is worth re-reading for the question it never asked.*
Cut mid-digit is `12` of `125`, and the decimal point is the one cut where the two languages'
notions of a number come apart.

The second finding is a boundary, in the branch this document spends the most words on:
**`Overflow::happened()` called a prompt that exactly filled the window an overflow.** `>` and `>=`
both passed, so the one thing that branch must not do — "a guess here compacts a conversation that
was fine" — was not written down. A conversation billed at exactly the window was answered; it fits.

What the property test asserts, and why it is a property rather than another row in `fragments()`:
for every prefix of a valid document, what comes back may hold less than the whole, and may hold a
value still being written, and may hold nothing — but it may never invent a key or contradict one,
which is exactly what a mis-placed safe end produces. The existing monotonic test could not see any
of this: it counts keys, and a wrong safe end counts the same keys while holding the wrong values.
*An assertion on how many is not an assertion on which.* The documents are chosen for the one shape
`fragments()` has no row for — a bracket that **closes** mid-document, which is where `open()` and
`close()` differ at all.

That took `PartialJson` from 32 survivors to 23 and `Overflow` from 4 to 3, and every one of the 26
left has a reason: four are `$x = false;` initialisers whose deletion **warns**, so `failOnWarning`
kills them where the shim does not (probed, not assumed — `Undefined variable $inString` on the line
that reads it); the rest are `?? null` against `''` in front of an `isset()`, and `>= 0` against
`> 0` on an offset that can only be 0 for a document not starting with `{`, which `parse()` answers
with `[]` either way.

Regression tests: `PartialJsonTest::testEveryPrefixSaysPartOfWhatTheWholeCallSaysAndNothingElse` over
six documents and every prefix of each, and
`OverflowTest::testAPromptThatExactlyFillsTheWindowIsNotAnOverflow`, which asserts both sides of the
boundary so the fix cannot become "never mind the silent case".

### Every bound in `JsonSchema` was checked from outside itself

`JsonSchema`'s 40, triaged, and they are one sentence: **the bounds were all tested on the wrong side
of themselves.** `minimum: 1` against 0, `maximum: 10` against 11, `minLength: 2` against one
character, `maxItems: 3` against four items, `maxProperties: 2` against three — every one of them
proves the bound rejects what is out of range, and not one of them says whether it accepts what is
*in* range. So `<` for `<=` and `>` for `>=` walked through all five, and `minimum: 1` rejecting the
value 1 would have been a green suite.

*A test that a bound rejects what is out of range is not a test of the bound.* The lower bounds were
pinned here by accident — `minItems: 2` has a valid case at exactly two items, so its own mutation
dies — which is the tell worth keeping: when one half of a symmetric pair is killed and the other
survives, the difference is usually the fixture and not the code.

**It matters more in this file than it would in most, because of who reads the message.** The whole
standard here is that a correction has to be one the model can act on, which is the argument the
`multipleOf` note already makes about refusing 0.3 as a multiple of 0.1. "must NOT have more than 3
items" against a list of exactly three is a correction nothing can act on: every edit the model makes
is still wrong, and the one thing it cannot do is disbelieve the validator. So an off-by-one that
rejects a **valid** document is the worse direction — an invalid one that slips through fails at the
provider, in the provider's own words, which is a sentence somebody can read.

Four more rules had no test, and two of them end in no message at all rather than a wrong one:

- **`isType()`'s `default => true` could have been `false`.** That is "an unknown keyword is ignored,
  never a failure" — the docblock's most important line — one level down, on the keyword a schema is
  likeliest to carry something unexpected in. And writing the test surfaced the consequence worth
  having on record: a `type` list is satisfied by any one arm, so an unknown name **in a union**
  satisfies everything and the names beside it stop narrowing. That is the rule rather than a hole in
  it, and it is now asserted rather than discovered.
- **`same()` handed two different kinds crashed under the mutation.** Both sides have to be arrays
  before the recursive walk starts; with `||` there instead of `&&`, `const: 5` against `[1,2]` is
  `count(5)` — a `TypeError` out of the validator. And a value of the wrong kind is the commonest
  thing this file exists to catch.
- **`multipleOf: 0` divided by zero**, once its guard was loosened by one operator. A schema somebody
  wrote wrong, and the keyword is skipped for the reason every unknown keyword is: the value is not
  what is broken.
- **`null` against a numeric bound.** `{"type": ["integer", "null"], "minimum": 1}` is an ordinary
  optional number, and PHP is perfectly happy to tell you that `null < 1` — so without the guard in
  front of the comparison every omitted number is too small. The same guard covers `true` and a
  string.

Plus two about the wording: **`uniqueItems` was only ever asked for and never absent**, so enforcing
uniqueness on every array broke nothing — where a list of the same string twice is an ordinary
argument. And **three identical items are one complaint, not two**: without the `break` the walk
reports each successive pair, so ten identical items arrive as nine messages saying the same thing.

That took 40 survivors to 27, and the 27 have reasons. Twenty-one are `?? null` turned into `?? ''`
in front of an `is_int()`, `is_string()` or `is_array()`, which `''` fails exactly as `null` does. One
is the `1e-9` tolerance, which differs only at exactly 1e-9. One is a `continue` that is genuinely
redundant, being the last statement before a branch its own condition has already excluded. And four
are initialisers and the `set_error_handler` pair, which **warn** when deleted — probed rather than
argued, `preg_match(): Compilation failed` and `Undefined variable $seen` — so `failOnWarning` kills
them where the shim does not, which is the shim caveat two entries up arriving for the third time.

Regression tests: `JsonSchemaTest::testAValueExactlyOnItsBoundSatisfiesIt` over nine bounds,
`testATypeNameThisHasNeverHeardOfIsIgnoredRatherThanRejected`,
`testAConstOfTheWrongKindIsAComplaintRatherThanACrash`, `testANullIsNotComparedAgainstANumericBound`,
`testAMultipleOfThatCannotDivideIsIgnoredRatherThanFatal`, `testWithoutUniqueItemsDuplicatesAreFine`,
`testThreeIdenticalItemsAreOneComplaintAboutTheFirstTwo`. Each of the thirteen mutations was
re-applied afterwards to confirm it dies.

**Both halves of this sweep say the same thing about the two files**, and it is worth stating once
rather than twice: `PartialJson` and `JsonSchema` are the two hand-written stand-ins for npm packages
in `packages/ai`, both were verified by a corpus against the real package, both corpora found real
bugs, and **neither corpus was kept**. What the suite had afterwards was 3 test methods for one and
38 for the other, over 785 lines that a provider's 400 is the only other check on. The traps above
already say a corpus is worth running and worth keeping the count of; the third clause is that it is
worth keeping as a **test**, because a count in this document is evidence about the day it was
written and a test is evidence every day.

### A killed sweep leaves workers behind, and contention manufactures *kills*

`packages/ai/src/Utils/Oauth` is the last directory the instrument had not been pointed at — 2,101
lines across ten files, four sign-in flows and a loopback HTTP server. 759 mutations. The finding
worth putting first is about the sweep and not about the code, because it is the one direction the
three earlier harness bugs never took.

**The first run was killed part-way through, and its eight test processes were not.** Nothing noticed:
the sweep's own per-mutation timeout lives in the Python that was killed, so eight `php` processes
carried on for **fifty minutes** — spinning, holding a CPU each. The replacement run recreated the
worker trees, so its files were clean, and it reported **759 run, 130 not killed**.

Re-run on an idle machine, the same 759 mutations report **272 not killed.** So the contaminated run
did not inflate the survivor list, it **halved** it — and that is the dangerous direction. Under 2×
CPU load the suite fails for reasons of its own: a canned server that answers on a timer answers too
late, a test that waits 50ms waits through the wrong window, an assertion about elapsed time comes
back wrong. Every one of those is a red suite, and a red suite is recorded as `killed`. **Contention
manufactures kills, so a contaminated sweep says the code is pinned when it is not.**

The three earlier bugs all produced false *survivors* — a worker race, a reused tree, a replacement
string that never matched — and a false survivor costs an afternoon chasing coverage that already
exists. A false kill costs the bug. So two rules, and the first is the cheap one:

- **Count the worker processes before trusting a number.** `ps -eo cmd | grep -c php` against the
  worker count, and `etimes` on any that look old. Eight workers means eight processes; thirteen
  means something from last time is still running, and the numbers from that run are worth nothing in
  either direction.
- **Kill the strays before re-running**, not just the run. The trees are recreated at startup, so the
  files are safe; the CPU is not.

One thing the two-pass design did earn, in the opposite way from expected. `InteractiveModeTest` is
**56 of the 62 seconds** a full-consumer filter costs here, and what it exercises of this directory is
the `/login` and `/logout` lists rather than the flows — so pass 1 ran without it and pass 2 re-ran
only the survivors with it. In the contaminated run pass 2 appeared to kill 29 of the first 50, which
looked like the filter-omission trap repeating. On the clean run it killed **0 of the 75 it reached**
before that run was killed too. So the suspicion was contamination as well, and the honest statement
is a sampled one: a quarter of the survivors were re-checked with `InteractiveModeTest` in the filter
and none of them died.

**What the 272 are.** By file, and the shape is worth reading before the individual finds:
`Anthropic.php` 6 of 37, `Provider.php` 1 of 27, and `Pkce`, `DeviceCode`, `OauthError` and
`Credentials` between them 3 of 13 — the pure parts are pinned. The survivors are in
`Antigravity.php` (51 of 128), `GeminiCli.php` (62 of 189), `GithubCopilot.php` (52 of 166) and
`CallbackServer.php` (67 of 191, plus 20 detected by hanging). And most of *those* are one of three
kinds: closing a socket or cancelling a watcher, where the end state is the same either way and there
is no seam to observe a peak through — the `HttpClient::follow()` measurement two entries up applies
unchanged; a guard on a value the caller cannot produce; and `?? null` against `''` in front of an
`is_string()`. These suites are thorough — 57 cases and 13 — and they cover nearly every rule this
document states about the four flows.

Three did not, and the first is a claim this file made about the platform:

**`CallbackServer::listen()`'s docblock said the warning is the only place the reason appears.**
Measured against a port already held: `$errstr` is `Address already in use` and the warning is
`stream_socket_server(): Unable to connect to tcp://127.0.0.1:8085 (Address already in use)` — the
same fact twice, and `$errstr` is the cleaner of the two, which is why the code prefers it. So the
handler earns its place by keeping a warning off the screen before the UI exists, not by carrying
information nothing else has; `$problem` is a fallback for a failure that populates one and not the
other. The fourth shape from the index, pointed at PHP rather than at this repository.

**And the test for it asserted the half that is a constant.** `testAPortSomebodyElseHoldsIsReportedByName`
checked the port number and the sentence after it, both of which are literals — so `unknown error` in
the middle passes, and deleting the whole `$reason` line passes. That is the `'ripgrep exited'` shape
exactly: *a test written against what the code says rather than against what the reader needs.* It
asserts `Address already in use` now, and that the message is **not** the warning's wording, since
both sources carry the reason and asserting the reason alone cannot say which one is used.

**`slow_down` adding five seconds had no test, and the first attempt at one held the mutation in
place.** The rule is upstream's and this document states it. The obvious test — serve `slow_down`,
abort, count the asks — passed identically with the five seconds and without, because
`MIN_INTERVAL` floors a declared interval of 0 at **one second**, so in a 150ms window there is
exactly one ask whatever the code does. Measured both ways rather than assumed, which is the only
reason it was caught: the window has to outlive one second for the two answers to differ at all. At
1.3s it is one ask against two, and the assertion is `assertSame(1, …)` rather than an upper bound,
because an upper bound is what was wrong the first time. *A test that cannot fail is not a test, and
the way to find out is to run it against the mutation it was written for.*

**Blank at the enterprise prompt had no test either.** `if (trim($typed) !== '' && $enterprise ===
null)` is two conditions, "something was typed" and "it is not a host", and either one alone turns
pressing Enter into an error — which is the whole of what `allowEmpty` on that prompt is for. `&&`
for `||` broke nothing.

**One divergence found on the way and left alone, because it is a decision rather than a fix.**
Upstream's `normalizeDomain` is `new URL("https://" + trimmed).hostname` in a `try`; pig's is
`parse_url('//' . $trimmed, PHP_URL_HOST)`. Run against the same inputs on both sides:

| typed | upstream | pig |
|---|---|---|
| `company.ghe.com` | `company.ghe.com` | `company.ghe.com` |
| `???`, `@@@` | null | null |
| `!!!` | `!!!` | `!!!` |
| **`a b`**, **`not a host!!`** | **null** | **accepted as a host** |

A space is a forbidden host code point to WHATWG and nothing to `parse_url`, so pig accepts a typed
domain with a space in it and goes on to build `https://a b/login/device/code`. It still fails — the
request cannot be made — but it fails with pig's own `Cannot parse URL` instead of the sentence
written for exactly this, and this document's claim that "something that is not a host is refused" is
narrower than it reads. A space is also the likeliest thing to be in a pasted domain. The fix is one
comparison; tightening a validation rule is the developer's call, so the measurement is here and the
change is not made. The regression test uses `???` so it pins the guard as written rather than the
behaviour somebody might want instead.

Regression tests: `CallbackServerTest::testAPortSomebodyElseHoldsIsReportedByName` (two assertions
added), `OauthTest::testSlowDownMakesTheNextAskWaitFiveSecondsLonger`,
`testBlankAtTheEnterprisePromptMeansGithubComRatherThanARefusal`,
`testSomethingTypedThatIsNotAHostIsRefusedBeforeAnythingIsAsked`. Each of the five mutations was
re-applied afterwards: four die, one is detected by hanging.

**With this the instrument has been over every directory in `packages/ai`**, and the tally across the
four sweeps is worth one line, because the *ratio* is the useful part rather than the totals:
providers 1,633 mutations with 191 survivors, `Http` 530 with 40, `Utils` 456 with 89 before the
tests and 63 after, `Utils/Oauth` 759 with 272. The last of those is not a worse-written directory —
it is four network flows whose bodies are socket discipline and whose decisions are already covered,
so the survivor count is dominated by lines with no observable effect. **A survivor rate is a fact
about what a suite can see, not a grade.**

### Why mutating the accumulator hangs the suite, and what that says about reachability

`AssistantMessageBuilder`'s 47 timeouts were recorded above as real and unexplained. They are
explained now, and the answer is a structural one about the five providers rather than about the
builder.

**Reproduced in one step** rather than inferred from the list: make `fail()` throw — one line, any
exception — and `AnthropicTest` does not fail, it **hangs**. The reason is the shape of `run()`:

```php
try {
    …the stream…
    $stream->push(new DoneEvent($message->stopReason, $message));
    $stream->end();
} catch (Throwable $error) {
    $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
    $failed = $builder->snapshot();
    $stream->push(new ErrorEvent($failed->stopReason, $failed));
    $stream->end();          // ← and nothing below this
}
```

`end()` is in both branches and in **no `finally`**, so a throw inside the `catch` never reaches it.
All five providers are `Async::spawn(fn () => $this->run(…))` with nobody awaiting the future, which
is the case this document already has a rule for, in as many words: *anything inside `Async::spawn()`
whose future nobody awaits must not be able to throw.* `run()`'s own `try` honours that for the body.
Its `catch` is outside any handler, so a throw there is lost and the stream is never closed — and a
consumer parked on a stream that never ends waits for ever. In a test that is a hung suite; in
`bin/pig` it is a spinner that never stops, because STDIN keeps the loop from ever going idle.

**So why is this a note and not a fix.** Nothing in that `catch` can throw. `fail()` assigns two
fields. `snapshot()` walks `$this->blocks`, whose every element `push()` initialises with a string or
an array, and hands them to four constructors that take exactly those types. `setUsage()` is
arithmetic. `EventStream::push()` returns early when the stream is done and cannot double-complete.
Checked one by one rather than assumed, because "can this throw" is the whole question here.

Which puts it in the category this project refuses: **a branch only a test could reach.** The 47
timeouts are therefore not a coverage gap — they are detections of a state no provider can be in,
and the correct reading of the sweep is that the builder is *pinned*, not that it is unprotected.

**And that distinction is the general lesson, because a hang and a failure mean different things
about reachability.** A mutation that makes the suite *fail* says the code under it is load-bearing
on a path the tests reach. A mutation that makes the suite *hang* says something else: the thing it
broke is also what the error handling depends on, so the failure path failed too. Every one of these
47 is that shape. Worth knowing before reading a timeout as either a detection or a defect — it is
a detection, and what it is detecting may be unreachable.

**A `finally` was the obvious answer and it was tried and reverted, which is the more useful half.**
The change looked free — the two `$stream->end()` calls become one below the `catch`, six files
(the five providers and `StreamProxy`, whose tail is byte-identical), a line removed rather than a
branch added. With `fail()` throwing it **still hangs**, and the reason is a fact about `EventStream`
worth knowing on its own: `end()` takes an optional result, and a bare `end()` **does not complete
`finalResult`** — that only happens when a terminal event is *pushed* and `isComplete()` recognises
it. So the `finally` closes the iteration and leaves `result()` unresolved, and a consumer awaiting
the turn's outcome waits exactly as long as before. The construction that does both is the one
`AgentLoop` already uses for this shape, `EventStream::fail($error)` — which would mean a nested
`try` inside the `catch`, for a state nothing can reach.

And the decisive fact is one that should have been checked before the option was offered at all:
**upstream has the same two branches and no `finally`.** `anthropic.ts` ends `push({type:"done"})`,
`stream.end()`, `catch`, `push({type:"error"})`, `stream.end()` — the shape pig ports. So the port is
faithful, the hazard is upstream's too, and the answer to "should this be structural" is that it is
not structural there either. Six files reverted, and what is left is this paragraph: *the option I
put to the developer was worse than it looked in two independent ways, and one `grep` of upstream
would have said so first.*

**One more harness note, and it is the cheapest of the four.** This sweep appeared to hang at
`77 mutations` with a single worker. It was not hung and it was not contaminated: the jobs were
submitted from a **generator** — `for i, f in enumerate(pool.submit(...) for m in jobs)` — so
`f.result()` blocked before the next `submit()` and eight workers ran one at a time, turning five
minutes into forty. The list has to be built before any result is read, which every earlier version
of the script did. *Three of this instrument's four bugs have looked like something other than what
they were, and the tell each time was a number that did not match the machine: thirteen processes for
eight workers, one process for eight workers.*

### `parse_url()` is not `new URL()`, and a typed domain went through with a space in it

The divergence the Oauth sweep turned up, now closed. Upstream's `normalizeDomain` is
`new URL(trimmed.includes("://") ? trimmed : "https://" + trimmed).hostname` inside a `try`; pig's
was `parse_url('//' . $trimmed, PHP_URL_HOST)`. Run against each other over 34 inputs, they
**disagreed on thirteen**, and the answer was three separate things rather than one:

| | upstream | pig had |
|---|---|---|
| `COMPANY.GHE.COM` | `company.ghe.com` | `COMPANY.GHE.COM` |
| `a b`, `not a host!!` | null | accepted as a host |
| `a<b.com`, `a>b.com`, `a^b.com`, `a\|b.com` | null | accepted |
| `a%b.com`, `a%20b.com` | null | accepted |
| `a<TAB>b.com` | `ab.com` | `a_b.com` |
| `a\x01b.com`, `a\x7fb.com` | null | accepted |
| `münchen.de` | `xn--mnchen-3ya.de` | `münchen.de` |

WHATWG's host parser **lowercases**, **refuses the forbidden host code points**, and **punycodes a
non-ASCII domain**; `parse_url()` does none of the three. The first two are done now and the two
agree on 33 of the 34.

- **The space is the one anybody reaches.** A domain pasted with a word after it came back as a
  host, so the flow built `https://a b/login/device/code` and failed with pig's own
  `Cannot parse URL` — instead of `'…' is not a GitHub Enterprise domain.`, which is the sentence
  written for exactly this and the only one that tells somebody what to fix.
- **Lowercasing is not cosmetic here**: the typed spelling reaches `Credentials::enterpriseUrl` and
  `baseUrl()`, so the same domain typed two ways was stored and sent as two different strings.
- **Two removal steps have to happen before `parse_url()`, not after.** `parse_url()` hands a tab or
  a control character inside a host back as an **underscore**, so a check on the parsed host would
  be looking at `a_b.com` and find nothing wrong with it. WHATWG strips C0 controls and spaces off
  each end and removes every tab, newline and carriage return, and both run first.
- **The order is upstream's, and it is what makes two answers look arbitrary.** The scheme is
  prepended *first*: `ghe.com\x01` has its control stripped because it is still at the end of the
  string, and `\x01ghe.com` does not, because `https://` is now in front of it and the control is
  inside the host. Both answers are upstream's and both have a test.
- **`[` and `]` are forbidden host code points and are deliberately not refused**, because an IPv6
  literal comes back wrapped in them — `[::1]`, which is what upstream answers too.
- **`%` is refused outright.** WHATWG percent-decodes a domain before checking it, so `a%20b.com` is
  the space again and `a%b.com` is an invalid escape; both throw there. Refusing it without decoding
  matches both measured cases, which is the standard this file holds a pattern to.

**The one that remains is the IDN, and it cannot be closed here.** `idn_to_ascii()` is ext-intl,
which this project deliberately does not require — the whole of `Graphemes` and `Width` exists to
avoid it. So `münchen.de` is `xn--mnchen-3ya.de` upstream and passes through as typed here. Passing
through rather than refusing is the smaller divergence: it accepts everything upstream accepts, the
punycode spelling is what the DNS holds anyway and is taken as it is, and an IDN Enterprise host is
the one shape in that method nobody has ever reported. If it ever matters, the price is a required
extension and this paragraph is where to argue it.

Found in the same batch and worth its own line: **`OauthTest` had no `use PHPUnit\Framework\Attributes\DataProvider`**,
so the first data-provided case in it failed with `ArgumentCountError: Too few arguments`. The
missing-`use` trap's fourth guise, and the first in a test file — where it reads as a broken test
rather than as a missing import, because the attribute silently resolves to a class in the test's own
namespace and no provider is ever found.

Regression tests: `OauthTest::testAHostWithSomethingForbiddenInItIsNotOne` (ten rows, every one of
them null under `new URL` as well), `testATabInsideAHostIsRemovedRatherThanRenamingTheHost`,
`testAControlAtTheEndIsStrippedAndOneAtTheFrontIsNot`,
`testATypedDomainIsLowercasedTheWayTheUrlParserDoesIt`, `testAnIpv6LiteralKeepsItsBrackets`.

### `packages/async` read against workerman, which is the reference it does have

This document has said from the start that `Pig\Async` "has no upstream counterpart at all — JS
ships an event loop, PHP does not", and used that to explain why the read-against-upstream
scoreboard has no row for it. **The developer's correction: PHP has mature event loops, and
workerman is the one to read.** So it was read against [workerman](https://github.com/walkor/workerman),
whose `EventInterface` is the comparison surface, and against the select-based loop
[ReactPHP](https://reactphp.org/event-loop/) and workerman share.

Ten of workerman's fifteen methods are here under the same names — `onReadable`, `onWritable`,
`cancel` (its `offReadable`/`offWritable`/`offDelay`), `defer`, `delay`, `run`, `stop`, plus
`isIdle` where it has `getTimerCount`. Three absences are pig's own answers rather than gaps:

| workerman | pig |
|---|---|
| `repeat()` / `offRepeat()` | a callback that re-arms itself — `BorderedLoader`'s spinner and `Process::runAsync`'s poll. A second way to say "again in a moment" is what the rule at the top of this file forbids |
| `onSignal()` / `offSignal()` | `pcntl_async_signals(true)` plus `pcntl_signal()` where the signal is wanted — `ProcessTerminal` for SIGWINCH, `InteractiveMode` for SIGCONT. Two users, neither of which wants the loop to own the table |
| `deleteAllTimer()` | `Loop::reset()`, which tests use and nothing else does |
| the `channel` socket pair | the `usleep()` branch in `poll()` — see below, where the two were measured against each other |

**And the comparison paid for itself somewhere else entirely: `grep` and `find` froze the screen.**
workerman's answer to "wait without blocking" is `Timer::add()` — register a callback and give the
loop back — which pig has as `Loop::delay()` plus a `Deferred`, and as `Process::runAsync()` for a
subprocess. Asking which callers actually use it found that **two of the four search tools do not**.
Measured with a 20ms timer armed and a command that takes half a second:

```
Process::stream   (grep tool)    ran 0.50s, loop ticked  0 times (a free loop would tick ~25)
Process::run      (find tool)    ran 0.50s, loop ticked  0 times
Process::runAsync (hooks)        ran 0.50s, loop ticked 24 times
```

So an `rg` or an `fd` over a large tree stopped the loop for as long as it took: **no keystrokes, no
spinner, no escape** — which is the entry above on a hook's command freezing everything a person can
see, in a tool instead of a hook. And `bash` was never affected, because `Tools\Run` is on the loop
with an `onReadable` per pipe and a `Deferred`: **pig has two subprocess runners and only one of
them was on the loop**, which is the first shape from the index at the worst possible call site,
since searching is what the system prompt tells the model to do instead of giving it more tools.

**Then the obvious question — what does upstream do — and the answer is three different things,
which is why it was worth asking before calling any of this a port defect:**

| | upstream | pig |
|---|---|---|
| `grep` | `spawn(rgPath, …)` — **async** | blocked, through `Process::stream()` |
| `find` | `spawnSync(fdPath, …)` — **blocking** | blocked, now async |
| `bash` | `spawn` | async, `Tools\Run` |
| the `@` picker | `spawnSync` in `autocomplete.ts` | blocking, with pig's own 2s cap |

So `find` is the odd one out in **both** trees, and pig's fix there is a deliberate divergence
rather than a rule it had failed to port — the same standing as `version_compare()` and the
picker's timeout. **`grep` is the other way round: upstream is async and pig is not, so that one is
a genuine port defect**, and the strongest argument for fixing it is that `grep.ts` already says
what the answer looks like.

`FindTool` is fixed by the one-word swap to `Process::runAsync()`, whose no-fiber fallback means a
test calling the tool directly still gets the blocking version and nothing outside a session
changes. **`GrepTool` needed a streaming async runner, which is `Process::streamAsync()`** — the
developer's call, since it is new public surface, and taken once upstream's `grep.ts` had settled
what the answer looks like. Four things about it:

- **`runAsync()` and `streamAsync()` share one `pump()`.** A watcher per pipe, a timeout timer, an
  abort listener and the wait for the *process* rather than for its pipes each have a reason
  written on them, and two copies of that are two things that can come to disagree about what
  killing a command means. Extracted from `runAsync()` with its cases passing unchanged, which is
  the same evidence `GoogleShared`'s extraction rests on.
- **The contract is `stream()`'s**, because a tool moved from one to the other: whole lines only
  with the tail held back, `false` stops the reading there and then, standard error is kept rather
  than drained, and a last line with no newline after it is still a line. All four asserted, because
  a difference in any of them is a difference in what the model is told.
- **The abort goes to the runner, not into the callback.** `GrepTool` used to call
  `$signal?->throwIfAborted()` per line, which was right while the call was blocking and is a hazard
  now: a throw from in there escapes a *loop* callback. The signal is passed to `streamAsync()`
  instead, so escape kills `rg` promptly rather than at its next match, and the caller still sees
  the throw from a `throwIfAborted()` after the call.
- **No fiber means the blocking one**, as `runAsync()` does, so a test calling a tool directly is
  unaffected.

Regression tests at both ends, and the tool end was the one that mattered: `ProcessTest` proves
`streamAsync()` leaves the loop free and keeps `stream()`'s contract, and swapping either tool back
to the blocking runner **broke nothing at all** until
`SearchToolsTest::testASearchDoesNotStopTheLoopWhileItRuns` existed — *wired at one end only*, in
the fix for a bug of exactly that shape. It asserts a 1ms timer ticking during a real search over
this repository: six to ten ticks either tool, against none when blocked.

Two smaller ones found in the same sweep and left alone, because each has a reason to think about
first: the `@` picker's `fd` goes through `Process::run()` from inside an *input callback* with no
fiber to suspend, so making it async means spawning per keystroke rather than swapping a call —
and upstream is `spawnSync` there as well, so pig is already the stricter of the two; and
`openUrl()` blocks for up to two seconds opening a browser, which happens once per `/login`.

**The `channel` is the one to have an argument about, and the argument came out for pig's side.**
workerman's `Select` opens a `stream_socket_pair()` in its constructor and keeps one end
permanently in the read array, for the reason its author gives plainly: *"stream_select does not
allow $read, $write, $e to simultaneously be empty, otherwise it will error."* That is the exact
constraint `poll()`'s `usleep()` branch exists for — PHP 8 raises
`ValueError("No stream arrays were passed")` — so the two are answers to one question, and
workerman's is the asynchronous one: `stream_select()` does the timing, and writing a byte to the
other end interrupts a wait already under way.

pig keeps the sleep, and the reason is that **the branch is only reached when nothing can
interrupt it**. It runs exactly when no stream is armed — so there is no socket that could become
ready, no keystroke that could arrive, and no callback that could run to create a timer, because a
single thread is inside the wait. The only thing that ends it is the timer it was sized to. That is
not an argument from taste; the one case that could refute it was measured:

```
a timer 2.0s out, no streams armed, SIGWINCH delivered at 0.1s from a child
  signal-queued callback ran at 0.103s
  run() returned at        2.000s
```

**A signal does interrupt the sleep** — `usleep()` returns on EINTR and the loop picks the queued
work up on the next tick — so the resize-and-redraw path is already prompt, which was the only
latency the channel would have removed. What is left for it to buy is one code path instead of two,
against three real costs: two file descriptors held for the life of the process, an `isIdle()` that
has to exclude the channel or `bin/pig` never exits, and a `Loop::reset()` that has to close the
pair or 2,500 tests leak 5,000 descriptors. Worth revisiting if a mode ever waits with no watcher
armed *and* something outside the loop has to end that wait; nothing does today.

**And on two things pig is ahead of both**, which is worth stating because it is the same
`version_compare()` rule one package over — where the reference is working around something, the
port is not the workaround:

- **EINTR is matched on the errno, not suppressed.** workerman's React loop is
  `@stream_select($read, $write, $except, …)`, with the `@` there precisely because a signal
  interrupts the call, and React dispatches signals with `pcntl_signal_dispatch()` before every
  select. pig captures the warning with a handler — `@` is forbidden here and the linter enforces
  it — reads `Unable to select [4]` out of it and returns, which is the entry on that trap above.
  The `@` throws away the errno, so it cannot tell EINTR from a real failure.
- **A closed stream is named by its watcher.** `stream_select()` silently drops one and then fails
  with `No stream arrays were passed`, which names nothing; `poll()` checks `is_resource()` per
  watcher first and says which one. Both loops have the hazard and only one of them has the
  message.

**The one real absence is a loop-level error handler.** workerman's interface has
`setErrorHandler(callable)` and every callback goes through a `safeCall()` that routes a throw to
it. pig has nothing: `runQueue()`, `poll()` and `runTimers()` each invoke callbacks bare.
Measured, all three:

```
a deferred callback    => RuntimeException escaped run(): boom
a timer callback       => RuntimeException escaped run(): boom
a readable watcher     => RuntimeException escaped run(): boom
```

**And the traps above are the bill for it.** The sentence "runs inside the loop's own input
callback, so there is nothing above it to catch" appears five times in this file, and each time it
is explaining why something small took the **whole session** down: one byte that is not UTF-8 in a
tool's output, the same in a diff, in a hook's message and in what the person typed; a `/settings`
hint one column too wide on a narrow terminal; a cursor left inside a character by an editor key.
Every one was fixed at its source, correctly. The *class* is still open, and the next throw inside
a `render()` ends `bin/pig` with a stack trace over a half-drawn screen.

**Upstream has no answer to this either, which was checked rather than assumed**: there is no
`process.on("uncaughtException")` or `unhandledRejection` anywhere in the four ported packages —
the only `process.on` handlers in the whole tree are SIGINT and SIGTERM in `mom` and `pods`, both
outside pig's scope. So a throw inside a render ends the process there too, and "upstream does it"
is not available as an argument for. workerman is the only precedent, which made this entirely
pig's own call rather than a port question — **and the developer's call was to take workerman's.**

`Loop::setErrorHandler(?Closure)` is that, with a private `safely()` that every callback the loop
invokes goes through. Three decisions came with it:

- **With no handler set a throw still escapes.** That is what every test in the suite was written
  against, and it is the honest default: a library that swallows by itself is the silent fallback
  this project forbids. Setting one is an application saying it has somewhere to report to.
- **All four sites, not three.** Deferred, timer, readable and writable are one accident four ways,
  and a wrapper on three of them is the shape this document keeps finding. `LoopTest` has a case
  per kind for that reason.
- **The report is deduplicated by message, in the caller.** A `render()` that throws throws again
  on the next tick, so reporting every one fills the transcript with one line per frame and scrolls
  away the thing it is trying to say — the same rule `ToolExecutionComponent`'s renderer fallback
  follows per call. `Loop` hands over every throw, which is its job; `InteractiveMode` decides what
  to draw.

`InteractiveMode::reportLoopFailures()` is the one caller, installed in `start()`: the throw becomes
`Error: …` in the transcript and the session stays usable. That is the whole point and also the
risk, stated plainly because it is the trade that was chosen: a component that cannot draw will keep
not drawing, and what the person sees is a red line rather than a crash.

Regression tests, and each end was mutated separately because *after wiring anything through, mutate
each end, not the middle*: `LoopTest::testWithNoHandlerAThrowStillEscapesTheLoop`,
`testAHandlerTakesAThrowFromAnyCallbackAndTheLoopCarriesOn` (four kinds),
`testTheSameFailureEveryFrameIsOneLineAndNotOnePerFrame`,
`InteractiveModeTest::testAThrowFromInsideTheLoopIsDrawnRatherThanEndingTheSession` — which also
types afterwards, because a session that survived and cannot be used has not survived — and
`testTheSameFailureEveryTickSaysSoOnceAndNotEveryTime`. Six mutations, six kills: unwrapping each of
the four sites, removing the caller, and removing the dedupe.

### 478 mutations over `packages/async`, and a wait that was a spin

Swept in the same batch, two passes as the Oauth entry describes, and with the stray-process count
checked **by the script** before it starts — the guard that entry asks for, which earned itself
immediately by refusing to run beside two leftovers.

300 of the 478 completed before the run was killed, with all ten files represented: **219 killed,
47 detected by hanging, 34 surviving.** Eleven percent, against `Utils/Oauth`'s thirty-six — *the
best-pinned package swept so far*, and the reason is visible in the shape: `AbortSignal` 14 of 14,
`AbortController` 8 of 9, `AbortState` 22 of 26, `Future` 35 of 40, `Deferred` 12 of 15. `Loop.php`
is 60 killed, 29 hanging and 18 surviving, and the hangs are what a broken event loop is: every
test waiting for something that will not arrive.

Two of the 34 were real, and the first is the more interesting kind of bug this instrument finds:

**A wait with no streams in it was a `usleep()` and deleting it changed nothing observable.**
`stream_select()` cannot wait on nothing — PHP 8 raises `ValueError("No stream arrays were
passed")` — so with timers armed and no watchers the wait is a sleep. Take the sleep out and every
callback still fires at the right moment and the wall clock is *identical*: 0.150s either way,
measured. What differs is the CPU — **0.1ms asleep against 134ms spinning**, a thousandfold, which
on a retry countdown or a `Process::runAsync()` poll is a core at 100% for as long as the wait
lasts. No assertion about output could have caught it, which is why `getrusage()` is what the test
asserts on. *A defect with no wrong answer in it still has a cost, and the instrument that finds
one measures something other than the answer.*

**And `onComplete()` has two paths of which only one was reached.** A future that is already
complete defers the callback instead of queueing it; deleting that `defer` changed no test. A
callback that never runs is whatever was waiting on it waiting for ever, and `EventStream` and
`Async::run()` are what register these.

Regression tests: `LoopTest::testWaitingOnATimerWithNoStreamsSleepsRatherThanSpinning` — which
asserts the timer really was waited for as well, so it cannot pass by returning early — and
`FutureTest::testACallbackRegisteredAfterCompletionStillRuns`, which also pins that the callback is
**not** run synchronously, the rule the pending path is written for. Both mutations re-applied
afterwards and both die.

The remaining 32 are the familiar three kinds: `?? null` against `''` in front of a type check,
`$x = []` and `$x = ''` initialisers whose deletion warns (so `failOnWarning` kills them and the
shim does not), and cleanup assignments whose end state is identical. The 178 mutations the killed
run never reached are worth finishing, and the file list above says where they would land.

### A space that is not U+0020 emptied the search box

`Tui\Fuzzy` is what `/resume`'s searchable session list, `--list-models` and `SelectList` filter through, and
`filter()` split the query into tokens with

```php
preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY)
```

**No `/u`, after an ASCII-only `trim()`.** So a space that is not U+0020 was never a gap between
tokens — it was a character the query demanded to find. Measured against upstream over the same
inputs:

| typed | upstream keeps | pig kept |
|---|---|---|
| `tls␣handshake` with U+3000 | 1 | **0** |
| `tls␣handshake` with U+00A0 | 1 | **0** |
| `␣tls` with a leading U+FEFF | 2 | **0** |
| U+3000 on its own | 2 (nothing typed yet) | **0** |

**None of those is exotic.** U+3000 is what pressing space produces on a Chinese keyboard in
full-width mode, U+00A0 is what pasting from a web page brings, and a byte-order mark rides on the
front of anything copied out of a file. So the one keystroke somebody uses to narrow a list of
thirty conversations emptied it, and a lone full-width space — which upstream reads as *nothing
typed* and answers with the whole list — answered with nothing at all. It looked like the list had
lost its contents, which is the failure mode `Cli\SessionList` exists to prevent.

`PREG_SPLIT_NO_EMPTY` already drops the ends, so the `trim()` in front of it was doing nothing the
split does not; it is gone with the ASCII-only pattern.

**The whitespace class is now the union of PCRE's and JavaScript's, which took a codepoint-by-codepoint
check rather than a guess.** Over the whole BMP the two differ by exactly three: `﻿` is in
JavaScript's `\s` and not in PCRE's (it is a format character, not a separator), while U+0085 and
U+180E are in PCRE's and not in JavaScript's. Only the first is a mistake to inherit — a zero-width
no-break space is invisible, so a word after one is still at the start of a word — so `Fuzzy::SPACE`
adds it and keeps the other two. Same rule as `version_compare()` replacing upstream's arithmetic:
**where JavaScript is the one missing a case, the port does not copy the gap.**

Found by the ninth corpus run: **3,078 comparisons against `fuzzy.ts`** — every query against every
haystack, 600 random slices of a haystack used as their own query, and the ordering both produce.
43 differed before the fix and 36 after, and *the 36 are all one deliberate thing*.

**That thing is the second find, and it is a claim this file was making.** `Fuzzy`'s docblock said
the character walk was "the one deliberate difference" and that code units and characters are the
same "for everything either project searches". An **astral** character is two code units and one
character, so every position after one differs: `math 𝐀𝐁𝐂 astral letters` searched for `math 𝐀𝐁`
scores −241.4 upstream and −157.9 here. Emoji are astral and a conversation's text is full of them —
pig's own test corpus has ✅ and 🎉 in it. pig's answer is the **better** one, because the score is
built out of gaps and positions and a family emoji inflating a gap by eight where a reader sees one
character is upstream measuring the encoding rather than the text. So nothing changed except the
sentence that hid it, which is the fourth shape from the index found by running the thing the
sentence was about.

*The four notes about this file had all been read rather than run*, which is what the rule at the top
of the traps says to fix: **a hand-rolled replacement for a package is worth a corpus, and a file of
pure functions is worth one even when reading finds nothing.** Reading found nothing here twice.

Regression tests: `FuzzyTest::testASpaceThatIsNotU0020SeparatesTokens` (three separators plus a
leading mark), `testASpaceThatIsNotU0020StartsAWordToo` (four, the score half), and the two blank
cases added to `testABlankQueryGivesTheListBackInItsOwnOrder`. Each rule fails on its own mutation:
putting the ASCII split back turns two red, dropping `\x{FEFF}` from the class turns three red, and a
byte walk instead of `mb_str_split()` turns three red.

### The registry was a transcription of a generated file

Two failures, months apart, with one cause. Groq answered `404 The model 'llama-3.3-70b-versatile'
does not exist or you do not have access to it` for a row `--list-models` still offered; and
`gemini-3.8-flash`, a real model, answered `no such model in the registry` and had to be
hand-declared in `models.json` before pig could reach it at all. Both were read as the price of
pinning the port to `d0a4c37`, which is a deliberate and defensible pin — and that reading was
wrong about what the pin covered.

**Upstream does not keep this table.** The file pig transcribed 178 rows out of opens with

```
// This file is auto-generated by scripts/generate-models.ts
// Do not edit manually - run 'npm run generate-models' to update
```

and at upstream HEAD the data is not in the repository at all: `models.generated.ts` is 130 lines
of imports, each provider's catalogue arriving from models.dev at build time (`git ls-tree` finds
no `providers/data/` — only the `.d.ts` that types the JSON import). So the thing pig pinned was a
generator's *output*, and `Models`' own docblock called the freeze fidelity: *"the figures are
upstream's at the anchor commit, which is the source a port should agree with rather than whatever
models.dev says today."*

**The decisive consequence: moving the anchor would not have fixed either failure.** There is no
newer table to port to. Porting the anchor forward is a separate, much larger question — 5,003
commits, the four packages 139 → 616 non-test files, 505 files at HEAD that do not exist at the
anchor — and it is *not* the answer to a stale registry. Conflating the two is what kept this
unfixed through two reports.

So `scripts/generate-models.php` is upstream's `generate-models.ts`, ported to the part pig needs:
read models.dev, keep what `tool_call` says can be handed a tool, map per provider to an api and a
base URL, and rewrite the rows. Five things about it are the decisions rather than the code:

- **It writes rows, not files.** Each table carries a `>>> generated` / `<<< generated` pair and
  only what lies between them is replaced, so every docblock, base URL, `RESOLD` entry and Copilot
  header stays hand-written and a regeneration's diff is the rows and nothing else. The alternative
  — emitting the whole file, as upstream does — would move all that prose into the generator.
- **The two subscription tables stay hand-written**, because they are hand-written in *upstream's
  generator too*: models.dev does not carry a Code Assist or Antigravity catalogue. That is a
  verified reason and not an omission.
- **Nothing is defaulted quietly.** Upstream writes `m.limit?.context || 4096`, which turns a
  missing window into a number small enough to make every conversation look nearly full, and a
  missing output cap into one that truncates every answer. The default is kept so the row is
  usable and **the complaint is what gets somebody to look** — the same arrangement as every other
  loader in this repository. Likewise a provider that has vanished from the catalogue is named and
  its table is **left alone**, because writing the empty result would take every one of that
  provider's models out of pig in silence.
- **`ANTHROPIC_MODELS` has no images column**, and the assumption behind that is now checked: a
  text-only Anthropic model is complained about rather than silently recorded as accepting images.
- **Upstream's corrections are ported with their reasons**, not their values alone — models.dev
  reports three times the real cache pricing for `claude-opus-4-5`, and two models it does not list
  are supplied by hand. A number with no reason beside it is a number nobody can ever retire, so
  each carries one, and an override whose row the catalogue has since started carrying **says so**
  instead of shadowing it for ever.

`--from <file>` reads a saved `api.json` and `--dry-run` prints the rows instead of writing them.
Neither is a seam for a test: models.dev is unreachable from the dev container (`CONNECT tunnel
failed, 403`, like every host but Anthropic's), a regeneration from somebody else's snapshot is the
only way to reproduce a table, and a generator whose input cannot be pinned is one whose output
cannot be explained.

**Every run prints what moved** — added, gone, and each field that changed — against the table
loaded before the rewrite, and it reloads the written file in a fresh process to build that
comparison, because PHP cannot be told to forget a class it has already resolved. A regeneration
nobody read is a registry nobody checked, which is the failure this whole entry is about.

Regression tests: `GenerateModelsTest`, twelve cases, which **spawns the script** the way
`RpcClientTest` spawns `bin/pig` and for the same reason. Four rules were mutated one at a time and
each turns exactly one case red: the `tool_call` filter, the deprecated-Copilot skip, the api-by-id
rule, and the empty-table guard. **The fourth appeared silent and was not** — the replacement text
in the mutation script never matched the file, so nothing had been mutated at all; it went red once
the substitution was checked. *A mutation that changes no test is only evidence once you have
confirmed the mutation applied.*

What this does **not** do: add providers. DeepSeek is the one worth naming — upstream ships it at
HEAD, pig has no entry, and adding one is a decision about `OpenAiCompat` rather than a row in a
table.

### The first real regeneration, and what it cost

Run against models.dev on a machine that can reach it: **99 added, 104 gone, 28 changed**, 178
models down to 173. The generator did what it was written to do, and then **49 tests went red with
nothing wrong in pig**. That is the bill for the decision, and it is worth itemising because most of
it was a kind of test this repository had not named before.

**Two of the 49 were real bugs.** Both had been latent for as long as the table was frozen, and both
are the same shape: a hardcoded id that only stops working when the catalogue moves.

- **`AgentState::DEFAULT_MODEL` named a retired model.** It was
  `gemini-2.5-flash-lite-preview-06-17`, models.dev has dropped it, so `Models::find()` answered
  null and an `Agent` nobody configured was back to throwing `No model configured` — the exact thing
  those two constants were added to prevent. `bin/pig` never reaches them, so nothing in a coding
  session would have shown it; `AgentTest::testAnAgentNobodyConfiguredStillHasAModel` did, which is
  the test doing its job rather than a test to fix. It is `gemini-2.5-flash-lite` now, and the
  docblock says the rule that picks a replacement (the cheapest thing Google sells) so the next
  person is not guessing.
- **An override replaced a good row with the stale fallback beside it.** `xai/grok-code-fast-1`
  carried an anchor-era `add` override, on the grounds that models.dev did not list it. **It had
  never fired**, because at the anchor the catalogue *did* carry the model — with a 256,000 window, a
  10,000 output cap and reasoning on, which is what pig's transcribed row held. models.dev has since
  dropped it, the override fired for the first time, and the diff read `window 256000 → 32768; output
  10000 → 8192; reasoning yes → no`. *An override that has never been exercised is an override nobody
  has checked.*

Asking upstream what it thinks **now** is what settled the second one, and it is the cheap move worth
remembering: HEAD carries `XAI_BUILTIN_EXCLUDED_MODEL_IDS`, and `grok-code-fast-1` is on it, with
`grok-3`, `grok-3-fast` and two preview ids. So the catalogue dropping it and upstream excluding it
agree, and re-adding it under any numbers would be offering a model that cannot be talked to — the
Groq 404 again. The override is gone and that list is ported as `EXCLUDED`, which also drops three
models this regeneration would otherwise have added.

**And the override machinery gained the sentence it was missing.** A `fix` merged its values blind,
so it reported "corrected" whether or not the catalogue still had the figure wrong — meaning the day
models.dev fixes its own number, that row becomes a no-op nobody retires. The `add` arm already said
when the catalogue had caught up; `fix` says it now too.

**The other 47 were tests asserting what providers sell.** Sixteen in `ModelsTest` alone: 178 models,
a count per provider, eleven named rows checked to the token and the cent. Those were *right* for a
hand-transcribed table — the docblock on the count said exactly why, *"the way it goes wrong is a row
quietly missing or doubled"* — and that reason expired the moment the rows were generated. What they
assert now is what pig does: every row built whole, every provider pig claims present and nothing
present that pig does not claim, the resale rule, the figures varying per model rather than one set
for all. Where a case needs *a* model with some property it **finds one in the table**, and fails
loudly if the table cannot supply it, because that absence is itself a finding — no reseller-only id
means the fallback in `get()` is dead code.

The rest named live ids as fixtures for rules that have nothing to do with which models exist: that
an alias beats the dated build behind it, that a bare id means the direct provider, that a colon sets
the thinking level, that a non-reasoning model gets its level clamped. `Pig\Test\ProbeModels` is the
answer — a family registered under real providers with ids beginning `zzp-`, which no real id or
display name contains, so substring matching cannot cross between them and the real table. Eleven
cases in `ModelResolverTest`, eleven in `CodingAgentSessionTest`, two in `InteractiveModeTest` and one
in `RpcModeTest` moved onto it.

Four places could not use it and each says why in place:

- **`AgentSessionTest` builds its models locally**, which that file already did — and the case that
  needed a model that *cannot reason* had nothing left to look up, because every Anthropic model in
  the table now reasons.
- **`RpcClientTest` spawns `bin/pig`**, so a registration in the test process does not cross the
  process boundary. It declares the pair in the child's `models.json` instead, which is the same door
  a person uses for a model pig has no entry for — and `CustomModels` requiring an `apiKey` is what
  makes the with-key and without-key cases work off one declaration.
- **`ModelListTest`'s punctuation case** relied on Copilot selling `claude-sonnet-4.5` while Anthropic
  sold `claude-sonnet-4-5`; Copilot's is gone, so a rule about a `.` being a `.` failed for a reason
  that had nothing to do with punctuation. It registers a dotted and a hyphened id of its own.
- **`InteractiveModeTest::testPickingFromTheListSwitches` named the first row of the list** while its
  own comment said *"the first row of the list, whichever it is — what matters is that choosing one
  actually changes the model"*. The regeneration put a different model at the top. The assertion says
  what the comment said now. *A comment that describes a weaker assertion than the code makes is a
  comment that will be right before the test is.*

**`test/live.php`'s table had two dead rows** — `xai/grok-3-fast` and `github-copilot/gpt-4.1`, both
gone from the catalogue — and a dead row there is only noticed when that provider is actually run,
which is the argument for running the harness with no arguments after a regeneration.

The rule the whole batch adds, and it is the same one as the documentation rule two entries up, one
domain over: **a test that names a live model id has an expiry date.** Name one only when the
assertion is about that model; otherwise register what the rule needs. The tell is the same as ever —
if the test's own comment says the id does not matter, the assertion should not name it.

**The second regeneration was the quiet one, and it is the one that says the machinery works.** With
`EXCLUDED` in place: `0 added, 4 gone, 0 changed` — exactly the four xAI ids and nothing else, so the
first run's 99/104/28 really was the frozen table catching up rather than the generator being
unstable. 169 models. And it retired an override without anybody re-deriving anything:

```
! the override for anthropic/claude-opus-4-5 changes nothing any more: models.dev has 3x the real cache pricing
```

models.dev had fixed its own figure. That line is the whole reason a correction reports when it
changes nothing, and it arrived on the mechanism's first opportunity to use it. The override is gone.

**Which left the `fix` arm with no entry, and that was worth not shrugging at** — an untested arm in
the thing that rewrites the registry is what this entry is about. So upstream HEAD was read for a
*live* correction instead of deleting the two tests, and there is one:
`GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS`. Measured against the regenerated rows, four of its ten are
short:

```
claude-opus-4.7    only 200,000
claude-opus-4.8    only 200,000
claude-sonnet-4.6  only 200,000
gpt-5.3-codex      only 400,000
```

GitHub's own table gives those the 1,000,000-token window. **A context window is where compaction
fires**, so a fifth of the real figure means pig summarising a conversation that had four times the
room — the failure the deleted `spotValues` docblock described in as many words ("too small
summarises a conversation that had room"). Four `fix` entries, upstream's reason carried with them,
and the check behind that reason is **inherited and not repeated here**, the same standing as
`EXCLUDED`.

One gap recorded rather than papered over: upstream keeps that as a *set*, so it also covers ids
models.dev does not carry for Copilot yet, while these are per-id. Six of the ten would report
"changes nothing" every run, which is noise, so the four that are live are the entries — and a *new*
Copilot model arriving with a too-small window is therefore not caught by itself. The tell is a
Copilot row whose window looks small beside its siblings.

### Eighteen of Gemini's twenty finish reasons were an error with no words in it

Reported from real use — *"新版 Gemini 和 DeepSeek 总是因为这个出错"* — and the half of it that could be
settled without waiting for the message was settled by a probe rather than by reading. A Gemini
candidate carries a `finishReason`, and `GoogleShared::stopReason()` answers `STOP => Stop`,
`MAX_TOKENS => Length`, `default => Error`. The default is the whole enum's worth of ways a turn can
produce nothing: `SAFETY`, `RECITATION`, `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII`, `LANGUAGE`,
`MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `OTHER` and the image ones. Measured, one chunk per
row:

```
finishReason=SAFETY                   stopReason=error   errorMessage=NULL   overflow=no
finishReason=RECITATION               stopReason=error   errorMessage=NULL   overflow=no
finishReason=MALFORMED_FUNCTION_CALL  stopReason=error   errorMessage=NULL   overflow=no
finishReason=OTHER                    stopReason=error   errorMessage=NULL   overflow=no
finishReason=MAX_TOKENS               stopReason=length  errorMessage=NULL   overflow=no
finishReason=STOP                     stopReason=stop    errorMessage=NULL   overflow=no
```

**`errorMessage` is null, and three things read it.** `Overflow::happened()` and
`Retry::worthRetrying()` each test `stopReason === Error && errorMessage !== null` as their *first*
condition, so such a turn is **neither compacted nor retried**; and what reaches the screen is an
error with no text. A safety block, a recitation block and a malformed call are then the same event
to look at — and the last of those is ordinary on Gemini 3 with tools. The nearest thing to a
diagnosis anybody had was the absence of one.

Upstream's `mapStopReason` does the same and sets no message either, so **this is a divergence rather
than a port correction** — and it is the shape the blocked-prompt case twenty lines up had already
answered: `promptFeedback.blockReason` *throws* `Gemini refused the prompt: SAFETY`, which
`Google::run()`'s catch turns into a real `errorMessage`. One of two siblings had the rule, which is
the first shape from the index.

So the reason is thrown, in the sibling's words and its shape. Four things had to be got right and
each has its own test, because each was a way to make the fix cost something:

- **The usage is read before the finish reason now.** They ride on the same chunk, and throwing
  jumped over `usageMetadata` — measured at `input=0 output=0` on a prompt of 40 tokens. A turn the
  provider refused was still billed for its input, which is
  `testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived`'s rule from the other end: *a turn that
  produced nothing did not therefore cost nothing.*
- **The open block is closed before the throw.** `fail()` keeps the content, so partial text survives
  — but without `close()` the events run `TextStart → TextDelta → Error` with no `TextEnd`, and a
  consumer that pairs them has to guess.
- **Nothing became retryable by accident.** None of the reason names matches `Retry::WORDS`
  (`overloaded|rate.?limit|…`), the sentence carries no `returned <status>` for `statusOf()` to read,
  and none matches an `Overflow` pattern — so a safety block is explained and still not waited out,
  which is right, because asking again gets the same answer.
- **`MAX_TOKENS` and `STOP` still say nothing**, or the fix would be "every finish reason throws".

Regression tests: `GoogleTest::testAFinishReasonThatMeansNothingUsableSaysWhichOneItWas` (four cases,
including a reason this pig has never heard of), `testARefusedTurnKeepsWhatItSaidAndWhatItCost`,
`testTheTwoReasonsThatAreNotFailuresStillSayNothing`, the strengthened
`testASafetyBlockIsAnErrorHoweverPolitelyItIsPhrased`, and
`RetryTest::testAGeminiRefusalIsExplainedAndStillNotRetried`. Each end fails on its own mutation:
dropping the throw turns **six** red, reading the usage afterwards turns exactly the cost one red, and
dropping `close()` turns exactly the event-order assertion red.

**And the old test is why this lasted.** `testASafetyBlockIsAnErrorHoweverPolitelyItIsPhrased`
asserted `StopReason::Error` and nothing else, so it passed identically with a message and without
one — *an assertion that holds either way is not an assertion*, for the fourth time this file has
said so.

### Google's overflow row, verified against a model released after the anchor

The entry this replaces was an open question: the new Gemini and DeepSeek both fail often enough to
have been reported, and the `Ai\Utils\Overflow` rows meant to catch them had never been read back
from those endpoints. The Google half is now answered, by declaring `gemini-3.8-flash` in
`models.json` and running the harness at it — **eleven of eleven, and the sentence matches**:

```
ok  overflow is recognised   ok, refused: google returned 400:
    The input token count exceeds the maximum number of tokens allowed 1048576.
```

Worth keeping both wordings, because they are not the same sentence and only one of them was ever
the example:

| | |
|---|---|
| the anchor-era example | `The input token count (1196265) exceeds the maximum number of tokens allowed (1048575)` |
| what `gemini-3.8-flash` says | `The input token count exceeds the maximum number of tokens allowed 1048576.` |

Google dropped both parenthesised numbers. `/input token count.*exceeds the maximum/i` spans the gap
because the `.*` sits exactly where the count used to be — **luck rather than design**, and the
useful reading is that a pattern anchored on the *shape* of the numbers would have missed it. So the
row holds, and the reason to write this down is that it was one `curl`-free command away from being
known and was assumed for months instead.

**Which also says the reported failures are not this.** A Gemini overflow is recognised and
compacted; what was not recognised is the entry above — a finish reason with no message — and that
is the one that had no way to say what it was.

DeepSeek's half stays open in a different sense: the branch that catches it is the usage comparison,
and **whether it fires at all depends on `models.json`'s `contextWindow` being the true window**. That
number is hand-written, `CustomModels` can check it is a positive whole number and cannot check it is
right, and it is an *input* to this very scenario — the oversized prompt is sized from it. Declared
too large, the prompt may still fit and "the provider accepted an oversized request" is an artefact
of the file; declared too small, an ordinary answer looks like a silent overflow. So for any declared
provider, the window is the first thing to get right and the last thing to trust.

The general rule, unchanged and now cheaper to follow: a pattern with no example beside it is a guess
and a guess here compacts a conversation that was fine. `php test/live.php <provider>/<model-id>`
prints the provider's own words when they are not recognised, which is what that argument was added
for; for a model newer than the anchor, declare it in `models.json` first.

### The overflow pattern for Cerebras and Mistral could never match pig's own words

Seventeenth, and the narrowest kind of porting bug: a regex ported with its subject left behind.
`Overflow::NO_BODY` matches `"400 status code (no body)"` — the **OpenAI SDK's** phrasing, which is
what upstream reads. pig has no SDK: all five of its providers write
`"<who> returned <status>: <message or body>"`, so a 4xx with an empty body ends at the colon and
that pattern could not fire.

Cerebras and Mistral answer an oversized prompt with exactly that — a bare 400 and no body — so the
one case the pattern exists for was the one it missed. `Retry::worthRetrying()` then found a
retryable status and pig sent the same too-long request three more times with backoff before giving
up, where compaction was the answer.

`EMPTY_BODY` is pig's own wording, anchored at the end so a 400 that *did* explain itself is still
not a guess, and `NO_BODY` stays for messages that come from somewhere else. **The test that was
there asserted the SDK string**, which no provider here produces; the new one goes through a real
provider and a canned 400, so the pattern and the message it has to match cannot drift apart again.

Checked at the same time and found matching, so nobody has to check them twice: the other
`packages/ai` utilities. `Utf8::sanitize()` handles what upstream's `sanitizeSurrogates()` handles
and the CESU-8 surrogate case its docblock claims (verified, not assumed); `Overflow`'s table is
upstream's, pattern for pattern; `Credentials`, `Provider` and all four OAuth flows carry upstream's
endpoints, client ids and the same five-minute renewal margin; `SseParser` follows the spec on the
one optional space, multi-line `data:`, comments and CRLF; `PartialJson` is a hand-written stand-in
for the `partial-json` package, and it has now been run **against** it — `partial-json@0.1.7`, every
prefix of a corpus of realistic tool arguments, two differences and both accounted for in its
docblock. `Retry` differs from upstream deliberately and says so: it reads the status out of
pig's own message shape instead of matching bare numbers anywhere in the prose, and adds 408 and
529 — Anthropic's real "overloaded" — to upstream's list.

### `Agent::continue()` turned a misuse into a fabricated turn in somebody's conversation

Upstream checks two things before starting a loop that adds no message: there has to *be* a
conversation, and the last message must not be the assistant's — a provider rejects a request that
ends on its own turn. pig had both checks, in `AgentLoop::continue()`, and not the one place that
matters.

`Agent::run()` wraps the whole loop in a `try`, and the loop's guards throw **synchronously**,
before the fiber starts. So the throw landed in `run()`'s `catch`, which does what it is there for:
turns a failed turn into an `AssistantMessage` with `stopReason: error`, appends it to the
conversation, sets `state->error` and emits `agent_end`. The caller saw nothing thrown. What a
misuse of the method left behind was **an assistant turn nobody's model produced, in the session
file**, saying "Cannot continue: no messages in context".

The two guards are in `Agent::continue()` now, where upstream has them, and the loop keeps its own —
it is a separate entry point and a public one. The rule this is an instance of: **a `catch` wide
enough to turn any failure into a message has to be narrower than the argument checks**, or every
programming error becomes conversation. Nothing in pig relied on the old behaviour; the suite was
green before the tests for it were written, which is exactly why it had survived.

### A truncated answer came back as a finished one, with no usage at all

Two terminal events end a Responses API stream and `OpenAiResponses` listed one. `response.failed`
and `response.completed` were there; **`response.incomplete` was not** — which is the one the API
sends when the answer was cut off, `max_output_tokens` reached being the ordinary way. So the stream
simply ended: `onCompleted()` never ran, the status was never read, and `stopReason()`'s own
`'incomplete' => Length` arm was **unreachable code**. Measured against the real API with a
sixteen-token budget:

```
NO  max_tokens stops it   it came back as stop after 0 output tokens against a budget of 16
```

Two symptoms, one missing arm, and the second is the worse one. **The usage rides on that event
too**, so a truncated turn reported none: the half-sentence read as the finished answer, and the
turn cost nothing in `/session` and the footer. Hitting the output cap is how a long answer
ordinarily ends, so this under-reported the commonest expensive turn there is.

*A `match` arm for a value that can never reach it is the same defect as a missing arm, and it reads
as coverage.* `stopReason()` looked complete — it names `incomplete` — and nothing above it could
deliver that word. The one place to check is the caller's event list, which is the third time this
file has arrived at *read the other end*.

**Upstream handles neither event**, so this is a divergence rather than a port correction. Its
stream ends the same way and its `stopReason` has the same unreachable arm.

The same run also produced a bare **`unknown error`** for an oversized prompt, which is
`errorText()` reading `$data['message']` on an `error` event that did not carry it there. Both
shapes are read now — flat and nested under `error` — and **the fallback is the payload itself**
rather than a sentence that describes nothing: `Overflow`'s table matches against a provider's own
words, so a message that goes missing there is a conversation that could have been compacted and
instead died.

**Both fixes were then run against the real API and both hold**, and the second answered a question
this entry had left open. The overflow case came back
`ok, refused: Error context_length_exceeded: Your input exceeds the context window of this model.` —
a message *and* a code where there had been neither, so **the live event nests both under `error`**
and the documented flat shape is not what arrives. Two things follow: reading only the documented
path loses the whole message, and `/exceeds the context window/i` — OpenAI's own row in
`Ai\Utils\Overflow`, inherited from upstream and never checked against the API — **matches.** The
truncation case came back `ok` as well: `response.incomplete` now ends the turn as `Length` with its
usage intact. *The fallback that prints the payload is what turned "which shape is it" from a guess
into a line of output.*

Regression tests: `OpenAiResponsesTest::testAnIncompleteResponseIsLengthAndKeepsItsUsage`,
`testAnErrorWithNoMessageWhereItBelongsIsNotCalledUnknown`,
`testAnErrorWithNoMessageAnywhereCarriesWhatArrived`. **The second one was silent on its own
mutation until a line was added**: asserting the sentence is in the message passes either way,
because the fallback prints the whole payload and the payload contains the sentence. It asserts the
fallback's own words are *absent* now — the third time this session that an assertion which held
either way was caught by mutating the thing it was written for.

### A 404 for a model pig offers, and a cause that did not survive the second data point

Groq answered every scenario with `404 The model 'llama-3.3-70b-versatile' does not exist or you do
not have access to it` — and **that id is in pig's registry**. `Ai\Models` was then a transcription
of `models.generated.ts` at the anchor, 2026-01-02, so a provider that had retired one since
answered 404 for a row pig still offered in `--list-models`, and `--model` still resolved.

**That was the reading, and the next run refuted it.** Swapping in `llama-3.1-8b-instant` — also in
the registry, and a current Groq model as far as anything here can tell — produced the same 404. Two
independent live models failing the same way on one key points at **the key's access** rather than at
the catalogue, and Groq's sentence says so in its second half: *"or you do not have access to it"*.
So Groq is still untested, the cause is the credential, and the staleness above is a real hazard that
this observation is not evidence for. *A cause that explains one data point is a hypothesis; the
cheap way to find out is one more point, which cost a single run here.*

**The hazard was real anyway, and this entry used to call it the deliberate price of pinning the
registry** — *"a port should agree with the thing it was ported from"*. It is fixed rather than
priced now: the rows are regenerated from models.dev, which is where upstream's come from, and
[the entry above](#the-registry-was-a-transcription-of-a-generated-file) has the argument. What
stands from this one is the method rather than the diagnosis: two data points cost one run and
turned a confident cause into the right one.

What it cost here was a whole provider's live run, and the harness made it worse before it made it
better: nine scenarios answered the same 404, and **two of them printed `ok`** — the empty-message
and budget cases accept a refusal as their answer and could not tell one refusal from another. Both
ask `isAboutTheRequest()` now (a 400 is the provider judging what was sent; a 401, 403 or 404 is it
saying something else entirely), and `text` is the pre-flight: if the plainest scenario cannot say
hello, the rest are skipped with one line instead of nine identical ones.

### A JavaScript index past the end is a field that disappears, and PHP has no such thing

The first find from running against a real API rather than a canned one, and it is the third shape
from the index in a guise the six rows of that table do not cover: **`undefined`**.

A tool call on the Responses API has two ids — `call_id` addresses the result, `id` is the item's own
— so pig carries them joined as `call_id|id`. A call from *another* provider has one id, and
`splitIds()` used it for both, with a sentence on it that was wrong in both halves: *"Reusing it for
both is what upstream does, and OpenAI only ever compares it with itself."* What the live run said:

```
NO  dangling tool call   refused: openai returned 400:
    Invalid 'input[1].id': 'call_abandoned_1'. Expected an ID that begins with 'fc'.
```

OpenAI validates the **shape** of `id`. And upstream does not reuse anything: it writes
`id: toolCall.id.split("|")[1]`, which for an id with no `|` is `undefined` — and `JSON.stringify`
**leaves an `undefined` field out of the object entirely**. So upstream sends no `id` at all and the
item is accepted. PHP has no value that makes a key vanish, so the omission has to be written on
purpose: `splitIds()` answers `null` for the item id and the emission spreads in nothing.

**Which conversations this refused, whole:** `/model` from Anthropic to gpt-5 with a tool call in the
history, and a dangling call `TransformMessages` invented a result for — that is, both of the cases
`TransformMessages` exists to make possible, against the one API that checks. The result item is
unaffected, because it is addressed by `call_id` alone.

**A test was pinning it.** `testACallFromAnotherProviderHasOneIdAndIsUsedTwice` asserted the id
appeared twice, which is what the code did rather than what the caller needs — the `'ripgrep exited'`
shape, and the reason the canned suite was green while the API said no. It is
`testACallFromAnotherProviderSendsNoItemIdAtAll` now, and putting the copied id back turns it red.

The rule to carry: **when porting a JavaScript expression that can produce `undefined`, ask what the
JSON looks like, not what the variable holds.** `split()` past the end, an array index out of range,
a missing property and a function with no return all give `undefined`, and every one of them is an
absent field once it is serialised. PHP's nearest equivalents — `null`, `''`, `false` — are all
present fields with values, and a provider that validates its input can tell the difference.

### An empty arguments list went out as `[]`, which is not an object

Found by the differential run above rather than by a provider complaining, which is the point of
running one: `PartialJson::parse('')` answers `[]`, and in PHP that is both the empty list and the
empty map — so a tool call with no arguments carries an array that `json_encode` writes as `[]`.

`arguments` is an object everywhere it is sent. **Anthropic and Google guarded it** —
`$content->arguments === [] ? new stdClass() : …` — and the two OpenAI arms and `MessageJson` did
not, so the same fact was known in two places out of four and the shape of the miss is this file's
commonest one. What it costs: Anthropic refuses `input: []` outright, and the session file — which
is pi's, and which pi hands straight back to a provider — recorded `"arguments": []` for pi to be
refused with later.

When does a call have no arguments? A tool that takes none, and a call whose arguments never
arrived because the turn was interrupted. Both are ordinary.

Fixed at all four sites, in the shape the two that had it already used: `=== [] ? '{}'` for the two
that encode to a string, `new stdClass()` for the two that hand back a value. **Four copies of one
rule** is what that leaves, and it is deliberate for now — the two originals were already inline and
a shared helper is a decision about `Pig\Ai`'s public surface, not an audit's to make. If a fifth
site appears, that is the moment.

### Anthropic was the one provider of four that never cleaned the conversation up

Fifteenth, from the request-building side, and it is the same count as the others: upstream calls
`transformMessages()` in all four providers; pig called it in three. `Anthropic::messages()` walked
`$context->messages` raw.

Both things that transform does are **refusals** on this API, not degradations:

- A thought is signed by the model that had it, and the signature means nothing anywhere else.
  pig's own rule downgraded an *unsigned* thinking block to text, so a Gemini thought — which has a
  signature, just not one Anthropic can read — went out as a signed `thinking` block and Anthropic
  rejected the whole request. **`/model gemini` then `/model sonnet` broke the conversation** until
  it was compacted past.
- A tool call with no result is what an interrupted turn leaves behind, and Anthropic refuses a
  `tool_use` with no matching `tool_result`. So the next thing anybody typed failed.

One line, and both tests fail on the old code. Two differences from upstream in `TransformMessages`
itself, both noted while reading it: pig flushes pending calls at the **end** of the conversation as
well as before each assistant or user message — upstream only inserts synthetic results when a
later message arrives, so a conversation that *ends* on a dangling call is still unsendable there —
and pig treats any non-result message as closing the turn rather than listing the roles.

### The provider that talks to Copilot sent none of Copilot's headers

Sixteenth, and a sharp one: `github-copilot`'s models are **`openai-responses`** in the registry, and
the three headers Copilot needs — `X-Initiator`, `Openai-Intent`, `Copilot-Vision-Request` — existed
only in `OpenAiCompletions`. Upstream has the same block in both providers because Copilot serves
both APIs; pig had it in the one Copilot never uses. So every Copilot turn went out without them:
an agent follow-up after a tool result was billed and rate-limited as though a person had just typed
it, and **an image was refused outright**, which is the whole of `/paste`-a-screenshot on Copilot.

**Nothing covered those headers in either provider**, which is how the one that mattered ended up
empty. They live in `Providers\Copilot` now — one implementation, two call sites, a test on each
side — for the reason this audit keeps arriving at. The call goes *after* `$model->headers` in both,
which is upstream's order: a registry entry must not be able to turn off the headers that make the
request acceptable at all.

Testing them needs one trick worth knowing: with a non-empty key, `endpoint()` asks
`GithubCopilot::baseUrl()` where to go and the request leaves for the real Copilot API, so a canned
server never sees it. The tests pass an empty key, which is the branch that keeps the model's own
base URL.

Two deviations in the request bodies that are **deliberate** and now written down:

- **Gemini's public endpoint gets an explicit `thinkingBudget: 0`** when nothing asked for
  thinking, where upstream sends no `thinkingConfig` at all. Saying nothing to Gemini is not saying
  no — it thinks by default — so upstream's "off" is really "Gemini decides". pig says no. (The
  Code Assist endpoint is the opposite way round: it rejects a `thinkingConfig` on a model that
  cannot think, and reads its absence as none, so that one sends nothing.)
- **The `# Juice: 0 !important` hack for gpt-5** keys off `$model->id` where upstream reads
  `model.name`. Upstream's registry names are `GPT-5.2` and the like, so `startsWith("gpt-5")` on
  the name is false and their own workaround never fires; pig's check is the one their comment
  describes.

### Every Anthropic turn's input count was wiped out by its own last event

Eleventh find, from reading the four providers' stream events. `message_delta` is the event that
carries the stop reason and the final usage, and both pig and upstream read its `usage` with a
`?? 0` / `|| 0`. Anthropic's `MessageDeltaUsage` types `input_tokens`, `cache_read_input_tokens` and
`cache_creation_input_tokens` as **`number | null`** — "may not be reported" — and the streaming
documentation's own example carries nothing but `output_tokens`. So the final event replaced the
counts `message_start` had already given with zeros.

What that costs: the input cost of every Anthropic turn disappears from `/stats` and the footer,
and `Compaction::contextTokens()` — which believes `totalTokens` — sees a conversation the size of
its last answer. **Auto-compaction never fires on Anthropic**; the turn recovers only when the API
rejects the request as too long, which is late, and by accident.

`Anthropic::update()` now merges: a field the delta reports updates, a field it does not leaves the
earlier number standing. That is right under either API behaviour, and the nullable type is the
argument for it — `null` means "no update", not "zero". It is a deliberate divergence from upstream's
literal `|| 0`, on the same ground as every other "no silent fallback" rule in this file.

**The fixture repeated the input in `message_delta`**, which real Anthropic does not, so the suite
agreed with the code. It now carries the documented shape, and two cases state the rule from both
sides: a delta with `input_tokens: null` keeps 1000, and a delta that does report 1200 is believed.

### OpenAI's final tool-call arguments were read from the deltas only

Twelfth. On the responses API a function call's arguments arrive as `function_call_arguments.delta`
events **and** whole, as `arguments` on the `response.output_item.done` item. Upstream builds the
finished `ToolCall` from that item (`JSON.parse(item.arguments)`); pig used only what the deltas had
accumulated and never looked at the field. Usually the same JSON, so usually the same call — but a
stream that sends no argument deltas, which this API allows and a compatible endpoint may do (Copilot
speaks this API and implements it itself), left the call with **no arguments at all**.

`AssistantMessageBuilder::setJson()` replaces rather than appends, for the same reason
`setSignature()` exists: the usual case is the same JSON arriving twice, and appending would give
`{"a":1}{"a":1}`.

### A Gemini tool call's thought signature was collected and then dropped

Thirteenth, and the clearest "wired at one end only" of the whole audit. Four places handle a tool
call's `thoughtSignature`: `GoogleShared` reads it off the part, `setSignature()` stores it,
`GoogleShared::messages()` writes it back into the request, and `TransformMessages` carries it
across. The fifth — `AssistantMessageBuilder::toContent()`, the one place that *builds* the
`ToolCall` — constructed it with three arguments out of four, so `ToolCall::thoughtSignature` was
always null for a call that came from a stream.

So Gemini never got its own thought context back with the call it came out of, which is what the
field is for and what Gemini 3 expects. The write half could not be tested before this, because
nothing could produce a call that had one; it has a test now, and so does the read half.

**Two smaller things in the same read.** A Gemini `Part` is a one-of by convention rather than by
schema, and upstream reads `text` and then `functionCall` from the same part; pig checked for the
call first and returned, dropping any text that shared the part. And pig keeps the last non-null
`thoughtSignature` on a thinking block where upstream assigns `part.thoughtSignature` every time,
including `undefined` — which clears a signature an earlier part had set. pig's is kept.

### OpenRouter's encrypted reasoning was neither read nor sent

Fourteenth, and both halves were missing, which is why nothing looked wrong. A reasoning model
reached through OpenRouter returns its chain of thought as `reasoning_details` — a list of
`reasoning.encrypted` objects, each naming a tool call by id — rather than as text in one of the
three `reasoning*` fields pig already read. Upstream files each one against the matching call's
`thoughtSignature` and writes them back beside `tool_calls` on the next request. pig did neither, so
**multi-step tool use through OpenRouter lost the model's reasoning between every turn.**

Both halves are ported, with the whole detail kept rather than just its `data`, because the whole
object is what goes back. Anything that will not decode is left out rather than sent as a string:
the field is a list of objects and is rejected otherwise.

Two differences in the completions provider that are **kept** rather than aligned, now that they are
written down: a tool call whose id arrives *after* its first delta stays one call here and becomes
two upstream (`toolCall.id && currentBlock.id !== toolCall.id` splits on the id appearing, where pig
also requires the open call to have had one); and pig pushes no `toolcall_delta` for a chunk that
carries an id and no arguments, where upstream pushes one with an empty delta.

### Twelve models were never told how hard to think

Ninth find, from auditing the providers — the part of pig that is hand-written where upstream has
an SDK, so the expectation was mistranslation and what turned up was a missing `match` arm.

`Stream::translate()` (upstream's `mapOptionsForApi()`) had four arms for five APIs. There was no
`Api::GoogleGeminiCli`, and the `default` handed back plain `StreamOptions` — so every model on the
Code Assist API got no thinking configuration at all: the five `google-gemini-cli` models **and the
seven `google-antigravity` ones**, which speak the same API. `--thinking high` on any of them asked
for nothing and was answered by a model that did not think, without a word anywhere. The `off` case
came out right by accident, because Code Assist reads a missing `thinkingConfig` as none.

**The `default` arm is the actual finding.** `Stream::start()`, ten lines below, matches on the same
enum with no `default` at all — so a new API would fail there loudly and silently get provider
defaults here. Upstream's own default is an exhaustiveness check that throws. `translate()` now has
one arm per API and no `default`, which is the same shape as its sibling and the reason the gap can
not recur silently.

Two rules were stated as being about the protocol when upstream has them about the model, and both
are now the model's:

- **xhigh** was clamped for every `openai-completions` model on the grounds that "xhigh is OpenAI's
  alone". True of the registry, false of a `models.json` proxy reselling `gpt-5.2` over
  chat-completions — which is the common shape where the direct API is unreachable. Upstream asks
  `supportsXhigh(model)` in both OpenAI arms; so does pig now.
- **Gemini 3** was `str_contains($id, 'gemini-3')` in the public arm and upstream's two checks
  (`3-pro`, `3-flash`) in the one being added. Two arms of one file disagreeing about which models
  are Gemini 3 is the shape of every other find here, so both now ask upstream's question.

The Code Assist arm is deliberately *not* the public Google arm: upstream gives it one flat budget
table for all 2.x models where the public arm has per-model ceilings (`2.5-pro` starts at 128 there
and 1024 here). The Gemini 3 level path is shared, because that part is the same.

**`StreamTest` covered one API of the four.** The tests it had were good ones — every reasoning
level against Anthropic's budget table — and the arm that was missing entirely belonged to a
provider nothing in that file mentioned. A data provider over one arm reads like coverage of the
method.

### The token total was recomputed for providers that report one

Tenth find, and the numbers it moves are the ones compaction decides on.
`AssistantMessageBuilder::setUsage()` called `withTotalTokens()` for every provider — Anthropic's
rule (it reports the components and no total) applied to the three that do report one. For Anthropic
and OpenAI's completions the sum is the same figure either way, so it looked harmless.

**For Google it is not.** Gemini's `promptTokenCount` *includes* the cached tokens, so
prompt + output + cacheRead counts them twice, and `totalTokenCount` — the figure Google sends,
which does not — was thrown away. `Compaction::contextTokens()` prefers `totalTokens`, so a
conversation with most of its prompt cached reported a context far fuller than it was and compacted
early: a summarisation paid for, and a shorter window than the model actually had. Upstream computes
the total in exactly the two providers whose API gives none and reads it in the two that do; that is
now the one line in `setUsage()`.

**The test fixture was written to agree with the bug.** `GoogleTest` asserted `totalTokens === 170`
against a `usageMetadata` whose `totalTokenCount` *was* 170 — 100 prompt + 10 candidates + 40
thoughts + 20 cached, which is not arithmetic Google does. Gemini would have sent 150. A fixture
invented to match the code under test is the same failure as a claim in this file that nobody
diffed.

Not changed, because it is upstream's too: the **cost** still charges `promptTokenCount` at the
input rate *and* `cachedContentTokenCount` at the cache rate, so the cached part is paid for twice
in the displayed cost. Upstream's `calculateCost` does exactly this with exactly these fields. It is
a divergence from reality rather than from upstream, and it belongs in one place — here — until
upstream moves.

### A hook's message sent mid-turn was queued where nothing reads it

Sixth find, from auditing `agent-session.ts`' queues. `sendHookMessage()` while the agent is
working did this:

```php
$this->followUps[] = $message->toText();   // and nothing else
```

`$this->followUps` is **this session's own record of what a person typed**, kept so the footer can
count it and `clearQueue()` can hand it back to the editor. The agent has its own queues and reads
neither of pig's lists. So a hook that noticed something during a turn — the case the method exists
for — had its message counted as pending for ever, never delivered to the model, and handed to the
person as their own text the next time they pressed escape. Upstream's line is
`await this.agent.queueMessage(appMessage)`: the message goes to the agent and **not** into the
queued-text list, which is the pair of decisions pig had exactly inverted.

`$this->agent->followUp($message)` is the fix — a follow-up rather than steering, because steering
interrupts the tools queued behind the current one and a hook's note is not a change of mind. It
stays out of `$this->followUps` for the reason upstream keeps it out: handing somebody a hook's
sentence to re-send is not putting their text back.

`HookMessagesTest::testAMessageSentWhileTheAgentIsWorkingStillArrives` sends one from inside the
first turn — the harness gained a `duringTurn` closure for it, since that is the only moment
`isStreaming()` is true and a test still has control. **Nothing covered this branch at all**, which
is why three lines of bookkeeping could stand in for the delivery.

### Ctrl+P was taken off the editor and bound to nothing

Seventh find, and the cheapest kind to have: `CustomEditor::claimed()` has always answered
`'ctrl+p'` and `'shift+ctrl+p'` — taking both keys away from the text field — and
`InteractiveMode::bindKeys()` never registered a handler for either. Upstream binds them to
`cycleModel("forward")` and `cycleModel("backward")`, which pig never ported. So the keys did
nothing, and a key that is claimed and then does nothing is worse than either half.

Ported as `ModelResolver::next()` — a rotation over a list, which is all that was left once
`setModel()` gained the key check, the file write and the settings write. It lives beside `parse()`
rather than on `AgentSession` because two callers need the same arithmetic: ctrl+p, and RPC's
`cycle_model`. **That command is now here too**, and the docblock that said it was not needed
("`get_available_models` and `set_model` are what it is made of") had to go: it was true for a host
until pig needed the same rotation itself, and then leaving it out would have meant two
implementations of one list walk — this audit's subject exactly.

One quirk kept on purpose: a current model that is not in the list counts as being at position 0,
so ctrl+p from there lands on the *second* model. That is upstream's `indexOf` returning -1, and
`--model` pinned to a provider whose key has since gone is how somebody gets there.

### Switching models in a session must not clobber the user's default model in settings.json

The entry that used to be here claimed upstream always writes `setModel()` to `settingsManager`.
That was false: upstream's `agent-session.ts` guards with `if (options.persist)` and `persist`
defaults to **false**. In pig, `$persistAsDefault` defaulted to `true`, so every `/model <name>`
command, every `Ctrl+P` cycle, and every `Enter` on the model picker silently rewrote `defaultModel`
and `defaultProvider` in `~/.pig/agent/settings.json`.

What that cost: somebody with a configured default (e.g. `antigravity/gemini-3.8-flash • medium`)
who switched to `google/gemini-3.8-flash` for one session had their settings file clobbered with
`google`, and next time they opened a new session (`pig`), it reopened on `google` instead of
restoring their default. pi never touches `settings.json` on a session switch: the change is
recorded in the session log (`model_change`) so that conversation remembers it, while a fresh
session always opens on the configured default.

`setModel()` and `setThinkingLevel()` now default `$persistAsDefault` to `false`. Switching via
`/model <name>`, `Enter` in the picker, or keyboard cycling (`Ctrl+P`, `Shift+Tab`) modifies only
the active session. The model picker supports `Ctrl+S` (`app.models.save`) to explicitly set the
chosen model as the persistent default, and `/settings` continues to persist changes.

### Going back to a question kept the question

Eighth find, and the biggest behavioural one in `agent-session.ts`. Upstream's `navigateTree()`
resolves the chosen point before moving: **a message somebody said resolves to the point before
it**, so the leaf lands on its parent, the message leaves the conversation, and its text is handed
back as `editorText` for the prompt. pig moved the leaf *onto* the message. The difference is the
whole "go back and ask it differently" flow: in pig the old wording stayed in the conversation and
the only way to re-ask was to type it again.

`TreeJump` gained `editorText`, `SessionManager::entry()` exists so the parent and the message can
be read as one fact about one entry, and both modes use it — the terminal fills the prompt, RPC
returns it. A hook's message is treated the same way, as upstream treats it: nothing is *sent* from
there, so offering the text for editing is not re-sending a hook's sentence as the person's, and
dropping the message while keeping nothing of it would lose it for no reason.

Three smaller parity gaps came out of the same read:

- **Going to where you already are** ran the whole recipe — hook, summary, replay. Upstream's first
  guard returns early, so `TreeJump` gained a fourth answer: `moved: false` with `aborted: false`,
  which the terminal must not report as "branch summary cancelled". A fourth state in a result
  object is cheaper than a caller guessing.
- **`summarise: true` with no model** moved without a summary. Upstream throws, and so does pig now:
  somebody who asked for the branch to be written down and got the move without it has lost the
  branch. Unreachable from the terminal (`goBackTo()` only offers the summary when there is a
  model), reachable over RPC.
- **A hook's summary was taken even when nobody asked for one.** The hook is *told* whether anyone
  wants a summary — it is the last argument of `SessionBeforeTreeEvent` — so prose returned anyway
  has misread the event, and upstream ignores it.

### A `models.json` key that was only a variable's name travelled as the key

The same question as the entry below — *is there a key for this model?* — at the one end that
answer had been fixed for everywhere else. `hasKeyFor()` was taught to filter `--list-models`,
`/model`, `get_available_models` and a resumed session; for a **declared** provider it was always
true, because `CustomModels::resolve()` could not tell a literal key from a name whose variable is
not set. Measured with a `models.json` naming `DEEPSEEK_API_KEY` on a machine without it:

```
custom keys declared:  {"deepseek":"DEEPSEEK_API_KEY"}
hasKeyFor('deepseek'): true
apiKey('deepseek'):    'DEEPSEEK_API_KEY'
in availableModels:    YES
```

So the models are listed, chosen and resumed onto, and the request leaves as
`Authorization: Bearer DEEPSEEK_API_KEY` — **which is the failure `CustomModels`' own docblock gives
as the reason for resolving in the first place**: *"a proxy handed the literal string `MY_BOX_KEY`
answers 401 and explains nothing."* The fallback produced the thing the feature exists to prevent,
and `hasKeyFor()`'s comment had reasoned about it and stopped one case short — *"a wrong one here
only if the file left it empty"*.

Upstream's `resolveApiKeyConfig` has the same fallback and cannot distinguish the two either, so
this is a deliberate divergence, and what makes it safe is **shape**: `^[A-Z][A-Z0-9_]*$` is what an
environment variable is called and what no provider's key looks like — `sk-ant-…`, `sk-…`, `AIza…`,
`gsk_…`, `xai-…` all carry lowercase or a dash. An all-capitals value with no variable behind it is
a name nobody filled in, so `resolve()` answers **null**, `hasKeyFor()` answers false and the models
are not offered, and `load()` adds a line where every other complaint about that file goes:

```
models.json: …/models.json, provider "deepseek": "apiKey" names DEEPSEEK_API_KEY, which is not set
```

Anything not shaped like a name is still taken literally, as upstream takes it, and an `apiKey` that
is simply absent keeps the complaint it already had rather than being described as an unset variable.
`authHeader: true` with no key adds no header instead of sending `Bearer ` with nothing after it.

**Found by pointing `test/live.php` at a declared endpoint** — the `models.json` path is the one part
of the registry with no live coverage at all, and the harness offering custom providers is what
reached it. The developer's keys included one for a provider pig ships no entry for, which is the
only reason anybody looked.

Regression tests: `CustomModelsTest::testAVariableNameWithNothingBehindItIsNotAKey`,
`testAKeyThatCouldNotBeAVariableNameIsNeverMistakenForOne`,
`testAnEmptyApiKeyIsNoKeyAndKeepsItsOwnComplaint`, `testAuthHeaderWithNoKeyBehindItAddsNoHeader`.
Putting upstream's fallback back turns the first and last red. The shared fixture's `apiKey` became
a literal in the same change: thirty tests were sharing a value that is now a complaint of its own.

### A model with no API key could be switched to, listed, and resumed onto

Fifth find, and the pattern from the fourth one again with a different word in the middle: upstream
asks its model registry one question — *is there a key for this?* — in five places, and pig asked it
in none of them. Everything still worked, because `Stream` throws `No API key for provider: x` when
the turn goes out. That is the wrong place for it:

- **`AgentSession::setModel()`** switched to a model this machine cannot talk to, wrote a
  `model_change` into the session file recording the conversation as being on it, and failed on the
  *next* turn from inside `Stream` — where it reads as the provider's fault. Upstream's first two
  lines are the key check and the throw; pig's are now the same, before anything is applied.
- **`restoreSettings()`** restored such a model from the file, so `--continue` on an afternoon spent
  under a borrowed `--api-key` reopened onto a model whose every turn fails. Upstream's
  `restoreModelFromSession()` checks the key as well as the model and falls back on either.
- **Three listings** — `--list-models`, `/model`, and RPC's `get_available_models` — showed every model
  pig knows of. Upstream's word for all three is `getAvailable()`, and it means *there is a key*: a
  host drawing a menu from that list offered twenty models with nineteen of them broken, and
  `/model gemini` with no Google key switched to Gemini instead of saying there is no such model
  here.

`Auth::hasKeyFor()` and `Auth::availableModels()` are the one home for the question — `Auth` is
what pig has instead of upstream's `ModelRegistry`, and three lists filtered three ways is the
shape this whole audit keeps finding. `hasKeyFor()` deliberately does **not** ask `apiKey()`:
that renews an expiring OAuth token on the way past, which is right for a turn and wrong for a
list — drawing `/model` would refresh every signed-in provider, and one unreachable network
would empty a listing of twenty usable models. So an expired stored token counts as a key here,
where upstream (which does refresh) would drop the provider until the refresh succeeded. The
one-per-provider question is asked once, not once per model: twenty-one models share five
providers and the answer cannot change mid-list.

`setModel()` throwing is why `InteractiveMode::useModel()` now catches: the lists are filtered, so
what is left is a `models.json` provider whose key variable is empty and a sign-out in another
window between drawing the picker and choosing from it.

**Three tests were passing because the checks were missing**, which is the fourth time this audit
has found that: `CodingAgentSessionTest`'s resumed-thinking-level case restored a model it had no
key for, and two `RpcClientTest` cases switched to `anthropic/claude-3-5-haiku-latest` and expected
`claude-sonnet-4-5` in the model list from a home with no Anthropic key at all. And the
environment they run in was part of the problem: this container exports `GITHUB_TOKEN`, which is a
key for github-copilot and therefore nineteen models, so "no keys anywhere" quietly meant
"nineteen models". `Pig\Test\WithoutProviderKeys` takes all thirteen provider variables out for the
length of a test and puts them back — because `putenv()` is process-wide and `RpcClientTest`
spawns `bin/pig` with this process's environment on top of its own.

### RPC's session switch and new-session skipped the three checks the terminal's have

Fourth find, and the clearest instance of the pattern the others taught: **the same operation in two
modes, with the checks on only one of them.**

`InteractiveMode` asks the hooks before leaving a conversation (`mayLeave()` →
`HookRunner::emitBeforeSwitch()`), and `/resume` is unreachable while a turn is streaming because
`showSessions()` guards on `isStreaming()`. `RpcMode::switchSession()` did none of it:

- **The cancellable `session_before_switch` hook was never fired.** A hook that refuses to leave a
  conversation worked in the terminal and was ignored over RPC. `emitBeforeSwitch()` and
  `SessionBeforeSwitchEvent` both existed — one of two callers used them.
- **A turn in flight was not aborted.** Upstream's `switchSession()` awaits `abort()` first. Without
  it the turn belonging to the conversation being left carries on writing into the one just opened.
  The terminal is safe from this by accident, not by rule: the command cannot be reached mid-turn.
- **The queue was not emptied.** Upstream sets `_queuedMessages = []`. What was queued was typed into
  the conversation being left, and sending it into the next one is the same crossing as appending to
  the wrong file — which is the bug `writeTo()`'s docblock was written for.

All three are now done in upstream's order, and the answer gained `cancelled`, which is what
upstream's client reads from the same place.

**`new_session` had all three too, and that is the payoff of the pattern rather than a second
coincidence.** Having found the shape once, its sibling was the next thing to read: `/new` in the
terminal goes through `mayLeave('new')`, upstream's `newSession()` fires the same cancellable hook
with reason `'new'`, awaits `abort()` and empties the queue before `reset()`. Over RPC the agent was
reset out from under a turn in flight, and what had been queued for the conversation being thrown
away was still queued for its replacement. Both commands now answer with `cancelled`.

The tests go through `bin/pig --mode rpc` with a real hook file in a temporary home — the first use
of `RpcClient` for something other than testing itself, and the reason a client was worth porting:
**a mode nothing drives the way a host drives it is a mode whose checks nobody misses.**

**Then the checks moved to where the operation lives, which is the actual fix.** Adding the three
lines to `RpcMode` made the two modes agree today; it left the next mode — the SDK, a web UI — free
to disagree, because nothing about `writeTo()` and `agent->reset()` says that a hook has to be asked
first. `AgentSession::startNew()` and `AgentSession::switchTo()` now hold the whole recipe (the
cancellable hook, the abort, the emptied queue, the new or opened file, `restore()` +
`restoreSettings()`, the `session_switch` emit), the way `goTo()` already held its own guards inside.
Both modes call them and keep only what is theirs: the screen, and `customTools->notify('switch', …)`
— `AgentSession` has no custom tools, and upstream's equivalent call from inside is the one piece not
followed. `InteractiveMode::mayLeave()` is gone with them; a hook refusal comes back as
`SessionSwitch(switched: false)` — a returned answer, like `TreeJump`, because a hook saying no is not
a failure, while a path that is not a session still throws.

One improvement on upstream's order while moving it: the new file is created, and the file being
switched to is opened, **before** the turn in flight is aborted and the queue emptied. Upstream
aborts first, so a session directory that cannot be written leaves the conversation aborted, emptied,
and still writing to the file it was about to leave. Now the throw costs nothing —
`testAPathThatIsNotASessionCostsTheConversationNothing` asserts the file, the messages and the queue
are all still there, and it fails if the two lines are put back in upstream's order.

**And the terminal gained the check it was missing in return.** `/new` never emptied the queue: text
typed during a turn and then thrown away with the conversation was still waiting to be sent in its
replacement. Reading two callers finds which one is wrong; giving them one implementation is what
stops the question coming back.

### Four keys out of eight were not upstream's, and a docblock said that was fine

The same find as the entry below, on a different shared file, and this time **the sentence that
should have prevented it is the one that caused it.** `CustomModels::compat()` read a
`models.json`'s compatibility block, and its docblock said:

> Upstream names four of these and this takes those four under upstream's own spellings … pig's
> `OpenAiCompat` has four more … a file that uses them is a pig file, which is the trade and it is
> stated.

Upstream's `OpenAICompat` in `types.ts` has **all eight**, and the four it supposedly lacks are
right there with a `requires` prefix on each: `requiresToolResultName`,
`requiresAssistantAfterToolResult`, `requiresThinkingAsText`, `requiresMistralToolIds`. pig read
them without the prefix, so a `models.json` written for pi had four of its eight settings silently
ignored — and the last of the four is the one that matters most, because a Mistral-shaped endpoint
rejects any tool id that is not exactly nine alphanumeric characters. Somebody who had configured
their proxy correctly for pi got a 400 out of pig with nothing on screen to connect it to.

Three things worth taking from it:

- **A claim about what upstream has is worth a `grep`, not a sentence** — which this file says about
  itself in four other places, and which is exactly what a confident docblock buys you out of doing.
  The claim was wrong twice over: about upstream's count, and about whose the other four were.
- **The tell was there and it is the same tell as last time:** four spellings out of a group of
  eight not matching is a typo, not a design. `retry.maxRetries` was one out of three.
- **The short spellings are gone rather than kept beside them**, for the session format's reason:
  nobody has a pig `models.json` from a release, and a file holding both names for one flag is a
  file that can disagree with itself.

And the test that covered it was called `testACompatBlockUsesUpstreamsSpellings` and asserted three
of the four that were already right. *A test named after the rule is not a test of the rule.*

Regression test: `CustomModelsTest::testTheFourKeysWithARequiresPrefixAreUpstreamsToo`.

**The rest of `types.ts` matched, which is worth the words because it is 217 lines of nothing but
field names** — and a renamed or dropped field there is a wire difference with no error to announce
it. Checked one by one: the five `Api` values, twelve `KnownProvider` names, five reasoning levels,
the four option fields and `reasoning` on top of them, all four content types including
`textSignature`, `thinkingSignature` and `thoughtSignature`, `Usage`'s five counts and five costs,
the five stop reasons, the three messages' fields, `Tool`, `Context`, **all twelve stream events**
down to `error` carrying its message under the field name `error` rather than `message`, and
`Model`'s twelve. Two differences with no defect behind them, now written where they live:
`ToolResultMessage`'s `isError` has a default where upstream requires it (every construction here
passes it), and `Context::$tools` is `[]` for "none" where upstream's is `undefined` — which is a
difference **on the wire**, because `[]` is truthy in JavaScript, so three of upstream's four
providers send `tools: []` for a conversation with no tools where pig sends no field. Only its
Google provider asks `.length > 0`, as pig does everywhere, and the one case where the empty field
is load-bearing already has a branch in `OpenAiCompletions`.

### One settings key out of seventeen was not upstream's

Third find from the audit, and the cheapest one to have prevented: **`retry.maxAttempts` where
upstream writes `retry.maxRetries`.** A `settings.json` written for pi had that number silently
ignored and got the built-in three.

What gave it away was not reading the key — it was reading its **siblings**. `retry.enabled` and
`retry.baseDelayMs` are both upstream's spellings, and `baseDelayMs` even carries a docblock saying
so ("as upstream writes it"). One key out of a group of three not matching is a typo, not a decision.
Sixteen of pig's seventeen settings keys were upstream's; this was the seventeenth.

It had a second layer: **CLAUDE.md asserted the thing that was false.** "Settings are upstream's keys:
`retry.enabled`, `retry.maxAttempts`, `retry.baseDelayMs`" — a sentence that would have stopped
anybody from checking. A claim about matching upstream is worth a diff, not a sentence.

And a third: **a test was passing because of the bug.** `AgentSessionTest` set `maxAttempts` in a
fixture and asserted two attempts; with the key corrected, the fixture stopped applying and the
assertion failed at three. The test had been documenting the wrong key rather than the behaviour.
That is worth remembering for the rest of the audit — a green suite proves the code and the tests
agree, and both were written by the same hand on the same day.

The method is still `retryMaxAttempts()`: attempts is what the number counts, and upstream's own name
is the odd one (it counts attempts after the first). The file speaks upstream's dialect; the code
speaks its own.

### A hook's compaction summary was indistinguishable from pig's own

Second find from the same audit. pi's `CompactionEntry` carries `fromHook?: boolean` — *"True if
generated by a hook, undefined/false if pi-generated"* — and `CompactionSummary` here did not have
it. `BranchSummary` did, which is what made the omission visible: `Compaction::files()` had the rule
on one arm and not the other.

```php
if ($message instanceof CompactionSummary) { …carry its file lists forward… }
if ($message instanceof BranchSummary && !$message->fromHook) { …carry them forward… }
```

Two things followed from the missing field. `Compaction::files()` carried a **hook's** read and
modified lists forward as though pig had found them, which upstream refuses
(`if (!prevCompaction.fromHook && …)`); and **pi reading the same file could not tell who wrote the
summary**, which matters more, because "a session file from either can be read by the other" is a
stated goal and a field pi defines was simply absent from what pig wrote.

The field is written by `SessionEntries` and `SessionCodec`, read back as false when absent — pi's own
rule for it, and what a file written before this existed actually means.

**The pattern worth taking from this one:** the same rule existing on one of two sibling arms is a
stronger signal than either arm on its own. The author knew the rule when writing `BranchSummary`;
the compaction arm could not follow it because the field was not there, and nothing failed.

**And it turned out to be the whole audit.** Most finds are one shape — *a rule present in one
place and absent in its sibling* — and none of them is a mistranslation of upstream: a `match` arm
missing a type (`BranchSummary`, `ImageContent`), a field one arm checks and the other has not got
(`fromHook`), one settings key out of three (`retry.maxRetries`), two session-leaving recipes with
different ideas about them (`RpcMode` vs `InteractiveMode`), one question — *is there a key for this
model?* — that upstream asks in five places and pig asked in none, and a settings write the terminal
did three times and RPC never. So the first tool to reach for is not "read this file against
upstream" but **"who else does this, and do they agree?"** — and the fix that sticks is one
implementation rather than the missing lines added twice, which is why `startNew()`/`switchTo()`,
`Auth::availableModels()`, `ModelResolver::next()` and the settings writes inside `setModel()` all
exist.

**The second shape, from the same sweep: a thing wired up at one end only.** A hook message pushed
onto a list the agent never reads; ctrl+p taken off the editor and bound to nothing; `TreeJump`
carrying no editor text because `goTo()` never worked out that a question is something you go back
to *before*; `TreeList::search()`, public and documented "for whoever draws the search line", with
nobody drawing it. None failed a test, because each was internally consistent — the wiring was
missing, not wrong. What finds them is reading the *other* end: who consumes this list, who handles
this key, what does the caller do with what it gets back.

**The third shape is not about the port at all: PHP's value model is not JSON's.** Both languages
read the same documents off the wire and disagree about what two of them being *the same* means, in
opposite directions — and the disagreement is invisible until a value crosses the boundary.

| The find | PHP | JSON, and JavaScript's runtime |
|---|---|---|
| `const: 5` refused `5.0` | `int` and `float` are two types, and `===` says so | one number type; `5.0` **is** `5`, and neither can express otherwise |
| An empty arguments list went out as `[]` | one array type: `{}` and `[]` decode to the same value | `object` and `array` are two types, and `input: []` is refused |
| `uniqueItems` and whole floats | `json_encode(1.0)` is `1`, so nothing was wrong — the branch written to fix it did nothing | — |
| `Utf8::sanitize` exists at all | UTF-8 bytes; the failure is a truncated sequence | UTF-16; the failure is an unpaired surrogate |
| Up in the editor landed inside a character | a string offset counts **bytes**, so `2` is two thirds of `你` | a string offset counts code units, so `2` is two characters |
| `str_pad()` lined up an ASCII-only column | a *length* is bytes, so 代码审查 is 12 | a length is code units, so it is 4 — and the terminal gives 8, which is neither |

So PHP splits numbers **finer** and arrays **coarser**. Copying upstream's comparison faithfully is
how the first one happened: `!==` is the honest translation of `!==` and the wrong answer anyway,
because the question is not "is this the same PHP value" but "is this the same JSON document". The
reading that finds these is not "does this line match upstream" but **"is this value the same thing
in both languages"** — and the place to look is every boundary: `json_decode`, `json_encode`, and
any comparison of two things that came through one. The last two rows widen that: a **string offset**
is a value too, so the same question has to be asked of every number carried over from a `length`,
`slice` or `indexOf` — see the trap entry, which is the one member of this family that crashes.

And the last row adds a **third** unit, which is the one a terminal program actually needs: columns.
Bytes, code units and columns are three different numbers for 代码审查 — 12, 4 and 8 — so "port the
unit" is not a choice between two. Anything lining text up wants the third, which is `Width::visible()`
and `Width::pad()`; anything indexing into it wants characters; and only a byte buffer wants bytes.

### Compaction counted an image as nothing, so a conversation of screenshots could not be compacted

The first find from auditing an already-ported file difference by difference, rather than from
anybody hitting it. `Compaction::estimateTokens()` had two holes, both under-counting, and
under-counting is the dangerous direction: the walk back through the conversation never reaches its
budget, so the cut point stays at the beginning and **compaction runs, pays for a summarisation call
and frees nothing**.

```
BranchSummary    (3900 chars) estimates as 0 tokens
CompactionSummary(3900 chars) estimates as 985 tokens
a tool result with one image estimates as 3 tokens (upstream: 1203)
cutPoint over 12 turns of images, keepRecent=2000: 0 of 24 messages dropped
```

After:

```
BranchSummary    (3900 chars) estimates as 1001 tokens
a tool result with one image estimates as 1203 tokens
cutPoint over 12 turns of images, keepRecent=2000: 22 of 24 messages dropped
```

**`BranchSummary` was simply missing from the `match`.** Every other message type was there,
including `CompactionSummary` right beside it, and the `default => 0` arm meant the omission was
silent — which is the cost of a `match` over types with a default: adding a type is not a compile
error, it is a zero.

**The image is the more interesting one, because upstream contradicts itself.** Its `toolResult` arm
adds 4800 characters per image; its `user` arm counts only text and adds nothing. So the same
screenshot is worth 1200 tokens if a tool returned it and nothing if somebody pasted it. pig counts
4800 in both, which is the second time this port has taken the right one of upstream's two answers
to the same question — the first was the `data:` prefix in `StreamProxy`. (Upstream's comment there
says "4000 chars, or 1200 tokens" while the code says 4800; 4800 is what divides into 1200, so the
code is the half that was meant.)

Worth keeping in mind for the rest of the audit: **no test failed when either hole was open**, and
the existing `testEveryKindOfMessageHasASize` walked five message types and asserted `> 0` on each —
it just never listed the sixth. A test that enumerates cases by hand goes stale exactly where a new
case was added.

### An abandoned branch was in the file and unreachable

`SessionManager::goTo()`'s docblock has always said it:

> Nothing is deleted and nothing is rewritten. The entries after this one are still in the file with
> their parents intact, so the branch that was abandoned **can be gone back to in exactly the same
> way**.

The first half was true and the second was not reachable. `/tree` listed `branch()`, which is the
path being talked on, and `SessionManager` had no public walker for anything else — so going back to
an abandoned branch needed an id that nothing would show. Reproduced before the fix:

```
before going back, branch() shows: A, re: A, B — about to be abandoned, re: B
after going back,  branch() shows: A, re: A, C — a different direction, re: C
entries mentioning the abandoned branch, still in the file: 2
```

Found by auditing the unported-components row rather than by anybody hitting it, which is the
interesting part: the row said those files were "mostly UI for unported subsystems", and for nine of
them that was right. For this one the subsystem was ported — the tree is in the file, in pi's own
format — and only the way in was missing. **A row that explains away a whole group is worth checking
one member at a time**; this table has now been wrong five times.

The fix is `SessionManager::tree()` plus `Interactive\TreeList`, upstream's `tree-selector.ts`. Two
things in the walker are worth keeping straight:

- **Children come out in file order.** Not sorted by id — an id is eight characters off a UUID, so
  sorting by it scrambles a fork's arms, and the first version did exactly that until the test
  asserting which arm comes first caught it. Not by timestamp either: that is the *message's*, and
  the entries of one turn share it to the millisecond. `$this->entries` is insertion-ordered, which
  is the file's order, so walking it once is the whole sort.
- **An orphan is a root, not a dropped entry.** A file written by something newer can hold an entry
  whose parent is not there, and losing part of somebody's conversation to keep a tidy tree is the
  wrong way round. Upstream guards the same case.

Three more the audit found in the list itself, all in what a row is made of:

- **What is typed had nothing on screen.** `search()` was there, public, documented "for whoever
  draws the search line" — and nobody drew it. Upstream has a component of its own for it, wired to
  the list's `getSearchQuery()`, which is one more thing to wire at one end only; here the second
  end was simply never written. So the rows narrowed and nothing said why: a typo could not be
  seen, an empty filter could not be told from a query that matched nothing, and backspace was
  blind. `TreeList::render()` draws it now, because the list owns the query — **always, even
  empty**, since the word `Search:` is what says typing does anything, and a query longer than the
  terminal is cut like every other line here.
- **Thinking counted as what a row says.** `textOf()` collected `ThinkingContent` along with text,
  and two things read it: the row, and the default filter's "did this turn say anything". Thinking
  plus a tool call is what most tool-calling turns look like on a provider that returns its
  reasoning — so the default tree grew a row of the model talking to itself between every question
  and its answer, and a search matched text nobody had ever read. It is out, which is upstream's
  rule and pig's own: `SessionInfo::$text` leaves thinking out of the *session* search for exactly
  this reason, and a tree is a list of points somebody might go back to. `[image]` stays in, and is
  the one addition — a screenshot pasted with nothing said about it is a point worth going back to,
  where upstream draws `user: ` and nothing after it.
- **A cleared name drew `[label: ]`.** Nothing is deleted from a session file, so clearing a name
  writes a `Label` entry with a null label, and its row under the `all` filter read like a
  rendering fault. Upstream's word for it is `(cleared)`.

### `--mode rpc` could not be started, by either route

Found by porting `rpc-client.ts` and pointing it at the real binary. Two guards in `bin/pig`, each
right on its own, closed the door between them:

```php
if ($mode === 'rpc' && ($fileArgs !== [] || $messages !== [])) { … exit(1); }   // a message makes no sense
if (!$interactive && $messages === [] && $fileArgs === []) { … exit(1); }        // "Nothing to say"
```

`bin/pig --mode rpc "hello"` was refused for having a message, and `bin/pig --mode rpc` was refused
for having none. **So rpc mode — one of the three documented ways in, with its own class, its own
twenty-two commands, its own test file and a section in this document — had never been reachable
from the command line.** `RpcModeTest` did not notice because it constructs an `RpcMode` in process
and hands it two streams; nothing started the binary.

The fix is `Arguments::needsAMessage()`, which is false for the terminal *and* for rpc, tested
directly. The condition could have stayed inline in `bin/pig` as `$mode !== 'rpc' && !$interactive`,
and then the next person to touch it would have had nothing to run either.

The general rule: **a mode that nothing starts the way a person starts it is a mode that can be
broken by a line somewhere else entirely.** `RpcClientTest` is now the one test in the suite that
spawns `bin/pig`, and that is the point of it — it also caught `RpcClient` sending `path` where the
wire wants `sessionPath`, which no amount of reading would have.

**One test in it failed three times in a full run and never on its own, and the third time is what
fixed it.** Each time it was `testAgentThatCannotStartSaysWhatItWroteToStandardError`, each time on
the 8.3 pass, and each time the message was `The agent closed its output` with **no trailer** where it
should carry `No model matches 'no-such-model-anywhere'` — the sentence whose whole job is to name the
reason, naming none.

The reading, which was right: the child's two pipes become readable in one `stream_select()`, the
stdout watcher is registered first, so `readOut()` sees EOF and words the failure with
`$this->stderr` still empty — and `fail()` then cancels the watcher that would have filled it in.
`trailer()` **reads the pipe itself** now, before it words anything. Every one of its six callers is
already reporting a failure, so a non-blocking read nobody is waiting on is free, and it does not
depend on which watcher the loop happened to reach first.

**The part worth keeping is how long the wrong test held the fix up.**
`testTheReasonSurvivesWhenBothPipesEndInTheSameBreath` was written for exactly this and *passes
either way*: a stand-in that writes its reason and exits immediately gets its stderr drained on some
other path, so for three appearances the evidence said "the obvious construction is already handled
somewhere" and the fix stayed unapplied. What reproduces it every time is one line different — the
stand-in closes standard output and **stays alive** (`fclose(STDOUT); usleep(400000);`), so the poll
that finds stdout at EOF finds standard error readable and unread, and the registration order decides
the rest. *A test built from the shape a race is guessed to have is not a test of the race.*

Regression test: `RpcClientTest::testTheReasonIsReadOffThePipeRatherThanHopedFor`, which fails with
the drain removed. The older one stays as the non-regression guard for the shape it does cover.

### A new session recorded no thinking level, so `--continue` came back on `off`

Found by extracting `bin/pig`'s startup into `CodingAgent::session()` and then writing the first test
for it. Upstream ends `createAgentSession()` with two lines pig had never ported:

```js
// Save initial model and thinking level for new sessions so they can be restored on resume
if (model) sessionManager.appendModelChange(model.provider, model.id);
sessionManager.appendThinkingLevelChange(thinkingLevel);
```

**The model survived without them by luck.** `SessionManager::settings()` walks the branch and takes
a `ModelChange` if it finds one — and falls back to the provider and model on the last
`AssistantMessage`, which every answered conversation has. **The thinking level has no such
fallback**: an assistant message does not carry one, and nothing else wrote one, because
`AgentSession::setModel()` only records a change *when it changes*. So a conversation started with
`--thinking high`, answered, and picked up with `--continue` came back with thinking **off** — no
warning, no line in the file, and on a reasoning model a visibly different answer to the same
question.

Reproduced before it was fixed, which is the only reason the shape above is stated rather than
guessed:

```
run 1 model=claude-opus-4-1 thinking=high
settings recorded in the file: {"model":{…,"modelId":"claude-opus-4-1"},"thinking":null}
run 2 model=claude-opus-4-1 thinking=off
```

The fix is upstream's two lines, in upstream's place — the `else` of "was this resumed". Two things
about it are worth knowing:

- **It costs no file for a session nobody had.** `SessionManager::append()` holds everything before
  the first assistant message in memory and flushes it in front once the conversation is worth
  keeping. pig already had the mechanism that makes recording this up front safe; it just never made
  the record.
- **The model is recorded explicitly now too**, even though the fallback would usually cover it. The
  fallback reads the model the last *answer* came from, which is not the model the next turn will
  use if `--model` said otherwise — so the file said one thing and the run meant another.

The general rule this is the second instance of: **where pig gets the right answer through a
fallback rather than a record, check what happens to the fact that has no fallback.** The first was
`version_compare` — a flattened `0.2.0` compared equal to `0.2.0-beta`, and the pre-release case was
the one nobody could see.

### A session that can change conversations cannot have a `readonly` session file

`AgentSession::$store` was `readonly`, and both `/resume` and `/new` changed which
conversation was on screen without being able to change where it was written. Two probes,
not reasoning:

```
/resume B, then say something:
  screen:      B's conversation, then the continuation
  A on disk:   A one / A first / said after resuming B / answered after resuming B
  B on disk:   B one / B first          ← stopped growing when it was resumed

/new, then say something:
  one file:    the old conversation / old answer / a brand new conversation / new answer
  sessions listed for this project: 1   ← the new session never existed as one
```

So `/resume` produced two files and neither was what happened, and `/new` appended the new
conversation onto the last one as though they were the same. With the tree it is worse: the
restored messages are not in the old store's `entries` at all, so the new ones are parented to
the old store's leaf and the file replays as something nobody saw.

`writeTo(?SessionManager)` is the fix, and it is a missing piece of the port rather than an
addition — upstream's `AgentSession` owns its `sessionManager` and replaces it in
`switchSession()` and `newSession()`. `/resume` writes to the file it opened; `/new` creates
one; `--no-save` stays null and `/new` does not quietly start saving. Which of the three
happens is `AgentSession::switchTo()`/`startNew()`'s to decide now, not each mode's — the audit
find below ("RPC's session switch and new-session skipped the three checks") says why.

**The comment in `newSession()` claimed this was deliberate** — "the same file: `/new` forgets
the conversation but keeps writing where it was writing" — which is how a bug survives a
reading. A comment that explains why something surprising is correct is worth exactly as much
as the reasoning in it, and that one had none.

Regression tests: `InteractiveModeTest::testWhatIsSaidAfterResumingIsWrittenToTheFileThatWasResumed`,
`testANewSessionGetsAFileOfItsOwn`, `testANewSessionWritesNothingWhenNothingWasBeingWritten`.
All three were checked against the old code and fail on it.

### `pig -p` printed the 503 and exited, while the retry that would have worked went with it

Upstream's `AgentSession.prompt()` ends with two lines and pig had ported one:

```ts
await this.agent.prompt(messages);
await this.waitForRetry();
```

pig waited for a retry **on the way in** — `prompt()`'s first statement, with the reason beside it —
and not on the way out. So `prompt()` returned the moment the agent's run ended, which for a turn
that failed with a 503 is *before the answer exists*: `afterTheRun()` has spawned the retry and it
has not slept yet.

Three of the four modes were only cosmetically wrong — the answer arrives, just after the call that
asked for it, and the events carry it to the screen or the host. **`-p` is the one that ends the
process.** `PrintMode::run()` loops `prompt()`, then prints the last assistant message, then
`bin/pig` exits — so with `retry.enabled` on by default, `pig -p "…"` against a busy provider printed
`Anthropic returned 503: overloaded` on standard error and exited 1, and the second attempt two
seconds later, which would have worked, never happened. A script's whole answer, lost to a pause.

Two things had to move for the one line to work:

- **The `Deferred` is made in `afterTheRun()`, not one line into `waitAndCarryOn()`.** That body runs
  a tick later, so at the moment `prompt()` wants to await it there was nothing there — upstream
  creates its `_retryPromise` synchronously in the `agent_end` handler for the same reason.
  `startRetrying()` is the one place both halves are made now, and making them at the decision also
  closes the window the controller's own move closed once before: the moment after a retry is decided
  and before anything can be told it is happening.
- **And that window turned out to be reachable**, which is the find inside the find. With the pair
  made a tick early, an `abortRetry()` arriving in that tick cleared both — and the spawned body then
  made *fresh* ones and slept against a controller nobody holds. Escape answered, and unreachable for
  the next half minute. `waitAndCarryOn()` returns instead when it finds them gone.
  `compactAndCarryOn()`'s two failure endings gained a `finishRetrying()` for the mirror of it: a
  retry whose next attempt overflows arrives there with somebody waiting on a run that is not coming.

**Five retry tests had to change shape, and that is the real cost of the fix being in the right
place.** `Async::run(fn () => $session->prompt('hi'))` was how every one of them got control back
with a retry still in flight, and `prompt()` now does not return until the retries are done — so
those tests either sat through a half-minute sleep or came back to a retry that was already over.
They spawn now, through one documented helper, `startTurnAndParkOnTheRetry()`; and it waits for the
**`RetryStartEvent`** rather than for `isRetrying()`, because the retry is decided a tick before the
fiber that announces it and parks on the timer exists, so the flag arrives too early to see either.

*One of them had been passing for the wrong reason all along*: `testAbortingStopsTheWaiting` aborted
in that same one-tick window, so the sleep it is named after had never been reached, and the
parked-sleep abort it was written to cover was untested. Both moments have a test now —
`testARetryCalledOffBeforeItBeganDoesNotBeginAfterAll` for the decision and that one for the sleep.

**And the overflow half is the same bug by the other door**, closed in the entry below once there was
a name for the thing to wait on: a turn that outgrew the window comes back as an error too,
`afterTheRun()` spawns the summarisation that fixes it, and `-p` printed `prompt is too long` and
exited while that was still being spawned. `$settled` is now made before *either* kind of background
work and released once nothing more is coming — which is at the tail of `afterTheRun()`, because an
auto-compaction's carry-on never touches the retry counter and so had no other ending to be released
by. `isRetrying()` moved onto `$retrying` in the same change, or it would have answered true every
time the session summarised.

Regression tests: `PrintModeTest::testA503IsWaitedOutRatherThanPrintedAsTheAnswer` and
`testAnOverflowIsSummarisedRatherThanPrintedAsTheAnswer` for what the person sees, and four mutations
that pin the ends separately — dropping the `await`, dropping `startRetrying()`, dropping
`startBackgroundWork()` on the overflow path, and dropping the release at the tail. The last of those
fails as `The event loop ran out of work while the root coroutine was still suspended`, which is the
honest shape of a handle nobody completes.

### A host could read the queue mode and never set it

`rpc-mode.ts` is 469 lines and almost all of it lines up: pig's field names are upstream's wherever
they overlap — `message`, `images`, `modelId`, `provider`, `level`, `mode`, `customInstructions`,
`enabled`, `command`, `outputPath`, `sessionPath`, `entryId` — and its response shapes are upstream's
keys with pig's own extras beside them (`sessionFile` and `messageCount` on a switch, `thinkingLevel`
beside a cycled model). Two divergences in pig's favour while reading it: an unknown command comes
back **with the id it was sent with**, where upstream answers `error(undefined, …)` so a host cannot
match the failure to the command; and a thrown argument error is a `success: false` response rather
than the process dying.

One command was missing, and the interesting part is that **this file's own table explained it away**.
`set_queue_mode` sat on the row about the anchor commit's queue split, beside `queue_message` — but
that split is about *queuing a message*, which `steer` and `follow_up` replace, and it says nothing
about the *mode*. So the mode had `AgentSession::setQueueMode()`, a row in `/settings`, and a field in
`get_state` — everything except the way a host changes it. *A reason that covers one thing does not
cover the thing filed next to it.*

Left out of `rpc-mode.ts` with reasons rather than by oversight:

| Upstream | Why not |
|---|---|
| `get_branch_messages` | `getUserMessagesForBranching()` feeds the twelfth selector, which is `/branch`'s list — the fork into a second session file pig does not do. `get_branch` is pig's answer: the tree, which is where pig's branches are |
| `new_session`'s `parentSession` | a field pi writes into the session **header** for lineage and **nothing in either tool reads back** — not the migrations, not the picker, not the export. pig's header is otherwise pi's field for field, so this is the one gap in that claim, and it is recorded here rather than filled because filling it threads a value through `create()`, `startNew()` and the command for a fact no reader wants yet. A pi file that carries one is read fine: an unknown header key is ignored |

### `/model son` offered file names, and the one method that knew better had no caller

`SlashCommand::$argumentCompletions` is upstream's optional hook for a command that knows what its
own argument can be. pig reads it — `slashSuggestions()` has always called it — and **nothing ever
supplied one**, in either project: upstream declares the field, calls it, and no command implements
it. So its own docblock's example, *"`argumentCompletions` is what makes `/model <tab>` list models
rather than files"*, was a claim about the repository that the repository did not keep. Past the first
space a completion means a path, so `/model son` offered whatever files in the project happen to have
`son` in the name.

`/model` is the one built-in whose argument is a known list rather than free text, so it has the
closure now — the same `availableModels()` list `/model` with nothing after it draws, so the two
cannot come to disagree about what is on offer. Matched as a **substring** over `provider/id` and not
fuzzily, which is the one place `--list-models`' own trade-off does not apply: a subsequence match puts
`moonshotai/kimi-k2-instruct` under `haiku`, which is tolerable in a listing somebody is reading and
not in a list they are choosing from with one keystroke.

**And then the fix did not work from the keyboard, which is the more interesting half.**
`CombinedAutocompleteProvider::shouldCompleteFiles()` is the method that answers "does a completion
here mean a path", and it is **public with no production caller** — `Editor::completeOnTab()` spelled
the rule out itself, `starts with a slash and has no space`. That is the *fourth* hand-spelling of the
predicate the trap below about a line starting with `/` is entirely about: that entry gave the rule
one home and this reader never started asking it. The editor asks now, and the provider answers with
the one thing only it can know — which commands exist, and which of them answer for their own
argument.

Three ends, three mutations, and the third took three goes to pin. Removing the completer from
`/model` breaks it; making the editor spell the rule again breaks it; but the check inside
`shouldCompleteFiles()` is redundant for `/model ` with nothing typed (the trailing space is trimmed,
so the line still reads as a bare command name) and unreachable while a list is open, because Tab
there means "accept this one". The sequence that needs it is the ordinary one: type an argument,
**escape** to dismiss the list, then Tab — and that is what the test does.

### Every numbered list a model wrote with spaces in it came out numbered 1, 1, 1

`markdown.ts` is the one remaining file a surface map cannot read — upstream hands the parsing to
`marked` and walks its tokens, where `Markdown\Lexer` and `Inline` **are** the parser. So it got the
treatment `EditDiff`, `Truncate`, `Keys` and `PartialJson` got: **`marked` 15 installed, a corpus of
54 documents, both sides' block structure dumped and compared.** Two bugs, in the commonest thing a
model writes.

**A list's own numbers were thrown away.** `ListBlock` held its items and whether it was ordered, and
nothing else — so `3. three` / `4. four`, which is how anybody quotes steps three and four of a
procedure, was drawn as `1.` and `2.`. `marked` carries a `start` for this and **upstream ignores it
too**: its `renderList` is `${i + 1}. `, so this is a divergence in pig's favour, like `EditDiff`'s
numbering and the image token count.

**And a blank line between items ended the list.** `1. one` / blank / `2. two` / blank / `3. three` —
a numbered list whose items are paragraphs, which is most of them — was read as *three lists of one
item*, and each one started again at its own number. With all the items written `1.`, the idiom that
lets the renderer do the counting, it came out `1.` `1.` `1.` and no `start` could have saved it.
Every other markdown reader calls this one **loose** list.

**The mutation check is the story here.** Fixing `start` alone made the first test pass *and* the
second one, because three lists of one item each carry their own start — so mutating the lexer change
back broke nothing, and by this file's usual rule it was dead code to delete. Rendering both ways is
what showed it was not: without it the blank lines the author put between the items **disappear**,
because the list ends and the next one begins with no gap between. So the lexer half was doing
something, and the something was undoing the author's spacing — a regression hidden behind a passing
test. *A mutation that changes no test can still change the output; render it and look.*

What it wanted was the whole distinction, which the `Lexer` docblock used to disclaim: `ListBlock`
carries `loose`, the lexer sets it when it walks over a blank line between two items, and the
renderer puts one line between items — never before the first or after the last, because that
spacing belongs to the block and not to the list. Three tests, one per fact: the numbers, the
blank lines, and a tight list staying tight; each of the three ends fails on its own mutation.

**Ten differences survive the corpus and each now has a reason on the `Lexer`**, which is the other
half of the run: four are the harness comparing `marked`'s raw text against pig's parsed inline text,
three are the subset pig states (setext headings, reference links, HTML), and three are pig's own
calls — a change of bullet marker does not start a new list, a task item keeps its `[x]` (which
`marked` lifts into a flag **upstream never reads**, so the box vanishes there), and an unclosed fence
keeps its last newline.

### A compaction refunded the bill

`/session` prints what this conversation has cost and the footer keeps a running total, and both
came from `AgentSession::stats()`, which summed **the messages in memory**. Compaction replaces what
it summarises with one `CompactionSummary` carrying no usage — so the moment a conversation got long
enough to need compacting, the number that says what it cost dropped back towards zero. Reproduced:
three turns at 0.25 each read `$0.750`, and one `compact()` later they read `$0.000`.

**Upstream has both answers and does not notice.** Its footer sums `sessionManager.getEntries()` —
the file — and its own `getSessionStats()`, which is what `/session` prints, sums `state.messages`.
Two readers of one question, in one tool, disagreeing exactly after a compaction; pig had copied the
wrong one into both places.

The split that settles it: **the counts are about the conversation and the money is about the
session.** After a compaction the conversation *is* one summary and whatever was kept, so "you said
three things" about a transcript showing one answers a question nobody asked — counts stay on
`messages()`. The money is what a provider charged, and every assistant message in the file came
back from one, including on a branch later walked away from. Neither compacting nor changing your
mind is a refund, so the total comes from `SessionManager::everyMessage()` — every entry, file order,
no branch to walk. With no file at all (`--no-save`) there is nothing else to count and the two
questions share an answer again.

Regression test: `AgentSessionTest::testACompactionDoesNotRefundWhatTheSessionSpent`, which asserts
the money survives *and* that the counts do not — the second half is what stops the fix being
"sum everything over the file" and calling a two-message transcript six messages long.

### A session on disk could not be exported, and skills were the one loader with no off switch

`main.ts` is 451 lines and almost all of it is pig's already, in `CodingAgent::session()` and
`bin/pig`. What a flag-by-flag comparison of the two found is three things, and the first two are
fixed here.

**`--export <file> [out]`.** Upstream exports a session to HTML from the command line and exits.
pig had every piece — `HtmlExport`, `/export` inside a session, `export` over RPC — and no way in
from the shell, so somebody with a `.jsonl` and no wish to reopen the conversation was stuck.
`HtmlExport::fromFile()` is upstream's `exportFromFile()`, and it is in the export module rather
than in the entry point for the reason four classes in `Cli\` exist: *a script that ends in
`exit()` cannot be called twice by a test.* `export` had to join `Arguments::TAKES_A_VALUE`, which
is the trap that list is for — a session path does not start with a dash, so the flag would have
taken no value and the path would have been sent to the model as a message.

*And the three lines in `bin/pig` had a bug that only running them shows:*
`echo 'Exported to: ', HtmlExport::fromFile(…)` writes left to right, so a missing file printed
`Exported to: ` and then the error underneath it. The write happens first and the sentence after.

**`--no-skills`.** `--no-hooks` and `--no-tools` have been there from the start; skills had
`skillsEnabled()` in the settings and no flag — three loaders, two with a switch on the command
line and one without, which reads as "this one cannot be turned off for one run". `withSkills` on
`session()`, and the settings still decide when nothing was typed.

**The third was a name collision, and the developer's call was to follow upstream, so the two flags
swapped.** pig's `--models` *listed* the registry; upstream's `--models` takes patterns and **scopes
the session**, with `--list-models` as the listing flag. The same word meaning two different things
in the two tools is the worst of the three outcomes — one of them prints and exits — so the listing
is `--list-models` now and `--models` is the scope, which brought upstream's
`resolveModelScope()` with it.

`ModelResolver::scope()` is that function, and the half the old note called "rows of work rather
than a dependency" was exactly that: `fnmatch()` for the globs, `parse()` for everything else, so
`--models sonnet` means what `--model sonnet` means. Five things came out of porting it, and the
third is the one worth remembering:

- **A pattern is a glob when it holds `*`, `?` or `[`**, and a thinking level is only stripped off
  one when the suffix really is a level — an id can hold a colon (OpenRouter's `:exacto`), so
  `claude-*:nonsense` is a pattern matching nothing rather than `claude-*` with a bad level on it.
- **The first entry of the scope is what the session opens on**, one step below `--model` in the
  order and above the environment. A resumed conversation still beats it, and needs no condition of
  its own: `restoreSettings()` already puts the file's model back unless `--model` was typed.
- **The cycling moved from `ModelResolver` to `AgentSession`, and the reason is the thinking
  level.** `next()`'s docblock used to argue the opposite — *"everything else it needs is already
  done for it, so what is left is which model comes next"* — which held for exactly as long as the
  answer was only a model. `--models sonnet:high,haiku:low` makes it a model **and** a level, and a
  rotation that left the level to its two callers is the wired-at-one-end shape with two ends to
  forget. `ModelResolver::next()` is the arithmetic; the list, the key check and the level are the
  session's.
- **`modelsOnOffer()` is the one answer to "which models are on offer"**, and the first draft had
  two: the terminal narrowed to the scope *and* `cycleModel()` narrowed again, so the mutation check
  that made `cycleModel()` ignore the scope broke only the RPC test. Two answers to one question, in
  the fix for a find about two answers to one question. It narrows the `/model` picker, its
  completions, `/model <pattern>` and the cycling.
- **A host's `get_available_models` is not narrowed**, as upstream does not narrow it, and
  `set_model` takes any model a key reaches in both tools. A scope is what the keys in front of
  somebody walk through, not a restriction — which is worth stating, because the other reading is
  just as plausible and would need `set_model` to refuse.

One pattern that matches nothing is a **warning** naming it, and the session starts on the ordinary
order: a typo in a flag that is a preference should not be a refusal to start, and the other three
patterns are still a scope. `bin/pig` splits on commas — the flag's spelling is the command line's
business and what it resolves to is the library's, which is `--tools`' division — and a `--models`
with nothing after it is refused there by name.

`RpcClientTest::testTheModelsFlagNarrowsWhatTheAgentOpensOnAndCyclesThrough` is the one that holds
`bin/pig`'s end: it is the only test in the suite that starts the binary, and removing the
pass-through broke **nothing at all** before it existed.

Left out of `main.ts` with reasons, so the flag list is not compared twice:

| Upstream | Why not |
|---|---|
| `checkForNewVersion()` | fetches `registry.npmjs.org` at every start to see whether a newer release exists. pig is not published, and the habit is one pig refuses elsewhere in as many words — *"reaching for the network to draw a completion list is not something a keystroke should do"* |
| `--system-prompt`, `--append-system-prompt`, and `.pi/SYSTEM.md` discovery | the system prompt is the developer's own file here, so this is theirs to decide rather than the audit's |
| `--hook <path>`, `--tool <path>` | pig adds hook and custom-tool paths through the settings only, which is where a path somebody uses twice belongs. The mirror of `--no-hooks`/`--no-tools`, which upstream lacks and pig has |
| `--session-dir <dir>` | `PIG_HOME` moves the whole directory, which is the only use anybody has had for it |
| `--provider` | pig resolves a provider and an id together (`ModelResolver`), so there is nothing for a second flag to disambiguate |

### A message typed while the conversation was being summarised was lost with a red line

`Editor::$disableSubmit` is honoured, `CustomEditor::disableSubmit()` is public, and **nothing in
pig ever called either** — the third piece of dormant machinery found in one read, after
`onDebug` and ctrl+p before it. Upstream has exactly one use for it, and it is the one
window where a message can neither be sent nor queued: a conversation being summarised out from
under it.

What it cost, reproduced through the real screen: the submit handler clears the editor and spawns
the turn, `prompt()` parks on the compaction's own handle, and then the compaction's carry-on starts
a run — so the parked turn wakes up to `Agent is already working. Use steer() or followUp().`, which
`sendAndWait()` draws as a red line. **The text was already gone from the editor, so what the person
typed is nowhere at all.**

Before `prompt()` learned to wait, the same keystroke was wrong the other way round: the turn went
out *during* the summarisation and the compaction's own `continue()` was the thing refused. Two
arrangements, two losses; the flag is the answer that loses nothing, because the keystroke never
becomes a submit and the line stays where it was typed.

Wired on `AutoCompactionStartEvent` and off on `AutoCompactionEndEvent`, which is upstream's pair.
Not on the manual `/compact`, and upstream does not either: that one was started by somebody who is
at the keyboard and just typed it, where the auto one arrives in the middle of their sentence.

**The flag's own docblock said it was for "while the agent is working", which pig does not use it
for and never did** — a message typed mid-run is *queued*, because the person watching a tool run is
exactly who has something to add. The fourth shape from the index, on a field.

The mutation check took three goes here, and the third one is the lesson. Removing the *re-enable*
broke nothing at first; then it broke nothing again, because the test asserted the typed line was
on screen — and with submit still disabled it is on screen, in the editor instead of in the
transcript. **An assertion that holds either way is not an assertion.** What only happens if the
turn ran is the model's reply, so that is what it asserts now.

Regression test: `InteractiveModeTest::testEnterDuringAnAutoCompactionKeepsWhatYouTypedRatherThanLosingIt`,
whose two halves fail separately when each end of the flag is removed. The harness gained one thing
for it — `holdTheAgent(letThrough: 3)`, so the call that is held can be the *summariser* rather than
the first turn.

### Three components read against their upstream, and the one thing PHP cannot have

`tool-execution.ts` (630) and `footer.ts` (324) were mapped member by member like the two big files
before them, and both came back matching — which is worth the words only with the list attached.
`ToolExecutionComponent` agrees with upstream on `read`'s `path:start-end` heading and its
arithmetic, `edit`'s `path:firstChangedLine` from the preview and then from the result, `write`
drawn from the arguments rather than the result, the `~` shortening, the tab width, and the per-tool
line limits; `FooterComponent` agrees on the five token bands, the 70/90 colour thresholds, the
aborted-turn skip when reading the context, `(auto)`, `(sub)`, and the path elision. pig's
`invalidate()` clears the cached branch exactly as upstream's does, with the same reason written on
it.

Three differences, each deliberate:

- **`file_path ||` is not ported.** Upstream's component reads `args.file_path || args.path` for
  `read`, `write` and `edit` while its own tool schemas say `path` — a fallback to a name it does
  not use. pig reads what its schemas declare.
- **`grep`'s pattern is drawn as `/pattern/` and carries its glob**, where upstream shows it bare.
  pig's own, and the delimiters are what tell a pattern from a path at a glance.
- **The git branch is not watched.** Upstream keeps an `fs.watch` on `.git/HEAD` and disposes of it,
  so an idle session notices a checkout made in another terminal; pig re-reads on `invalidate()`,
  which fires on every agent event, so it notices the next time anything happens. **This is the
  usual "PHP has what JavaScript reaches for" the other way round**: `fs.watch` is free in Node and
  PHP has no portable file watcher at all without an extension — `Loop::onReadable()` watches
  streams, not directories. The cost is a branch name that is one event stale on a screen nobody is
  looking at, and the alternative is an fd, a `dispose()` and an extension.

### The prompt answered to eleven keys that were named nowhere

`Editor` has handled ctrl+w, ctrl+u, ctrl+k, alt+backspace, ctrl+a/home, ctrl+e/end,
alt+left/right, ctrl+left/right, shift+enter, alt+enter, tab completion and the prompt history
since it was ported. **Not one of them appeared on any screen.** `KEYS` holds the keys the
*application* binds — esc, ctrl+c, ctrl+o and the rest — and upstream's `/hotkeys` is where the
editing ones are written down, and pig had ported the keys and not the table.

So `/help`, whose own row reads `Show the keys and commands`, showed some of the keys. That is the
claim-not-kept shape rather than a missing nicety, which is why it is a fix and not a note: the keys
worked, and the only way to find them was to already know them.

`EDITING_KEYS` is that table, **beside `KEYS` rather than as a second command**, because pig already
prints the keys in two places — `/help` and the banner under ctrl+o — and upstream's own two lists
disagree with each other. It is kept by hand, as upstream's is, and the test is what notices: when
`Editor::editingKey()` grows an arm, `testEveryKeyTheEditorAnswersToIsNamedSomewhere` is where it
gets its name.

Two things fell out of adding it, both about the column:

- **The two printers were the same ten lines twice.** `banner()`'s expanded branch and
  `commandHelp()` each looped over `KEYS` and then `COMMANDS`. They could not have disagreed about
  the tables; they could and immediately would have disagreed about the column, since only one of
  them would have got the third table. `keysAndCommands()` is the one implementation.
- **The column was the constant `12`** and `shift+enter` is eleven columns, so it would have sat one
  space from its description while `esc` sat nine away. It is measured from the widest label now,
  with `Width::visible()`, which is `--list-models`' rule and for `--list-models`' reason.

### `/debug` was three-quarters ported and did nothing

The renderer has had the `onDebug` callback and a shift+ctrl+d intercept in
`handleTerminalInput()` since it was ported, `Keys::isShiftCtrlD()` answers, and **nothing in the repository
ever called the setter.** So the one key that works whatever holds the focus — which is the whole
point of a key that captures the screen — did nothing at all. The same shape as ctrl+p taken off the
editor and bound to nothing, one package over.

What it was missing is upstream's `handleDebugCommand()`, and the argument for porting it rather
than deleting the hook is that **pig already has the interesting end**: `TuiBase::checkWidth()` writes
`[n] (w=N) <line>` for every line of the frame when one is too wide to draw, and *every width bug in
this file was found by reading exactly that* — `str_pad` lining up an ASCII-only column,
`BashOutputComponent`'s 22-column note, the frame drawn twice over itself. Each time it had to be
got at with a probe written outside the repository, because from inside a real session there was no
way to ask. `TuiBase::frame()` is that dump on demand, shared with `checkWidth()` — the same question
before a fault and after one.

Four decisions in it:

- **Known and not listed**, beside `quit` and `arminsayshi`, because `COMMANDS` is what `/help`
  prints and what the autocomplete offers, and this is for reporting a fault rather than for using
  pig. The **key** is what `KEYS` names, since working regardless of focus is its advantage.
- **The escapes stay escapes.** The lines go through `json_encode`, so a log holding a cursor
  sequence cannot move the cursor of whatever opens it — and a column count beside a line whose
  codes are invisible is the pair that makes a padding bug obvious.
- **The conversation goes in as the session file's own shape**, through `SessionCodec`, rather than
  as a second notion of what a message looks like.
- **`0600`, and the mode is set before there is anything to read.** Not the file's own importance:
  a conversation holds whatever the model read, which on a bad day is somebody's `.env`. The session
  file it duplicates is inside a `0700` directory; this one sits at a predictable name in the home
  directory, so it says so itself. Set first rather than chmodded after, as `Auth::save()` does it.

Regression tests: `InteractiveModeTest::testTheDebugKeyWritesTheFrameAndTheConversation` and
`testTheDebugCommandWritesTheSameThingWithoutBeingListed` — one per route, and each stays green when
the other's end is removed.

### Two loaders said escape worked and the key reached neither

`onRetryStart()` draws `… — trying again in 30s (1/3, esc to stop)` and `onOverflow()` draws
`Context is full — summarising, then trying again. (esc to cancel)`. Both labels are upstream's and
both were true of nothing: `InteractiveMode::interrupt()` ended with

```php
$this->session->agent->abort();
```

— **the agent's abort, not the session's.** A sleeping retry and a running summariser both happen
*between* runs, where `$this->agent` has no controller to raise, so escape moved the queued text back
into the editor and then did nothing at all. Reproduced through the real screen: the countdown says
"esc to stop", escape arrives, and thirty seconds later the turn goes again.

`AgentSession::abort()` is what reaches all three, and it now reaches the third as well —
`abortCompaction()` beside `abortRetry()`, for the reason already written on it: *neither of these has
an agent to interrupt.* Upstream's escape handler calls `abortCompaction()` itself, beside `abort()`;
one call here is the same choice as `startNew()`'s, and it is what stops a caller reaching two of the
three and believing it has finished.

**The summariser had nothing behind the label either**, which is the second half of this and the
worse one. `compactAndCarryOn()` called `compact()` with **no signal at all**, so the *auto*
compaction — a whole conversation, at high reasoning, on a full context window — could not be stopped
by anything, and its own `Summarising was cancelled.` branch was unreachable on that path. That is
precisely the failure `Retry`'s abortable sleep exists to prevent, one method over: a wait escape
cannot reach is indistinguishable from a hang.

pig has one `compact()` where upstream has two paths and two controllers, so there is one
`$compacting` here, made inside `compact()` — which gives `isCompacting()` and `abortCompaction()`
one answer whoever started the summarisation. A signal the caller brought (the terminal's, for
`/compact`) is **forwarded into** it rather than carried alongside, so what goes downstream has a
single `aborted()` to ask: pig has no combinator for two signals, and the reason it has none is the
note on `Future` above. Both directions are tested, and each is a separate mutation — the forwarding
listener, and which signal goes down.

Two smaller things fell out of the same read, both on `get_state`: upstream's `RpcSessionState`
declares `isCompacting` and `queueMode` and pig's answer carried neither, so a host could not see a
summarisation at all and could set the queue mode without ever reading it back — the
wired-at-one-end shape, on a field rather than a key.

Regression tests: `InteractiveModeTest::testEscapeStopsTheRetryTheScreenSaysItCanStop` for the label
and the key, `AgentSessionTest::testEscapeReachesASummarisationNobodyAskedFor`,
`testStoppingTheRunStopsASummarisationWithIt`, `testATypedCompactionCanBeStoppedFromEitherEnd` (two
cases, one per end), and `RpcModeTest::testGetStateDescribesTheModelAndWhatItIsDoing` for the fields.

### `/new` during a retry left the old conversation's retry running in the new one

`startNew()` and `switchTo()` stop whatever is in flight before they throw the conversation away,
and both asked the wrong question first:

```php
if ($this->isStreaming()) { $this->abort()->await(); }
```

**A retry that is sleeping is not streaming.** `abort()`'s own docblock says so, two hundred lines
down — *"a retry that is sleeping has no agent to interrupt: the run is already over and the next
one has not started. Escape has to reach both"* — and `prompt()` says it a third time and deals
with it a third way, by waiting the retry out. So the rule was written down twice, with its reason,
and the two methods in front of it asked `isStreaming()` instead. Upstream calls `await this.abort()`
unconditionally in both.

What it cost: press `/new` in the two-to-eight seconds a retry is waiting, and the retry wakes up in
the conversation that replaced the one it was rescuing. The agent has been emptied by then, so
`continue()` refuses and `carryOn()` turns the refusal into `RetryEndEvent` — **"Retry failed:
cannot continue, no messages in context", in a brand new conversation nobody has said anything in
yet.** Type something first and it is worse: the retry lands mid-turn, `continue()` refuses for
being busy, and the same red line arrives on top of an answer that is still streaming.

Both call `abort()` unconditionally now, which needs no `isStreaming()` of its own: `abort()` on an
idle agent does nothing, `abortRetry()` returns early when nothing is being retried, and
`waitForIdle()` on an idle agent hands back a completed future — so the `await()` does not even
require a fiber, and no caller changed.

**The two tests that were written first passed while doing the wrong thing**, which is the part
worth keeping. Both ended in `self::settle()`, and the sleep in them is half a minute: the loop had
nothing else to poll, so `tick()` slept the timer out, the retry ended **by itself**, and
`assertFalse($session->isRetrying())` was true whatever `startNew()` had done. Thirty seconds per
case, green either way. They assert straight after the call now, with no settle at all — the same
failure as the `/label` test that asked about the leaf, and the same fix: *ask whether the assertion
could fail.*

Read in the same pass and **left alone**, so nobody re-derives it: `goTo()` and `compact()` have the
same `isStreaming()` guard and neither is this bug. Upstream's `navigateTree()` does not abort — it
has no streaming guard at all, pig's being its own — and `compact()` cannot, because pig reuses it
for auto-compaction (`compactAndCarryOn()` calls it from inside the retry machinery, so aborting the
retry there would kill its own caller) where upstream has a second implementation for that path.
Neither has a reproduced consequence; if one turns up, this is the entry.

Regression tests: `AgentSessionTest::testStartingANewSessionStopsARetryThatWasStillWaiting`,
`testSwitchingSessionStopsARetryThatWasStillWaiting` — one per method, and each stays green when the
other's guard is put back — and `testARetryDoesNotCarryOnIntoTheSessionThatReplacedIt`, which is the
one that reproduces what the person actually sees.

### A `before_*` hook result is only heard if the runner thinks it decisive

`HookRunner::ask()` walks the handlers and stops at the first *decisive* answer, which each
event defines for itself. `emitBeforeCompact()` counted both `cancel` and a supplied
`compaction`; `emitBeforeTree()` counted only `cancel`. So a hook that wrote its own branch
summary was walked straight past, `ask()` returned null at the end of the list, and the model
was asked to write one anyway — the hook's work thrown away, silently, with no error anywhere.

It was caught by a test written at the same time as the feature
(`HookWiringTest::testAHookCanWriteTheHandoverItself`), which is the only reason it was caught
at all: nothing about the wrong behaviour is visible without a hook that supplies a summary.

Whenever a `before_*` result grows a field that means "I have handled this", the predicate in
its `emit…()` has to grow with it. There are three of these now and they are the place to look
first when a hook seems to have been ignored.

### A dialog opened in front of a suspended fiber has to be escapable

A hook's `tool_call` handler runs inside the agent's fiber. `$ctx->ui->confirm()` parks that
fiber on a `Deferred` and hands the keys to a component; the answer resumes it. If the
component has no way to say "no answer", nothing resumes it — the turn is stopped forever,
the tool never returns, and the only way out is killing pig. It does not look like a hang in
the tool: it looks like the agent went quiet.

Two things follow, and both are load-bearing rather than tidy:

- `Pig\Tui\Components\Input` got `setCancelHandler()` for this. `SelectList` already had
  one, and the multi-line dialog gets escape through `CustomEditor`. Any future dialog in
  `TerminalUi` needs the same before it is used — and `custom()` cannot be given it, which is
  why its docblock says so twice.
- `TerminalUi` refuses a second dialog while one is open, because opening one would take
  focus from the first and leave *its* fiber unreachable. The refusal answers with the safe
  value — null, or false for `confirm()`.

Tests: `HookUiTest::testEscapingAnInputAnswersWithNothing`,
`testASecondDialogIsRefusedRatherThanStacked`, and the three
`testAGuardCanAskBeforeLettingAToolRun` cases that drive the whole chain.

### A credential a scanner knows cannot live in the repository, encoded or not

`Oauth\GeminiCli` needs Google's client id and secret for the Gemini CLI. It carried them three
ways before it stopped carrying them at all, and each step is worth keeping:

1. **Written out**, with a comment arguing that neither is really secret — both ship in every
   Gemini CLI install and in a published npm package, and PKCE is what protects the exchange. All
   true. GitHub's push protection refused the push: it has patterns for exactly "Google OAuth
   Client ID" and "Google OAuth Client Secret".
2. **base64, as upstream's `atob()` wrappers have them.** Refused again, at the new line numbers:
   **the scanner decodes base64.** So upstream's encoding is not what gets past a scanner, and
   assuming it was is what cost the second push.
3. **Not in the repository.** They are handed to the constructor, and `CodingAgent\Auth`
   finds them: `GEMINI_CLI_CLIENT_ID` / `GEMINI_CLI_CLIENT_SECRET`, then `geminiCli.clientId` /
   `geminiCli.clientSecret` in the settings file — pig's usual order. Absent, `/login` refuses
   with a message naming both places and saying plainly that pig does not ship them.

Two rules out of it, and the second is the one that generalises:

- **Before writing any real credential into a file, assume the host scans for its provider's
  pattern — and that it decodes.** Obfuscating harder is an arms race against a control whose
  job is to protect that credential, and it fails on the day the scanner learns the trick.
- **A scanner that detects a credential also reports it to the issuer**, and Google revokes what
  is reported. So a plaintext copy in a public repository is a good way to break a sign-in for
  everyone using it, upstream included — which makes this a correctness argument and not only a
  policy one.

`Anthropic`'s and `GithubCopilot`'s client ids stay written out, because nothing objects to them:
one is a bare UUID, the other a GitHub OAuth *client* id, which is not a secret and is not what
GitHub looks for. The asymmetry is the scanners' and not a choice.

### An arrow function whose body returns nothing is a fatal error

`fn (): void => $this->say($note)` does not compile: an arrow function always returns its
expression, and returning the result of a `void` method is `A void method must not return a
value` — at parse time, so `php -l` catches it, but only after it has been written. A closure
with a body is the fix. Hit twice in two days, in `Cli\SessionList`'s submit handler and in
`InteractiveMode`'s sign-in progress callback: **a one-line callback that calls a `void` method
has to be a block.**

The same family: `$closure?->($argument)` is not syntax either. Nullsafe applies to `->method()`
and `->property`, never to invoking a variable, so a nullable callback needs an `if`.

### `stream_socket_pair()` with a dropped peer (tests)

`[$a] = stream_socket_pair(...)` garbage-collects the peer, putting `$a` at EOF — permanently
"readable", with `fread()` returning `''`. Keep both ends in scope.

### A missing `use Throwable` makes every catch in the file a no-op

`AgentSession` had no `use Throwable;` and had never needed one. The first three
`catch (Throwable $problem)` blocks written into it were therefore catching
`Pig\CodingAgent\Session\Throwable`, a class that does not exist — so nothing was ever
caught, the exception escaped the `Async::spawn` that was running the work, landed in a
`Deferred` nobody awaits, and **vanished**. What it looked like: `AutoCompactionStartEvent`
went out, and then the session sat there. No error, nothing on the shell, nothing in the
transcript.

`php -l` cannot see it — an unimported class is resolved at run time — and the class is only
resolved on the path that throws, which is the path nobody exercises by hand. It was caught by
a test written for the failure case, which is the only reason it was caught at all.

The sweep is worth keeping: for every file, collect its `use` statements and the names it
writes in `catch (X)`, `instanceof X`, `new X(`, `X::` and typed parameters, and report the
difference. Run over `packages/*/src` it found this one and nothing else real — three
docblock mentions and two names that resolve through a sibling file in the same namespace.

**It has happened three times now**, each in a different disguise, which is what makes it
worth a rule rather than a story:

| Where | What it did |
|---|---|
| `catch (Throwable)` with no import | never caught anything; the fiber died silently |
| `instanceof Component` with no import | would never have matched, so every hook renderer would have fallen back to the default |
| `new Text(...)` in a test with no import | threw where the code under test catches, so the test failed on the fallback rather than on the missing import |

The third is the one to remember, because it wasted the most time: the error surfaced as a
*feature* not working, because the thing that threw was inside a `try` belonging to the code
under test. **Run the sweep after adding an `instanceof` or a `catch` to a file you have not
touched before** — it is one command, and it is cheaper than reading the screen dump.

Two rules fall out, and the second is the one that generalises:

- A `catch` clause naming a class the file does not import is a `catch` that never fires.
- **Anything inside `Async::spawn()` whose future nobody awaits must not be able to throw.**
  `AgentSession::inTheBackground()` wraps the work and turns a throw into a `RetryEndEvent`,
  so a bug in there is a reported failure rather than a session that stops mid-sentence.

### An abort with nothing yet to abort reports a cancellation that did not happen

`abortRetry()` used to abort a controller created around the *sleep*, while `isRetrying()` was
already true from the moment the retry started. Between those two points escape said
"Retrying was cancelled.", reset the counter, completed the waiter — and the retry carried on,
slept, sent the request, and eventually announced a second, contradictory end.

The controller is now created together with the waiter, so there is never a moment where the
retry is running and nothing can stop it, and `carryOn()` checks the signal before starting a
run in case the abort arrived after the sleep finished. **Whenever a flag says "this is
happening", the thing that stops it has to exist by the time that flag is set** — not a line
later.

### `tick()` waits out the timer it is polling for (tests)

A test that wants to abort something mid-sleep cannot just tick twice and then abort. With a
one-second timer armed and no streams to watch, `Loop::poll()` has nothing to select on and
`usleep()`s the whole second — so two ticks really are two seconds, the sleep is long over,
and the abort lands on a retry that has already moved on to its next attempt. The symptom is
a second, successful end event that makes no sense next to the cancellation.

An expired timer of the test's own makes `pollTimeout()` ~0, so the tick returns with the
retry still parked:

```php
Loop::get()->delay(0.0, static fn () => null);
Loop::get()->tick();
```

Same family as the RPC trap above, opposite direction: there a tick blocked forever because
nothing was pending, here it blocked for a second because something was.

### A provider that throws hangs the agent, and swallows the reason

`AgentLoop::start()` and `continue()` push events from inside `Async::spawn()`, so the
producer is a coroutine of its own. A throw there — `Stream::simple()` failing to resolve a
hostname, a missing key, anything synchronous before the first event — escaped that fiber and
left the `EventStream` open forever. `Agent` was already written for this: its consumption of
the stream sits in a `try` whose `catch` calls `recordFailure()`. The throw just never crossed
the fiber boundary to reach it, so what a caller actually got was
`AsyncError: The event loop ran out of work while the root coroutine was still suspended` —
with the real cause gone.

Fixed with `EventStream::fail(Throwable)`: it closes the stream, fails `result()`, and hands
the throw to every parked consumer, so `foreach ($stream as $event)` rethrows it and `Agent`'s
existing catch does the rest. `AgentLoop`'s two spawned bodies are wrapped in a try that calls
it. Events pushed before the throw are still delivered — a producer that pushed three events
and then failed did three real things, and the prompt has already been announced, so a UI that
drew the user's message does not have to un-draw it.

`fail()` rather than `end($error)` because **a stream that ended has a result and a stream
that failed has a reason**, and a consumer that cannot tell them apart reads a dead connection
as the model having nothing to say.

The general shape, which is the part worth remembering: **anything inside `Async::spawn()` has
its own stack, so a `try` outside the spawn catches nothing that happens inside it.** Every
spawned producer needs a way to hand its failure to whoever is waiting.

### `NoUi` was reported as a UI, so `hasUi` lied

`HookContext::$hasUi` answers one question — will asking reach anybody — and `HookRunner`
computed it as `$this->ui !== null`. That was right for as long as nothing passed a `NoUi`
deliberately: the default is `null`, and `HookContext` turns null into `NoUi` with `hasUi`
false. `PrintMode` is the first caller to pass one on purpose, and it was told there was
somebody there, so a hook that checks before asking asked and heard nothing.

Now `$this->ui !== null && !$this->ui instanceof NoUi`. The predicate belongs with `NoUi`,
which is by definition nobody; a caller passing `hasUi: true` beside a `NoUi` is making a
claim that contradicts itself, and `HookContext` still lets one, which is worth revisiting.

### A flag that takes no value eats the argument after it

`bin/pig`'s parser decided whether an option took a value by looking at the next argument: if
it did not start with `--`, it was the value. That worked for exactly as long as nothing but
options was ever passed. The moment positional messages meant something,
`bin/pig --read-only "fix the bug"` set `read-only` to `fix the bug` and had no prompt left,
with nothing said about it.

`Cli\Arguments::TAKES_A_VALUE` is the list, and everything not on it is a flag. Upstream's
`args.ts` spells each option out for the same reason. **A CLI cannot afford a guess about what
the next word means**, and the failure mode of guessing is silent.

### A hex id used as an array key becomes an integer

An entry id is eight hex characters, and about one in forty-three is all digits. PHP turns an
array key that looks like an integer into one, so `$entries["12345678"]` comes back out of a
`foreach ($entries as $id => …)` as the **int** `12345678` — and a function typed `string $id`
then throws. Intermittently, in about two percent of runs, which is the worst rate there is:
often enough to happen to a user, rare enough to pass every time you look.

Lookups are safe — PHP normalises the subscript the same way — so only iteration is affected,
and the fix is `(string) $id` where the key comes out. It has been latent since ids existed;
pig's twelve-character ids hit it about one run in three hundred.

### A short option is not automatically a flag

`SHORT` maps `-p` to `print`, and the first version of the branch that handled it did
`$options[$short] = ''` and moved on — right for `-p`, and wrong for every short option whose
long form takes a value. Adding `-r` would therefore have made `-r some/path.jsonl` set
`resume` to the empty string, which `bin/pig` reads as "show me the picker", and left the path
in the message list to be sent to the model as a prompt.

The same mistake as the flag that ate the prompt, one level down: **two spellings of one
option must not each carry their own idea of whether it takes a value.** So the short branch
resolves to the long name and falls through into the same code, and a test asserts that every
pair in `SHORT` parses identically.

### One chunk is one key (tests)

`Keys::isEnter($data)` is `$data === "\r"`, and every other reader is the same shape: the
chunk that arrives *is* the key. So `type('hello' . ENTER)` in a `FakeTerminal` test delivers a
single six-character key that is not Enter, and it lands in the editor as text — the
characters appear, the submit handler never runs, and the test fails on something that looks
like the submit being broken. One `type()` per key, which is what every test in
`InteractiveModeTest` already did.

### Validating inside the fiber answers a command twice

`RpcMode::prompt()` read its `message` field inside the `Async::spawn()` that runs the turn.
A command with no `message` therefore threw in the *fiber*, where the only thing to catch it
was the fiber's own handler — which sent an id-less failure, **after** `dispatch()` had already
returned null and the success response had gone out. A host was told both that its prompt was
accepted and that it was not, in that order, and the second line named no command it had sent.

Whatever a command needs is read before the spawn. The rule generalises: anything spawned
answers separately from the command that spawned it, so everything that can fail as *the host's
mistake* has to fail on the near side of the spawn, and only the work itself belongs on the far
side.

### A loop with one readable watcher blocks forever on a zero-timeout tick (tests)

Driving `RpcMode` from a test means turning the loop by hand, and `isIdle()` is never true while
the stdin watcher is armed, so the stopping condition has to be a tick count. That much is
obvious. What is not: `pollTimeout()` returns null — block until a stream is ready — when there
are watchers and no timers, so a plain `tick()` with nothing to read never comes back.

`Loop::wake()` does not fix it. A tick runs the queue *before* it polls, so by the time
`pollTimeout()` is consulted the queue is empty again and the answer is still null. An expired
timer is still there when it is asked: `delay($seconds, fn () => null)` before each tick.

And the timeout has to be nonzero if a subprocess is involved. Forty ticks at a zero timeout are
over in microseconds — long before `echo hello` has written anything — so a `bash` command over
the protocol looked like it had produced nothing at all. A millisecond each gives the poll
something to wait with.

### A changelog and a version, and two tests that were pinning the absence of the file

Asked for directly: *一打开就可以看到是否有更新* — a start that says whether there is a newer pig, the way
pi's does. Three things were missing and the developer named all three: no `CHANGELOG.md`, no `bin`
key or `version` in `composer.json`, and no executable bit on `bin/pig` in git. Three answers
settled the shape — the version comes from **Packagist**, the notice **prints the command and
updates nothing** (which is pi's behaviour, and `pi update` is for *extensions*), and the check is
**on by default with a way off**.

What that produced: `CHANGELOG.md`, `"bin"` in `composer.json`, `CodingAgent\Version`,
`Cli\UpdateCheck`, `Cli\SelfUpdate` (`pig update`), `Settings::updateCheckEnabled()`, `--no-update-check`, and
`InteractiveMode::sayNewVersion()`. `bin/pig`'s `const VERSION = '0.1.0'` is
`define('VERSION', Version::current())`, because **a `const` needs a compile-time value** and a
second copy of the number is the thing `Version` exists to prevent.

Five things in it are decisions rather than code:

- **Every way the check can go wrong is silence.** A 404 — which is what Packagist answers until pig
  is published, so it is today's ordinary case — a 500, a body of the wrong shape, a proxy that
  refuses, a machine with no network: all null, nothing on screen. Not a silent fallback, because no
  value is being invented; the alternative is a warning every morning, which is how people learn to
  skip warnings.
- **It is spawned, inside the one `Async::run()` the interactive start already has.** A network call
  in front of the first frame is a start that hangs on a bad network, and the answer arriving while
  somebody is already typing is exactly what `sayNewVersion()` being public is for.
- **A pre-release is never offered and is always told**, which sounds contradictory and is two
  questions: a beta is not *offered* to somebody who did not ask for one, and somebody already
  running `0.2.0-beta.1` is told when `0.2.0` lands. `version_compare()` orders both correctly —
  `Changelog`'s rule, for `Changelog`'s reason — so the asymmetry is one `str_contains` on the way
  out and one regex on the way in.
- **The version lives in the git tag and nowhere else.** This bullet said `composer.json` for one
  batch; see the entry below for why that was wrong and what it cost.
- **"you have" is `$this->version`, not `Version::current()`.** The component already holds the
  string the banner drew, and reading the manifest a second time made the screen contradict itself
  three lines apart — banner `v0.0.0`, notice `you have 0.1.0` — about the one comparison the block
  exists to make.

**And the two tests that had to change are the entry.** Both went red on the *existence* of
`CHANGELOG.md`, having been written when there was none:

| | asserted | why it passed |
|---|---|---|
| `InteractiveModeTest::testSlashChangelogSaysSoWhenThereIsNoChangelog` | `No changelog entries found.` | `/changelog` calls `Changelog::parse()`, which reads the file at the repository root. The constructor's `$changelog` is the **upgrade note** only, so injecting nothing was never "no changelog" |
| `ChangelogTest::testPigsOwnPathIsBesideTheReadme` | `Changelog::parse() === []` | same file, and the assertion would have been green for a `path()` pointing anywhere at all |

So the one test of `/changelog` and the one test of the default path were both pinning that a file
did not exist. *A test whose subject is a file is worth asking what it asserts on the day the file
appears* — and the tell is the same one this document keeps naming: **an assertion that holds either
way is not an assertion.** `parse() === []` holds for a correct path to a missing file and for a
wrong path, which is two facts sharing one green. Both assert the pair now — `path()` names a file
this parser can read, and the version this pig is has an entry in it — and the empty answer keeps
its own case against a path that is not there, which is where a question about a missing file
belongs. Two docblocks asserting "pig has no `CHANGELOG.md`" went with them, in `Changelog` and in
two places in this document: the fourth shape from the index, on the file the feature is about.

**Each end was mutated separately**, which is the rule this feature had four chances to break and
broke twice. Eleven mutations: the `<=` (the version you have offered as an upgrade), the
pre-release skip, the `v` strip, the swallowed `Throwable`, `Version::path()`, both facts in the
notice, `Changelog::path()`, and `bin/pig`'s two. Nine die. **Two survived and one of them was a
real gap**: `Settings::updateCheckEnabled()` could `return true;` with the whole suite green — the
setting had no test at all, so a switch that works in production was inert in every test, which is
this document's commonest shape. Three `SettingsTest` cases close it, and the `!== false` is
deliberate rather than tidy: an empty `update` block is somebody who set a sibling key, and reading
that as "off" is a feature turning itself off because a neighbour was written.

Two things stay unpinned and the reason is on record rather than guessed:

- **`bin/pig`'s `$checkForUpdates` line.** Replacing it with `true` kills nothing, because the only
  consumer is the interactive branch, which needs a terminal, and the check's whole observable
  effect is a network call the container cannot make plus a block `sayNewVersion()` draws on its
  own. `--help` naming the flag is what there is. If this is ever worth closing, the piece to move
  is the decision — a `bin/pig` that asked something testable which check to make would be
  pinnable, and that is new surface rather than an audit's call.
- **`Version`'s cache.** `return self::$current = …` → `return …` survives: the answer is identical
  and the cost is one extra `file_get_contents` per call, with no seam to observe the peak through.
  The same standing as `HttpClient::follow()`'s `body->close()`.

**And the harness bit back, for the fifth time, in the fifth shape.** A mutation run hit my own
two-minute limit, the python was killed with SIGTERM mid-mutation, **its `finally` never ran**, and
the tree kept `$version = $version;` where the `v` strip had been. The next batch then measured two
mutations against a tree carrying a third — so one kill was partly somebody else's and the survivor
could have been anything. Caught by grepping for `ltrim` in a file I had just read and finding none.
The four earlier bugs were a worker race, reused trees, a replacement that never matched and a
filter missing a consumer; this one is **the harness being killed from outside**, and the fix is
that the restore copy is written to disk *before* the mutation is applied, the run gets a timeout
well inside mine, and the script asserts the file is byte-identical afterwards. *A `finally` is not
a guarantee when the signal comes from outside the process — the only durable undo is a copy on
disk made first.*

**What is left for the developer, and it is not a code change.** `bin/pig` and `bin/pig-ai` have no
executable bit in git and the container copy is not a git repository, so that one is one command on
the Mac: `git update-index --chmod=+x bin/pig bin/pig-ai`.

### Ten test files could not be loaded by PHPUnit at all, and the shim could not see it

The first real `vendor/bin/phpunit` run in a long while did not fail a test. It **could not build the
suite**:

```
PHP Fatal error: Cannot override final method PHPUnit\Framework\TestCase::run()
  in packages/ai/test/GenerateModelsTest.php on line 218
```

`TestCase::run()` is `final`, and overriding a final method is a fatal error **at class-load time** —
so PHPUnit's `TestSuiteLoader` died on the first file that did it and **no test in the repository
ran.** Not the file's tests: none of them, in any package.

`GenerateModelsTest` had a private `run()` that shells out to the generator. Renamed, and then the
question worth asking was not "is that file fixed" but **who else does this** — the first shape from
the index, pointed at a rule PHPUnit owns. `TestCase` has **70 final instance methods**, and nine
more collisions were sitting behind the first one:

| | |
|---|---|
| `AgentLoopTest::run()` → `drive()` | 9 call sites |
| `ModelsTest::any()` → `anyFrom()` | 4 |
| `OauthTest::run()` → `onTheLoop()` | 36 |
| `BashOutputTest::output()` → `outputOf()` | 2 |
| `PrintModeTest::run()` → `execute()` | 22 |
| `SignInTest::run()`/`output()` → `signIn()`/`said()` | 12 + 10 |
| `ToolTestCase::run()`/`output()` → `execute()`/`textOf()` | **77 + 56**, across six subclasses |

`ToolTestCase` is the base class the tool tests extend, so that pair alone is most of
`coding-agent`'s suite. `run` and `output` are the obvious names for "run the thing" and "what came
out", which is exactly why they were chosen six separate times and exactly why they are taken.

**The shim is why this survived**, and it is a new entry in the list of ways it is more permissive
than the real runner. The known ones were `failOnWarning`, `failOnNotice` and `failOnDeprecation` —
a mutation that only warns survives the shim and dies under PHPUnit. This one is worse in kind: the
shim's own `TestCase` has no `run()` to be final, so it ran, green, a suite PHPUnit **could not
load**. *A stand-in runner tells you about the tests; it does not tell you the real runner can start.*
So the shim now sweeps for it before running anything and refuses with the list, which was checked by
putting one collision back and watching it refuse.

The neighbouring PHPUnit 12 rule was swept at the same time and is clean: every method named by a
`#[DataProvider]` is `public static`, which that version requires and older ones did not.

### What the first real PHPUnit run said, once it could load the suite

With the ten final-method collisions gone, `vendor/bin/phpunit` ran for the first time in a long
while: **2595 tests, 2 errors, 5 failures, 1 deprecation.** Not one of them was a bug in pig's
behaviour, and that is the interesting part — every one was either a test coupled to the machine it
ran on, or a test that could not work under the real runner. So the entry is about the **kinds**.

**Kind one: a test that passes or fails by what the machine happens to have.** Two of these, both
green in the container and red on a Mac that had actually used pig:

- **`SearchToolsTest`'s two missing-tool tests emptied `PATH` and stopped there.** But
  `ExternalTool::locate()` looks in **pig's own `~/.pig/agent/tools` first**, deliberately, so a downloaded
  copy beats one the PATH shadows — which means on any machine where pig has ever fetched `fd`, the
  tool was found and nothing threw. A test about something being absent that only passes where it was
  never present. `PIG_HOME` now points at an empty directory as well, which is what
  `ToolInstallerTest` had been doing in its `setUp` all along: *the answer was already in the
  repository, in the sibling that got it right.*
- **`InteractiveModeTest`'s export test asserted a path against the wrapped screen.** In the
  container `/tmp/pig-interactive-…` fits on one line; on macOS
  `/var/folders/mk/6fds…/T/pig-interactive-…/out.html` wraps, and the renderer split it mid-word as
  `out.` / `html`. Reproduced here by pointing `TMPDIR` at a path of that shape. Collapsing
  whitespace does **not** undo it — joining those two rows gives `out. html`, which is not the
  filename either — so `screenText()` renders at a width nothing wraps at. The rule: **assert layout
  against `screen()` and words against `screenText()`**, and a path in an assertion is words.

**Kind two: a test that could not work under the real runner, and the shim could not show it.**
`testATagCutWithAVeeIsNotTheVersionItReports` steered `Version::current()` with
`InstalledVersions::reload()` and reported `dev-main` instead of the fabricated `v0.9.0`. Reading
Composer's source says why: `getInstalled()` consults **every registered ClassLoader's dataset
before** the one `reload()` sets, so a reloaded version is ignored wherever an autoloader is
registered — which is every real run. **The shim registers no Composer ClassLoader**, so there the
reload was the only dataset and it worked. That is a new line in the list of ways the shim differs,
and a structural one rather than a strictness one: *anything that depends on
`ClassLoader::getRegisteredLoaders()` behaves differently under the shim.*

The fix was not a better seam. Stripping a tag's `v` was written out **twice** — `Version::current()`
and `UpdateCheck::newest()` — which is the first shape from the index on a one-line rule, and the
worst place for it, since the two are the opposite sides of one comparison and a disagreement makes
`v0.2.0` newer than `0.2.0`. It is `Version::plain()` once, both readers call it, and it is tested as
what it is: a pure function over a table. **New public API, so it was flagged rather than assumed.**

**Kind three: hygiene that only shows up in a long-lived process.** Two `OverflowTest` errors,
`Reader r1 watches a closed stream; cancel the watcher before closing it` — which is this document's
own trap, thrown from inside a test that had done nothing wrong. Two causes, both fixed:

- **`OverflowTest` and `HookRunnerTest` were the only two test classes that turn the loop and never
  `Loop::reset()`.** The loop is a singleton that outlives a test, so those two inherited whatever
  watchers the previous test left — and the error names *their* `Async::run()`. Found by sweeping
  every class for the pair (touches the loop, never resets), which is two out of forty-odd.
- **`CannedServer` never cancelled the watcher on its listening socket**, and nothing ever stopped a
  server: `OverflowTest` builds one per call and drops it. So the stream went away on garbage
  collection with its watcher still armed. It has `stop()` and a destructor now, cancelling before
  closing, and its two deferred writes are guarded with `is_resource()` so a stopped server cannot
  write to a closed peer. A helper is not exempt from the rule the rest of the tree follows.

The container could not reproduce the second one on its own — the probe said OK with the destructor
removed, because `Loop` holds the stream resource itself in its watcher record and so keeps it alive.
That is worth knowing: **the container's loop keeps a dropped socket open, so this class of leak is
invisible here and shows up wherever GC and test order differ.** Both fixes are correct by the rule
whether or not the ordering that exposed them can be staged again.

**And one deprecation, which `failOnDeprecation` makes a failure:** `ReflectionMethod::setAccessible()`
is deprecated in PHP 8.5 and has had no effect since 8.1 — reflection reaches a private method by
itself. One call in the tree, in `GoogleTest`, removed.

**The last two were half of a commit that did not travel.** Commit `98ed239` ("Align with Pi system
prompts") changed `SystemPrompt.php`'s wording to upstream's and left `SystemPromptTest` asserting
pig's earlier phrasing — `read-only work only` against `ONLY for read-only operations`, and `Use bash
for file work` against `Use bash for file operations like ls, grep, find`. The source was the
intended state and the assertions followed it, which is also the direction this file's own fidelity
rule points: where the two disagree about what upstream says, upstream decides.

Worth knowing for anybody reading an older note: **the prompt and its test were treated as off-limits
for a while**, as the developer's own working copy, so they were held out of every sync. That no
longer holds — the edit was upstream alignment rather than private work, and both files are ordinary
parts of the tree again. *A rule about which files not to touch is worth re-asking about, because the
reason for it expires quietly.*

### Packaging for Packagist, and the version field that was the wrong answer to the right question

The entry above got `Version` right in principle and wrong in fact. Its reasoning — *one written-down
number beats two* — is sound, and it was pointed at the wrong file: **Packagist derives a package's
version from the git tag**, and its own documentation says the `version` field "should be omitted",
for precisely the reason the entry gives. So `composer.json` holding `0.1.0` did not remove the second
copy, it *created* it, and the failure it warned about (a build from tag `v0.2.0` reporting itself out
of date for ever) became "somebody forgot to edit the field before tagging" — the same bug with a
manual step in front of it. *A rule about not writing a fact down twice is only as good as knowing
where the first copy already is.*

Both of these were the developer's calls, and both came out the way the facts pointed.

**One package, not six.** packagist.org does not host archives — it references the source — so it
cannot serve a package from a subdirectory, and multi-package repositories are a Private Packagist
feature. The monorepo therefore publishes as a single `pigagent/pig` whose root `composer.json` autoloads
all five namespaces out of `packages/*/src`. What that removed is worth listing, because every line
of it existed only to make the split work in a checkout: the `path` repository, the five
`pig/*: "@dev"` requires, `minimum-stability: dev` and `prefer-stable`. All four are root-only
settings anyway — **Composer reads `repositories` and `minimum-stability` from the root package and
nowhere else** — so `composer global require pigagent/pig` would have gone to Packagist for five packages
that are not there, with `@dev` constraints nothing would satisfy. The alternative, splitting into six
read-only repositories with a CI job to push them, buys `require pig/ai` for somebody who has never
asked for it, and costs six tags to keep in step — which is the rule at the top of this file.

The five `packages/*/composer.json` stay, and what they are has changed: **structure documentation,
not manifests.** Nothing reads them now. They are kept because the package split is the port's
skeleton and each one states what its package needs, but a requirement written only there is a
requirement Composer will not enforce — which is exactly how the next paragraph happened.

**`type` is `library`, not `project`.** `project` means "an application you `create-project` from";
the default is `library` and that is what a CLI installed with `composer global require` is.

**And collapsing found a requirement nothing declared: `ext-pcntl`.** This document has said since
`pig/tui` was ported that the extension is required, with the reason (SIGWINCH is the only way to
learn the window was resized) and the consequence (`ProcessTerminal` *refuses to start* rather than
drawing at the startup width for ever) — and **no `composer.json` in the tree listed it.** So
`composer install` succeeded on a machine without it and pig died at startup with a `TuiError`,
where Composer's whole job is to refuse beforehand. The fourth shape from the index, on a manifest:
a claim about the repository the repository did not keep. It is in the root's `require` now, with
`ext-json`, `ext-mbstring`, `ext-openssl` and `ext-pcre`, which the sub-packages declared and the
root — the only one Composer reads — did not.

**`Version` asks Composer instead of reading a file.** `Composer\InstalledVersions::getPrettyVersion('pigagent/pig')`,
`v` stripped. That costs one platform requirement, `composer-runtime-api ^2.0`, which is Composer's
own runtime rather than a package anything downloads — the same kind of entry as `php` or `ext-json`,
and it was put to the developer as a dependency question because that is the standing rule.
`getPrettyVersion` and not `getRootPackage()`: installed globally, pig is a *dependency* of the
global project, so the root package is that project and not pig.

**A checkout is not a release, and the unguarded shape of that is the dangerous one.** Measured, by
rewriting `installed.php`'s answer four ways:

| Composer says | `pig --version` | asked of Packagist |
|---|---|---|
| `0.1.0` (tag `v0.1.0`) | `pig 0.1.0` | yes |
| `v0.2.0` | `pig 0.2.0` | yes |
| `dev-main` (a branch) | `pig dev-main` | **no** |
| `1.0.0+no-version-set` (no tag reachable) | `pig 1.0.0+no-version-set` | **no** |

`dev-main` is obvious. `1.0.0+no-version-set` is the one to be careful about: it *reads as* `1.0.0`,
so an unguarded `version_compare()` puts a checkout ahead of every real release and the check goes
quiet — right answer, wrong reason, and it would have stayed quiet after `1.0.1` shipped too. The
gate is `UpdateCheck::A_RELEASE`, anchored at both ends so the suffix is refused rather than ignored
down to the number in front of it, and it lives with the other reasons that class says nothing rather
than as a condition in `bin/pig`. Both are printed as Composer words them, because `no-version-set`
is a sentence a developer can read and an invented `0.0.0` is a number they would have to go and
check.

**The two tests that asserted the field are gone the same way the changelog ones went.**
`testPigsOwnVersionComesFromComposerJsonAndNowhereElse` asserted `$manifest['version'] ===
Version::current()`, which was true and was a test of the wrong fact; it now asserts `composer.json`
has **no** `version` key, which is the decision. And `testTheChangelogHasAnEntryForTheVersionThisIs`
could only ever pass where the version was a release, so it skips in a checkout and says which
version made it skip — the assertion that matters runs on a tag, which is where it is wanted.

**Two things the verification tripped over, both stale generated state rather than source.** The
shim autoloads `packages/*/src` by hand and bypasses `vendor/`, so it had no
`Composer\InstalledVersions` and every version test died on a missing class; it hands that one file
over now. Worse: `vendor/composer/installed.json` still recorded `pig/ai` and `pig/async` as
requiring `php >=8.4`, from before the floor moved to 8.3 — so a `dump-autoload` regenerated
`platform_check.php` as `PHP_VERSION_ID >= 80400` and **every test that spawns `bin/pig` failed on
8.3**, 35 of them, with a Composer platform error that named nothing about pig. The metadata was
corrected to what `packages/*/composer.json` actually declare. *A generated file can be years out of
date and cost nothing until something regenerates a second file from it* — and collapsing to one
package removes this whole class, since there will be no `pig/*` entries in anybody's lock.

**Installed, and the first install failed on the one thing nothing warns about.**
`composer global require pigagent/pig` locked and installed `v0.1.0` cleanly — which is the real
acceptance test, since it exercises the `bin` declaration, the five namespaces' autoload and the
`ext-*` requirements at once — and then `pig` answered `command not found`, because Composer's global
bin directory is not on anybody's PATH until they put it there. Composer prints nothing about this.
The README said `composer global require …` and then `pig`, so **the install instructions did not
work as written**; both now name the one-time fix. It is a per-machine prerequisite shared by every
Composer-installed command rather than anything about pig — and it is worth knowing that it is
*not* the executable bit: a binary without one answers "permission denied", not "command not found".
The bit was missing from git all the same (`100644`) and is `100755` now, which matters for whoever
clones rather than installs.

**And it is not what upstream's users go through, which is worth knowing before dismissing it as
normal.** `npm install -g @earendil-works/pi-coding-agent` needs no PATH step, because **npm derives
its global prefix from the `node` binary's own location**, so a global install lands in the directory
that already holds `node` — and that directory is on PATH by definition, or you could not run `node`.
Composer has no equivalent property: `composer` lives in one directory and `$COMPOSER_HOME/vendor/bin`
is another, which nothing puts on PATH. So "every PHP CLI is like this" is true and is not the same
claim as "this is as good as it gets"; the JS side of the same tool simply does not have the problem.
Nothing pig ships can change it — a package cannot choose where Composer puts its bins.

**`pig update` is the CLI command, matching pi's `pi update`.** Instead of telling users to run
Composer directly, `UpdateCheck::COMMAND` is `'pig update'` and `bin/pig update` calls `Cli\SelfUpdate`.
It checks the runtime environment: in a git repository checkout it runs `git pull` followed by
`composer install`, while in a Composer global installation it executes `composer global update pigagent/pig`.
`pig update --extensions` updates installed extension packages via npm. `pig update --models` refreshes model catalogs.
Startup checks also spawn `PackageUpdateCheck` to check npm packages asynchronously and render
`Package Updates Available` boxes with `sayPackageUpdates()`, matching upstream pi's layout.
https://pigagent.dev/install.sh | sh` runs `composer global require` — the same one package from the
same Packagist — so `Version::current()` still reads Composer's answer and
`UpdateCheck::COMMAND` is still the right command for anybody who used it. What it adds is the two
things Composer will not do: **name every missing PHP extension before anything is installed**,
where a missing `ext-pcntl` otherwise surfaces mid-install as a platform error that names no fix, and
**offer to put Composer's global bin directory on the PATH**, which is the step that turns a
successful install into `command not found`.

Modelled on upstream's, and the reading corrected a claim made here first: pi's quickstart says "the
curl installer uses npm globally", which describes its **legacy** path — the default is a *managed*
install that fetches release metadata from `pi.dev/api`, runs `npm ci` into
`~/.pi/agent/install/releases/<version>` and symlinks a launcher onto the PATH. That layer exists to
pin transitive dependencies npm's global install does not, and **pig has none to pin**, so copying it
would buy nothing and re-create the two-version-sources problem below. What was worth taking is its
shape and four functions: picking the shell profile from `$SHELL`, `fish_add_path` versus `export
PATH`, a whole-line `grep -Fxq` so running the installer twice does not append twice, and asking
through `/dev/tty` — necessary because a piped script *is* standard input. 200 lines against
upstream's 1789; the 600 lines of logo animation and raw-key menus are not the part that works.

**The structural thing to keep: every function is defined first and the last line is the only call.**
Piped to `sh`, a download cut off half way still executes what arrived — so a script that did work
before its end would perform half an install. `InstallScriptTest::testNothingRunsUntilTheLastLine`
pins it, because nothing else would notice a refactor that moved the call up.

And the extension list is a **second copy of what `composer.json` requires**, deliberately: a shell
script cannot read the manifest before PHP is known to exist, which is the very case the preflight is
for. So it is pinned instead — the list, the minimum version in both the compared and the printed
form, and the package name against `Version::PACKAGE`. Five mutations, five kills.

**No phar and no Homebrew tap, for now, and the reason is not effort.** A second install route is a
second answer to *which version am I running* — the thing `Version` was just rebuilt to have exactly
one of — and worse, a `brew`-installed copy would be told to run `composer global update
pigagent/pig`, a command that does nothing for it. Closing that means `UpdateCheck::COMMAND` branching
on how pig was installed, which is a real feature rather than a packaging chore. Both routes are
feasible (box bundles `vendor/composer/installed.php`, so `InstalledVersions` answers inside a phar,
and `Changelog::path()` resolves under `phar://` once the file is bundled); the developer's call was
to wait, on the grounds that building a second distribution channel for friction that no user has hit
yet is exactly the anticipation this project keeps declining. The entry to reopen is this one.

**The vendor `pig` was taken, which is why the package is `pigagent/pig`.** Submitting answered what
nothing here could check: `pig` on Packagist holds an unrelated `pig/router`, and vendor names there
are first-come-first-served, so the claim route was closed and the name had to change before the
first release. `pigagent` matches the project's own domain. It cost two lines — `Version::PACKAGE`
and `composer.json`'s `name` — because the name is written **once** and `UpdateCheck::COMMAND` is
built from it; `UpdateCheckTest` asserts the manifest and the constant agree, which is the test that
makes a rename two lines rather than a hunt. **The binary is untouched**: `pig` is what `bin` says
and has nothing to do with the package name, so nothing a person types changed.

Worth knowing for the next name: `vendor/composer/installed.php` records the old one until
`composer install` or `update` runs — `dump-autoload` does **not** rewrite it, which is the same
staleness that turned a years-old `>=8.4` into 35 failures two paragraphs up. So a rename looks
broken (`Composer does not know about pigagent/pig`) until the lock is refreshed.

`authors` is `owner888` with a homepage, which is the GitHub account rather than the name the
standing rule keeps out of this repository. And `composer.json` no longer matches any lock, so the
Mac needs a `composer update` before anything else.

**And publishing has a consequence this document leans on in two places.** The back-compatibility
row says *"pig has never shipped, so there is nobody with an old file"*, and the session-format
section says there is *"no reader for the old shape, on purpose"* because *"a reader for it would be
compatibility with nothing"*. Both are arguments from having no users, and **the day `pigagent/pig` is on
Packagist they expire** — the rule they rest on is already stated correctly ("back-compatibility is a
debt to real users, and there are none until there is a release"), so nothing in it is wrong, but
the release is what starts the debt. Related and smaller: the root package is a path-repository
monorepo, so `composer global require pigagent/pig` cannot install it as it stands — the five
`packages/*` have to be published too, or the root has to stop depending on them by path. That is a
packaging decision and it is the developer's.

### 831 mutations over the eight files whose mistakes are not undoable

The instrument had been over every directory in `packages/ai`. This is `coding-agent`, narrowed to
the files where a bug is neither visible nor recoverable: `ReadTool`, `WriteTool`, `EditTool`, `Run`,
`Truncate`, `SessionManager`, `SessionEntries`, `SessionCodec` — the four tools that change the
user's files or kill their processes, the arithmetic that decides what the model is shown of them,
and the three classes that write the session file **pi also reads**. Everywhere else in the tree a
mistake shows up as a wrong answer somebody can see; here it shows up as a file that is gone.

**831 mutations, and the estimate before it ran was 945** — 0.38 per source line, the rate the five
earlier sweeps had been stable at. Off by 12%, which is worth knowing next time a sweep is budgeted
from the rate rather than counted: the rate is a planning figure, not a measurement.

Pass 1 detected 706 of the 831 — 692 killed outright and 14 by hanging the suite, which is a
detection too. **219 survived it before the tests below and 125 after.** What it found divides in
four, and the first is the one worth the words.

**`Truncate` had no test of its own.** 78 mutations, 40 survivors — the highest rate of the eight,
and nearly all of them one shape: `<=` became `<` and `>` became `>=` across `head()`, `tail()`,
`line()`, `size()` and the byte accounting, and the whole suite stayed green. **Every bound turned
out to be right**, which is not an argument for leaving it alone: the correctness came from a run
against upstream over 777 documents, the run was not kept, and *what a corpus proves it proves on the
day it ran.* Third time — after `PartialJson` and `JsonSchema`, whose entries say the same thing. So
`TruncateTest` is 21 cases written to `JsonSchemaTest`'s rule, **asserting that a bound rejects what
is out of range is not a test of the bound**: each pins the value that exactly reaches the limit *and*
the first one past it, because only the pair says where the edge is.

**Four real gaps, each one arm of a guard nobody could reach.**

- **The "Not a pig session file" guard was testable only on its easy arm.** `!is_array($header) ||
  ($header['type'] ?? null) !== 'session'` — the existing fixture wrote `just some text`, which fails
  **both** halves, so `||` → `&&` survived. Verified consequence: pig opens a `~/data/train.jsonl` as
  a conversation and appends to it. The same guard is in `describe()`, so it is also a row in
  `--resume`'s list that is not a conversation.
- **Escape between the model asking for a write and the write happening was unpinned in all four
  tools.** Deleting `$signal?->throwIfAborted()` from `read`, `write` or `edit` broke nothing. For
  `write` and `edit` that is the one thing escape has to mean: the file afterwards is the file that
  was there before.
- **Two write-failure detections never fired.** `file_put_contents(…) === false` in
  `SessionManager::write()` and in `WriteTool` — mutate the `false` and the throw is unreachable, and
  the model is told `Wrote  bytes to …` (false cast to a string) about a file that does not exist,
  then carries on editing it. Neither is easy to stage on purpose, so the tests use the two shapes
  that fail without needing a permission: a directory standing where the file should be, and a
  symlink into a directory that is not there. **A failing `file_put_contents` warns as well as
  answering false**, and a warning fails a test under `phpunit.xml`, so both scope a
  `set_error_handler` around the one call with the reason on it.
- **A compaction replayed the messages that came after it.** `keptFrom()` walks up to the compaction
  and `break`s there; without the break it collects the messages after it as well, and the walk then
  appends those a second time — a resumed conversation whose last exchange the model is shown twice.

**And a whole family the sweep is unusually good at: the default on every field pi omits.** The
session file is pi's, so a file written by pi — or by a pi old enough to predate a field — arrives
with that field missing, and what missing *means* is a decision per field rather than one rule. Every
one of them survived: `display` on a hook's message (absent means shown, or a hook that told somebody
something told nobody), `fromHook` on both summaries in both decoders (absent means pig's own, which
is pi's own rule for its own field, and it decides whether `Compaction::files()` carries the file
lists forward), `cancelled` and `truncated` on a typed `!command`, the `firstKeptEntryId` that is null
rather than an id matching nothing, and the timestamp that orders the catch-up flush. `millis()` had
no test at all, so `$at === false` → `!==` — every timestamp read back as *now* — was green.

Smaller, same batch: the `/resume` list's label was "the first thing said" and reading past it was
unpinned (every row labelled by the last thing typed into it); a conversation whose first message
opens with a **pasted screenshot** went through `opening()`'s `instanceof` in a way that only held
because of an `&&`; the message count counted messages rather than lines; `Run`'s timeout timer was
left armed when a command beat it, which keeps `isIdle()` false and is a session that finishes and
will not quit; both spill thresholds were unpinned at the exact limit, where a file is written that
nothing will ever name; and **`timeout: 0` meant no timeout where one operator over it means no
time** — the schema says `number` and the description says there is none by default, so a model
spelling "no limit" as 0 is ordinary, and the mutated guard kills every command instantly.

**The third kind is the survivors with a measured reason, and two are worth knowing.**
`stream_set_blocking($pipe, false)` in `Run::start()` looks load-bearing and is not: every read is
gated by `Loop::onReadable()`, and `fread` on a **blocking** pipe returns what is available rather
than filling the buffer — measured, 0.000s and `"one\n"` on both settings against `echo one; sleep
0.4; echo two`. So the line cannot be observed from outside and the mutation is equivalent.
`ReadTool` and `EditTool` each check the signal twice, and **neither check can be killed alone**
because nothing between them suspends: the tools are synchronous, so the signal cannot change, and
only deleting both changes anything (which `testAnEditThatEscapeStoppedLeavesTheFileAsItWas` catches).
The rest are the familiar three — `?? null` against `''` in front of a type check, initialisers whose
deletion *warns* (so `failOnWarning` kills them and the shim does not), and cleanup whose end state is
identical: `fclose($this->spill)` is belt-and-braces because `Run` is local to `execute()` and PHP
closes the handle when it goes, which is `HttpClient::follow()`'s `body->close()` again. The 125 in
one line: 56 are `?? null` against `''`, 41 are statement deletions (mostly those initialisers), and
the remaining 28 are operators inside the three shapes above.

| | mutations | survived, before | after |
|---|---|---|---|
| `Truncate` | 78 | 40 | 10 |
| `SessionManager` | 366 | 78 | 67 |
| `SessionEntries` | 71 | 23 | 13 |
| `SessionCodec` | 43 | 12 | 1 |
| `ReadTool` | 69 | 9 | 5 |
| `EditTool` | 25 | 5 | 5 |
| `WriteTool` | 17 | 3 | 1 |
| `Run` | 162 | 43 | 37 |

`EditTool`'s five do not move and each is accounted for above: the two signal checks that cannot be
killed apart, the read-only case that skips as root, and two failures — a read that cannot fail after
`is_file`, and a write that cannot fail after `is_writable` — with no way to stage either. **A
survivor rate is a fact about what a suite can see, not a grade**, which this file has said once
before and is the right reading of that row.

**The fourth kind is the harness, for the seventh time, and this one changed the source.** A
throwaway script re-applied each mutation to confirm it now dies, matching its target line by
`old in line` — and the pattern `            $this->closePipe($fd);` is a **substring of the
deeper-indented call inside `read()`**, so it deleted the wrong line. Then my own two-minute limit
SIGTERMed it mid-job, the `finally` did not run, and the tree kept a `read()` that never closes a
pipe at EOF. Caught by reading the file rather than by a failure: with the line gone the suite still
passed 28 of 28, because every command in it is short enough that `close()`'s own sweep tidies up
afterwards. Three rules, and the middle one is new:

- **Anchor a mutation to one line and refuse when the pattern matches more than one.** Substring
  matching across an indented tree finds the wrong statement silently.
- **The undo is a copy on disk written before the mutation** — already the rule (bug 6) — **and the
  restore is asserted byte for byte at the end.** The assertion is what turns "it probably restored"
  into a fact.
- Give the run a timeout well inside the caller's, so the process ends itself rather than being
  killed from outside.

Regression tests, and each of the mutations above was re-applied afterwards to confirm it now dies:
`TruncateTest` (21 cases, the whole file), `SessionManagerTest`'s eleven new ones (the two guards, the
self-parented entry, the orphan, the list's label and count, a cleared and a whitespace-only name,
the unwritable file, the compaction's trailing messages, the stray directory),
`SessionEntriesTest` and `SessionCodecTest` (new files, the defaults and the two `default => null`
arms), `PiFormatTest::testTheUpgradedFileKeepsOnlyOneClaimAboutWhereTheCutIs`,
`ReadToolTest`'s four, `EditToolTest`'s two, `FileToolsTest`'s two, and `BashToolTest`'s five.

### Two files that belonged in those eight, and the front door neither was tested through

The eight were chosen by one rule — *a mistake here is neither visible nor recoverable* — and two
files that fit it exactly were left out. **`Migrations`** moves somebody else's session files and
renames their `oauth.json`, and this document already says of it that "half-migrating somebody
else's data is worse than none". **`Auth`** writes the credentials file pig and pi *share*, and
Anthropic rotates refresh tokens, so one bad write there signs you out of both tools at once. 333
mutations over the two of them, 87 alive, and 62 after the tests below.

**The find is one shape and it is in both files: every test went in through the seam and none
through the door.** `MigrationsTest`'s sixteen cases all call `authToAuthJson($directory)` or
`sessionsFromAgentRoot($directory)` with a directory handed to them. `bin/pig` calls neither: it
calls `Migrations::run()`, with no argument. So four mutations survived together, and each is a
different way for the migration to silently not happen:

| mutation | what it would do in production |
|---|---|
| delete `self::authToAuthJson()` from `run()` | credentials never move; pi's `auth.json` never appears |
| delete `self::sessionsFromAgentRoot()` from `run()` | pi 0.30.0's stray sessions stay invisible to both tools |
| delete either `$directory ??= Config::piHome()` | the default is never resolved — the only path production takes |

None of the four could fail a test, because nothing in the suite ever reached `run()` or let a
directory default. One case does all four: point `PI_HOME` at a temp directory, put an `oauth.json`
and a stray session in it, call `run()`, and check both halves happened. **The rule, which is this
document's "wired at one end only" pointed at a test file rather than at the code: a class whose
tests all pass the seam an argument has no coverage of its default, and the default is what ships.**

Three smaller ones, each a guard that could not fail against the fixture it had:

- **`recordedCwd()`'s "not a session" check is `SessionManager`'s, with the same weak fixture.**
  `!is_array($header) || type !== 'session'` → `&&` survived, because the existing case writes
  `{"type":"notes"}` — which has no `cwd`, so a weakened guard has nowhere to file the file and
  leaves it alone by accident. The shape that moves is another tool's log with a `cwd` field in it,
  and then pig files somebody's unrelated `.jsonl` under `sessions/`. **Second time this exact pair
  of lines has been found under-tested for the same reason**; the sibling is four entries up.
- **An entry in pi's `oauth.json` that is not an object** was migrated when the `&&` became `||` —
  and `{"type": "oauth", ...$notAnArray}` writes an `auth.json` neither tool can read, from the only
  copy of those tokens.
- **`Auth::save()`'s write-failure throw never fired**, the third of these in one session. Its own
  docblock is the argument for the test: *"a token that did not stick is a sign-in that said it
  worked, and the next thing that happens is an authentication error nobody can connect to this."*

**And `antigravityClient()` was unpinned end to end** — every line of it, where its sibling
`googleClient()` was covered. That is the *"a rule present in one place and absent in its sibling"*
shape on a **test file**: the two methods are deliberately not one reader (different OAuth clients,
different scopes, and a Gemini CLI token is refused at Antigravity's endpoint), so the coverage has
to be two as well. Both now pin four things each — the environment wins, a variable exported with
nothing in it falls through to the settings (the `getenv()` trap), a setting that is present and
blank is not set, and **half a pair is refused rather than handed over with a gap in it**, which
otherwise sends somebody to a browser, takes their consent, and fails at Google's token endpoint
with nothing on screen naming the missing half.

`AuthTest::CLEARED` gained `ANTIGRAVITY_CLIENT_ID` and `_SECRET` in the same pass: without them, a
machine with that pair exported would have `testAFlowWithNoClientCredentialsIsRefusedBeforeAUrlIsShown`
open a browser and fail for a reason that has nothing to do with the code — the same "passes on what
the machine happens to have" as the missing-tool tests two entries up.

**Three things in `Auth` are not testable, the developer was asked, and the answer was to leave them
that way.** `login()`'s `setCredentials()` — the line that makes a sign-in persist at all —
`fresh()`'s store of a **rotated** refresh token, and the Copilot block that switches on the models
an account has not accepted: all three sit behind `new Anthropic()` / `new GithubCopilot()`
constructed inside `Auth` with no endpoint to point elsewhere. Every flow class *has* that seam
(`Anthropic`'s docblock says it is there "so a test can point it at a loopback server"); `Auth` is
the one caller that does not use it. Upstream is built the same way, so this is not a port gap — it
is new public surface, which made it the developer's call, and the call was **no**: a factory
argument on `Auth`'s constructor is machinery for three tests and nothing else asks for it.

So this paragraph is the record, and it is not an open question — **do not re-propose the seam.**
What would reopen it is a reproduced failure rather than the coverage, because the consequences are
the worst in the file: a sign-in that reports success and stores nothing, a rotated token thrown
away so the next turn is signed out, and a third of Copilot's model list failing on its first turn.
If one of those three is ever traced to a real bug, that is the day the seam is worth its keep, and
this is the entry to start from. `test/live.php` is the other route: it already reaches real
endpoints with real credentials, so a scenario that signs in and reads the file back would cover all
three without any new surface at all.

### The three files that can lose a conversation without saying so

Next tier after the ten, by the same rule, and the criterion had to widen a little: not *a file the
user cannot get back* but **a conversation that changes under somebody and never says it did**.
`Session/Compaction` decides what is thrown away, `Session/AgentSession` coordinates every way a
conversation is left or replaced, and `Settings` is what carries a person's choices to the next run.
1,301 mutations — 144, 985 and 172 — and the shapes are not the ones the earlier rounds found.
**208 survived and 168 after the tests below**: `Compaction` 40 to 33, `AgentSession` 126 to 114,
`Settings` 50 to 21.

**`Compaction`: the boundaries, and an assertion that could not fail.**

- **The compaction threshold was pinned on neither side.** `shouldCompact()` is the arithmetic that
  decides when a conversation gets summarised away, and the cases were 190k against a 200k window
  and 150k against the same — both a long way from the line. `> window - reserve` and `>=` both
  passed. Exactly `window - reserve` is still room for the answer the reserve is *for*, so that is
  the last size that does not summarise, and the pair is now the test.
- **The `<=` this file has a whole entry about was unpinned.** "A token count measured before a
  compaction describes a conversation that no longer exists" ends with *"`<=` rather than `<`: the
  summary is written straight after the turn it replaces and a millisecond holds both"* — and `<`
  passed, because every fixture had them a full second apart. The consequence is the one that entry
  opens with: the stale reading is believed, the session asks to compact before every turn, and the
  second attempt fails with `Already compacted` in front of everything the person types.
- **`request()`'s `<previous-summary>` block: the test asserted the tag and the tag is in the
  prompt.** `UPDATE_PROMPT` says "provided in `<previous-summary>` tags", so
  `assertStringContainsString('<previous-summary>', …)` holds whether or not the block was ever
  added — and inverting the condition that adds it left the model told to update a summary it was
  not given, which silently drops everything the first compaction said. It asserts the summary's
  *text* now. **Fifth time in this document that an assertion held either way**; the tell is always
  that the string being asserted appears somewhere else in the same output.
- Smaller, same file: a file list is de-duplicated and sorted on the read side and neither on the
  modified side, and a tool call whose arguments never arrived — an interrupted turn, half-parsed
  JSON — put a null in the list of files the summary claims were touched.

**`AgentSession`: 985 mutations, 859 killed in pass 1 — the best rate of any file swept, and the
126 that were left are concentrated in exactly the recipe this document already has an entry for.**
The entry is "RPC's session switch and new-session skipped the three checks the terminal's have",
and its fix moved the whole recipe into `startNew()` and `switchTo()`. What the sweep says is that
the recipe arrived without tests of its own: the cancellable `session_before_switch` hook could be
deleted from either method, the queue could stop being cleared, `restoreSettings()` could stop being
called on a resume, and the `session_switch` event could stop being emitted — nine mutations across
the two methods, none of which any test could see. **A fix that moves a rule into one place still
needs the rule tested in that place**, which is the same lesson as "after wiring anything through,
mutate each end", one level up.

Two more in the same file, both about what reaches disk: a `!command`'s `BashExecution` is appended
to the session file directly, because there is no `message_end` to carry it — and the append could
be deleted, on both the immediate path and the flush that runs when a command was held back during a
turn. A conversation that reopens without what was run in it is missing the half that explains the
rest.

**`Settings`: the accessors, as a family.** This document already records `updateCheckEnabled()`
being "entirely unpinned"; the sweep says that was one of a set. Deleting the `set()` out of
`setHideThinking`, `setShowImages`, `setRetryEnabled`, `setQueueMode` or `setLastChangelogVersion`
broke nothing, and so did deleting the `get()` out of `queueMode()`, `lastChangelogVersion()`,
`hooks()` and `customTools()` — a setter that stopped writing leaves a screen agreeing with itself
and a file that does not, and a getter that stopped reading is a preference that silently does
nothing. Three table-driven cases cover the family instead of one test each: every switch out and
back, every default an untouched file means, and the four numeric accessors against the three ways a
hand-written file misses `is_int($value) && $value > 0` — **a quoted number** (the mistake
`models.json`'s `cost` block has its own entry for), a zero, and a negative. Under `strict_types` a
quoted number comes back out of a method declared `int`, which is a TypeError from somewhere that
has nothing to do with the file.

**One equivalent mutant worth writing down, because it looks like a gap.** `startNew()` and
`switchTo()` both guard `if ($refusal !== null && $refusal->cancel)`, and no mutation of the `&&`
can fail a test — `emitBeforeSwitch()` goes through `HookRunner::ask()`, whose decisiveness
predicate *is* `$r->cancel`, so a result that does not cancel never comes back at all. The two are
one rule in two places. Harmless, kept, and measured rather than assumed — and the test that covers
the behaviour (a hook that only watches does not block the switch) says on itself that it cannot
kill that mutation.

### A spinner above the prompt repainted the line somebody was typing into

Reported with a screenshot: typing Chinese while the agent worked put the pinyin and its candidate
list below the footer, moving on every spinner tick. pig's own renderer fixed it by rewriting only
changed lines, never touching the composing line, and moving the caret *inside* the
synchronized-output wrapper (Terminal.app does not support 2026, so the per-line diff was what
mattered). **The developer then chose a literal port of upstream's `TuiMainScreen` and
`CURSOR_MARKER`, accepting that this may come back.** If it does, the differences to look at first:
upstream rewrites every row from the first change to the last change (a change above *and* below
the editor rewrites the editor), and `positionHardwareCursor()` writes the cursor move *after* the
`\e[?2026l`.

The other half of that report stays fixed in the components: `AssistantMessageComponent::update()`
keeps its `Markdown` components and sets them again by position, and `setText()` returns early for
the text it already has — without that guard a 1,600-line answer costs ~19ms a delta instead of 0.1.
Regression tests: `MessageComponentsTest::testABlockThatHasStoppedChangingKeepsTheComponentThatDrewIt`,
`testABlockWhoseKindChangesDoesNotInheritTheWrongComponent`,
`testAMessageWithFewerBlocksThanLastTimeDrawsOnlyWhatItHasNow`.

### One keystroke took a second and a half, and the component said it was cheap

The other half of the same report — *"任务完成以后输入很卡"* — and the entry above is the record of
guessing at it twice and missing. What settled it was one fact from the developer that no
measurement of mine had asked for: **only one conversation was slow, and only when resumed with
`pig -r`.** Other sessions were fine. That is not a statement about frame size or about images; it
is a statement about *what is in that session*.

So the session was profiled rather than imagined — 1.7MB, 416 messages, **201 tool results**:

```
frame: 3295 lines, 1.23 MB          a keystroke: median 1536 ms
  render the whole tree    459 ms     the editor taking the key: 0.01 ms
  rowOf(editor)           1042 ms     the dearest component: 12 lines, 50 ms
```

`BashOutputComponent` is the tail of a command's output cut to a few rows, and it **had no cache**,
with a docblock explaining why: *"the wrap is cheap and the text changes on nearly every frame while
the command is running."* Both halves are false for a command that has finished. The wrap is over
**the whole output** — it wraps 600KB to keep twenty rows — and a resumed transcript is nothing but
finished commands, whose text will never change again. So every keystroke re-wrapped every command
output in the conversation, and `Container::rowOf()` renders the tree twice more to find the caret's
row, so it happened three times over. Cached on text, rows and width like every other component
here: **1536ms → 1.85ms.**

**The comment is the whole lesson, and it is the fourth shape from the index pointed at a
performance claim.** "The wrap is cheap" was a claim about the input, made where the input comes
from outside; "the text changes on nearly every frame" was true of the one caller the author had in
mind and false of the other, which is `replay()`. *A docblock that justifies the absence of a cache
is an assertion about every caller, and it goes stale the same way a docblock listing absences
does.* The sweep worth running after this one: every `invalidate()` in the tree whose body is a
comment saying nothing is cached.

**Two measurements from the same run are worth keeping, because neither is a bug and both will look
like one later.** `Container::rowOf()` is 1.1ms of the remaining 1.85 — it renders `$chat` twice
(once walking into it to search, once for `count()`), which with caches hitting is array
concatenation rather than layout, and there is no symptom to justify changing it. And the first
frame of that session is **1.24MB in a single `ProcessTerminal::write()`**, which blocks the one
thread until the terminal has drained it — 815ms there, and it is the shape to suspect first for a
session carrying screenshots, since a 600KB PNG becomes an 800KB image sequence and a full redraw
re-emits every one of them.

Regression tests: `BashOutputTest::testAFinishedCommandIsNotWrappedAgainOnEveryFrame` — a **ratio**
rather than a number, because what a loaded machine does in a millisecond is not a fact about this
code, and the gap it stands in for is 800× — plus `testTheSameOutputAtADifferentWidthIsWrappedAgain`
and `testNewOutputAndANewRowCountBothReachTheScreen`. The width key and the cache itself are killed
by those; the `setText`/`setRows` invalidations and the text and row keys are **mutually redundant**,
each covering the other's mutation, and both are kept because that is exactly the shape `Text` and
`Markdown` already have.

### The footer cut its lines to a byte count and handed the terminal half a character

Three places in `FooterComponent` cut a line that would not fit, and all three counted **bytes** —
`substr(Ansi::strip($text), 0, $width - 3) . '...'`. The method one of them sits in is called
`trim()` and its own docblock said *"measured by what is visible rather than by bytes"*, which was
true of the measuring and false of the cutting, three lines below it.

What that produces is not a crooked column, which is the rest of this family. It is **a fragment
that is no encoding's text**: three columns of a hook's status line in Chinese is nine bytes, and
the ninth is the middle of the fourth character. Every other display path in this repository
sanitises what it is handed against exactly that — and this one *made* it rather than passing it
on, downstream of all of them.

`justify()` had a second bug stacked on the first: `$gap = str_repeat(' ', $width - $leftWidth -
strlen($cut))`. The cut text is right-aligned, so the gap is what puts it against the edge — and
sized in bytes, a model id in a script where one character is not one byte leaves the line **short
of the width**. Short rather than over, which is why nothing caught it: every assertion in that
file asks whether a line overflows. *An assertion about overflowing cannot see a line that stops
early*, which is the `grep`-exit-code shape again.

All three go through `Width::truncate()`, which is ANSI-aware and cuts on grapheme boundaries and
already existed. Found by asking what could hand a terminal bytes it cannot turn into a string,
after Terminal.app trapped inside `__CFStringCreateImmutableFunnel3` while drawing — **that is not
evidence it was the cause**, and the reported session's footer is ASCII throughout, so this is a
bug found while looking rather than the bug looked for.

Regression tests: `FooterTest::testNothingIsEverCutInsideACharacter` over widths 8 to 80 and
`testAModelNamedInAWideScriptStillFitsBesideTheCounts` over 58 to 96 — a column at a time, because
the cut only lands inside a character at some of them, and only some widths reach the branch at
all. The second asserts the line is **exactly** the width whenever any of the right-hand text is
on it; without that, the byte-sized gap survives every mutation.

### The list widget could filter and nothing ever asked it to

`SelectList::setFilter()` existed, and the only caller in the whole repository was its own test.
Every picker — models, sessions, themes, sign-ins — took arrow keys and nothing else, and a
printable key fell off the end of a `match` into `default => null`.

It also filtered on the wrong field. `str_starts_with($item->value, …)`, and the model picker's
values are `0`, `1`, `2`: a row's position in the list. *There was nothing there anyone could
type.* A prefix match is the wrong shape besides — `cmt` should find `commit`.

So: upstream's `fuzzy.ts` ported to `Tui\Fuzzy` (scores pinned against numbers taken from running
the original — see below), `setFilter()` moved onto it, and `handleInput()` given a backspace arm
and a printable-text arm. Two details are upstream's and neither is obvious:

- **Escape cancels the picker rather than clearing the query.** The way out must not depend on
  whether you typed.
- **A query moves the selection to the top match; clearing one leaves it where it is.** Not
  symmetrical, and right: clearing a query should not throw you back to the top of a list you had
  scrolled into.

The search line is drawn *only when something has been typed*, which is what keeps this out of the
editor's completion popup — the one `SelectList` that must not grow a search box, since it is
already filtered by the line you are writing. It never gets one, because the editor forwards only
up and down arrows to it and so can never accumulate a query. No flag, no opt-in: the two
behaviours fall out of the same rule.

`isPrintable()` is the arm that needed the care. "Not handled above" is not the same as "text": an
arrow is `\e[A`, a function key `\e[15~`, ctrl chords are the C0 range, and any of them taken as
text is a stray character in the query.

### Pinning a port to the original's arithmetic

`Fuzzy` is scored, and a scoring function is the one kind of port that can be wrong in a way no
ordinary test notices: every "the better match ranks first" assertion still passes while the
picker quietly feels different to type into.

So the numbers were taken from the original — `node --experimental-strip-types` over pi's own
`fuzzy.ts`, 24 pairs, scores printed — and compared against this one. All 24 matched exactly,
multibyte and digit-swap cases included, and they are now a data provider in `FuzzyTest`
(`testTheScoreIsTheOneUpstreamGives`). If one moves, the scoring changed.

Two deliberate deviations, both narrow:

- **Characters, not bytes.** The original counts UTF-16 units; `strpos()` looking for one byte of a
  three-byte character finds the middle of the character before it, and the index it returns then
  poisons every gap and boundary sum after it. `mb_str_split` and a character-index `indexOf`.
- **`[a-z]` and not `\p{L}`** in the digit-swap patterns, which *is* the original: the query is
  already lowercased, and a script whose letters are not these has no digit-order convention this
  would be fixing.

`usort()` is relied on being stable, as the original relies on `Array.prototype.sort` being
stable: rows that score alike keep the order they were handed in, which for the model picker is
the current model first and the default one second.

### A test that could not fail, and the experiment that found the case

`SelectItem::$searchText` carries upstream's `getModelSelectorSearchText` — provider, then
`provider/id`, then provider again, and the bare id *last*, so that `openai/gpt-5` ranks OpenAI's
own above a reseller's `openrouter/openai/gpt-5`.

Wiring it into the picker survived every mutation at first. The fallback haystack is the label and
the description, which between them already contain the id, the provider *and* the name — so the
same rows matched either way and only their order differed. A test asserting the right row is
present cannot see that.

Rather than assert on the wiring, the ranking was measured: both haystacks built over the real
registry, ten provider-prefixed queries filtered through each, top row compared. **Nine of ten
flipped.** The clearest is the one upstream's docblock describes: `openai/gpt` gives `openai/gpt-4.1`
with the search line and groq's `openai/gpt-oss-120b` without it, because that row's *id* contains
the provider the query names. That is now the regression test, and it fails on the mutation with
`groq · GPT OSS 120B` in the message.

The lesson is the general one: when a mutation survives, the missing test is usually a *comparison*
the assertions never make, and the way to find it is to run the two versions side by side over real
data rather than to reason about which case might differ.

### The footer said less than upstream's about the same session

Two things missing, both upstream's and both ported:

`• thinking off` when a model that *can* reason is not being asked to. It used to show nothing,
and nothing cannot distinguish "this model does not think" from "thinking is off" — a fact about
the session that the corner is exactly the place for.

`(provider) ` in front of the model id, under two conditions that are easy to drop and are the
whole design. It appears **only when more than one provider is on offer** — one provider makes the
prefix noise, since it distinguishes nothing — and **only if it fits**, dropped *whole* rather than
cut, because half a provider name in parentheses reads as a different provider, and the id is the
part you steer by. The fit is measured against the left side's width *after* it was truncated, not
the width it wanted.

The provider count is memoised and cleared in `invalidate()`, which is what a model switch or a
sign-in calls; upstream pushes the count in from outside for the same reason. The test for that
clearing is the one that needed `WithoutProviderKeys` — a second provider signed into mid-session
has to bring the prefix out, and without the clear it stays hidden until the next run.

Not ported: the `→ routed-model` segment, which is for upstream's virtual models. There is no
equivalent here yet.

### `ANTIGRAVITY_MODELS` has no upstream left to copy from — but the deployment has a catalogue

Moved to the extension with the provider: the table is `extensions/pig-antigravity/src/Models.php`,
the catalogue reader is `Catalog.php` beside it, and `scripts/fetch-antigravity-models.php` prints
the extension's shape. The point that survives: upstream has no Antigravity to copy from since 0.71,
the deployment answers `v1internal:fetchAvailableModels` with routing and enums as well as models,
and the catalogue's display names are not trustworthy where its ids and numbers are. See "A provider
an extension brings" above for why none of it is in `packages/` any more.
### `thinkingLevelMap`: the half of Antigravity's routing that belongs in pig

The Antigravity catalogue carries two maps and they are not the same kind of thing. `routing` and
`modelEnums` turn a level into **another upstream model** — `gemini-3.8-flash` at medium is sent as
`gemini-3.8-flash-medium`, which is sent as `MODEL_PLACEHOLDER_M319`. `thinkingLevelMap` turns a
level into **what this model calls it**, or says the model does not have it.

The second is upstream's own model-schema field (`ai/src/types.ts`, and
`ThinkingLevelMapSchema` in `core/model-config.ts` validates it in a `models.json`). The first is a
gateway's business: when the transport goes through something that already resolves ids — as it
does here, through an OpenAI-compatible gateway — putting the routing in pig too means two things
deciding one thing, which is the argument `GoogleGeminiCli`'s docblock already makes about the
retry loop it declined to port. So the line is: **the map comes in, the routing stays out.** That
boundary was read off pi's source rather than guessed; the entry above says why it could not be
read off pig's.

**Three states, and PHP flattens two of them by default.** A key that is absent means "send the
level's own name"; a key whose value is *null* means the model does not have that level; a string
is what to send instead. `$map[$level] ?? …` reads null as absent, which is the one value that
means the opposite — so `hasThinkingLevel()` is `array_key_exists`-first, and the docblock saying
so was written *before* the first implementation got it wrong anyway. Upstream writes
`mapped === null` freely because JavaScript tells `undefined` from `null`; this is the line where
that stops being free. The regression test is `ModelTest::testALevelTheMapNullsIsOneTheModelDoesNotHave`.

Where each piece lives, and why it is not all on `Model`: `Model` is in the `ai` package, which
depends on `pig/async` and nothing else — it has never heard of `ThinkingLevel`, which lives in
`agent-core`. So `Model` carries the string-keyed half (`thinkingEffort()`, `hasThinkingLevel()`,
`supportsXhigh()`) and `ThinkingLevel` carries the enum half (`supportedBy()`, `clampedFor()`).
Writing it the other way round inverts the package layering, and the first draft did.

**`xhigh` keeps its hardcoded id list as a fallback**, which is a deviation. Upstream can insist
xhigh be opted into through the map because every model it ships carries one; pig's generated
tables carry none, so a model that says nothing falls back to the list and `gpt-5.2` keeps the
level it has. The map still wins when it mentions xhigh at all — written as `idList || map` instead
of `map ?: idList` it passes everything except a `models.json` that redeclares one of those three
ids with `"xhigh": null`, which is the test that had to be added to kill that mutation.

**Two contracts were changed on purpose.**

`AgentSession::availableThinkingLevels()` still answers the **empty list** for a model that cannot
reason, while `ThinkingLevel::supportedBy()` answers `[off]`. Both are right: "one level, and it is
off" is a true statement about the model, and "no levels at all" is what `cycleThinkingLevel()` and
the settings list use to decide there is no thinking row to draw. Handing upstream's answer
straight to those callers turned "no row" into "a row with one choice" and broke seven tests, none
of them about thinking.

And asking for a level a model lacks now lands on **the nearest level it has** — up from what was
asked for, then down — where it used to fall to `off`. That reverses a decision this repository had
written down, on the grounds that sending `high` for `xhigh` answers a different question from the
one asked. That reads well for xhigh alone and fails everywhere else: once a map can remove *any*
level, the same rule turns "medium, please" on a model that goes low-or-high into thinking switched
off entirely, which is further from the question than `high` ever was. The Antigravity flash models
are exactly that shape — `off` is null, they always think.

**A hazard found the hard way.** `OpenAiCompletionsTest::send()` rebuilds the `Model` field by
field to swap in the canned server's URL. A new constructor field left off that list does not fail
to compile — it makes the feature look broken in whichever test happens to need it, which is how
half an hour went on a map that was being dropped in the test helper rather than ignored by the
provider. It is the only place in the repository that copies a `Model` that way; if a second one
appears, this is the note that says why it is worth avoiding.

### `~/.pig` became `~/.pig/agent`, and the downloaded binaries left `tools/`

Two moves, one of them only cosmetic and the other a collision that had been quietly working.

`Config::home()` is `~/.pig/agent` now, the same shape `piHome()` has always had — upstream's
`getAgentDir()` is `~/.pi/agent`, and pig kept everything a level up, so every path comparison
across the two tools had a step in it that was pure accident. Everything else follows for free:
sessions, `settings.json`, `auth.json`, hooks, skills and commands are all built off that one
method, and exactly two tests had the old path written out.

**No migration and no fallback.** `PIG_HOME` still wins, and an old `~/.pig` is not searched. The
developer asked for the move rather than for compatibility, and a fallback that reads the old
directory forever is how somebody ends up with two half-populated homes and no way to tell which
one a given file came from. `mv ~/.pig/* ~/.pig/agent/` is the whole upgrade.

The second move is the one worth remembering. `ToolInstaller::directory()` was
`Config::home() . '/tools'` — **the same directory `CustomTools\CustomToolLoader` scans for
folders with an `index.php` in them.** A downloaded `fd` binary sat in the list of the person's own
tools, and the only reason nothing ever broke is a rule written for a different purpose: the
loader wants directories and a binary is a file. That is a coincidence holding two features apart,
not a design. They are `bin/` and `tools/` now, which also happens to be upstream's name for the
first. `CustomToolsTest::testADownloadedBinaryIsNotMistakenForOneOfThePersonsTools` is what
notices if they are ever merged back.

**A blanket `~/.pig/` → `~/.pig/agent/` pass over the docs is not safe**, and the obvious way to
do it damaged the docblock that had just been written to explain the change — `~/.pig/agent`
became `~/.pig/agent/agent`. The give-away is `grep -rn "agent/agent"`, which is worth running
after any such rename. Project-local `.pig/hooks` and `.pig/tools` have no tilde and are
deliberately untouched: those are per-project directories and did not move.

### Antigravity: three names for one model choice

`extensions/pig-antigravity/src/Routing.php` now. The three names (logical → runtime → enum) and the
rule that an unknown model is refused rather than defaulted are unchanged; what changed is where
they live. See "A provider an extension brings" above.
### `defaultProvider` was written from the start and read by nothing

The footer said `(google) gemini-3.8-flash • thinking off` where pi said
`(antigravity) gemini-3.8-flash • medium`, and the provider half was a real bug.

`Settings::setDefaultModel($id, $provider)` stores both. `CodingAgent` read only
`$settings->defaultModel()` — the **bare** id — and a bare id means the direct provider by
`Models::RESOLD`'s rule. So a session last used on `antigravity/gemini-3.8-flash` reopened on
Google's public model of the same name: same id, different model, different bill. `defaultProvider`
had no reader outside the tests that asserted it was written.

**Invisible until a resold id was the default.** `claude-sonnet-4-5` remembered as `anthropic` and
resolved bare gives the same model either way, which is every case anybody had.

Two boundaries the fix needs, both tested:

- **Only the remembered model gets the remembered provider.** `--model gemini-3.8-flash` and
  `PIG_MODEL` are left exactly as typed. Applying a stored provider to what somebody just typed
  would be the worse bug of the two.
- **A stored provider that has gone falls back rather than failing.** `google-antigravity` is
  precisely that case after the rename above, so any settings file written before it names a
  provider that no longer exists. A tool that will not start because of a line it wrote itself is
  not a trade worth making.

The other half of that footer — `• thinking off` against pi's `• medium` — was not a bug. Measured:

    (google) gemini-3.8-flash       off/minimal/low/medium/high    off stays off
    (antigravity) gemini-3.8-flash  low/medium/high                off clamps to low

Google's public model has no `thinkingLevelMap`, so every level is available and a session with
thinking off shows exactly that. Antigravity's says `off => null` — it cannot be turned off — so
the same session clamps up to `low`. Both halves of the difference came from being on the wrong
model.

### Removing Gemini CLI, and the four rules that nearly went with it

Upstream removed Gemini CLI and Antigravity together in `0.71.0`; pig kept Antigravity and
removed the other. Gone: `Providers\GoogleGeminiCli`, `Utils\Oauth\GeminiCli`, their tests, the
`Api` case, the `Stream` arms and `geminiCli()` options builder, `Oauth\Provider`'s case,
`Models::GEMINI_CLI` with its five rows, and `Auth::googleClient()` — dead once nothing called it.

**The interesting part was the tests.** Nineteen of them failed, and the lazy reading is "tests for
deleted code, delete them". Four covered behaviour that is *still live* in `Oauth\Antigravity` and
had only ever been tested through the flow that was going:

- the token exchange being **form-encoded** rather than JSON;
- the **CSRF state check** — `hash_equals` against the PKCE verifier;
- the email being read, and a provider that refuses to say it **not** being a failure;
- `access_type=offline` plus `prompt=consent`, and the state being the verifier.

Same story in `AuthTest` for the client-credentials reader: `antigravityClient()` implements
env-then-settings, **the environment winning over settings**, an exported-but-empty variable not
counting as set, and an empty setting not counting either — and the last three were only tested on
`googleClient()`. All seven were ported rather than dropped. **Deleting a provider is where
coverage goes quietly**: the tests that fail are obvious, and the ones that were carrying a second
provider's only coverage are not.

Two tests were rewritten rather than moved, because what they asserted no longer exists: the one
comparing Antigravity's client pair against Gemini CLI's now asserts that the reader looks at *its
own* names and refuses rather than falling back, and `GoogleTest`'s Gemini 3 Pro level test is
replaced by the drift note below.

### pi pruned a message from the model's context and pig sent it anyway

Found by reading the developer's own session file — 24MB, written by pi 0.87, header `version: 3`
where pig's reader and writer knew only 2. The v3 diff is small and one piece of it is a whole
mechanism pig did not have: **`context_edit`**, an append-only line saying *what the model is shown
of an earlier entry has changed* — `replacement: null` omits it, a string or content list replaces
its content — while the entry itself stays in the file, the tree, the UI and the money. pi's
`buildSessionProjection()` applies the latest edit per target on the active branch; pig's rule for
a line it cannot read is "a node holding nothing, walked past", so **the target was walked past too,
and sent to the model whole.** Five of them in that file; all small, which is luck and not design —
the same entry is what pi writes when it prunes a tool result that has outlived its use.

**And the write side was pig's own gap, under a sentence this file already had.** *"The failed
message comes off the agent's state before the retry, and stays in the session file"* — true, and
it stops one step short: nothing in the file said it had come off, so **`--resume` put the failed
turn back into the agent's state.** Upstream's `_omitRecoveryAttempt()` writes a `context_edit(null)`
for exactly this. `AgentSession::dropLastAssistantMessage()` does now, through
`SessionManager::entryOf()` — identity on the message object, because the one `append()` was handed
on `message_end` is the one the agent's state holds — and `appendContextEdit()`, which refuses an id
the file has not got the way a label does.

Three things decided in the projection:

- **Edits are collected before the walk**, because an edit sits *after* the message it edits, and
  only edits on the path count — which is pi's "branch-relative" for free, since `pathTo()` is the
  branch. The test puts two edits of one target on the live branch and a third on an abandoned one.
- **A string replacement is one text block**, pi's rule for the roles whose content has to be an
  array, and the role and metadata are kept — a replaced tool result still answers its call id.
- **The kept range of a compaction is edited too**, or a pruned result inside the kept window would
  come back the moment the conversation was compacted.

**`VERSION` is 3 now, and `upgrade()` had to learn the difference between 1 and 2.** It read
`version < VERSION` as "v1" and ran the re-id-every-line path — correct for v1, destructive for a
v2 file that already has its tree. `upgradeFromV2()` is pi's `migrateV2ToV3()`: the header and the
one rename (`hookMessage` → `custom` on a message's role, which pig never wrote), nothing else
touched. Two tests pinned `version === 2` and both were pinning the number rather than the rule —
"pi's current version" — and say 3 now.

What this does **not** port: `usage` entries (pi's cache-warm accounting lines, read as nothing here
and summed nowhere), and `systemMessage` on a compaction. Both are read-and-walk-past, which is the
right answer until something here needs them.

Regression tests: `PiFormatTest::testAContextEditWithNullOmitsItsTargetFromTheModelAndFromNowhereElse`,
`testAContextEditWithAStringReplacesTheContentAndKeepsTheRole`,
`testTheLatestEditOnTheBranchWinsAndOneOnAnotherBranchDoesNot`, `testAnEditIsWrittenInPisShapeAndReadBack`,
`testAV2FileIsBroughtToV3WithoutReIdingAnything`, and
`AgentSessionTest::testAFailedTurnTakenOffTheStateStaysOffItWhenTheSessionIsResumed` — which goes
red when the `appendContextEdit()` in `dropLastAssistantMessage()` is removed. Verified against the
real file: 0 of the 5 pruned targets reach the model.

### A crash is written down, so `/bug` has something to attach

`CrashLog` is upstream's `core/crash-log.ts`: `~/.pig/agent/crashes.json`, five records, the newest
last, each with the time, version, kind, message, stack, session file and directory. Three writers
and two readers:

- **`bin/pig`'s `set_exception_handler`** writes `fatal_error`, hands the terminal back, prints the
  message with the throw site, and says how to report it — upstream's `handleFatalRuntimeError()`.
  Verified live with a malformed `trust.json`, which is an uncaught throw before any screen exists.
- **`InteractiveMode::reportLoopFailures()`** writes `loop_error` for a throw the loop *caught*.
  pig survives those and draws a red line, which is the entry on `Loop::setErrorHandler`; they are
  recorded anyway, because a thing the loop had to catch is a pig bug by definition and the person
  looking at the red line is exactly who `/bug` is for. Once per message, like the line.
- **`start()`** says the newest unnotified crash once, if it is under a week old — upstream's
  wording — and marks them all told.
- **`BugReport::build()`** attaches them under `## Recent crashes`, and **`write()` clears the
  file**, as upstream clears it once a report is out: attached to a report that was sent, they
  are not attached to the next one too.

**Everything in it is best effort, and that took two goes.** A crash log that throws is a second
crash with the first one lost, so `record()` swallows everything — the one place in `coding-agent`
allowed to. The first version still leaked a *warning*: `mkdir()` warns *and* answers false, and
`phpunit.xml`'s `failOnWarning` is what said so. A `set_error_handler` scoped to the write turns
the warning into the throw the swallow already covers; `@` is not allowed here.

And `getTraceAsString()` **starts at the caller of the throwing frame**, so the throw site — the
one line a report needs most — is not in it. The stack is `Class: message in file:line` and then
the trace, which is what the test asserts and what the first version did not have.

Regression tests: `CrashLogTest` (7), `BugReportTest::testRecentCrashesAreAttachedAndHandedOverOnceWritten`,
`InteractiveModeTest::testTheLastCrashIsAnnouncedOnceAtStartup` and
`testAThrowTheLoopCaughtIsWrittenDownForBug`.

### Gemini 3.x on the public endpoint

The entry this replaces was an open question: `Stream::gemini()` picked the level path with
`str_contains($id, '3-pro') || str_contains($id, '3-flash')`, the generated table had moved on to
`gemini-3.1-pro-preview`, `gemini-3.5-flash` and `gemini-3.8-flash`, and so every Gemini 3.x model but
`gemini-3-flash-preview` fell through to `thinkingBudget: -1` — "think as much as you like", with the
level ignored. It was pinned rather than fixed because fixing it needed a fact nobody here had:
what the endpoint takes for 3.5 and 3.8.

**Measured, one request per model and level, 2026-10-01**, with a `GEMINI_API_KEY` on a machine
that can reach `generativelanguage.googleapis.com`:

| | `LOW` | `MEDIUM` | `HIGH` | `MINIMAL` | `thinkingBudget: 0` |
|---|---|---|---|---|---|
| gemini-3-flash-preview | ok | ok | ok | ok | ok, no thoughts |
| gemini-3.1-flash-lite | ok | — | — | ok | ok, no thoughts |
| gemini-3.5-flash | ok | ok | ok | ok | ok, no thoughts |
| gemini-3.6-flash | ok | — | — | ok | ok, no thoughts |
| **gemini-3.5-flash-lite** | ok | ok | ok | ok | **400** `invalid argument` |
| **gemini-3.7-flash** | ok | — | — | **400** `not supported` | accepted, **thinks anyway** (79) |
| **gemini-3.8-flash** | ok | ok | ok | **400** `not supported` | accepted, **thinks anyway** (93) |
| **gemini-3.1-pro-preview** | ok | ok (111 thoughts) | ok | **400** `not supported` | **400** `only works in thinking mode` |
| **gemini-3.1-pro-preview-customtools** | ok | — | — | **400** | **400** |

Three things came out of it, and the first is worse than the question that was asked:

- **Thinking *off* was a 400 on three models, every turn.** `pig --model google/gemini-3.1-pro-preview`
  with the default level failed before the model said a word — `Budget 0 is invalid. This model only
  works in thinking mode` — and 3.5 Flash Lite the same with a less helpful sentence. 3.7 and 3.8
  Flash *accept* the zero and think anyway, so "off" there was a label on a thing that was on. None
  of this was the drift; it was the `thinkingEnabled: false` arm, which was right for 2.5 and is
  wrong for the models that cannot stop.
- **Every 3.x model takes a level**, so the check is `str_contains($id, 'gemini-3')` and the budget
  arm is for 2.5 alone. `MEDIUM` is a real level on Pro now — 111 thinking tokens against LOW's 87
  and HIGH's 142 — so upstream's fold of Pro to two levels went with `gemini-3-pro-preview`, the
  model it was measured on.
- **Which levels a model refuses has no pattern in its name.** MINIMAL is refused on 3.7, 3.8 and
  both Pro ids and accepted on 3.5, 3.6, 3.1 Flash Lite and 3.5 Flash Lite; `off` is refused on Pro
  and 3.5 Flash Lite, ignored on 3.7/3.8, honoured on the rest. So it is **data, per row**: the
  Google table gained an optional tenth cell, a `thinkingLevelMap` — the same machinery the
  Antigravity rows already use — and `ThinkingLevel::clampedFor()` moves `off` and `minimal` up to
  `low` *before* a request is built. The map comes from `scripts/generate-models.php`'s `OVERRIDES`,
  each with the measurement as its `why`, because models.dev knows nothing about refusals and a
  regeneration must not lose them. A row with nothing to say keeps its nine cells.

**And the generator's own report was lying about it**, which is worth more than the fix: the first
regeneration printed `antigravity/gemini-3.8-flash: levels {…} → []` for every Antigravity row, as
though the maps had been wiped. The file was untouched. The report reloads the written table in a
fresh process over a tab-separated line, and that line had no column for the map — so "after" read
as empty for every model that has one. It carries the map now, and the second run is `0 added, 0
gone, 0 changed`. *A diff printed by the tool that made the change is a claim like any other*; the
`git diff` is what said the file was fine.

Verified end to end through `bin/pig -p` against the real endpoint: 3.1 Pro with thinking off
answers (clamped to low), 3.8 Flash with `--thinking high` answers, 3.5 Flash with thinking off
answers with no thoughts. Regression tests:
`GoogleTest::testEveryGemini3ModelInTheTableTakesALevelAndNoneABudget`,
`testAModelThatRefusesALevelSaysSoInItsRowRatherThanAtTheProvider`, and
`GenerateModelsTest::testALevelTheEndpointRefusesIsWrittenAsATenthCellAndOnlyThere`.

### Verified against the real deployment

`bin/pig -p hi --model antigravity/gemini-3.8-flash` answered on the first real request. That one
line validates six things at once, none of which a canned server can check:

1. the provider name in `auth.json` (`antigravity`) resolving to a key;
2. the host — no `sandbox` in it;
3. the `User-Agent` header the deployment insists on;
4. the envelope: `requestType`, `userAgent`, `requestId`, `sessionId`, the labels;
5. the routing — `gemini-3.8-flash` going out as `gemini-3.8-flash-low` at the default level;
6. the model enum naming a model the deployment recognises.

Any one of them wrong is a 4xx, so "it answered" is a stronger result than it looks. **What it does
not cover is a turn with tools in it** — `normalizeConversationTurns` exists in the reference
implementation to stop the deployment answering 400 on those, and whether pig needs it depends on
whether `GoogleShared::contents()` can produce the shapes it guards against. The reference built
its contents from an OpenAI-shaped payload and pig does not, so the answer is not obvious either
way and is worth a real tool-using turn before any of it is ported.

### One string had to move in three places at once

`auth.json` says `antigravity`. Making pig read it is not an alias — `Auth::apiKey()` does
`Provider::tryFrom($provider)`, so **the key in `auth.json`, the `Oauth\Provider` enum value and
the registry's provider name are all the same string**, and changing one without the others just
moves where the key goes missing. So all three moved together:

    Models::ANTIGRAVITY              'google-antigravity'  ->  'antigravity'
    Oauth\Provider::GoogleAntigravity ... = 'google-antigravity'  ->  Provider::Antigravity = 'antigravity'
    the registry's 7 stale rows       ->  the catalogue's 14, with thinkingLevelMap

No compatibility shim for the old name. It was upstream's before upstream deleted the provider,
nothing writes it any more, and a second accepted spelling is a second place to look when a key
does not resolve.

The antigravity models moved to `Api::Antigravity` at the same time, which made three things in
`GoogleGeminiCli` dead — `SANDBOX_ENDPOINT`, `SANDBOX_HEADERS` and `headersFor()`, the last of
which existed only to choose between two header sets for two providers sharing one class. They are
gone, and the two tests that covered them moved to `AntigravityTest` rather than being dropped:
the registry shape one, and **the `RESOLD` rule** — `claude-sonnet-4-6` is Anthropic's id and a
bare `--model sonnet` must not quietly go through Google. That rule did not change; only the id
and the provider name in its test did.

**What the thinkingLevelMap from the catalogue actually says**, now that it is wired:

    gemini-3.8-flash    low / medium / high
    gemini-3.1-pro      low / high
    gpt-oss-120b        medium
    the other eleven    high

Every row says `off => null`: **these models always think.** So `off` is not offered, which is
also why the routing tables still having an `off` target is not a contradiction — that is what to
send if something asks anyway. `minimal` and `xhigh` are null everywhere too, so the picker offers
three levels at most where it used to offer six, and `gemini-3.8-flash • medium` is now a thing pig
can say.

### The Antigravity provider is `GoogleGeminiCli` plus an envelope

`extensions/pig-antigravity/src/AntigravityApi.php` now, implementing `Pig\Ai\Extension\StreamApi`
and still built on `GoogleShared`, which is public for exactly this. The three constants that were
wrong (host, User-Agent, envelope `userAgent`) and the trajectory-id byte cut are as recorded on the
class. See "A provider an extension brings" above.
### `auth.json` says `antigravity`, pig looked for `google-antigravity`

The key in `auth.json` is `antigravity`, and it is the extension's `OauthFlow::id()` now — the
registry keys `Auth::signIn('antigravity')` by it, so the file and the provider name cannot drift
apart without a key going missing, which was the point of the rename. See "A provider an extension
brings" above.
### A user quota request sent as `[]` was refused, and `X-Goog-User-Project` triggered a 403

Two bugs in Antigravity quota discovery (`QuotaClient`):
1. `postJson` serialized empty request bodies as `json_encode([])`, which produces `"[]"`. Google Cloud Code RPC endpoints require the root element to be an object message and returned 400 `Invalid JSON payload received. Root element must be a message.` Fixed by encoding empty arrays as `"{}"`.
2. Sending `X-Goog-User-Project: <projectId>` to personal quota endpoints (`v1internal:loadCodeAssist` and `v1internal:retrieveUserQuotaSummary`) caused Google Cloud IAM to demand `serviceusage.services.use` project permissions, returning 403 `Caller does not have required permission to use project`. Those endpoints belong to the user's personal Google subscription rather than a project quota, so that header is omitted.

In addition, `CodingAgent::session()` now honors `PI_PROVIDER`, `PI_MODEL` and `PI_REASONING_LEVEL` from the environment when `PIG_*` variables are not set, and clamps the startup thinking level using `ThinkingLevel::clampedFor($chosen, $level)` so models with `thinkingLevelMap` (such as Antigravity models requiring reasoning) do not default to `off`.

### `/quit` while working left the agent, bash, retries and compactions running in the background

**Phenomenon**: When `/quit` or `/exit` was entered while the agent was `working` (streaming an LLM response, running tools, retrying, or compacting), the process failed to terminate in-flight work cleanly, and hooks like `system-notify.php` continued firing desktop notifications on turn completion.

**Cause**:
`InteractiveMode::stop()` calls `$this->session->dispose()`, `$this->tui->stop()`, and `Loop::get()->stop()`. However, `AgentSession::dispose()` previously only disconnected the agent listener and cleared `$this->listeners`. It omitted:
1. `$this->abortRetry()` (sleeping retry timers kept firing and starting new turns in the background)
2. `$this->abortCompaction()` (background compaction continued)
3. `$this->abortBash()` (running bash subprocesses kept executing)
4. `$this->agent->abort()` (the in-flight LLM stream and tool loops were never aborted)
5. `$this->clearQueue()` (queued follow-up and steering messages were not discarded)

As a result, background fibers and subprocesses continued running to completion and emitting events.

**Countermeasure**:
Aligned `AgentSession::dispose()` with upstream `agent-session.ts`'s `dispose()`: wrapped in a `try...catch (Throwable)` and explicitly invoke `$this->abortRetry()`, `$this->abortCompaction()`, `$this->abortBash()`, `$this->agent->abort()`, and `$this->clearQueue()` before unsubscribing and clearing listeners.

**Test**:
`InteractiveModeTest::testQuittingWhileTheAgentIsWorkingAbortsTheAgentAndStops`.

### `/reload` boundary and the extension redeclaration fatal error

**Phenomenon**: When `/reload` was executed, if any loaded `.php` extension defined top-level named functions or classes (e.g. `function sendSystemNotification() {}`), PHP threw `PHP Fatal error: Cannot redeclare function ...` and crashed the session. Furthermore, updates to `pig`'s own core package classes (`packages/`) cannot take effect via `/reload`.

**Cause**:
Unlike Node.js modules which are wrapped in a private closure and whose module cache can be cleared, PHP files `require`d in the same process register top-level named functions and classes directly in PHP's global symbol tables. Re-`require`ing the file on `/reload` attempts to re-declare those symbols, causing a fatal error. Additionally, PHP does not support class unloading; once a class is compiled into memory by Composer, its definition cannot be replaced in the running process.

**Countermeasure**:
1. All extensions should use scoped closures and pass helpers via closure `use` or `HookContext $ctx` instead of declaring global functions or classes. `HookContext` now provides `$ctx->set()`, `$ctx->get()`, and `$ctx->has()` backed by a shared `HookState` instance for passing shared services and middleware state across handlers. The standard extensions (`copy.php`, `system-notify.php`, `smart-session.php`, `permission-gate.php`, `gemini-video.php`) were refactored to this scoped closure pattern so they can be reloaded repeatedly with zero conflicts.
2. `HookLoader::checkTopLevelSymbols()` was added to `HookLoader` and `ExtensionLoader`: before `require`ing an extension or hook, it tokenizes the file with `PhpToken::tokenize()`. If any top-level named function or named class is detected, loading is safely refused as a structured `HookError` / `ExtensionError` with guidance, completely preventing PHP fatal redeclaration crashes.
3. Clearly document `/reload`'s boundary across `README.md`, `README.zh-CN.md`, `bin/pig --help`, and `CLAUDE.md`: `/reload` live-refreshes extensions, skills, custom commands, tools, settings, and context files (`CLAUDE.md` / `AGENTS.md`), but upgrading `pig`'s own core engine classes still requires restarting `pig`.

**An extension with a class file beside its entry has the same problem one level up**, and
`require_once` does not solve it. `pig-web-search` keeps `HeadlessBrowser` in its own file; the
entry `require_once`d it, and the full suite died with `Cannot redeclare class
PigWebSearch\HeadlessBrowser (previously declared in ~/.pig/agent/extensions/…)`. `require_once`
deduplicates by **path**, and the same class lives at two paths — the installed copy under
`~/.pig/agent/extensions` and the repository's — so a process that touches both (the test suite;
in production, one pig moving between a project with its own copy and one without) loads the class
twice. The entry guards by *class* now: `if (!class_exists(HeadlessBrowser::class, false)) require`.
Whichever copy loaded first serves both, which is the trade; the alternative is a loader that
refuses a second copy of a class, which is the fatal error with a politer message.

### Footer session name display, `/name` command, and full `smart-session` telemetry parity

**Phenomenon**: Upstream pi's footer top line displays `pwd (branch) • <session-name>`, which extensions like `smart-session` use to render dynamic titles and token speed meters (`~/Development/owner/pig (main) • 仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms`), while pig previously only showed `pwd (branch)`. Furthermore, pig had no `/name` command, lacked `session_info` entry encoding/decoding, omitted streaming message hook events (`message_start`, `message_update`, `message_end`, `session_info_changed`, `agent_settled`), and had an incomplete `smart-session.php` stub.

**Cause**:
1. `FooterComponent::where()` only formatted `$cwd` and git branch, omitting `$this->session->getSessionName()`.
2. `SessionEntries` did not encode or decode pi's standard `session_info` entry format (`{"type":"session_info","id":...,"name":...}`), and `SessionManager` had no `getSessionName()` / `setSessionName()` API.
3. `AgentSession::tellHooks()` only dispatched `AgentStartEvent`, `TurnStartEvent`, `TurnEndEvent`, and `AgentEndEvent`. Hook extensions observing streaming tokens or agent settling never received callbacks, so token speeds and auto-summaries could not compute.
4. In `smart-session.php`, checking `$message->role === 'assistant'` assumed JavaScript plain object conventions. In pig, messages are typed PHP classes (`AssistantMessage`) without a `role` property, causing stream handlers to bail out immediately.
5. Background LLM summarization lacked the active session's OAuth/API credentials, failing on OAuth-backed providers like Antigravity. Additionally, reasoning models like Gemini 3.8 Flash produce thinking tokens that exhaust small token budgets (e.g. 50 tokens), prematurely terminating on `StopReason::Length`.
6. `InteractiveMode` lacked an `onSessionNameChanged` listener, so extension updates to session name did not trigger immediate TUI re-renders.

**Countermeasure**:
1. Added `SessionInfoEntry` and mapped it in `SessionEntries` and `SessionManager` (`sessionName()` / `setSessionName()`), with persistence in `.jsonl` files and preview support in `/resume` (`describe()`).
2. Added `sessionName()` / `getSessionName()` and `setSessionName()` across `AgentSession`, `HookApi`, and `HookContext`, wired to trigger `onSessionNameChanged` and immediately request a TUI re-render.
3. Extended `HookApi::EVENTS` with `session_info_changed`, `message_start`, `message_update`, `message_end`, and `agent_settled`, forwarding them from `AgentSession::tellHooks()`.
4. Provided `$ctx->complete($prompt)` and `$ctx->apiKey($model)` in `HookContext`, enabling background tasks to seamlessly execute LLM completions using active session credentials.
5. In `smart-session.php`, supported both `instanceof AssistantMessage` and role checks, set title generation budget to 1000 tokens matching upstream, and bound speed telemetry and auto-summarization to `$pi->setSessionName(...)`.
6. Added `/name` built-in slash command to view or rename the current session on the fly.
7. In `FooterComponent::where()`, appended ` • {$sessionName}` beside git branch, rendering the identical top line.

### TUI performance bottlenecks, CJK word boundary bugs, and rendering optimizations

**Phenomenon**: 
1. In `Width::visible()`, LRU cache eviction used `array_shift()`, which in PHP triggers O(N) re-indexing and memory block movements on every cache insertion once full (512 items).
2. In `TextWrap::tokenize()`, lack of CJK character boundary awareness caused Chinese sentences lacking ASCII spaces to be treated as a single massive English token, awkwardly severing trailing English words (e.g. `请确认提交commit-id` was chopped into `请确认提交co` and `mmit-id`), while causing heavy CPU overhead in `breakWord()`.
3. In `Chars::isPunctuation()`, punctuation check was hardcoded to ASCII and required `strlen === 1`. Multibyte CJK punctuation (`，。！？；：“”‘’《》【】……——`) were misclassified as word characters, causing `Ctrl+W` / `Alt+Backspace` to delete entire sentences across Chinese commas and periods.
4. In `Markdown.php`, large base64 inline image lines (Kitty/iTerm2 protocol) were fed into `TextWrap::wrap()` and padded, incurring massive string search overhead.
5. In `Editor.php`, `layout($width)` was executed 2–3 times per keystroke across `render()`, `caret()`, and `Container::rowOf()`.

**Countermeasure**:
1. Replaced `array_shift(self::$cache)` with `unset(self::$cache[array_key_first(self::$cache)])` in `Width.php` for O(1) hash eviction, speeding up cache updates by 5.0x and expanding capacity to 2048.
2. Aligned `TextWrap::tokenize()` with upstream pi's `cjkBreakRegex`: each CJK glyph forms an atomic token, allowing natural wrapping and clean hyphenation/wrapping for mixed English words.
3. Updated `Chars::isPunctuation()` with Unicode punctuation matching (`^[\p{P}\p{S}]\z/u`), preserving `_` as word identifiers while correctly recognizing Chinese and full-width punctuation as word boundaries.
4. Added `isImageLine()` bypass in `Markdown::lines()` to skip wrapping and padding on terminal image protocol lines.
5. Added cached layout in `Editor.php` to memoize the visual line layout within the same render frame.

### `SessionManager::describe()` 必须流式读取，否则扫描多个 10MB+ 会话会触发 PHP 128MB OOM

**现象**：
在 Web 模式或会话选择器加载包含超大 `.jsonl` 会话（如 16MB、14MB 包含大量长上下文、压缩摘要与工具调用的会话）时，触发 `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in SessionManager.php on line 144`。

**原因**：
`SessionManager::lines()` 此前使用 `file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)` 将整文件一次性读入由所有行组成的巨大 PHP 数组，并在后续使用 `array_slice` 发生数组浅拷贝。PHP 数组对于长字符串的内部 zval 结构体和散列表开销达到文件体积的 5-8 倍，单个 16MB 会话文件载入数组就消耗了近百兆内存；若一个目录下连续遍历 30 个会话的 `describe()`，极易瞬间冲破 PHP 默认的 128MB `memory_limit` 限制。

**对策**：
1. 重构 `SessionManager::describe()` 为流式逐行解析（`fopen` + `fgets`），读完一行立即解码并释放内存，实现 O(1) 恒定内存解析（即使单个会话文件达到 100MB，解析内存也仅几 KB）。
2. 在 `describe()` 提取会话搜索关键词 `$said` 时，增加 `$totalSaidLength < 65536` 上限截断保护，避免累积数万条长历史消息把内存撑爆。
3. 在 `HttpServer::listAllWorkspaces()` 读取首行 `cwd` 时，改用 `fgets($fh)` 只读取单个首行，避免调用 `file()` 吞进整个大文件。
4. 在 CLI 入口 `bin/pig` 和 `bin/pig-ai` 中显式设置 `ini_set('memory_limit', '512M');`，提供双重运行保障。

### PHP 8.5 `curl_close()` 弃用警报与 `CurlHandle` 资源生命周期

**现象**：
在 PHP 8.5 运行时下执行 cURL 网络请求（如 `pig-computer` 或其它扩展模块发起 HTTP 调用）时，终端或日志抛出：
`PHP Deprecated: Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0`。

**原因**：
自 PHP 8.0 起，`curl_init()` 返回值已由传统的底层 resource 升级为 `\CurlHandle` 类实例对象，对象在超出作用域或析构时会自动释放底层 cURL 连接句柄；在 PHP 8.5 中，调用 `curl_close()` 已正式标记为 Deprecated。

**对策**：
在整个仓库及扩展中全面弃用 `curl_close($ch)`，改用 `unset($ch)` 显式注销或令其在作用域结束自然析构。

### 命令后台化（`&`）导致子进程继承管道、`Run::wait()` 永久卡死

**现象**：
当模型或用户在 `bash` 工具中执行后台任务（如 `nohup ... &`、`(cmd) &`、或启动后台守护进程/服务）时，前台父进程已立即退出并输出结果，但 `Run::wait()` 陷入无限阻塞、会话彻底卡死，直到外部强行终止进程。

**原因**：
Unix 在 `proc_open` / `fork` 衍生进程时，子进程默认继承父进程打开的所有未标记 `O_CLOEXEC` 的文件描述符（包括 stdout/stderr 管道的写端）。即使父 shell 执行完 `echo ...` 正常退出，后台孙进程仍持有管道写端。`Run.php` 此前仅依赖 `feof($pipe)` 检测完成，而操作系统只有在所有写端均关闭时才会使 `feof()` 为真；同时 `Run::wait()` 未设置默认超时，导致 Fiber 永久等待管道 EOF。

**对策**：
在 `Run::wait()` 中增加基于 `Loop::delay(0.1)` 的进程状态轮询：一旦检测到主命令进程 `proc_get_status()['running'] !== true`（说明主 shell 已经退出生命周期），给予 50ms 宽限期让事件循环读取并 drain 尽缓冲区中的残留数据，随后立即结束 `$this->finished` 等待，关闭管道并安全退出，杜绝后台孙进程阻断会话。

### Web 模式下 LLM 发送失败无任何错误提示（静默吞错）

**现象**：
在 Web 界面向模型提问后，偶尔没有生成任何回复，转圈停止，界面恢复为空白且没有弹出任何红色错误提示；输入框中的内容被清空，用户误以为请求丢失或卡住。

**原因**：
1. `RpcEvents.php` 的 `agent_end` 事件此前未抽取失败消息的 `errorMessage`，输出事件为 `{"type":"agent_end","messages":[...]}`（缺少 `error` 顶层字段），而前端 `onEvent(evt)` 仅检查 `evt.error`，导致 turn 失败时错误直接穿透遗漏。
2. 流式错误事件 `ErrorEvent` 在 `RpcEvents::delta()` 中仅序列化了 `stopReason`，遗漏了 `$event->error->errorMessage`。
3. `retry_end` 重试全部耗尽失败后，前端仅隐藏横幅并刷新状态，未在聊天区渲染 `evt.error`。
4. `RpcMode::prompt()` 内部异步抛出异常时发送的错误响应缺少 `$id`，前端 `onRpcEvent()` 因无对应 pending promise 直接忽略，既未通知发送操作也未渲染到界面。
5. 前端在连接等待或发送报错时提前清空了 `promptInput.value`，导致用户输入内容在错误时被彻底销毁。

**对策**：
1. 在 `RpcEvents::encode(AgentEndEvent)` 中增加 `lastError` 提取器，将失败消息的 `errorMessage` 显式序列化至 `agent_end.error`；并在 `ErrorEvent` 的 delta 中包含 `error`。
2. 前端 `onEvent(evt)` 增加后备错误提取 `evt.error || lastErrorMessage(evt.messages)`，双重确保 `agent_end` 出错必定在聊天区输出红色 `msg-error`。
3. 前端 `retry_end` 失败时（`!evt.succeeded`）在界面输出具体重试失败错误。
4. 前端 `onRpcEvent` 增加对无 ID 或未匹配失败响应的通用捕获，终止 running 状态并调用 `appendErrorMessage`。
5. `submitMessage` 增加故障回填保护：发送或连接失败时将 `text` 和 `pendingImages` 完整还原回输入框。

### 长 quota reset 是行动提示，不是原始 provider 错误

**现象**：pi 在当前模型 quota 用尽且 reset 很久以后时显示 `Quota reached. Please wait 15h7m17s. Next: switch models or try again after reset.`；pig 只显示 provider 原始错误（例如 Antigravity/Google 的 429、`RESOURCE_EXHAUSTED`、`Your quota will reset after …` 或 Antigravity 的 `Resets in …`），用户需要自己从 JSON/长句里看出下一步。TUI 还会把同一个失败显示两次：assistant error component 一次，`InteractiveMode::onMessageEnd()` 的 `sayError()` 又一次。

**原因**：`Retry::statedDelay()` 能读 Google 的 `reset after …`，但漏了 Antigravity 真实的 `Resets in …`；`Retry::worthRetrying()` 会在超过 `MAX_STATED_WAIT` 时拒绝等待，但拒绝后没有规范化错误文案；`AgentSession` 直接 settle，UI/RPC/print/session file 都保留原始 provider 文案。更细的一层：失败 assistant 先通过 `MessageEndEvent` 写盘，再通过 `AgentEndEvent` 通知 UI，所以只在 `afterTheRun()` 改最后消息会修当前状态而漏掉 session file 和 `agent_end`。TUI 的重复是另一个 sibling 问题：`AssistantMessageComponent::update()` 已经会画 error，`onMessageEnd()` 只应在没有 streaming component 的畸形事件流里 fallback 到 `sayError()`。

**规则**：短 reset 仍然按 provider 指定时间重试；超过 `MAX_STATED_WAIT` 且错误像 quota/rate-limit 的，统一显示 pi 风格行动提示。规范化必须发生在 `MessageEndEvent` 写盘前，也必须反映到 `AgentEndEvent` 发给 UI 的 messages 上。同一个 assistant error 只能由一个 transcript component 负责显示；`sayError()` 是兜底，不是第二份渲染。

**对策**：`Retry::quotaMessage()` 负责识别长 quota reset 并格式化 `h/m/s`，`statedDelay()` 同时读 `reset after …` 和 `Resets in …`；`AgentSession::onAgentEvent()` 在 fan-out 前规范化 `MessageEndEvent` 与 `AgentEndEvent`，同时替换 agent state 的最后一条 assistant message；`InteractiveMode::onMessageEnd()` 只有 `$this->streaming === null` 时才 `sayError()`。回归测试断言不会出现 retry countdown，session state、`MessageEndEvent` 和 `AgentEndEvent` 三处都是同一句 pi 文案，并且 TUI 只显示一次。

### Web 历史回放消息块不能有第二套更窄的 wire 规则

**现象**：Web 重新打开会话或自动压缩后，若干 transcript block 与 TUI 不一致甚至直接丢失：压缩摘要仍显示旧文案、不按 `tokensBefore` 展示；自动压缩完成事件带了 summary 却没有追加块；hook 消息完全不显示；历史工具结果丢失 `details.diff`、截断提示和完整输出路径；`!command` 历史回放没有自己的块。

**原因**：Web 有两条分叉：后端 `SessionCodec` 只编码 compaction / branch / bash 三种 app message，漏掉 `HookMessage`，所以 RPC/Web 事件链上 hook 消息变成 `null`；前端历史回放又有一套手写工具卡和 summary/hook HTML，读取 `result.diff` 而不是真实的 `result.details.diff`，并把工具结果的 `details` 丢掉。这是 TUI、session file、RPC wire 和 Web 四端各自回答同一个问题。

**规则**：Web 历史回放必须消费 `SessionCodec::encode()` 的完整形状；工具卡只允许 `ToolCard` 一处决定 result 怎么画，不能在 `app.js` 再写一套 `formatToolResultBody()`；压缩、分支摘要和 hook 消息都用同一个主题 token block，而不是硬编码紫色或旧文案。自动压缩的 `auto_compaction_end.summary` 和后续 `message_end` 可能是同一个摘要，前端必须按 `role:timestamp` 去重。

**对策**：`SessionCodec` 增加 `HookMessage` 的 `role: "custom"` 编解码；Web 回放把 tool result 包成 `{content, details}` 交给 `ToolCard`，`edit` 优先显示 `details.diff`，`write` 显示写入内容，`bashExecution` 走 bash card；compaction / branch / hook 使用 `.compaction-box` + theme token，hook 消息五行预览并可点击展开；`auto_compaction_end` 追加 summary 且与 `message_end` 去重。回归测试用 PHP 实际消息生成 JSON，再由 Node 运行 Web renderer，避免测试自己发明一套 wire。

### `Auth::fresh()` 在非协程中刷新 OAuth 令牌导致 `Future::await()` 崩溃

**现象**：
当恢复历史会话（`bin/pig -c` 或 `--resume`）且上一次使用的模型是基于 OAuth 认证的提供商（如 Antigravity、Anthropic OAuth）且该 token 刚好在本地过期时，启动瞬间抛出致命崩溃：
`Pig\Ai\Utils\Oauth\OauthError: Could not renew the antigravity token: Future::await() must be called inside a coroutine; wrap the entry point in Async::run()`。

**原因**：
在 `bin/pig -c` 启动的主引导流（`CodingAgent::session()`）中尚未进入 `Async::run()`，此时处于普通的同步执行流中。`AgentSession::restoreSettings()` 此前为了校验恢复的模型是否有可用 key，调用了 `$this->keyFor($model)`，而 `keyFor()` 内部触发了 `$auth->apiKey()`。`Auth::apiKey()` 发现 access token 已过期后调用了 `$this->fresh()` 试图进行异步网络刷新，内部调用的 `Socket::connect()` 执行了 `Future::await()`，因外部无 Fiber 协程直接抛错。

**对策**：
1. 在 `Auth::fresh()` 中加入非协程保护：一旦捕获到或者检测到当前处于普通同步上下文（`Fiber::getCurrent() === null`），绝不在同步流中强行刷新网络 token，而是返回已有凭据，留待进入 `Async::run()` 真实 turn 时再安全异步换票。
2. 在 `AgentSession::restoreSettings()` 预检模型可用性时，优先使用无网络副作用的 `$this->auth->hasKeyFor($model->provider)`，不仅杜绝崩溃，更消除了启动阶段多余的远程网络请求。

### `SessionManager::open()` 必须流式读取超大会话避免 128MB OOM

**现象**：
在恢复包含数千轮历史对话或包含大量代码 diff、体积达到 50MB~65MB+ 的历史会话时，抛出 `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in SessionManager.php on line 150`。

**原因**：
`SessionManager::open()` 此前依赖 `SessionManager::lines()`，后者使用 `file($path)` 将整个 65MB、数万行的 JSONL 文件一次性读入为巨大的字符串数组，并在 `array_slice` 时触发内存倍增，瞬间吞噬超过 150MB 内存，冲破 PHP 默认的 128MB 上限。

**对策**：
将 `SessionManager::open()` 重构为逐行流式读取（`fopen` + `fgets`），读一行解析一行放入会话树并立即释放行字符串，内存占用从 150MB+ 骤降至不足 10MB（在标准 128MB 限制下仅需 1.7 秒即可无压力打开 65MB 超大历史会话）。

### `Socket::write()` 与 `read()` 遇对端关闭触发 Broken pipe Warning 导致终端刷屏与重试失效

**现象**：
在长连接通信或流式请求期间，如果服务端因超时、限流、断网或重置连接而提前关闭了 TCP/SSL 连接，客户端在调用 `Socket::write()` 写数据时，终端会被 PHP 抛出的多条原生 OpenSSL Warning 严重刷屏：
`Warning: fwrite(): SSL operation failed with code 5. OpenSSL Error messages: error:80000020:system library::Broken pipe in Socket.php on line 92`。
并且，`Socket::write()` 此前仅抛出硬编码的 `SocketError('Write failed')`，不仅丢失了底层的真实失败原因，还导致 `Retry::worthRetrying()`（匹配 `broken pipe` 词典）无法命中该网络故障，会话直接报错中断而未能自动重试。

**原因**：
`Socket.php` 中虽然定义了用于捕获 Warning 的 `capturingWarnings()` 闭包工具，但在 `Socket::write()` 和 `Socket::read()` 调用底层 PHP 内置函数 `fwrite()` 和 `fread()` 时，未包裹在 `set_error_handler` 中。当连接对端关闭导致 EPIPE/Broken pipe 时，PHP 底层直接将 OpenSSL Warning 打印到了标准错误输出，既破坏了 TUI 和 CLI 的渲染状态，又丢弃了 warning 文本，使得上层捕获到的异常信息不包含 `broken pipe` 关键字。

**对策**：
1. 在 `Socket::write()` 和 `Socket::read()` 中全面通过 `self::capturingWarnings()` 执行 `fwrite` 和 `fread`，杜绝任何 PHP Warning 泄露到终端。
2. 当写入或读取返回 `false` 时，主动调用 `$this->close()` 释放已失效连接，并将 `$warning` 详细原因追加至异常信息中（形如 `Write failed: ... Broken pipe`）。
3. 异常信息包含 `Broken pipe` 后，上层会话重试模块 `Retry::worthRetrying()` 能瞬间识别网络抖动与对端重置，从而自动触发无缝透明重试。

### `fwrite(): SSL operation failed` 零写死循环、fclose close_notify 警告泄露与网络重试补强

**现象**：
长对话、网络波动、代理服务器关闭连接或发送大 payload 时，偶发抛出 `fwrite(): SSL operation failed with code 5` / `SSL operation failed with code 1` / `Broken pipe`，或者终端突然冒出原生 PHP Warning；会话直接红字中断报错，未能进入自动重试。

**原因**：
1. **零写死循环（Zero-write loop）**：在 OpenSSL 非阻塞流上，当连接被对端提早关闭时，`fwrite` 并不总是返回 `false`，而是返回 `0`。在非阻塞模式下，已关闭的 TCP socket 在 `stream_select` 中永远处于 Writable 状态，因此 `$this->awaitReady(true)` 瞬间返回，导致 `while ($offset < $length)` 在毫秒级内空转上万次并耗尽超时，甚至导致 OpenSSL 状态机崩溃报错。且 `Socket::write()` 原先缺少对 `feof($this->stream)` 的即时检测。
2. **`fclose()` close_notify 警告泄露**：当连接已被底层异常打断时，直接执行 `fclose($stream)` 会触发 OpenSSL 尝试发送 TLS `close_notify` alert，在 Broken pipe 连接上写入直接向终端抛出裸露的 `Warning: fwrite(): SSL operation failed with code 5`。
3. **`Retry::WORDS` 正则漏判 PHP 原生网络异常**：`Retry::WORDS` 原先仅涵盖了 `broken pipe` 和 `connection reset` 等字眼。而 PHP 在发生 SSL 故障、握手失败、写失败时，异常信息通常为 `Write failed: fwrite(): SSL operation failed with code 5`、`Read failed: ...`、`TLS handshake with ... failed`、`Cannot connect to ...`。这些关键错误信息未被纳入正则，导致所有此类网络波动被误判为“不可重试的请求错误”，直接中断会话。
4. **一秒轮询片段被误当成整体写入超时**：连续零写时，代码用 `awaitReady(true, min(1.0, $stallLimit))` 等下一次可写。这样一个 30 秒或 5 秒的整体写入超时，遇到对端暂时不读、socket 一秒内没有重新可写，就会提前抛出 `Socket timed out after 1.0s`。这不是 provider 超时，而是 pig 把内部 poll slice 当成了用户可见超时；大 payload 或代理背压时尤其容易触发。

**对策**：
1. 在 `Socket::write()` 中加入前置 `feof()` 检查及连续零写（`$zeroWrites > 10`）熔断保护，遇到断开立即安全抛出 `SocketError`，杜绝死循环空转与状态机损坏。
2. 在 `Socket::close()` 中使用 `self::capturingWarnings()` 包裹 `fclose($stream)`，彻底静音已断连 SSL 的 `close_notify` 原生警告。
3. `Socket::capturingWarnings()` 改为字符串累加（`$warning .= ' ' . $message`），确保 OpenSSL 的连续复合报警（如 Code 5 叠加具体 Broken pipe）不丢失。
4. 全面扩充 `Retry::WORDS` 正则，将 `ssl.*operation failed`、`tls.*handshake.*failed`、`write failed`、`read failed`、`cannot connect`、`network.*unreachable`、`host.*unreachable`、`socket.*closed` 等原生异常全部纳入自动重试机制。遇网络抖动或 SSL 断连自动无缝重试，彻底保障长时间无人值守运行的稳定性。
5. 零写后的等待使用整体写入超时剩余值（`$stallLimit - elapsed`），而不是固定的一秒片段；`SocketTest::testAWriteStalledByAPeerThatDoesNotReadGetsTheWholeWriteTimeout` 用一个接受但不读取的本地 peer 证明旧代码约 1 秒失败，新代码等完整 1.5 秒。

### 会话树回溯 O(N²) 数组重分配与 `latestFor` 全文件扫描导致 `pig -c` 启动严重迟钝

**现象**：
在存在大体积历史会话（如数十兆、上万轮对话）的工作目录中执行 `pig -c`（恢复最新会话）时，启动首帧耗时高达 2.28 秒，而上游 `pi -c` 仅需 0.5 秒左右，慢了 3.6 倍以上。

**原因**：
剖析定位到 4 个严重的性能杀手：
1. **`SessionManager::latestFor()` 全量扫描**：此前直接调用 `listFor($cwd)`，而 `listFor` 会对排在前 30 个的历史会话全部进行完整的逐行流式 `describe()` 解析与提取。如果目录下有几个大文件，仅这一步就耗去近 400ms，而其实只需找出最新 mtime 的单个文件。
2. **`SessionManager::pathTo()` O(N²) 内存重排**：在从 leaf 回溯到 root 的 `while` 循环中，此前调用了 `array_unshift($path, $id)`。PHP 的 `array_unshift` 是 O(N) 的内存块整体搬移和全键重排，在包含 2.8 万个节点的树链路上循环执行 2.8 万次，耗时高达 718ms！
3. **`SessionManager::settings()` 全树正向扫描**：原实现先构建完整的 `pathTo($this->leaf)`，再从 root 到 leaf 正向遍历两万多个节点以查找最后一次配置变更，耗时高达 725ms。而查找最新配置理应从 `leaf` 沿 `parent` 链反向向上回溯，只要匹配到最新的 model 和 thinking 即可立即 `break` 退出。
4. **`SessionManager::resolve()` 循环解包拷贝**：此前在循环中执行 `$messages = [...$messages, ...self::edited([[$id, $item]], $edits)];`，每轮循环都对已有数组全量解包深拷贝，造成 O(N²) 的内存分配。
5. **`SessionEntries::decode()` 冗余计算**：对文件中的每一行均在最顶层无条件执行慢速的 `self::millis()`（包含 `strtotime` 与 `preg_match`），而在占文件 99% 的 `message` 行上该结果根本未被使用。

**对策**：
1. **`SessionManager::latestPathFor()` 首行轻量嗅探**：对齐上游 pi 的 `findMostRecentSession`，仅读取首行验证 header，不再全量 `describe()` 读取整个 71MB 会话，单项耗时直接从 206ms 暴跌至 1.6ms（提速 128 倍）！
2. `SessionManager::pathTo()` 改为 `$path[] = $id`（O(1) 追加）并在退出循环后执行单次 `array_reverse($path)`（O(N)），单项耗时直接从 717ms 暴跌至 3ms，提速 230 倍。
3. `SessionManager::settings()` 改为从 `leaf` 沿 `parent` 链反向向上查找，遇到最新设置立即终止，耗时从 725ms 暴跌至 0.6ms，提速 1200 倍。
4. `SessionManager::resolve()` 改为直接 `$messages[] = ...` 单条追加，消除循环解包拷贝，耗时从 30ms 降至 7ms。
5. `SessionEntries::decode()` 仅对需要时间戳的非 `message` 条目执行 `millis()`。
6. 实测 `pig -c` 在真实 71MB、2.8 万行超大会话下的首帧启动耗时从 2275ms 缩减至 **489ms**，不仅完全追平甚至在部分轮次超越了 `pi -c`（440-540ms）！

### `PtyManager::input()` / `resize()` 在已退出的终端 ID 上触发 Undefined array key Warning

**现象**：
当 Web 终端已退出或标签页关闭后，前端 xterm.js 窗口由于 resize 事件监听器或快速连击滞后发送的 `terminal_resize` / `terminal_input` 请求在服务端触发：
`PHP Warning: Undefined array key "term-..." in PtyManager.php on line 106`。

**原因**：
PHP 的空安全调用操作符 `$this->terminals[$id]?->resize()` 仅在左侧结果为 `null` 或对象时安全，如果 `$id` 在散列表数组中不存在，PHP 在计算表达式左侧 `$this->terminals[$id]` 时会首先产生未定义键警告。

**对策**：
改用 `($this->terminals[$id] ?? null)?->input($data)` 与 `($this->terminals[$id] ?? null)?->resize($cols, $rows)`，使用 null 合并运算符（`?? null`）安全访问数组元素，对已退出或未找到的终端静默忽略，彻底杜绝 Warning 泄露。

### Bash 耗时计时器（Elapsed / Took）与 `test/lint.php` 批处理加速

**现象**：
1. 在 TUI 运行长耗时命令（如用户 `!command` 或模型调用 `bash` 工具）时，bash 块内缺少执行耗时显示，无法获知具体耗时了多久（例如 `Elapsed 30.3s`、`Took 53.1s`）。
2. 执行 `php test/lint.php` 耗时高达 53.1 秒，严重拖慢提交前检查与本地流转。

**原因**：
1. 对照上游 pi `packages/coding-agent/src/core/tools/renderers/bash.ts`，pi 官方在 bash 渲染器中记录了 `startedAt` 与 `endedAt`，运行中动态显示 `Elapsed ${formatDuration}`，完成后显示 `Took ${formatDuration}`；pig 的 `ToolExecutionComponent` 此前未对齐该耗时计时器。
2. `test/lint.php` 此前在 `foreach ($files as $file)` 中对 637 个文件逐一调用 `exec(PHP_BINARY . ' -l ' . $file)` 串行启动了 637 个独立的 PHP 子进程，进程启动开销导致耗时达 53 秒。

**对策**：
1. 在 `ToolExecutionComponent` 与 `InteractiveMode` 中对齐 upstream pi 的耗时格式化器 `formatDuration()`（秒保留一位小数 `30.3s`、分秒 `3m 42s`、时分秒 `1h 15m 30s`），并在 bash 结果中渲染 muted 样式的 `Elapsed <duration>`（运行中）与 `Took <duration>`（完成后）。
2. 在 `InteractiveMode::executeBash` 中加入 500ms 刷新调度，保证即使无标准输出的命令也能平滑跳动显示当前经过时间。
3. `test/lint.php` 改用 `array_chunk($files, 100)` 批量分块执行 `php -l`，如有语法错误自动回退到逐文件定位；全量 lint 耗时由 **53.1s 暴降至 0.9s（提速 59 倍）**。

### 全屏固定底部 Dock 与滚动视口（ChatViewport & ScrollView）

**现象**：单屏流式渲染下，对话一长输入框和 Footer 就被推出屏幕。upstream pi 的 fullscreen 模式里输入框、工作指示器、状态栏始终贴在底部，往上滚时视口最后一行正中浮出 `↓ Jump to latest message · Ctrl+End`。

**结构（照 upstream 逐文件移植）**：
1. `TuiAltScreen`（`tui-alt-screen.ts`）每帧用 `Layout::renderLayoutFrame()`（`layout.ts`）把布局根排进整窗矩形；没有布局根时把 children 放进一个隐式 follow-end 的 `ScrollView`。
2. `ChatViewport::create()`（`chat-viewport.ts` 的 `createChatViewport()`）：`VStack[transcript ScrollView(basis 0, grow 1, min 1), dock VStack(basis auto)]`；dock 各项可收缩，编辑器最少 3 行。pig 的 overlay 跟 status 一槽、extension footer 跟 footer 一槽、编辑器上方的 Spacer 放在 widgetsAbove 槽。
3. `ScrollView`（`components/scroll-view.ts`）只管状态：`updateLayout()` 时跟随末尾，`scrollBy()` 返回没滚完的行数（滚轮外溢、拖选自动滚动都靠它），滚动条 `hidden|auto|always`。
4. 搜索高亮、浮条、overlay、滚动条、选择高亮都在 `TuiAltScreen::doRender()` 里按 upstream 的顺序合成；`tui.altScreen.*` 键（翻页、半页、逐行、上/下一个 prompt、搜索、顶/底）由 `TuiAltScreen::handleViewportKey()` 查 `Pig\Tui\Keybindings::getKeybindings()`，见下面的键位条目。
5. 光标：和 upstream 一样找 `TUI::CURSOR_MARKER`；叶子盒子比分到的高度高时，`Layout` 让带标记的那一行留在盒子里。

### 全屏模式滚轮失灵、底部 Dock 被顶走：AltScreen 必须开鼠标上报 + 关 autowrap

**症状**：fullscreen 下滚轮翻不动历史（Terminal.app 把滚轮变成 ↑/↓，被输入框拿去翻历史）；底部输入框偶尔整体上移一行且不再归位，调窗口高度后错位；`Ctrl+Home` 直接 fatal。

**根因**：为保住终端原生选择复制，把 `?1000h ?1002h ?1006h` 去掉了，`InteractiveMode` 里的滚轮解析成了死代码（测试直接塞 SGR 序列所以一直绿）。绘制分支没有 `?7l`，某行在终端里比 `Width::visible()` 宽一格就自动换行，底行换行会滚屏，逐行 diff 不知道；高度变化也不整屏重画。`scrollToTop()` 在 `ScrollView` 里不存在。

**避坑规则**：AltScreen 照 upstream `TuiAltScreen`：进屏 `?1049h ?7l` + 鼠标上报（tmux/screen 用 button-motion，其余 all-motion，都带 `?1004` 焦点），选择复制由 pig 自己做（`TuiAltScreen` 里的 selection + `AltScreenFlashContainer` 的 `Copied!`）；每帧逐行绝对寻址第 0..height-1 行，宽或高变了就 `\x1b[2J` 整帧重画。不要为了原生选择再关鼠标上报。

```php
$this->terminal->write(self::ENTER_ALT_SCREEN . self::DISABLE_AUTOWRAP . $mouse . "\x1b[2J\x1b[H");
// 鼠标/焦点报告可能和按键挤在同一次 read 里，TuiAltScreen::handleViewportInput() 先把它们抠出来。
```

### 向上滚动后崩溃：`Rendered line N is 172 columns wide, terminal is 171`

**症状**：fullscreen 下往上滚，`↓ Jump to latest message` 胶囊盖在含中文的那一行上时，`checkWidth()` 抛错，pig 崩溃。（现在 `TuiAltScreen` 照 upstream 把超宽行截断而不是抛错，但拼接本身仍必须对。）

**根因**：`Width::composite()` 用非 strict 的 `sliceByColumn()` 取胶囊左边的部分，一个宽字符正好跨在起始列上时被整个留下，整行多出一列。upstream 的 `compositeTuiLine()` 用 `extractSegments()` + strict slice，跨边的宽字符丢掉、用空格补齐，最后还按 `$totalWidth` 截断。

**避坑规则**：任何"把 A 盖到 B 某一列上"的拼接都走 `Width::composite()`；自己拼 before/selected/after 时三段都传 `strict: true`。

```php
$before = Width::sliceByColumn($line, 0, $start, true);
```

### TUI 拆成 upstream 的 `TUI` / `TuiBase` / `TuiMainScreen` / `TuiAltScreen`（无别名）

开发者要求 100% 对齐 upstream，出问题直接去 pi 找答案，所以旧类名不留别名。

| 旧 | 新 |
|---|---|
| `Tui`（类） | `TUI`（接口）+ `TuiBase`（公共）+ `TuiMainScreen`（regular）+ `TuiAltScreen`（fullscreen） |
| `new Tui()` + `setAltScreen()` | `TuiRenderer::createInteractiveTui()`（`tui-renderer.ts`） |
| `onInput()` | `addInputListener()` / `removeInputListener()` |
| `setDebugHandler()` | `$tui->onDebug = ...` |
| `handleInput()` / `draw()` | `handleTerminalInput()` / `doRender()` |
| `setViewportRenderer()` / `setPrimaryScrollView()` / `setCopySelection()` | `setLayoutRoot()` / 布局里 `primary: true` 的 `ScrollView` / `TuiAltScreenOptions` |
| `new ChatViewport(...)`、`renderViewport()`、`indicatorRect()` | `ChatViewport::create(...)`；浮条由 `TuiAltScreen` 合成 |
| `Caret` 接口、`caret()`、`Container::rowOf()` | `Focusable` + `public bool $focused` + `TUI::CURSOR_MARKER` |
| `ScrollView::scrollToBottom()`、`contentLines()` | `scrollToEnd()`、`LayoutBox::$scrollContentLines` |
| `TuiAltScreen::scrollPage()`、`InteractiveMode` 里查 `tui.altScreen.*` 的输入监听 | `TuiAltScreen::handleViewportKey()` + `Pig\Tui\Keybindings`（`keybindings.ts`） |
| `InteractiveMode::$tui`（具体渲染器） | `$tui` 是 `TuiRenderer::createInteractiveTuiReference()` 给的转发引用（upstream 的 `ui`），具体渲染器是 `$renderer`（upstream 的 `renderer`） |

**macOS 大小写陷阱**：`Tui.php` → `TUI.php` 只差大小写。APFS 默认不区分大小写、git 默认 `core.ignorecase=true`，`git add -A` 不会记下改名，Linux 上 PSR-4 找不到 `TUI.php`。必须 `git rm --cached packages/tui/src/Tui.php && git add packages/tui/src/TUI.php`。

### `tui.altScreen.*` 键位走 TUI 的全局注册表，不走 coding-agent 的 `Keybindings`

**结构**：upstream 的 `keybindings.ts` 是 `Pig\Tui\Keybindings`（`TUI_KEYBINDINGS`、`getKeybindings()`、`setKeybindings()`）+ `KeybindingsManager`。`TuiAltScreen` 只问注册表。coding-agent 的 `Keybindings::load()` 把 `keybindings.json` 里的 `tui.altScreen.*` 分出去，`InteractiveMode` 构造时 `TuiKeybindings::setKeybindings($keybindings->tuiKeybindings())`，和 upstream 构造函数里的 `setKeybindings()` 同一处。

**避坑规则**：`tui.editor.*`、`tui.input.*`、`tui.select.*` 还没有组件去读注册表，所以 `keybindings.json` 写它们会报"pig has no action for"，不要放开成静默接受。注册表是进程级静态的：装了自定义键位的测试在 `setUp()`/`tearDown()` 里 `Keybindings::reset()`。`actionFor()` 只认 `app.*`，否则 `searchNext` 的 `enter` 会被编辑器当成动作吃掉。

```php
TuiKeybindings::setKeybindings($this->keybindings->tuiKeybindings());
```

### 图片模块照 upstream 的 `terminal-image.ts` 改名（无别名）

| 旧 | 新 |
|---|---|
| `Images\Capabilities`（`::detect()`、`->drawsImages()`） | `Images\TerminalCapabilities`；`TerminalImage::detectCapabilities()`；`->images !== null` |
| `Images\CellSize` / `Images\ImageSize` | `Images\CellDimensions` / `Images\ImageDimensions`（值对象） |
| `ImageDimensions::of/png/jpeg/gif/webp`（解析器） | `TerminalImage::getImageDimensions/getPngDimensions/getJpegDimensions/getGifDimensions/getWebpDimensions` |
| `TerminalImage::capabilities()` / `cellSize()` / `setCellSize()` / `reset()` | `getCapabilities()` / `getCellDimensions()` / `setCellDimensions()` / `setCapabilities()` + `resetCapabilitiesCache()` |
| `TerminalImage::kitty()` / `iterm2()` / `render()` / `fallback()` / `rows()` | `encodeKitty()` / `encodeITerm2()` / `renderImage()` / `imageFallback()` / `calculateImageRows()` |
| `TuiBase::isImageLine()`、`Markdown` 里私有的 `isImageLine()` | `TerminalImage::isImageLine()` |
| `new Image($b64, $mime, ?theme, maxWidthCells, filename, size)`、`ImageTheme->fallback` | `new Image($b64, $mime, ImageTheme, ImageOptions, ?ImageDimensions)`、`ImageTheme->fallbackColor` |

**避坑规则**：全屏下 Kitty 图片只上传一次，之后用 `getKittyImagePlacement()` 换成只放置的命令（`a=p`），离屏缓存按张数/传输字节/解码字节三道上限淘汰；iTerm2 在全屏期间关掉图片（`setCapabilities` 置空，停止时恢复）。能力探测的环境变量先 `PIG_*` 再 `PI_*`，用 helper 判断，不能用 `?:`——`PIG_HYPERLINKS=0` 在 PHP 里是假值会掉到 `PI_`。测试改了全局能力就在 `tearDown()` 里 `resetCapabilitiesCache()`。

### 终端颜色查询：成批读入里挑回复

**结构**：`TuiBase::queryTerminalColors()`（返回 `Future<TerminalColors>`）、`onTerminalColorSchemeChange()`、`setTerminalColorSchemeNotifications()`（DEC 2031）照 upstream `tui.ts`；解析在 `TerminalColors::parseOscColorResponse()` / `parseTerminalColorSchemeReport()`（`terminal-colors.ts`）。

**避坑规则**：upstream 的输入已按序列切好，pig 的一次 read 里可能夹着 18 个颜色回复 + DA1 + 按键，`consumeTerminalColorReplies()` 在监听器之前按顺序挑出来，剩下的照常分发；有查询挂着时，read 末尾半截的 `\e]…`/`\e[?…` 留到下一次拼上。没有查询挂着时 OSC 回复和 DA1 是普通输入（upstream 同样）。

### 运行中切换 TUI 模式：组件拿的是转发引用，`instanceof` 只能问 `$renderer`

**结构**：照 upstream 的 `switchTuiMode()`，`/settings` 里改 TUI mode 时同一棵组件树（document、pending、status 槽、widgetsAbove 槽、editor、widgetsBelow、footer 槽）从旧渲染器卸下、挂到新渲染器上；fullscreen 另外 `setLayoutRoot(ChatViewport)`。退出时 `fullscreenExitOutput=transcript` 也是切到 regular 再 `renderNow()` 打出对话，`resume-hint` 只离开备用屏。

**避坑规则**：`InteractiveMode::$tui` 是 `TUI` 转发引用，Loader、TerminalUi、扩展拿到的都是它，切换后仍然有效；`$this->tui instanceof TuiAltScreen` 永远是 false，判断模式、调 `setLayoutRoot()`/`setCopyOnSelect()`/`frame()` 一律用 `$this->renderer`。扩展经 `TerminalUi::onTerminalInput()` 加的监听挂在旧渲染器上，切换后由 `rebindTerminalInputListeners()` 搬过去，新加输入监听的入口也要进这个登记表。有 overlay 栈条目时拒绝切换（upstream 同样）。

### 全屏鼠标：组件优先，滚轮在 overlay 聚焦时留给 overlay

**结构**：照 upstream，`TuiAltScreen::handleMouseEvent()` 依次是捕获/按下目标 → 搜索框按钮 → overlay → 浮条/滚动条 → 布局里的组件（`dispatchMouseToLayout()`，跳过用 `Container` 默认 `handleMouse()` 的栈节点）→ 文本选择。抬起时没移动就合成 `click`。OSC 8 链接按下记下 URL，原地抬起时调 `TuiAltScreenOptions::$openUrl`。

**避坑规则**：pig 的 read 是成批的，`handleViewportReport()` 返回 false 的报告（overlay 聚焦、没有组件接的滚轮）原样留在 read 里交给聚焦组件——这就是 upstream 的 `return undefined`。upstream 对 `openUrl`/右键粘贴用 catch 吞掉失败；pig 不吞，回调自己报错（`InteractiveMode::openInBrowser()` 本来就不抛）。用户消息和非工具调用的助手消息首尾带 OSC 133 `A`/`B`/`C`，`tui.altScreen.previousPrompt/nextPrompt` 靠 `A` 定位，别在渲染里剥掉它们（`TuiAltScreen` 只在合成屏幕时去掉前缀）。

### `Ansi::at()` 不认 OSC/APC，`\e]8;;\a` 被当成 4 列可见字符

**症状**：`Width::composite()` 在一个已经拼过的行上再拼，第二段落到错误的列（`left  right` 变成 `leright`）。

**根因**：`compositeTuiLine()` 在两段之间插 `\e[0m\e]8;;\a`；`Ansi::at()` 只认 CSI，`]` `8` `;` `;` 被 `sliceByColumn()` 一类按列切的函数算成 4 列。upstream 的 `ansiCodeLength()` 认 OSC（`\e]`）和 APC（`\e_`），到 BEL 或 ST 结束。

**避坑规则**：`Ansi::at()` 是所有按列切分的基础，它认的序列必须和 upstream `ansiCodeLength()` 一致。

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

## Conventions

- **A new composer dependency is the developer's call, every time.** Ask before adding one, and say what
  it would cost to write instead — something small enough to write in an afternoon gets written
  here. Zero runtime dependencies is the point of the project, not an accident of it. `partial-json`
  and `sanitize-unicode` were both replaced by a file each rather than pulled in.
- `README.md` and `README.zh-CN.md` are one document in two languages. Every change to one lands
  in the other in the same commit — a half-translated README is worse than an untranslated one.
- `declare(strict_types=1)` in every file; PSR-12; one class per file.
- `match` over `switch`, `#[\Override]` on every override, `str_contains`/`str_starts_with`,
  constructor property promotion, `readonly` for anything that should not change after construction.
- No `@` error suppression — `test/lint.php` fails the build on one. A function that warns *and*
  returns false gets a `set_error_handler` around it, because the warning text is usually the only
  place the errno appears; where the condition is knowable in advance, check it instead
  (`SessionManager::lines()` checks `is_readable` rather than suppressing `file()`).
- No silent fallback: `catch` must re-throw (`throw new X(..., $e)`). No `?? default` to paper
  over a missing value, no `clamp`/floor without a reproduced bug behind it.
- `Deferred::complete()` twice throws. Where ported code relies on a JS promise ignoring its
  second resolve, the call site guards with `isComplete()` and says so in a comment — so the
  leniency stays local instead of becoming a global rule.
- **`CHANGELOG.md` matches upstream pi's format exactly.** Every release uses the `## [x.y.z] - YYYY-MM-DD` header (e.g. `## [0.87.1] - 2026-09-22`) and groups items into four standard sections: `### New Features` (major highlights, new model workflows), `### Added` (new capabilities, options, APIs, tools), `### Changed` (behavioral updates, defaults, refactoring), and `### Fixed` (bug fixes, crash preventions, protocol corrections). Only sections with items are included, and entries clearly state what changed and why.
- **Extensions are 100% pure PHP; never bridge or depend on npm packages.** Pig stays zero-runtime-dependency beyond PHP itself. Any extension from the upstream/pi ecosystem (such as `pi-antigravity`) must be ported directly to native PHP (e.g. `pig-antigravity`) using `ExtensionApi` and scoped closures. Do not query npm registries, parse `package.json`, or shell out to `npm`.
- **UI 文案与占位符（Placeholder）极简克制原则：严禁把功能说明、快捷键提示直接堆砌塞进核心 UI 控件中（又臭又长、喧宾夺主）。** 主输入框 placeholder 保持极致简练（`Ask pig a question` / `向 pig 提问...`），绝不堆砌 `(Enter to send, Shift+Enter for new line)` 等长篇操作说明。快捷键与用法说明属于 HUD 速查窗（`⌘ Shortcuts`）、Help 文档或悬停 Tooltip，保证核心交互界面清爽纯净的高级感。
- **每次发新版必须递增小版本号，严禁覆盖已有 tag（禁止 force push tag）。** 遵循 SemVer 标准，每次发布必须增加版本号（例如 `v0.2.3` ➔ `v0.2.4`），打出全新的独立 tag 并推送到远程，绝对不使用 `git tag -f` 或 `git push -f` 强推已有 tag。
