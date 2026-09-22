#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ask a model something and watch the answer arrive.
 *
 *   ANTHROPIC_API_KEY=sk-ant-... php examples/ask.php "why is the sky blue?"
 *   PIG_MODEL=claude-sonnet-4-5 php examples/ask.php
 *
 * The whole stack under this is pig's own: one non-blocking TLS socket, HTTP/1.1 by
 * hand, SSE parsed as it arrives, coroutines on top of Fiber. Ctrl-C mid-answer and the
 * abort path is the one the TUI will use.
 */

use Pig\Ai\Context;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;

require __DIR__ . '/../vendor/autoload.php';

$prompt = $argv[1] ?? 'In one sentence: what is a coroutine?';
$modelId = getenv('PIG_MODEL') ?: 'claude-haiku-4-5-20251001';

$model = Models::get($modelId);

if ($model === null) {
    fwrite(STDERR, "No model called '{$modelId}'.\n");

    exit(1);
}

$controller = new AbortController();

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use ($controller): void {
        $controller->abort('interrupted');
    });
}

$started = microtime(true);
$firstToken = null;

Async::run(static function () use ($model, $prompt, $controller, $started, &$firstToken): void {
    $stream = Stream::simple(
        $model,
        new Context([new UserMessage($prompt)], 'Answer in plain prose. Be brief.'),
        new SimpleStreamOptions(signal: $controller->signal),
    );

    foreach ($stream as $event) {
        if ($event instanceof ThinkingDeltaEvent) {
            fwrite(STDERR, $event->delta);
        }

        if ($event instanceof TextDeltaEvent) {
            $firstToken ??= microtime(true) - $started;
            echo $event->delta;
        }

        if ($event instanceof ErrorEvent) {
            fwrite(STDERR, "\n\n[{$event->reason->value}] {$event->error->errorMessage}\n");
        }
    }

    $message = $stream->result()->await();

    printf(
        "\n\n— %s · %d in / %d out (%d cached) · first token %.2fs · total %.2fs\n",
        $message->stopReason->value,
        $message->usage->input,
        $message->usage->output,
        $message->usage->cacheRead,
        $firstToken ?? 0.0,
        microtime(true) - $started,
    );
});

Loop::reset();
