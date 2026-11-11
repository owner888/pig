#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Does the agent still do the task?
 *
 *   php scripts/eval.php --host [--provider <p> --model <m>] [smoke|documentation-audit]
 *   php scripts/eval.php --docs [--provider <p> --model <m>] [--runs-per-variant <n>] [-t <pattern>] [suite.docs.eval.php …]
 *   php scripts/eval.php [--json] [--keep] [--filter <substring>] [name …] [-- <bin/pig options>]
 *
 * `--host` and `--docs` run `packages/evals` (ported from upstream's `packages/evals`):
 * - `--host` runs `SmokeEval` and `DocumentationAuditEval`
 * - `--docs` runs the paired `without_docs` vs `with_docs` documentation-lift evals
 *   (`ExtensionsDocsEval`, `ModelsDocsEval`, `OpenAiProviderDocsEval`, `CustomProviderDocsEval`,
 *   `TuiDocsEval`) and writes comparison reports under `packages/evals/.eval/<run-id>/`.
 *
 * Without `--host` or `--docs`, each case under `evals/` is run through `bin/pig`, as a
 * person would run it, and checked against what its `CASE.md` says must be true afterwards.
 *
 * Every case runs as `bin/pig --mode json --incognito --no-update-check -p <prompt>` in a
 * temporary copy of its `fixture/` directory; what follows `--` is appended (`--model`, `--no-extensions`,
 * `--tools`). Nothing is written under `~/.pig`: an incognito run keeps no session and no log. The
 * keys and models are the machine's own, as `test/live.php` has it — so this costs money, and nothing
 * runs it automatically.
 *
 * A case is `evals/<name>/CASE.md` — frontmatter, then the prompt — and an optional `fixture/`:
 *
 *   ---
 *   timeout: 120                      # seconds, the whole run; 120 when left out
 *   args: ['--tools', 'read,edit']    # bin/pig options for this case alone (quote a value with a comma)
 *   expect:
 *     answer:                         # the final assistant text
 *       contains: [42]
 *       not_contains: [sorry]
 *       regex: ['/\b42\b/']
 *     files:                          # in the working directory, after the run
 *       - { path: main.php, exists: true, contains: [function], equals: "…" }
 *       - { path: main.php.bak, exists: false }
 *     check: [php -l main.php]        # shell commands in the working directory; exit 0 passes
 *     tools:                          # by name, from the tool_execution_start events
 *       called: [edit]
 *       not_called: [write]
 *   ---
 *   Prompt text, as the person would type it.
 *
 * Only deterministic checks: a model grading a model is a second thing to get wrong, and it costs
 * as much again. What a run reports beside pass/fail is what to read a model change against —
 * tokens in and out, the cost pig computed, the tool calls, the wall time.
 *
 * Output is one line per case and a summary; `--json` prints the same as one JSON document on
 * standard output instead. Exit code is the number of failed cases, capped at 1 — so `&&` works.
 * `--keep` leaves the temporary directories in place and prints their paths, for reading what the
 * model actually did.
 */

use Pig\CodingAgent\Prompt\Frontmatter;
use Pig\Evals\Cli;

require __DIR__ . '/../vendor/autoload.php';

if (($argv[1] ?? null) === '--docs') {
    exit(Cli::runDocsComparison(array_slice($argv, 2)));
}

if (($argv[1] ?? null) === '--host') {
    exit(Cli::runHostEvals(array_slice($argv, 2)));
}

const DEFAULT_TIMEOUT = 120;

$root = dirname(__DIR__);
$evalsDir = "{$root}/evals";
$pig = "{$root}/bin/pig";

// ---- arguments -------------------------------------------------------------------------------

$json = false;
$keep = false;
$filter = null;
$names = [];
$pigArgs = [];
$args = array_slice($argv, 1);

while ($args !== []) {
    $arg = array_shift($args);

    if ($arg === '--') {
        $pigArgs = $args;
        break;
    }

    match (true) {
        $arg === '--json' => $json = true,
        $arg === '--keep' => $keep = true,
        $arg === '--filter' => $filter = array_shift($args) ?? usage('--filter needs a value'),
        str_starts_with($arg, '--') => usage("unknown option {$arg}"),
        default => $names[] = $arg,
    };
}

/** @return never */
function usage(string $problem): void
{
    fwrite(STDERR, "{$problem}\nusage: php scripts/eval.php [--json] [--keep] [--filter <substring>] [name …] [-- <bin/pig options>]\n");
    exit(2);
}

// ---- cases -----------------------------------------------------------------------------------

/**
 * @return list<array{name: string, dir: string, prompt: string, timeout: int, args: list<string>, expect: array<string, mixed>}>
 */
function loadCases(string $evalsDir, array $names, ?string $filter): array
{
    $dirs = $names === []
        ? array_filter(glob("{$evalsDir}/*", GLOB_ONLYDIR) ?: [], static fn (string $d): bool => is_file("{$d}/CASE.md"))
        : array_map(static fn (string $n): string => "{$evalsDir}/{$n}", $names);
    sort($dirs);

    $cases = [];
    foreach ($dirs as $dir) {
        $name = basename($dir);
        if ($filter !== null && !str_contains($name, $filter)) {
            continue;
        }
        $file = "{$dir}/CASE.md";
        if (!is_file($file)) {
            throw new RuntimeException("no case at {$file}");
        }
        [$front, $body] = Frontmatter::parse((string) file_get_contents($file));
        $prompt = trim($body);
        if ($prompt === '') {
            throw new RuntimeException("{$name}: CASE.md has no prompt below the frontmatter");
        }
        $timeout = $front['timeout'] ?? DEFAULT_TIMEOUT;
        if (!is_int($timeout) || $timeout <= 0) {
            throw new RuntimeException("{$name}: timeout must be a positive integer");
        }
        $caseArgs = $front['args'] ?? [];
        if (!is_array($caseArgs)) {
            throw new RuntimeException("{$name}: args must be a list");
        }
        $expect = $front['expect'] ?? [];
        if (!is_array($expect)) {
            throw new RuntimeException("{$name}: expect must be a map");
        }
        $cases[] = [
            'name' => $name,
            'dir' => $dir,
            'prompt' => $prompt,
            'timeout' => $timeout,
            'args' => array_map(strval(...), $caseArgs),
            'expect' => $expect,
        ];
    }

    return $cases;
}

// ---- running ---------------------------------------------------------------------------------

/** A fresh working directory holding the case's `fixture/`, when it has one. */
function workDir(string $caseDir): string
{
    $dir = sys_get_temp_dir() . '/pig-eval-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException("cannot create {$dir}");
    }
    $fixture = "{$caseDir}/fixture";
    if (is_dir($fixture)) {
        copyTree($fixture, $dir);
    }

    return $dir;
}

