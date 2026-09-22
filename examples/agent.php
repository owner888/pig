#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The coding agent, without a UI.
 *
 *   ANTHROPIC_API_KEY=sk-ant-... php examples/agent.php "what does Truncate::tail do?"
 *   PIG_MODEL=claude-sonnet-4-5 php examples/agent.php "add a test for X" --write
 *
 * Read-only by default, because the first thing anyone does with a coding agent is point
 * it at a repository they care about. Pass --write to give it edit, write and bash.
 *
 * Everything under this is pig's own: the tools, the agent loop, the HTTP, the event
 * loop. Ctrl-C stops a turn mid-flight, including a bash command it had started.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Tui\Style;
use Pig\Tui\Width;

$arguments = array_slice($argv, 1);
$canWrite = in_array('--write', $arguments, true);
$prompt = implode(' ', array_values(array_filter($arguments, static fn (string $a): bool => $a !== '--write')));

if ($prompt === '') {
    fwrite(STDERR, "usage: php examples/agent.php [--write] \"what you want done\"\n");

    exit(1);
}

if (getenv('ANTHROPIC_API_KEY') === false) {
    fwrite(STDERR, Style::red("ANTHROPIC_API_KEY is not set.\n"));

    exit(1);
}

$cwd = getcwd() ?: '.';
$tools = $canWrite ? ToolSet::ALL : ToolSet::READ_ONLY;

$agent = CodingAgent::create(
    CodingAgent::model(getenv('PIG_MODEL') ?: 'claude-haiku-4-5-20251001'),
    $cwd,
    $tools,
);

echo Style::dim(($canWrite ? 'read-write' : 'read-only') . ' · ' . implode(', ', $tools) . " · {$cwd}\n\n");

/** Everything the model would read in a block of content, as one string. */
$text = static function (array $content): string {
    $out = '';

    foreach ($content as $block) {
        if ($block instanceof TextContent) {
            $out .= $block->text;
        }
    }

    return $out;
};

$agent->subscribe(static function (AgentEvent $event) use ($text): void {
    if ($event instanceof ToolExecutionStartEvent) {
        $summary = $event->arguments['command']
            ?? $event->arguments['pattern']
            ?? $event->arguments['path']
            ?? '';

        echo Style::cyan('  · ' . $event->toolName), ' ', Style::dim(Width::truncate((string) $summary, 70)), "\n";

        return;
    }

    if ($event instanceof ToolExecutionEndEvent) {
        $said = $text($event->result->content);

        if ($event->isError) {
            // In full, and every line of it. An error is the one tool result whose
            // whole purpose is to tell someone what to do next, and trimming it to
            // one line is how "install it with ..." gets cut off.
            foreach (explode("\n", rtrim($said)) as $line) {
                echo Style::red('    ✗ '), $line, "\n";
            }

            return;
        }

        // A success is summarised: the model has read all of it, and a thousand lines
        // of output here would bury the answer underneath.
        echo Style::dim('    → '), Style::dim(Width::truncate((string) strtok($said, "\n"), 70)), "\n";

        return;
    }

    if ($event instanceof MessageEndEvent && $event->message instanceof AssistantMessage) {
        $said = $text($event->message->content);

        if (trim($said) !== '') {
            echo "\n", $said, "\n";
        }

        return;
    }

    if ($event instanceof AgentEndEvent) {
        echo "\n";
    }
});

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use ($agent): void {
        $agent->abort();
    });
}

Async::run(static fn () => $agent->prompt($prompt));

if ($agent->state->error !== null) {
    fwrite(STDERR, Style::red($agent->state->error) . "\n");

    exit(1);
}
