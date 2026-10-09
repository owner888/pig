#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * How long a frame takes, on a real conversation, against a fake terminal.
 *
 *   php scripts/bench-tui.php [session.jsonl] [regular|fullscreen] [columns] [rows]
 *
 * With no session given, the largest file under `~/.pig/agent/sessions` is used. Nothing is
 * written anywhere: `PIG_HOME` is pointed at a temporary directory, the provider is never called,
 * and the terminal is `FakeTerminal`, which only counts bytes.
 *
 * What is measured, each as wall time per frame and bytes written per frame:
 *   start     — the first draw, with the whole transcript replayed
 *   keystroke — one character typed into the prompt, 20 times
 *   resize    — the width changing by one column, and back, 10 times
 *   expand    — ctrl+o on, then off
 *   scroll    — page up / page down, fullscreen only
 *
 * The numbers are what to read before touching anything: a 1.5s keystroke on a resumed session
 * was found this way, and was cache, not drawing.
 */

use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Tui\Test\FakeTerminal;

require __DIR__ . '/../vendor/autoload.php';

// An empty first argument means "the default", so the mode can be given on its own.
$sessionPath = ($argv[1] ?? '') !== '' ? $argv[1] : null;
$tuiMode = $argv[2] ?? 'regular';
$columns = (int) ($argv[3] ?? 120);
$rows = (int) ($argv[4] ?? 40);

if ($sessionPath === null) {
    $home = getenv('HOME') ?: sys_get_temp_dir();
    $candidates = glob("{$home}/.pig/agent/sessions/*/*.jsonl") ?: [];
    usort($candidates, static fn (string $a, string $b): int => filesize($b) <=> filesize($a));
    $sessionPath = $candidates[0] ?? null;

    if ($sessionPath === null) {
        fwrite(STDERR, "No session found under {$home}/.pig/agent/sessions; pass one.\n");
        exit(1);
    }
}

$scratch = sys_get_temp_dir() . '/pig-bench-' . getmypid();
mkdir($scratch, 0o700, true);
putenv("PIG_HOME={$scratch}");

// `InteractiveMode` draws a terminal that is not a `ProcessTerminal` in regular mode whatever
// the settings say, so the fullscreen renderer is asked for the way the tests ask for it.
putenv("PIG_TUI_MODE={$tuiMode}");
// The scrollbar is kept on screen: with `auto`, every scroll arms a one-second hide timer, and
// `settle()` below — a loop with nothing to read — sleeps that second out, so a page-up measured
// as 1,009ms when the frame itself took nine. `always` draws the bar on every frame instead, which
// is the dearer path and the one worth timing.
$settings = Settings::inMemory(['tuiMode' => $tuiMode, 'fullscreenScrollbar' => 'always']);
$agent = new Agent(new AgentOptions(
    streamFn: static fn () => throw new RuntimeException('the bench never asks the model'),
    apiKey: 'bench',
));
$agent->setModel(new Model('claude-bench', 'Bench', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));
$cwd = dirname($sessionPath);
$agent->setTools(HookedTool::wrap(ToolSet::create($cwd, ['read']), new HookRunner()));

$saved = SessionManager::open($sessionPath);
$session = new AgentSession($agent, $cwd, $saved, $settings);
$session->restore($saved->messages());

$terminal = new FakeTerminal($columns, $rows);
$mode = new InteractiveMode(
    $session,
    $cwd,
    'bench',
    'dark',
    $terminal,
    [],
    [],
    null,
    [],
    $settings,
    null,
    null,
    [],
    [],
    null,
    null,
    keybindings: new Keybindings(),
);

$settle = static function (): void {
    for ($tick = 0; $tick < 50 && !Loop::get()->isIdle(); $tick++) {
        Loop::get()->tick();
    }
};

$bytes = static function () use ($terminal): int {
    $total = 0;
    foreach ($terminal->writes as $write) {
        $total += strlen($write);
    }
    $terminal->writes = [];

    return $total;
};

/** @var list<array{string, float, int, int}> name, ms per frame, bytes per frame, frames */
$results = [];
$measure = static function (string $name, int $frames, Closure $frame) use (&$results, $bytes, $settle): void {
    $bytes();
    $start = hrtime(true);
    for ($i = 0; $i < $frames; $i++) {
        $frame($i);
        $settle();
    }
    $elapsed = (hrtime(true) - $start) / 1e6;
    $results[] = [$name, $elapsed / $frames, intdiv($bytes(), $frames), $frames];
};

$messages = count($saved->messages());
$measure('start', 1, static function () use ($mode): void {
    $mode->start();
});
$measure('keystroke', 20, static function (int $i) use ($terminal): void {
    $terminal->type(chr(ord('a') + $i % 26));
});
$measure('resize', 10, static function (int $i) use ($terminal, $columns, $rows): void {
    $terminal->resize($columns + ($i % 2 === 0 ? 1 : 0), $rows);
});
$measure('expand', 2, static function () use ($terminal): void {
    $terminal->type("\x0f");
});
if ($tuiMode === 'fullscreen') {
    $measure('scroll', 10, static function (int $i) use ($terminal): void {
        $terminal->type($i % 2 === 0 ? "\e[5~" : "\e[6~");
    });
}

printf("%s — %d messages, %s, %dx%d, PHP %s\n\n", basename($sessionPath), $messages, $tuiMode, $columns, $rows, PHP_VERSION);
printf("%-10s %10s %12s %7s\n", 'frame', 'ms/frame', 'bytes/frame', 'frames');
foreach ($results as [$name, $ms, $perFrame, $frames]) {
    printf("%-10s %10.1f %12s %7d\n", $name, $ms, number_format($perFrame), $frames);
}
printf("\npeak memory %.1f MB\n", memory_get_peak_usage(true) / 1048576);

exec('rm -rf ' . escapeshellarg($scratch));