function copyTree(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    /** @var SplFileInfo $item */
    foreach ($it as $item) {
        $target = $to . '/' . $it->getSubPathname();
        if ($item->isDir()) {
            if (!mkdir($target, 0700)) {
                throw new RuntimeException("cannot create {$target}");
            }
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("cannot copy {$item->getPathname()}");
        }
    }
}

function removeTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $item */
    foreach ($it as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

/**
 * One `bin/pig` run, read as JSON events.
 *
 * @param list<string> $command
 * @return array{answer: string, tools: list<string>, input: int, output: int, cost: float, exit: int|null, error: ?string, seconds: float, stderr: string}
 */
function runPig(array $command, string $cwd, int $timeout): array
{
    $started = microtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start ' . implode(' ', $command));
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $timedOut = false;
    while (true) {
        $read = array_filter([$pipes[1], $pipes[2]], static fn ($p): bool => !feof($p));
        if ($read === []) {
            break;
        }
        $left = $timeout - (microtime(true) - $started);
        if ($left <= 0) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        $write = $except = null;
        if (stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6)) === false) {
            throw new RuntimeException('stream_select failed');
        }
        foreach ($read as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk === false) {
                throw new RuntimeException('read from bin/pig failed');
            }
            if ($pipe === $pipes[1]) {
                $out .= $chunk;
            } else {
                $err .= $chunk;
            }
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $answer = '';
    $tools = [];
    $input = 0;
    $output = 0;
    $cost = 0.0;
    $error = $timedOut ? "timed out after {$timeout}s" : null;

    foreach (explode("\n", $out) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $event = json_decode($line, true);
        if (!is_array($event)) {
            // The first line is the session header; anything else that is not JSON is the
            // answer of a run that fell back to text, which the checks below will report.
            continue;
        }
        switch ($event['type'] ?? null) {
            case 'tool_execution_start':
                $tools[] = (string) $event['toolName'];
                break;
            case 'message_end':
                $message = $event['message'] ?? [];
                if (($message['role'] ?? null) !== 'assistant') {
                    break;
                }
                $input += (int) ($message['usage']['input'] ?? 0) + (int) ($message['usage']['cacheRead'] ?? 0) + (int) ($message['usage']['cacheWrite'] ?? 0);
                $output += (int) ($message['usage']['output'] ?? 0);
                $cost += (float) ($message['usage']['cost']['total'] ?? 0);
                $text = '';
                foreach ($message['content'] ?? [] as $part) {
                    if (($part['type'] ?? null) === 'text') {
                        $text .= $part['text'];
                    }
                }
                if ($text !== '') {
                    $answer = $text;
                }
                break;
            case 'agent_end':
                if (isset($event['error'])) {
                    $error ??= is_string($event['error']) ? $event['error'] : json_encode($event['error']);
                }
                break;
        }
    }

    if ($error === null && $exit !== 0) {
        $error = "bin/pig exited {$exit}" . ($err !== '' ? ': ' . trim($err) : '');
    }

    return [
        'answer' => $answer,
        'tools' => $tools,
        'input' => $input,
        'output' => $output,
        'cost' => $cost,
        'exit' => $timedOut ? null : $exit,
        'error' => $error,
        'seconds' => microtime(true) - $started,
        'stderr' => $err,
    ];
}

// ---- checking --------------------------------------------------------------------------------

/**
 * @param array<string, mixed> $expect
 * @param array{answer: string, tools: list<string>} $run
 * @return list<string> what failed, in words; empty when everything held
 */
