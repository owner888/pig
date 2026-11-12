# PHP SDK Guide

`pigagent/pig` is an AI coding agent engine built in 100% pure PHP. You can install it via standard Composer dependencies to embed pig agent sessions, LLMs, tools, Skills, and context engineering directly into any PHP 8.3+ application.

Native in-process integration driven by type-safe, object-oriented PHP APIs.

---

## Installation

```bash
composer require pigagent/pig
```

Ensure standard PHP extensions are installed: `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pcntl`, `ext-pcre`.

pig's I/O runs on PHP `Fiber`s and one event loop. Every call that talks to a model or runs a tool must happen inside `Pig\Async\Async::run()`, which starts the loop and returns once the closure and everything it started have finished.

---

## Quickstart: Creating an Agent Session

`CodingAgent::session()` assembles a session the way `bin/pig` does: it discovers workspace context (`AGENTS.md` / `CLAUDE.md`, `SYSTEM.md`, skills, prompt templates, and extensions) and resolves the model from the arguments and the settings. The settings and the credentials are passed in, so the caller decides where they come from:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\CustomModels;
use Pig\CodingAgent\Settings;

Async::run(static function (): void {
    $cwd = getcwd();

    // 1. Settings (~/.pig/agent/settings.json and .pig/settings.json) and credentials
    //    (auth.json, then the providers' environment variables)
    $settings = Settings::load($cwd);
    $auth = Auth::discover(settings: $settings);

    // Optional: models.json, so custom providers and models resolve
    CustomModels::discover()->install($auth);

    // 2. The session
    $started = CodingAgent::session(
        $cwd,
        $settings,
        $auth,
        model: 'claude-sonnet-4-5',
        thinking: 'medium',
    );

    foreach ($started->warnings as $warning) {
        fwrite(STDERR, "warning: {$warning}\n");
    }

    $session = $started->session;

    // 3. Send a prompt; returns once the turn, its tool calls, and any queued messages are done
    $session->prompt('List files in the current directory and check for issues');

    // 4. The latest assistant text
    echo $session->lastAssistantText() . PHP_EOL;

    // 5. Drop listeners and let go of the agent
    $session->dispose();
});
```

`model` takes the same patterns as `--model` (`sonnet`, `anthropic/claude-sonnet-4-5`, `sonnet:high`); `provider` restricts the lookup. Other named arguments mirror the command line: `tools`, `excludeTools`, `noTools`, `readOnly`, `models`, `resume` (a session file), `continue`, `save`, `sessionId`, `systemPrompt`, `appendSystemPrompt`, `extensionPaths`, `skillPaths`, `promptTemplatePaths`, `withExtensions`, `withSkills`, `withPromptTemplates`, `withContextFiles`, and `projectTrusted`. `Settings::load($cwd, projectTrusted: false)` and `projectTrusted: false` keep the project's own files out.

`CodingAgent::session()` throws `CodingAgentError` with a message worth showing as is, for example when no model has credentials.

`$started` (a `StartedSession`) also carries the resolved `model`, `thinking`, `contextFiles`, `skills`, `extensionRunner`, `customTools`, `store`, and `extensions`.

---

## Lightweight Core: Custom Agent (In-Memory / Read-Only)

If you need a lightweight agent in daemon workers, Web APIs, or ad-hoc scripts, `CodingAgent::create()` builds a bare `Pig\Agent\Agent` with pig's tools and system prompt, without settings, sessions, or extensions:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pig\Agent\ThinkingLevel;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Ai\Models;
use Pig\Async\Async;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Tools\ToolSet;

// Resolve a model from the catalog
$model = Models::find('anthropic', 'claude-sonnet-4-5');

// Read-only agent: read, grep, find, ls
$agent = CodingAgent::create(
    model: $model,
    cwd: '/path/to/project',
    tools: ToolSet::READ_ONLY,
    apiKey: getenv('ANTHROPIC_API_KEY') ?: null,
    thinking: ThinkingLevel::High,
);

// Subscribe to execution lifecycle events
$agent->subscribe(static function ($event): void {
    if ($event instanceof ToolExecutionStartEvent) {
        echo "Executing tool: {$event->toolName}...\n";
    }
    if ($event instanceof ToolExecutionEndEvent) {
        echo "Tool {$event->toolName} " . ($event->isError ? 'failed' : 'completed') . "\n";
    }
});

// Run the prompt
Async::run(static fn () => $agent->prompt('Find all files with TODO comments'));

if ($agent->state->error !== null) {
    fwrite(STDERR, $agent->state->error . "\n");
}
```

