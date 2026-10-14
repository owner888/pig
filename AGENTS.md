# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## This file stays under 200 lines (hard rule)

All of it goes into every request, so `AGENTS.md` holds what every task needs and an index to
`docs/dev/`; a trap, a design note or a decision goes there, never here. `AgentsFileTest` fails
the suite once this file passes 200 lines.

## This file is written in English

Every rule, trap entry, table and heading in this file and under `docs/dev/` is English — not because of the reader's
language but because the file is read by the model alongside upstream's TypeScript, both READMEs'
English half and the source comments, and a rule that exists in two languages is a rule with two
copies that can drift. Two things stay as they are: a person's words quoted verbatim (*"任务还没
完成，但是总是偶发触发系统通知"*), and CJK strings that are data (`你好`, `代码审查` in the
width tables). The developer's requests are reported in English ("the developer asked for…"),
never pasted.

## pig is a minimal agent

**The developer's standing rule, above every other decision in this file: pig stays minimal, and
nothing that muddles the logic gets added.** More tools, more options, more paths through the same
code are not free — the model spends part of every turn choosing between them, and every extra arm
is a place for the rules to disagree with each other, which is what most of the traps in
`docs/dev/traps/` turned out to be.

So a feature that upstream does not have needs a reason that survives being read out loud, and a
feature upstream *does* have arrives in upstream's shape rather than a wider one. When a choice is
between two workable designs, the one with fewer branches wins. When something is already in reach
another way — searching through `bash` rather than a third search tool — the second way does not get
added for convenience.

This is the rule that decided the built-in tool set: four by default, as upstream has it, with
`grep`, `find` and `ls` reachable by name through `--tools`.

### The developer's name does not appear in this repository

Every file here goes to git, so **the developer is "the developer"** — never a name, in source
comments, docblocks, test fixtures, `AGENTS.md`, `docs/dev/` or either README. A test that needs a home directory
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

Where the anchor does not build, and what was done about it: [docs/dev/upstream.md](docs/dev/upstream.md).

## Layout

```
packages/async/      → Pig\Async\        (pig/async)       Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           (pig/ai)          unified LLM API, HTTP/SSE, Anthropic; Extension\ for a provider an extension brings
packages/agent-core/ → Pig\Agent\        (pig/agent-core)  AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          (pig/tui)         renderer, widths, keys, editor, markdown, images, env
packages/coding-agent/ → Pig\CodingAgent\ (pig/coding-agent) tools, theme, session, interactive CLI
bin/pig                                     the entry point
```

The rest of what this section used to hold — the two `Paths` classes, `Pig\Tui\Env`, how much of
each package is ported — is in [docs/dev/design/overview.md](docs/dev/design/overview.md).

## Commands

```bash
composer install        # PHPUnit 12; the five packages/ are autoloaded by the root package
php test/lint.php       # php -l over every file (PHPUnit only parses what it loads)
vendor/bin/phpunit      # filter: vendor/bin/phpunit --filter Loop
php test/live.php       # the providers against the real endpoints — costs money, needs keys
                        # `… google` for one provider, `… google/<model-id>` for one model
php scripts/generate-models.php   # rewrite Ai\Models' rows from models.dev
                        # `--dry-run` prints them instead; `--from <file>` reads a saved api.json
php scripts/bench-tui.php [session.jsonl] [regular|fullscreen] [cols] [rows]
                        # ms and bytes per frame on a real conversation against a fake terminal
```

PHPUnit 12 is the newest release that still runs on PHP 8.3, so it is what the floor allows.
PHPUnit 13 requires 8.4 and becomes available if the floor ever moves.

`test/AssertsThrows.php` adds one assertion on top of PHPUnit: `assertThrows()` asserts a throw
mid-test and hands back the exception, which `expectException()` cannot do because it scopes to
the whole method.

The dev container has no route to packagist, so tests run on the Mac, not in the agent sandbox.

## Version floor: PHP >= 8.3

