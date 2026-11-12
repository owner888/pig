# Extensions

Extensions add executable capabilities to the pig coding agent using 100% pure PHP. Use an extension when a workflow requires custom Agent tools, slash commands, lifecycle hooks, custom message renderers, or interactive terminal confirmation dialogs (Terminal UI).

Extensions execute directly inside the pig process with the same permissions. They can inspect prompts, tools, files, credentials, and session histories, so load extensions only from trusted sources.

### 8. Transform Markdown Before It Is Drawn (`registerMarkdownTransformer`)

A transformer rewrites Markdown just before the terminal draws it; the session and the model still see the original. It is handed the Markdown and `['messageType' => …, 'isStreaming' => …]` and returns Markdown. By default it runs on the transcript's user, assistant and thinking text; `messageTypes` lists the surfaces instead: `user`, `assistant`, `assistant-thinking`, `custom` (custom messages without a renderer, expanded skill blocks), `summary` (expanded branch and compaction summaries) and `changelog` (What's New, `/changelog`, Extension Updates).

```php
// Show :warning: shortcodes in changelogs as a symbol.
$pig->registerMarkdownTransformer(
    fn (string $markdown, array $context): string => str_replace(':warning:', '⚠', $markdown),
    ['messageTypes' => ['changelog']],
);
```

An extension has one transformer; registering again replaces it. One that throws is skipped and the next runs on what came before. It runs on every redraw, streaming updates included, so keep it cheap.

---

## Create and Load an Extension

An extension file returns a PHP closure receiving a `Pig\CodingAgent\Extensions\ExtensionApi` instance:

Create `~/.pig/agent/extensions/hello.php`:

```php
<?php

use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;

return function (ExtensionApi $pig): void {
    $pig->registerCommand('hello', function (string $args, ExtensionContext $ctx): void {
        $name = trim($args) !== '' ? trim($args) : 'world';
        $ctx->ui->notify("Hello, {$name}!");
    }, description: 'Display a friendly greeting');
};
```

Launch pig and run `/hello`. During local development, you can also load an extension on the fly:

```bash
pig --extension ./hello.php
```

---

## Extension Locations and Conventions

pig automatically discovers and loads extensions from:

1. **User Global Extensions**: `~/.pig/agent/extensions/`
   - Single-file: `~/.pig/agent/extensions/my-ext.php`
   - Multi-file directory: `~/.pig/agent/extensions/my-ext/index.php`
