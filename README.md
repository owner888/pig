# pig — PHP AI Agent

**English** · [简体中文](README.zh-CN.md)

A PHP port of [pi](https://github.com/earendil-works/pi), the agent harness whose coding agent
runs on a famously small core. Same architecture, same file layout, written for PHP 8.3 with no
runtime dependencies — no Guzzle, no ReactPHP, no amphp, no ncurses. Just the standard library.

> **Status: early.** The agent loop runs end to end — prompts, streaming answers, tool calls,
> steering mid-run — and the terminal UI is complete: differential rendering, a multi-line
> editor with history and completion, markdown, and inline images. What is missing is the
> coding agent's own front end. All seven tools work — read, write, edit, bash, grep, find,
> ls — and `examples/agent.php` runs them end to end; what is missing is the interactive CLI.

## Packages

| Package | Namespace | State |
|---|---|---|
| `pig/async` | `Pig\Async\` | Event loop, futures, coroutines — **done** |
| `pig/ai` | `Pig\Ai\` | Unified LLM API — **Anthropic streams end to end**; other providers pending |
| `pig/agent-core` | `Pig\Agent\` | Agent loop with tool calling and state — **done** |
| `pig/tui` | `Pig\Tui\` | Terminal UI with differential rendering — **done** |
| `pig/coding-agent` | `Pig\CodingAgent\` | Coding agent — **all seven tools and the prompt done**; CLI pending |

`pig/async` has no counterpart upstream: JavaScript ships an event loop and PHP does not. It
exists so one `stream_select()` can wait on the model's socket and on the keyboard at the same
time, which is what makes interrupting a running turn — and typing while the model streams —
possible at all.

## Try it

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... php examples/ask.php "why is the sky blue?"
```

Everything under that line is pig's own: one non-blocking TLS socket, HTTP/1.1 written by hand,
SSE parsed as it arrives, coroutines on `Fiber`. Ctrl-C mid-answer exercises the same abort path
the TUI will use.

## Requirements

PHP >= 8.3 with `ext-json`, `ext-mbstring`, `ext-openssl`, and `ext-pcntl` for the terminal UI.

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
