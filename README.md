# pig — PHP AI Agent

**English** · [简体中文](README.zh-CN.md)

A PHP port of [pi](https://github.com/earendil-works/pi), the agent harness whose coding agent
runs on a famously small core. Same architecture, same file layout, written for PHP 8.3 with no
runtime dependencies — no Guzzle, no ReactPHP, no amphp, no ncurses. Just the standard library.

> **Status: it runs.** `bin/pig` is a coding agent you can talk to in a terminal: streaming
> answers, tools with live output, edits shown as diffs, Escape to interrupt, typing
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
| `pig/agent-core` | `Pig\Agent\` | Agent loop with tool calling, JSON Schema validation of tool arguments, and state — **done** |
| `pig/tui` | `Pig\Tui\` | Terminal UI with differential rendering — **done** |
| `pig/coding-agent` | `Pig\CodingAgent\` | Coding agent — **tools, prompt, interactive CLI, saved sessions, compaction, model switching, skills, hooks, custom tools and all three modes (terminal, print, RPC) done** |

`pig/async` has no counterpart upstream: JavaScript ships an event loop and PHP does not. It
exists so one `stream_select()` can wait on the model's socket and on the keyboard at the same
time, which is what makes interrupting a running turn — and typing while the model streams —
possible at all.

**Behind a proxy**, which some networks require to reach a provider at all:

```bash
bin/pig --proxy socks5://127.0.0.1:7891      # or http://127.0.0.1:7890
https_proxy=http://127.0.0.1:7890 bin/pig    # or all_proxy, or proxy.url in the settings
```

HTTP CONNECT and SOCKS5, with a username and password if the proxy wants one. TLS starts *after*
the tunnel is open and verifies the provider's certificate, so the proxy carries the bytes and can
read none of them; a hostname is handed to the proxy to resolve, because a local resolver is often
the other thing that does not work. Loopback always goes direct, `no_proxy` and `proxy.bypass` add
to that, and `--no-proxy` turns the lot off.

`CodingAgent::session()` is the whole of startup as a call: it resolves the model and the thinking
level, loads the skills, context files, hooks and custom tools, opens or creates the session file,
and hands back a session plus a list of warnings. `bin/pig` is a caller of it rather than the place
it lives, so the resolution order can be tested instead of run and looked at.

A different thing with the same name: `Agent\StreamProxy` sends the conversation to a **gateway
server** that holds the provider keys and makes the call, speaking upstream's `/api/stream` wire
format so a gateway written for pi works unchanged. Nothing turns it on — it is a `streamFn` you
pass in — because it hands the conversation to whoever runs that server, which is the point for a
team that wants no keys on laptops and a reason to avoid it otherwise.

A tool call is checked against the tool's own JSON Schema before it runs — `Ai\Utils\JsonSchema`
is a draft-07 subset with AJV's wording, because the reader of a validation failure is the model
that has to correct itself from it. An unknown keyword is ignored rather than failed, so adding a
keyword to a tool's schema can never break the tool.

## Try it

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` takes away edit, write and bash; `--theme light` for a light terminal;
`--api-key <key>` uses a key for this run only, without saving it;
`-c`/`--continue` to pick up where you left off, or `-r`/`--resume` on its own to choose from
a list — type in that list to search it, and the search matches anything said in the
conversation, not just the line it opened with. Escape there starts a new conversation instead;
ctrl+c leaves without starting one. `/resume` inside a session shows the same list, search and
all. `-h` for the flags, `-v` for the version.
`/help` inside lists the keys.

With a Claude Pro or Max subscription there is no key to set: `/login` gives you a URL to open
and takes the code that comes back. A GitHub Copilot subscription works the same way — it shows
a code to type at github.com and waits, and escape stops the waiting. Gemini CLI (Google Cloud
Code Assist) opens a browser and catches the redirect on `localhost:8085`, so port 8085 has to be
free; it needs Google's own client id and secret, which pig does not ship — set
`GEMINI_CLI_CLIENT_ID` and `GEMINI_CLI_CLIENT_SECRET`, or `geminiCli.clientId` and
`geminiCli.clientSecret` in `~/.pig/settings.json`. Antigravity is the fourth and works the same
way on port 51121, with a client id and secret of its own (`ANTIGRAVITY_CLIENT_ID` and
`ANTIGRAVITY_CLIENT_SECRET`, or `antigravity.clientId` and `antigravity.clientSecret`) — it is
what gets you Gemini 3, Claude and GPT-OSS through a Google subscription. Whichever you use, the
token is kept in `~/.pi/agent/auth.json` — pi's own file, when pi has one, so signing in once is
signing in once. `/logout` forgets it.

There is also `bin/pig-ai login` — the same four sign-ins from a plain command line, for a
machine you are setting up over ssh. `bin/pig-ai list` names them.

If that directory still has an old pi's `oauth.json` in it,
pig moves it across on the first run and says which providers it moved — the old file is renamed,
not deleted.

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

The model gets four tools by default — read, bash, edit, write — which is upstream's set;
`--tools read,grep,find,ls` names the set outright, and `--read-only` swaps in the read-only four.
Fewer tools is deliberate: a model with seven spends part of every turn choosing between them, and
searching through `bash` with `rg` is what the prompt already asks for.

`--model` takes a part of a name rather than a whole id — `--model sonnet`, `--model 'opus 4.1'` —
and `--model sonnet:high` sets the thinking level at the same time. `--models` lists the ones
you have a key for, with their context and output limits; `--models gem pro` narrows that,
fuzzily, over the provider and the id together. `/model` offers the same list, and switching to
a model with no key is refused by name rather than failing on the next turn. Ctrl+P steps to the
next model on that list without opening it, Shift+Ctrl+P back to the previous one.

171 models are known: Anthropic's, OpenAI's own on the Responses API, Gemini, and Groq,
Cerebras, xAI, Zai and Mistral, which all speak OpenAI chat-completions. Set the matching
`*_API_KEY` and `--model` reaches them.

GitHub Copilot's nineteen are there too, and Google Cloud Code Assist's five — the models those
subscriptions serve, under the same ids their own providers use. So `gpt-5` and `gemini-2.5-pro`
name two models each: a bare name means the direct provider, and a subscription's is
`--model github-copilot/gpt-5` or `--model google-gemini-cli/gemini-2.5-pro`.

`/label before the refactor` names where you are, and `/tree` shows the name beside what was
said — pi's own label entries, so a name set in either tool shows up in the other.

`/tree` goes back to an earlier point in the conversation and carries on from there. It draws the
**whole** conversation as a tree, forks and all, so the road not taken is a row you can move the
cursor onto rather than something the file merely still contains:

```
  • user: port the tree selector
    • assistant: Here is what it does…
    ├─ user: actually, do the proxy first
    │  └─ assistant: Right — starting with CONNECT…
    └─ • user: no, keep going with the tree
          • assistant: Carrying on…
```

`•` marks the path you are on. Ctrl+O cycles five filters (everything, no tool results, only what
you said, only what you named, absolutely everything), typing searches, and `l` names the row under
the cursor. Going back offers to summarise the branch you are leaving, so an hour of exploring
arrives on the branch you are joining instead of being left behind. Going back to something *you*
said takes it out of the conversation and puts it back in the prompt, to be asked differently.

Sessions are written in **pi's own format**, in pi's own directory layout — so a conversation
started in one opens in the other, and `--resume` lists what is in `~/.pi/agent/sessions/`
beside pig's own. Carrying on from a pi session appends to that file, in that format.

Resuming brings back the model and the thinking level that conversation was being had with, not
whatever a new one would open with. `--model` still wins if you name one.

`/changelog` shows what changed release by release, and new entries are shown once after an
upgrade (not when you resume a conversation).

`/export` writes the conversation out as one self-contained HTML file — markdown rendered,
code highlighted, no JavaScript in it at all.

A turn that fails because the provider is busy — a 429, a 503, a socket that died — is
**waited out and sent again**, doubling from two seconds, up to three times, with what the
provider said and a countdown on screen and escape to stop. A turn that fails because the
conversation outgrew the model's window is a different thing and is treated as one: it is
summarised first, then sent again, because the same request would be exactly as long in four
seconds. `retry.enabled: false` in the settings turns the first off.

A provider pig has never heard of goes in `~/.pig/models.json` — your own box, a proxy, a
local server — and its models then work everywhere a built-in one does, including `--model`,
`/model` and `--models`:

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "MY_BOX_KEY",
  "api": "openai-completions",
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] } } }
```

`apiKey` is the **name of an environment variable** if one answers to it, so the key itself
need not be in the file. `api` is one of `openai-completions`, `openai-responses`,
`anthropic-messages` or `google-generative-ai`, and can be set on the provider or per model;
`authHeader: true` sends the key as `Authorization: Bearer …` for a proxy that wants it there.
Anything wrong with the file is printed and skipped — the rest of it, and every built-in
model, still work. It is pi's format, and pi's own `~/.pi/agent/models.json` is read when pig
has none.

Settings live in `~/.pig/settings.json`, and a project can override them in
`.pig/settings.json`. The theme, model and thinking level you pick are remembered.
`/settings` shows what can be changed from inside a session — theme, thinking, whether
reasoning is drawn, whether pictures are drawn, whether messages you type mid-run go over one at
a time or together, auto-compact, auto-retry — with what each one is set to now. Enter changes
the row you are on and the list stays open; escape closes it.

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

A hook can also **say** something — `$pi->sendMessage('build', 'the tests are failing')` puts
it in the conversation where the model reads it, with `display: false` if it is for the model
and not for you, and `triggerTurn: true` to have the agent answer it there and then. A hook can
draw its own messages with `registerMessageRenderer()`. And `$pi->appendEntry('permissions',
$data)` writes something into the session file that the model never sees — hook state that is
still there after a restart, costing no context.

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
also `~/.claude/skills`, `.claude/skills`, `~/.codex/skills`, `~/.pi/agent/skills` and `.pi/skills`,
so a skill written for another agent — or for pi, before the move — works here unchanged. Two folders
holding the same name is an override rather than an error: `pig > pi > claude > codex`, a project
folder beats the home one, and `--skills-dir` beats all of them. `/skills` lists what was found and
where each one came from, and the startup says which file an override took the name from.

`Rpc\RpcClient` is the other end of `--mode rpc`: it starts the agent, sends the twenty-two commands
and hands back events, with every call suspending its own fiber rather than returning a promise — so
a host reads like a program that blocks, and nothing blocks.

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

**No Composer dependencies at runtime**, which includes the provider protocols: the HTTP client,
the event-stream parser and all five providers are written here. Upstream does none of that — the
Anthropic, OpenAI and Google SDKs do it for pi. That is not a preference: PHP has no official SDK
for the OpenAI or Gemini APIs at all, and the official Anthropic one streams by iterating a PSR-18
response body synchronously, which would block the single `stream_select()` that watches the model's
socket and the keyboard together — and with it Esc-to-interrupt and typing while the model streams.
CLAUDE.md has the full arithmetic, and it is a dated judgement rather than a principle.

## Development

```bash
composer install
php test/lint.php      # php -l over every file
vendor/bin/phpunit
```

Verify against the floor, not just your PHP: 8.3 rejects 8.4-only syntax at parse time, and it is
easy to reach for a feature the declared floor does not have.

`PIG_TIMING=1 bin/pig` prints what each part of starting up cost, on standard error, before the
terminal takes over.

See [CLAUDE.md](CLAUDE.md) for the porting rules, the decisions on record, and the traps found so far.

## Upstream

Ported against pi at commit `d0a4c37` (2026-01-02) — the snapshot where `agent-loop.ts` was 418
lines, before the harness grew. Class, file and method names track upstream so a diff against a
newer upstream commit stays mechanical.

## License

MIT