function check(array $expect, array $run, string $cwd): array
{
    $failures = [];

    foreach ((array) ($expect['answer']['contains'] ?? []) as $needle) {
        if (!str_contains($run['answer'], (string) $needle)) {
            $failures[] = "answer does not contain " . var_export((string) $needle, true);
        }
    }
    foreach ((array) ($expect['answer']['not_contains'] ?? []) as $needle) {
        if (str_contains($run['answer'], (string) $needle)) {
            $failures[] = "answer contains " . var_export((string) $needle, true);
        }
    }
    foreach ((array) ($expect['answer']['regex'] ?? []) as $pattern) {
        $matched = preg_match((string) $pattern, $run['answer']);
        if ($matched === false) {
            throw new RuntimeException("bad regex {$pattern}: " . preg_last_error_msg());
        }
        if ($matched === 0) {
            $failures[] = "answer does not match {$pattern}";
        }
    }

    foreach ((array) ($expect['files'] ?? []) as $file) {
        $path = (string) ($file['path'] ?? throw new RuntimeException('a files entry needs a path'));
        $full = "{$cwd}/{$path}";
        $exists = is_file($full);
        if (isset($file['exists']) && (bool) $file['exists'] !== $exists) {
            $failures[] = $exists ? "{$path} exists" : "{$path} does not exist";
            continue;
        }
        if (!$exists) {
            if (isset($file['contains']) || isset($file['equals'])) {
                $failures[] = "{$path} does not exist";
            }
            continue;
        }
        $content = (string) file_get_contents($full);
        foreach ((array) ($file['contains'] ?? []) as $needle) {
            if (!str_contains($content, (string) $needle)) {
                $failures[] = "{$path} does not contain " . var_export((string) $needle, true);
            }
        }
        if (isset($file['equals']) && $content !== (string) $file['equals']) {
            $failures[] = "{$path} differs from the expected content";
        }
    }

    foreach ((array) ($expect['check'] ?? []) as $command) {
        $command = (string) $command;
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException("cannot run {$command}");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $failures[] = "`{$command}` exited {$exit}" . (trim($output) !== '' ? ': ' . trim(strtok($output, "\n") ?: '') : '');
        }
    }

    foreach ((array) ($expect['tools']['called'] ?? []) as $tool) {
        if (!in_array((string) $tool, $run['tools'], true)) {
            $failures[] = "tool {$tool} was not called";
        }
    }
    foreach ((array) ($expect['tools']['not_called'] ?? []) as $tool) {
        if (in_array((string) $tool, $run['tools'], true)) {
            $failures[] = "tool {$tool} was called";
        }
    }

    return $failures;
}

// ---- main ------------------------------------------------------------------------------------

$cases = loadCases($evalsDir, $names, $filter);
if ($cases === []) {
    fwrite(STDERR, "no cases under {$evalsDir}\n");
    exit(2);
}

$results = [];
foreach ($cases as $case) {
    $cwd = workDir($case['dir']);
    $command = ['php', $pig, '--mode', 'json', '--incognito', '--no-update-check', ...$case['args'], ...$pigArgs, '-p', $case['prompt']];
    $run = runPig($command, $cwd, $case['timeout']);
    $failures = $run['error'] !== null ? [$run['error']] : [];
    $failures = [...$failures, ...check($case['expect'], $run, $cwd)];

    $result = [
        'name' => $case['name'],
        'pass' => $failures === [],
        'failures' => $failures,
        'tools' => $run['tools'],
        'input' => $run['input'],
        'output' => $run['output'],
        'cost' => $run['cost'],
        'seconds' => round($run['seconds'], 1),
        'answer' => $run['answer'],
    ];
    if ($keep) {
        $result['dir'] = $cwd;
    } else {
        removeTree($cwd);
    }
    $results[] = $result;

    if (!$json) {
        printf(
            "%-4s %-32s %6.1fs %7d in %6d out  $%.4f  %s\n",
            $result['pass'] ? 'ok' : 'FAIL',
            $case['name'],
            $result['seconds'],
            $result['input'],
            $result['output'],
            $result['cost'],
            $run['tools'] === [] ? '-' : implode(',', $run['tools']),
        );
        foreach ($failures as $failure) {
            echo "       - {$failure}\n";
        }
        if ($keep) {
            echo "       → {$cwd}\n";
        }
    }
}

$passed = count(array_filter($results, static fn (array $r): bool => $r['pass']));
$total = count($results);
$summary = [
    'passed' => $passed,
    'total' => $total,
    'input' => array_sum(array_column($results, 'input')),
    'output' => array_sum(array_column($results, 'output')),
    'cost' => array_sum(array_column($results, 'cost')),
    'seconds' => round(array_sum(array_column($results, 'seconds')), 1),
];

if ($json) {
    echo json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    printf(
        "\n%d/%d passed  %d in %d out  $%.4f  %.1fs\n",
        $passed,
        $total,
        $summary['input'],
        $summary['output'],
        $summary['cost'],
        $summary['seconds'],
    );
}

exit($passed === $total ? 0 : 1);