`require.php` in the root `composer.json` is the source of truth. Why 8.3, and what moving it would
change: [docs/dev/decisions.md](docs/dev/decisions.md#version-floor-php--83).

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
- **Iron rule: If PHP can solve it, use pure PHP — never bridge to other languages (Python, Node.js, etc.).** Pig's core tenet is zero runtime dependencies beyond PHP itself. If a task, tool, automation, protocol, or extension can be accomplished in PHP (via built-in streams, fibers, processes, sockets, or native platform CLI tools like `adb`, `stty`, `sips`), it must be written in pure PHP. Never introduce Python, Node.js, npm, pip, or auxiliary interpreter wrappers as middleman glue layers. Extensions are 100% pure PHP using `ExtensionApi` and scoped closures. Do not query npm/pip registries, parse foreign package formats, or shell out to auxiliary interpreters when native PHP handles the job directly.
- **UI copy and placeholders stay minimal.** Feature descriptions and shortcut hints are never piled into core controls: the main input's placeholder stays as short as `Ask pig a question` / `向 pig 提问...`, never `(Enter to send, Shift+Enter for new line)` and the like. Shortcuts and usage belong in the shortcuts HUD (`⌘ Shortcuts`), the help text or a hover tooltip, so the core interaction surface stays clean.
- **Every release bumps the patch version; an existing tag is never overwritten (no force-pushed tags).** SemVer: every release raises the version (`v0.2.3` → `v0.2.4`), gets a new tag of its own pushed to the remote, and never uses `git tag -f` or `git push -f` on an existing tag.
- **Documentation is updated before the tag, without being reminded.** Before `git tag`, go over everything user-visible in the release (commands, options, settings, slash commands, file formats, defaults) and update, in order: (1) `README.md` + `README.zh-CN.md`; (2) the site docs, `../smart-book/app/Views/pig/docs/{en,zh-cn}/*.md` (a new page also goes into `nav.json`) — pigagent.dev/docs serves them; (3) `CHANGELOG.md`'s `## Unreleased` becomes `## [x.y.z] - date`, and the file is copied to `../smart-book/app/Views/pig/CHANGELOG.md`, which the site's banner version and /changelog page read. A purely internal refactor does (3) only. The release notes say what each of the three changed, or why one was not.

## Where the rest is

This file is what every task needs; everything else is under `docs/dev/` and is read when the work
reaches it. **Before changing code in an area, read that area's traps file.** A new trap entry —
symptom, cause, rule, the code — goes at the end of its area's traps file, never here: this file
stays under 200 lines, because all of it is in every request.

- [Decisions on record](docs/dev/decisions.md) — choices made on purpose; read before "fixing"
  something that looks wrong.
- [Known traps](docs/dev/traps/README.md) — the three shapes nearly every find takes, and the
  questions to ask before reading a file against upstream.

| Area | | | Covers |
|---|---|---|---|
| tui | [design](docs/dev/design/tui.md) | [traps](docs/dev/traps/tui.md) | the terminal UI: rendering, widths, keys, the editor, themes, fullscreen |
| web | [design](docs/dev/design/web.md) | [traps](docs/dev/traps/web.md) | `pig web`: the page, the WebSocket, tabs, login, the terminal drawer, the service |
| rpc | [design](docs/dev/design/rpc.md) | [traps](docs/dev/traps/rpc.md) | `--mode rpc`, print and JSON mode, `--mode mcp` |
| providers | [design](docs/dev/design/providers.md) | [traps](docs/dev/traps/providers.md) | each provider API, the model registry, thinking levels, usage, overflow |
| auth | [design](docs/dev/design/auth.md) | [traps](docs/dev/traps/auth.md) | OAuth flows, API keys, `auth.json` |
| session | [design](docs/dev/design/session.md) | [traps](docs/dev/traps/session.md) | the session file, compaction, branches, resuming, the system prompt, retries |
| tools | [design](docs/dev/design/tools.md) | [traps](docs/dev/traps/tools.md) | built-in tools, subprocesses, command-line flags, directories and configuration |
| runtime | [design](docs/dev/design/runtime.md) | [traps](docs/dev/traps/runtime.md) | PHP and the async runtime: Fiber, Loop, Future, sockets, where PHP is not JavaScript |
| extensions | [design](docs/dev/design/extensions.md) | [traps](docs/dev/traps/extensions.md) | extensions, hooks, MCP, codemode, skills, prompt templates, the bundled extensions |
| testing | [design](docs/dev/design/testing.md) | [traps](docs/dev/traps/testing.md) | the test suite, mutation sweeps, live provider tests, packaging |