`ToolSet::CODING` (the default) is `read`, `bash`, `edit`, `write`; `ToolSet::READ_ONLY` is `read`, `grep`, `find`, `ls`; `ToolSet::ALL` adds `powershell` and the rest. `CodingAgent::model($id)` looks a model up by ID alone. See [`examples/agent.php`](https://github.com/owner888/pig/blob/main/examples/agent.php) for a complete script.

---

## Session Persistence & Tree Management (SessionManager)

By default, `CodingAgent::session()` saves conversations to append-only JSONL tree files under `~/.pig/agent/sessions/`.

### 1. In-Memory Sessions

```php
$started = CodingAgent::session(
    $cwd,
    $settings,
    $auth,
    save: false, // nothing is written; the session still has an ID (sessionId: sets it)
);
```

### 2. Time-Travel & Branch Navigation (/tree)

`AgentSession` supports non-destructive branching within the same session log. `goTo()` needs a saved session:

```php
// The current branch, root to leaf
$branch = $session->store()->branch();

// Jump back to a historical entry ID (returns a TreeJump)
$jump = $session->goTo($targetEntryId, summarise: true);

if ($jump->cancelled) {
    echo "An extension cancelled the navigation\n";
} elseif ($jump->moved) {
    echo "Jumped to historical entry.\n";

    if ($jump->summary !== null) {
        echo "Branch summary:\n" . $jump->summary->summary . "\n";
    }

    // Going back to a user message lands before it and hands its text back
    if ($jump->editorText !== null) {
        echo "Re-ask: {$jump->editorText}\n";
    }
}
```

`goTo()` also takes `instructions`, `replaceInstructions`, and `label`, as RPC's `navigate_tree` does.

### 3. Session Compaction (compact)

pig compacts automatically when the context passes the threshold. To compact manually:

```php
// Returns the CompactionSummary entry it wrote, or null when it was called off
$compaction = $session->compact('Focus on database schema and authentication changes');

if ($compaction !== null) {
    echo "Compacted {$compaction->tokensBefore} tokens into:\n{$compaction->summary}\n";
}
```

`compact()` throws `AgentError` when the agent is working, there is nothing to compact, an extension cancelled it (`Compaction cancelled`), or the model fails.

---

## Codemode and MCP

SDK sessions do not load the built-in extensions (`builtin:mcp`, `builtin:llama.cpp`, `builtin:codemode`, `builtin:tool-search`, `builtin:schedule`). Pass the ones you want in `extensionPaths`, and wire the runner so extensions see `session_start`, which is when the MCP extension connects its servers:

```php
use Pig\CodingAgent\Extensions\BuiltinExtensions;
use Pig\CodingAgent\Extensions\Events\SessionStartEvent;
use Pig\CodingAgent\Extensions\NoUi;

$started = CodingAgent::session($cwd, $settings, $auth, extensionPaths: array_filter([
    BuiltinExtensions::pathOf('mcp'),          // mcp.json servers
    BuiltinExtensions::pathOf('codemode'),     // `codemode` / `codemode-deferred` exposure
    BuiltinExtensions::pathOf('tool-search'),  // `deferred` exposure
]));

$started->extensionRunner->wire($started->session, new NoUi());
$started->extensionRunner->emit(new SessionStartEvent());
```

`Pig\CodingAgent\PrintMode` does this wiring for you: `(new PrintMode($session, 'text', $started->extensionRunner, $started->customTools))->run(['your prompt'])` prompts, prints the answer, and returns the exit code `pig -p` would.

---

## Concurrency & Interrupt Control

`pig` is powered by native PHP `Fiber` coroutines and `stream_select`. Long-running operations can be interrupted cleanly:

```php
// From another fiber, a signal handler, or a hook: stop the prompt in progress.
// abort() returns a Future that resolves once the session is idle.
$session->abort()->await();

$session->isIdle(); // true
```

`steer($text)` and `followUp($text)` queue messages while a prompt runs; `prompt($text, streamingBehavior: 'steer')` does the same for a prompt sent mid-turn.

---

## Message Types Reference

- **`UserMessage`**: User turns with text (`TextContent`) and multimodal attachments (`ImageContent`).
- **`AssistantMessage`**: Model responses containing text, reasoning blocks (`ThinkingContent`), and tool calls (`ToolCall`).
- **`ToolResultMessage`**: Tool execution output.
- See [Message Types](/docs/latest/message-types) for complete contracts.
