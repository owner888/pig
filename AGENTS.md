# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## About this file

All of it goes into every request, so it holds what every task needs and an index to `docs/dev/`;
traps, design notes and decisions go there. It stays under 200 lines (`AgentsFileTest`).

Everything in it and under `docs/dev/` is English, so no rule exists in two languages. A person's
words quoted verbatim and CJK strings that are data stay as they are; the developer's requests are
reported in English ("the developer asked for…").

## pig is a minimal agent

**The developer's standing rule, above every other: pig stays minimal, and nothing that muddles the
logic gets added.** Every extra tool, option or path costs the model a choice each turn and is a
place for the rules to disagree. A feature upstream lacks needs a reason that survives being read
out loud; one upstream has arrives in upstream's shape. Between two workable designs, fewer
branches wins; what is already in reach another way is not added for convenience.

**The developer is "the developer"** — never a name, in any file here (source, tests, fixtures,
docs). Fixtures use `/Users/dev/...`, `acme`, `dev@example`.

## Upstream

**Port against the latest pi** — upstream `main`, not a pinned commit. Read the file as it is on
upstream `main` before porting or "fixing" anything; a difference from it is a bug unless
`docs/dev/decisions.md` or the code's own comment records it as the developer's choice. Where pig
cannot follow upstream and what it does instead: [docs/dev/upstream.md](docs/dev/upstream.md).

## Layout

```
packages/async/      → Pig\Async\        Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           unified LLM API, HTTP/SSE, providers
packages/agent-core/ → Pig\Agent\        AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          renderer, widths, keys, editor, markdown, images, env
packages/coding-agent/ → Pig\CodingAgent\ tools, theme, session, interactive CLI, web
bin/pig                                  the entry point
```

More: [docs/dev/design/overview.md](docs/dev/design/overview.md).

## Commands

```bash
php test/lint.php       # php -l over every file
vendor/bin/phpunit      # --filter Loop for one
```

The live provider tests, the model catalogue scripts, the TUI bench and the evals are in
[docs/dev/design/testing.md](docs/dev/design/testing.md) and [docs/dev/release.md](docs/dev/release.md).
PHP >= 8.3 (`require.php` in `composer.json`; why: [decisions](docs/dev/decisions.md#version-floor-php--83)).

## Conventions

- **A new composer dependency is the developer's call, every time.** Ask first and say what writing
  it would cost; something an afternoon can write gets written here.
- **Pure PHP only**: never Python, Node.js or another interpreter as glue; native CLI tools (`adb`,
  `stty`, `sips`) are fine. Extensions are pure PHP on `ExtensionApi`.
- `README.md` and `README.zh-CN.md` are one document: a change to one lands in the other in the
  same commit.
- `declare(strict_types=1)`, PSR-12, one class per file; `match`, `#[\Override]`, promoted and
  `readonly` properties.
- No `@` suppression (`test/lint.php` fails on one): check the condition first, or wrap the call in
  `set_error_handler` to keep the warning text.
- No silent fallback: `catch` re-throws (`throw new X(..., $e)`); no `?? default` over a missing
  value, no clamp or floor without a reproduced bug behind it.
- A plain HTTP request that may be retried goes through `Pig\Ai\Http\Retrying::fetch()`; model
  requests keep `ProviderRetry` and `Retry`, as upstream.
- `Deferred::complete()` twice throws; ported code that relies on a promise ignoring a second
  resolve guards with `isComplete()` and says so.
- `CHANGELOG.md` entries go under `## Unreleased`, in upstream's sections (`New Features`, `Added`,
  `Changed`, `Fixed`), each saying what changed and why ([format](docs/dev/release.md#changelog-format)).
- UI copy stays minimal: placeholders like `Ask pig a question`, shortcuts in the HUD or help.
- **Never commit, tag or push** unless the developer reviewed the change and said to. Releasing:
  [docs/dev/release.md](docs/dev/release.md).
- **What pig can do is in the docs in the same change**, because the model reads them, not the
  source: a new tool, setting, command or option gets its line in the site page (`cli.md`,
  `settings.md`, …, zh-cn too) with its default and how to turn it on or off. `docs/*.md` is
  copied from the site by `scripts/sync-docs.php`, never edited by hand.

## Where the rest is

**Before changing code in an area, read that area's traps file.** A new trap — symptom, cause,
rule, the code — goes at the end of it, never here.

- [Decisions on record](docs/dev/decisions.md) — read before "fixing" something that looks wrong.
- [Known traps](docs/dev/traps/README.md) — the shapes nearly every find takes.

| Area | | | Covers |
|---|---|---|---|
| tui | [design](docs/dev/design/tui.md) | [traps](docs/dev/traps/tui.md) | rendering, widths, keys, the editor, themes, fullscreen |
| web | [design](docs/dev/design/web.md), [UI sizes](docs/dev/design/web-ui.md) | [traps](docs/dev/traps/web.md) | `pig web`; **read the UI sizes before any CSS** |
| rpc | [design](docs/dev/design/rpc.md) | [traps](docs/dev/traps/rpc.md) | `--mode rpc`, print, JSON, `--mode mcp` |
| providers | [design](docs/dev/design/providers.md) | [traps](docs/dev/traps/providers.md) | provider APIs, the model registry, thinking, usage |
| auth | [design](docs/dev/design/auth.md) | [traps](docs/dev/traps/auth.md) | OAuth, API keys, `auth.json` |
| session | [design](docs/dev/design/session.md) | [traps](docs/dev/traps/session.md) | the session file, compaction, branches, the system prompt |
| tools | [design](docs/dev/design/tools.md) | [traps](docs/dev/traps/tools.md) | built-in tools, subprocesses, flags, configuration |
| runtime | [design](docs/dev/design/runtime.md) | [traps](docs/dev/traps/runtime.md) | Fiber, Loop, Future, sockets, where PHP is not JavaScript |
| extensions | [design](docs/dev/design/extensions.md) | [traps](docs/dev/traps/extensions.md) | extensions, hooks, MCP, codemode, skills, bundled extensions |
| testing | [design](docs/dev/design/testing.md) | [traps](docs/dev/traps/testing.md) | the suite, mutation sweeps, live tests, packaging |