2. **Project Local Extensions**: `<cwd>/.pig/extensions/` or `<cwd>/extensions/`, loaded only once the project is [trusted](/docs/latest/security#understand-project-trust)
3. **Settings and packages**: paths in the `extensions` setting and extensions of installed [packages](/docs/latest/packages)
4. **CLI Temporary Flag**: `pig --extension /path/to/ext.php`
5. **Built-in extensions**: `builtin:mcp`, `builtin:llama.cpp`, `builtin:codemode`, `builtin:tool-search`, and `builtin:schedule`, loaded from pig itself (see [Settings](/docs/latest/settings#resources))

A copy of a built-in extension's folder (for example `~/.pig/agent/extensions/pig-mcp`) is not loaded and is reported as stale; the built-in loads instead.

> **Note on PHP Reload Safety**:
> Always use scoped closures and `ExtensionContext` state storage. **Never declare top-level global named classes or named functions inside extension files**, ensuring `/reload` hot-reloads cleanly without PHP fatal redeclaration crashes.

---

## Core Capabilities

### 1. Register Tools with the Model (`registerTool`)

```php
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;

$pig->registerTool(new ToolDefinition(
    name: 'fetch_stock_price',
    label: 'Get Stock Price',
    description: 'Fetch real-time stock quote for a given ticker symbol',
    parameters: [
        'type' => 'object',
        'properties' => [
            'symbol' => ['type' => 'string', 'description' => 'Ticker symbol, e.g. AAPL']
        ],
        'required' => ['symbol']
    ],
    execute: function ($id, $params, $onUpdate, $ctx) {
        $symbol = $params['symbol'];
        return new AgentToolResult([
            new TextContent("Current price for {$symbol} is $230.50")
        ]);
    }
));
```

Pass `defaultActive: false` to register a tool inactive, as `codemode` is: it stays off until `--tools`, `defaultTools` or `setActiveTools()` names it. Returning `new AgentToolResult(..., terminate: true)` from `execute` ends the agent loop after the tool batch completes (when every call in the batch returned `terminate: true`), without making another model call.

### 2. Guard Dangerous Operations (`tool_call`)

Prompt for confirmation before destructive bash commands (`rm -rf`, `git push --force`):

```php
use Pig\CodingAgent\Extensions\Results\ToolCallEventResult;

$pig->on('tool_call', function ($event, $ctx) {
    if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', 'rm -rf')) {
        $allowed = $ctx->ui->confirm('⚠️ Destructive Action', 'Detected dangerous deletion. Allow execution?');
        if (!$allowed) {
            return new ToolCallEventResult(block: true, reason: 'User rejected destructive operation.');
        }
    }
    return null;
});
```

A call that another tool made while it ran — a [`codemode`](/docs/latest/cli#how-codemode-works) script's `$tools->bash(...)` — goes through `tool_call` and `tool_result` like the model's own, with `$event->parentToolCallId` set to the calling tool's call (its `toolCallId` is then `<parent id>/<n>`). It is `null` for a call the model issued, so a gate can hold a script to a different rule:

```php
$pig->on('tool_call', function ($event, $ctx) {
    if ($event->parentToolCallId !== null && $event->toolName === 'bash') {
        return new ToolCallEventResult(block: true, reason: 'Scripts may not run shell commands.');
    }
    return null;
});
```

### 3. Task Settlement Notification (`agent_settled`)

```php
$pig->on('agent_settled', function ($event, $ctx) {
    $ctx->ui->notify('Agent task completed!');
});
```

### 4. Overrule a Cache Refresh (`cache_warming_decision`)

Before each prompt cache refresh pig asks the extensions. The event carries `warmCost`, `missCost`, `continuationProbability` and pig's own `action`; return a result to change it. The last handler with an answer wins, and a handler that throws leaves pig's answer standing.

```php
use Pig\CodingAgent\Extensions\Results\CacheWarmingDecisionEventResult;

$pig->on('cache_warming_decision', function ($event, $ctx) {
    // Never pay more than a cent to keep a cache warm.
    return $event->warmCost > 0.01 ? new CacheWarmingDecisionEventResult('stop') : null;
});
```

### 5. Register and Distribute Language Packs (`registerLocale`)

Extensions can register complete custom language packs (e.g. Japanese, Traditional Chinese, Spanish). The Web UI automatically discovers them via `/api/locales` and exposes them in the Language Settings menu:

```php
// ~/.pig/agent/extensions/pig-lang-ja/index.php
use Pig\CodingAgent\Extensions\ExtensionApi;

return function (ExtensionApi $pig): void {
    $pig->registerLocale('ja', '日本語', [
        'workspaces' => 'ワークスペース',
        'directories' => 'ディレクトリ',
        'sessions' => 'セッション',
        'new_session' => '+ 新規セッション',
        'placeholder' => '質問を入力するか、画像を貼り付けてください...（⌘+Enter で送信）',
        'accounts' => 'アカウントとクォータ',
        'copy' => 'コピー',
        'copied' => 'コピーしました！',
        'delete_session' => 'セッションを削除',
        'rename_session' => 'セッション名を変更',
        // Refer to Web UI exported JSON template for complete key lists
    ]);
};
```

### 6. Register Web UI Sidebar Panels (`registerWebPanel`)

Extensions can register sidebar panels in `pig web` (such as the Antigravity accounts and quota panel) via `registerWebPanel()`. The extension returns declarative blocks (cards, action lists with buttons, progress meters, lazy-loaded sections), rendered in the browser using the active theme and locale without running extension code client-side:

```php
$pig->registerWebPanel(
    id: 'my-panel',
    title: ['en' => 'My Panel', 'zh-CN' => '我的面板'],
    render: function (array $ctx): array {
        return [
            ['type' => 'card', 'title' => 'Overview', 'body' => 'All background services operational.'],
            ['type' => 'meter', 'label' => 'Quota Usage', 'value' => 75, 'max' => 100, 'unit' => '%'],
        ];
    },
    action: function (string $action, array $data, array $ctx, HookUi $ui): ?string {
        // Handle button actions; can ask the user via $ui (confirm, select, input...)
        if ($action === 'delete' && !$ui->confirm('Are you sure?')) {
            return null;
        }
        return 'Success!';
    },
    icon: '📊',
    commands: ['mypanel'],
    placement: 'header', // 'sidebar' (default) or 'header' (users can reposition in UI)
    label: ['en' => 'Panel', 'zh-CN' => '面板'],
);
```

### 7. Run the Built-in Tools Somewhere Else (`operations`)

The built-in tools take upstream pi's `operations`: an object that does their file or process work, so the same tool, with the same description, output and truncation, works on another machine, in a container or over SSH. Without one, a tool works on this machine.

| Tool | Operations | What it is asked |
|---|---|---|
| `read` | `ReadOperations` | `access()`, `readFile()`, `detectImageMimeType()`, `createReadStream()` |
| `write` | `WriteOperations` | `writeFile()`, `mkdir()` |
| `edit` | `EditOperations` | `access()`, `readFile()`, `writeFile()` |
| `ls` | `LsOperations` | `exists()`, `stat()`, `readdir()` |
| `find` | `FindOperations` | `exists()`, `glob()`, used instead of `fd` |
| `grep` | `GrepOperations` | `isDirectory()`; `rg` still runs on this machine, as upstream's does |
| `bash`, `powershell` | `BashOperations` | `exec(command, cwd, onData, signal, timeout, env)` |

All of them are in `Pig\CodingAgent\Tools`, each with a `Local…` class that is the default.

- The methods are synchronous. A tool runs in a fiber, so an implementation that waits on the network suspends that fiber; it does not return a promise.
- A file that cannot be had is a `FileAccessError` with its kind (`NOT_FOUND`, `NOT_ALLOWED`, `IS_DIRECTORY`, `FAILED`) and, optionally, the reason. The tool then says it in its own words, with the path as the model wrote it: `Could not write a.txt: No space left on device`.
- `exec()` takes the timeout in seconds. It throws `CommandStopped` when it was aborted or ran out of time, and it hands each chunk of output to `$onData($chunk, $stream)`, with 1 for stdout and 2 for stderr.

An extension that points `bash` and `read` at a build server over SSH:

```php
<?php

use Pig\Agent\AgentTool;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Tools\BashOperations;
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\FileAccessError;
use Pig\CodingAgent\Tools\LocalShellOperations;
use Pig\CodingAgent\Tools\ReadOperations;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\Tui\Process;

return function (ExtensionApi $pig): void {
    $host = 'deploy@build-server';
    $remoteCwd = '/srv/app';

    // bash over SSH: this machine's shell runs `ssh host 'cd … && <command>'`.
    $bash = new class ($host) implements BashOperations {
        public function __construct(private string $host)
        {
        }

        public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
        {
            $remote = 'cd ' . escapeshellarg($cwd) . ' && ' . $command;

            return LocalShellOperations::bash()->exec(
                'ssh ' . escapeshellarg($this->host) . ' ' . escapeshellarg($remote),
                sys_get_temp_dir(), // where the local ssh runs; the command runs in $cwd over there
                $onData, $signal, $timeout, $env,
            );
        }
    };

    // read over SSH: `test -r` to check, `cat` to read.
    $read = new class ($host) implements ReadOperations {
        public function __construct(private string $host)
        {
        }

        public function access(string $absolutePath): void
        {
            [$exit] = Process::run(['ssh', $this->host, 'test -r ' . escapeshellarg($absolutePath)], 30.0);

            if ($exit !== 0) {
                throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file: {$absolutePath}");
            }
        }

        public function readFile(string $absolutePath): string
        {
            [$exit, $stdout, $stderr] = Process::run(['ssh', $this->host, 'cat ' . escapeshellarg($absolutePath)], 60.0);

            if ($exit !== 0) {
                throw new FileAccessError(FileAccessError::FAILED, "Could not read {$absolutePath}", trim($stderr));
            }

            return $stdout;
        }

        public function detectImageMimeType(string $absolutePath): ?string
        {
            return null; // every file is read as text
        }

        public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable
        {
            yield $this->readFile($absolutePath);
        }
    };

    // The built-in tool with the operations, registered under the built-in's name, which replaces it.
    $register = static function (AgentTool $tool) use ($pig): void {
        $definition = $tool->definition();
        $pig->registerTool(new ToolDefinition(
            name: $definition->name,
            label: $tool->label(),
            description: $definition->description,
            parameters: $definition->parameters,
            execute: static fn (string $id, array $params, ?Closure $onUpdate, $ctx, ?AbortSignal $signal = null)
                => $tool->execute($id, $params, $signal, $onUpdate),
        ));
    };

    $register(new BashTool($remoteCwd, operations: $bash));
    $register(new ReadTool($remoteCwd, operations: $read));
};
```

A tool registered with a built-in's name replaces the built-in. For something lighter, `BashTool`'s `spawnHook` can rewrite the command into `ssh host …` without any operations.

---

## Extension References

- **[pig-computer](/docs/latest/computer)**: native macOS desktop control (screenshots, mouse, keyboard, apps) for Computer Use. A package of its own: `pig install git:github.com/owner888/pig-computer`.
- **[pig-web-search](https://github.com/owner888/pig-web-search)**: DuckDuckGo search (`web_search`), a page's readable text (`fetch_web_page`), the live DOM from a headless Chrome (`browse_web_page`), and `/search`. A package of its own: `pig install git:github.com/owner888/pig-web-search`.
- **[pig-vless](/docs/latest/vless)**: a VLESS inbound beside the agent so a phone can proxy through this machine (TCP, optional TLS).
- **[pig-antigravity](https://github.com/owner888/pig-antigravity)**: the Antigravity provider (Gemini and Claude models through a Google account), multi-account rotation, quota tracking, Google Search grounding and image generation. A package of its own: `pig install git:github.com/owner888/pig-antigravity`.
- **[pig-gemini-video](https://github.com/owner888/pig-gemini-video)**: hands a local video to Gemini and brings its answer back as text, whatever model is in use (`analyze_video` and `/gemini-video`). Uses a Gemini API key, or the Antigravity sign-in when `pig-antigravity` is installed too. A package of its own: `pig install git:github.com/owner888/pig-gemini-video`.
- **[pig-android-use](/docs/latest/android)**: operate an Android phone over ADB — view tree, taps, typing, screenshots, and a background screen the person does not see (`android_*` tools and `/android`). A package of its own: `pig install git:github.com/owner888/pig-android-use`.
- **[pig-permission-gate](https://github.com/owner888/pig-permission-gate)**: stands between the model and `bash` — blocks `rm -rf /` and `~`, asks before `sudo`, `dd`, `git reset --hard` and the like, and turns `rm` into a move to the Trash (or `_to_delete/` where there is no `trash`). A package of its own: `pig install git:github.com/owner888/pig-permission-gate`.

`pig-computer`, `pig-android-use`, `pig-antigravity`, `pig-gemini-video`, `pig-permission-gate` and `pig-web-search` are packages, installed with `pig install`; the other extensions that are not built in are copied into `~/.pig/agent/extensions/` by `pig update --extensions`.
