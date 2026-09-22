# pig — PHP AI Agent

**English** · [简体中文](README.zh-CN.md)

A PHP port of [pi](https://github.com/earendil-works/pi), the agent harness whose coding agent
runs on a famously small core. Same architecture, same file layout, written for PHP 8.3 with no
runtime dependencies — no Guzzle, no ReactPHP, no amphp, no ncurses. Just the standard library.

> **Status: it runs.** `bin/pig` is a coding agent you can talk to in a terminal: streaming
> answers, seven tools with live output, edits shown as diffs, Escape to interrupt, typing
> while it works, and `!cmd` to run a shell command the model can then see (`!!cmd` keeps it
> out of the conversation). Sessions are saved as they happen — `--continue` picks up the
> last one, `/resume` picks from a list. When the context fills it summarises itself and
> carries on, which `/compact` also does on demand. What is missing is a model picker.

## Packages

| Package | Namespace | State |
|---|---|---|
| `pig/async` | `Pig\Async\` | Event loop, futures, coroutines — **done** |
| `pig/ai` | `Pig\Ai\` | Unified LLM API — **Anthropic streams end to end**; other providers pending |
| `pig/agent-core` | `Pig\Agent\` | Agent loop with tool calling and state — **done** |
| `pig/tui` | `Pig\Tui\` | Terminal UI with differential rendering — **done** |
| `pig/coding-agent` | `Pig\CodingAgent\` | Coding agent — **tools, prompt, interactive CLI, saved sessions and compaction done**; no model registry yet |

`pig/async` has no counterpart upstream: JavaScript ships an event loop and PHP does not. It
exists so one `stream_select()` can wait on the model's socket and on the keyboard at the same
time, which is what makes interrupting a running turn — and typing while the model streams —
possible at all.

## Try it

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` takes away edit, write and bash; `--theme light` for a light terminal;
`--model <id>` to talk to something else; `--continue` to pick up where you left off.
`/help` inside lists the keys.

`examples/ask.php` is the same stack with no UI at all:

```bash
ANTHROPIC_API_KEY=sk-ant-... php examples/ask.php "why is the sky blue?"
```

Everything under that line is pig's own: one non-blocking TLS socket, HTTP/1.1 written by hand,
SSE parsed as it arrives, coroutines on `Fiber`. Ctrl-C mid-answer exercises the same abort path
the TUI uses.

## Requirements

PHP >= 8.3 with `ext-json`, `ext-mbstring`, `ext-openssl`, and `ext-pcntl` for the terminal UI.
`stty` is the one external binary that is required. `fd` and `rg` are needed by the `find` and
`grep` tools, and pig downloads them into `~/.pig/tools/` on first use if they are not installed
(`PIG_OFFLINE=1` turns that off).

`Fiber` arrived in PHP 8.1 and everything rests on it, so 8.1 is the absolute floor. 8.3 is the
floor actually declared: 8.1 is end-of-life and 8.2 loses security support at the end of 2026.

## Development

```bash
composer install
php test/lint.php      # php -l over every file
vendor/bin/phpunit
```

Verify against the floor, not just your PHP: 8.3 rejects 8.4-only syntax at parse time, and it is
easy to reach for a feature the declared floor does not have.

See [CLAUDE.md](CLAUDE.md) for the porting rules, the decisions on record, and the traps found so far.

## Upstream

Ported against pi at commit `d0a4c37` (2026-01-02) — the snapshot where `agent-loop.ts` was 418
lines, before the harness grew. Class, file and method names track upstream so a diff against a
newer upstream commit stays mechanical.

## License

MIT
