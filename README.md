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
> carries on, which `/compact` also does on demand, and `/model` switches models mid-session.
> Skills are picked up from `~/.pig/skills` and from Claude's and Codex's folders too, and
> images a tool returns are drawn in the terminal. Hooks are PHP files that can block a
> tool, edit what the model is shown, or add commands of their own, and a folder with an
> `index.php` in it is a tool the model can call. `-p` prints one answer and exits, and
> `--mode json` or `--mode rpc` drops the terminal for JSON lines, for an editor.

## Packages

| Package | Namespace | State |
|---|---|---|
| `pig/async` | `Pig\Async\` | Event loop, futures, coroutines — **done** |
| `pig/ai` | `Pig\Ai\` | Unified LLM API — **Anthropic, OpenAI chat-completions, OpenAI Responses and Gemini all stream end to end** |
| `pig/agent-core` | `Pig\Agent\` | Agent loop with tool calling and state — **done** |
| `pig/tui` | `Pig\Tui\` | Terminal UI with differential rendering — **done** |
| `pig/coding-agent` | `Pig\CodingAgent\` | Coding agent — **tools, prompt, interactive CLI, saved sessions, compaction, model switching, skills, hooks, custom tools and all three modes (terminal, print, RPC) done** |

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
`--continue` to pick up where you left off. `/help` inside lists the keys.

Anything that is not an option is a message, and `@some/file` is read in front of it:

```bash
bin/pig "what does bin/pig do?"            # ask, then carry on in the terminal
bin/pig @src/Thing.php "why is this slow?" # the file first, then the question
bin/pig @screenshot.png "what is wrong here?"
```

An image goes in as an image, so a screenshot reaches the model without a tool call. `--` ends
the options, for a message that starts with a dash or an `@`.

Ctrl+G opens whatever is in the prompt in `$VISUAL` or `$EDITOR` and puts the result back —
for the message that turned out to be three paragraphs. It works while the model is still
answering: the editor gets the terminal, and the answer keeps arriving behind it.

`--model` takes a part of a name rather than a whole id — `--model sonnet`, `--model 'opus 4.1'` —
and `--model sonnet:high` sets the thinking level at the same time. `--models` lists them.

147 models are known: Anthropic's, OpenAI's own on the Responses API, Gemini, and Groq,
Cerebras, xAI, Zai and Mistral, which all speak OpenAI chat-completions. Set the matching
`*_API_KEY` and `--model` reaches them.

`/tree` goes back to an earlier point in the conversation and carries on from there. The road
not taken stays in the session file, so going back costs nothing and can be undone the same way
— and it offers to summarise the branch you are leaving, so an hour of exploring arrives on the
branch you are joining instead of being left behind.

`/export` writes the conversation out as one self-contained HTML file — markdown rendered,
code highlighted, no JavaScript in it at all.

A turn that fails because the provider is busy — a 429, a 503, a socket that died — is
**waited out and sent again**, doubling from two seconds, up to three times, with what the
provider said and a countdown on screen and escape to stop. A turn that fails because the
conversation outgrew the model's window is a different thing and is treated as one: it is
summarised first, then sent again, because the same request would be exactly as long in four
seconds. `retry.enabled: false` in the settings turns the first off.

Settings live in `~/.pig/settings.json`, and a project can override them in
`.pig/settings.json`. The theme, model and thinking level you pick are remembered.

A markdown file in `~/.pig/commands/` or `.pig/commands/` becomes a slash command: `review.md`
is `/review`, its body is the prompt, and `$1` and `$@` are filled from what follows.

A PHP file in `~/.pig/hooks/` or `.pig/hooks/` that returns a callable is a hook. It gets
sixteen events — every tool call and result, every turn, the context on its way to the model,
compaction, `/tree`, startup and shutdown — and can block a tool, rewrite what the model is
shown, or add a slash command of its own:

```php
<?php // ~/.pig/hooks/no-force-push.php

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

A hook runs inside pig, so it can hand back an object and reach pig's own classes — and a
hook that loops or calls `exit()` takes the session with it. Broken ones are named on the
shell at startup rather than crashing; `/hooks` lists what loaded and `--no-hooks` skips them.

A hook can also **ask**, mid-turn, and wait for the answer:

```php
$pi->on('tool_call', fn ($event, $ctx) => $ctx->ui->confirm('Let bash run?', $event->input['command'] ?? '')
    ? null
    : new ToolCallEventResult(block: true, reason: 'You said no.'));
```

The tool call parks, a picker appears, and the keystroke resumes it — the handler gets a
plain `bool` back. `select`, `input`, a multi-line `editor`, `notify`, a keyed footer line and
`custom` (draw your own component) are there too. Escape answers no, so walking away from the
question does not wave the tool through.

A folder with an `index.php` in `~/.pig/tools/` or `.pig/tools/` is a tool the model can
call — a folder, because that directory also holds the `fd` and `rg` binaries pig may have
downloaded, and a file there is one of those. Same loader as hooks, same trade-off; `/tools`
lists everything the model has and where each one came from, and `--no-tools` skips them:

```php
<?php // ~/.pig/tools/wc/index.php

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

`$ctx` is the session: the conversation so far, which model is answering, whether the agent
is busy, a way to stop it, and the same `ui` a hook gets. A tool can also be told when the
session starts, switches, jumps or ends, which is how one that keeps state rebuilds or lets
go of it. And it can draw its own call and its own result in the transcript,
so a tool whose answer is a table is not squeezed through formatting meant for files.

Skills are folders with a `SKILL.md` in them. pig reads `~/.pig/skills` and `.pig/skills`, and
also `~/.claude/skills`, `.claude/skills` and `~/.codex/skills`, so a skill written for another
agent works here unchanged. `/skills` lists what was found and where each one came from.

There are three ways in, and the terminal is only the default. `-p` says it, prints the
answer and exits — for a shell script, or a pipe:

```bash
bin/pig -p "one sentence on what this repo does" | pbcopy
bin/pig -p @error.log "what went wrong?"
```

`--mode json` is the same run with every event on standard output instead: the streaming a
terminal would draw, for something that wants to parse it. Warnings stay on standard error, so
the lines are all JSON.

```bash
bin/pig --mode json -p "..." | jq -r 'select(.type=="message_update") | .delta.delta // empty'
```

`--mode rpc` reads commands as JSON lines and never stops: for an editor, or anything else
driving pig from code.

```bash
echo '{"id":"1","type":"prompt","message":"what does bin/pig do?"}' | bin/pig --mode rpc
```

Twenty-three commands — prompt, steer, abort, switch models, compact, run a shell command,
walk the conversation tree, export — and the answer arrives as the same streaming events the terminal
draws. A hook can still **ask**: the question goes out as a `hook_ui_request` line and the tool
call parks until the host answers it, which is the terminal trick with a different transport.

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
