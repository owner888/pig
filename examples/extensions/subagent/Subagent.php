<?php

declare(strict_types=1);

namespace PigSubagent;

use Closure;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Tools\Truncate;
use Pig\Tui\Process;
use RuntimeException;

/**
 * The half of upstream's `index.ts` that is not the tool itself: the helpers, and `runSingleAgent()`.
 * `index.php` holds the registration, `execute` and the two renderers, in upstream's order.
 *
 * A result is an array, not a class, for the reason `Agents` gives: it is the tool's `details`.
 *
 * @phpstan-import-type Agent from Agents
 * @phpstan-type Usage array{input: int, output: int, cacheRead: int, cacheWrite: int, cost: float, contextTokens: int, turns: int}
 * @phpstan-type Result array{agent: string, agentSource: 'user'|'project'|'unknown', task: string, status: 'pending'|'running'|'completed'|'failed'|'aborted', exitCode: ?int, messages: list<array<string, mixed>>, stderr: string, usage: Usage, model: ?string, stopReason: ?string, errorMessage: ?string, terminationSignal: ?int, step: ?int}
 * @phpstan-type Details array{mode: 'single'|'parallel'|'chain', results: list<Result>, isError: bool}
 * @phpstan-type Resolved array{agent: ?Agent, available: list<Agent>, cwd: string}
 */
final class Subagent
{
    public const int MAX_PARALLEL_TASKS = 8;
    public const int MAX_CONCURRENCY = 4;
    public const int COLLAPSED_ITEM_COUNT = 10;
    public const int PER_TASK_OUTPUT_CAP = 50 * 1024;
    private const int TRUNCATION_NOTICE_RESERVE = 256;

    /** Upstream's `formatTokens()`. */
    public static function formatTokens(int $count): string
    {
        return match (true) {
            $count < 1000 => (string) $count,
            $count < 10000 => number_format($count / 1000, 1) . 'k',
            $count < 1000000 => round($count / 1000) . 'k',
            default => number_format($count / 1000000, 1) . 'M',
        };
    }

    /**
     * Upstream's `formatUsageStats()`.
     *
     * @param Usage $usage
     */
    public static function formatUsageStats(array $usage, ?string $model = null): string
    {
        $parts = [];

        if ($usage['turns'] > 0) {
            $parts[] = "{$usage['turns']} turn" . ($usage['turns'] > 1 ? 's' : '');
        }
        if ($usage['input'] > 0) {
            $parts[] = '↑' . self::formatTokens($usage['input']);
        }
        if ($usage['output'] > 0) {
            $parts[] = '↓' . self::formatTokens($usage['output']);
        }
        if ($usage['cacheRead'] > 0) {
            $parts[] = 'R' . self::formatTokens($usage['cacheRead']);
        }
        if ($usage['cacheWrite'] > 0) {
            $parts[] = 'W' . self::formatTokens($usage['cacheWrite']);
        }
        if ($usage['cost'] > 0) {
            $parts[] = '$' . number_format($usage['cost'], 4);
        }
        if ($usage['contextTokens'] > 0) {
            $parts[] = 'ctx:' . self::formatTokens($usage['contextTokens']);
        }
        if ($model !== null && $model !== '') {
            $parts[] = $model;
        }

        return implode(' ', $parts);
    }

    /**
     * Upstream's `formatToolCall()`: one line per call in the shape the built-in tools draw.
     *
     * @param array<string, mixed> $args
     */
    public static function formatToolCall(string $toolName, array $args, Theme $theme): string
    {
        $shorten = static function (string $path): string {
            $home = (string) getenv('HOME');

            return $home !== '' && str_starts_with($path, $home) ? '~' . substr($path, strlen($home)) : $path;
        };
        $string = static fn (mixed $value, string $default): string => is_string($value) && $value !== '' ? $value : $default;
        $path = $string($args['file_path'] ?? $args['path'] ?? null, '...');

        switch ($toolName) {
            case 'bash':
                $command = $string($args['command'] ?? null, '...');
                $preview = mb_strlen($command) > 60 ? mb_substr($command, 0, 60) . '...' : $command;

                return $theme->fg('muted', '$ ') . $theme->fg('toolOutput', $preview);
            case 'read':
                $range = self::readLineRange($args['offset'] ?? null, $args['limit'] ?? null);

                return $theme->fg('muted', 'read ') . $theme->fg('accent', $shorten($path)) . ($range !== '' ? $theme->fg('warning', $range) : '');
            case 'write':
                $content = $string($args['content'] ?? null, '');
                $lines = count(explode("\n", $content));

                return $theme->fg('muted', 'write ') . $theme->fg('accent', $shorten($path)) . ($lines > 1 ? $theme->fg('dim', " ({$lines} lines)") : '');
            case 'edit':
                return $theme->fg('muted', 'edit ') . $theme->fg('accent', $shorten($path));
            case 'ls':
                return $theme->fg('muted', 'ls ') . $theme->fg('accent', $shorten($string($args['path'] ?? null, '.')));
            case 'find':
                return $theme->fg('muted', 'find ') . $theme->fg('accent', $string($args['pattern'] ?? null, '*'))
                    . $theme->fg('dim', ' in ' . $shorten($string($args['path'] ?? null, '.')));
            case 'grep':
                return $theme->fg('muted', 'grep ') . $theme->fg('accent', '/' . $string($args['pattern'] ?? null, '') . '/')
                    . $theme->fg('dim', ' in ' . $shorten($string($args['path'] ?? null, '.')));
            default:
                $json = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                $preview = mb_strlen($json) > 50 ? mb_substr($json, 0, 50) . '...' : $json;

                return $theme->fg('accent', $toolName) . $theme->fg('dim', " {$preview}");
        }
    }

    /** Upstream's `formatReadLineRange()`: `:start`, `:start-end`, or nothing. */
    private static function readLineRange(mixed $offset, mixed $limit): string
    {
        if ($offset === null && $limit === null) {
            return '';
        }

        $start = $offset === null ? 1 : (is_numeric($offset) ? (int) $offset : null);

        if ($start === null) {
            return '';
        }

        if ($limit === null) {
            return ":{$start}";
        }

        if (!is_numeric($limit)) {
            return '';
        }

        $end = $start + (int) $limit - 1;

        return ":{$start}" . ($end !== 0 ? "-{$end}" : '');
    }

    /**
     * Upstream's `getFinalOutput()`: the first text part of the last assistant message.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function finalOutput(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            foreach ($message['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'text') {
                    return (string) $part['text'];
                }
            }
        }

        return '';
    }

    /** @param Result $result */
    public static function isFailed(array $result): bool
    {
        return $result['status'] === 'failed' || $result['status'] === 'aborted';
    }

    /**
     * Upstream's `getFailureEvidence()`: everything that says why, each once.
     *
     * @param Result $result
     */
    public static function failureEvidence(array $result): string
    {
        $evidence = [];
        $seen = [];
        $add = static function (string $label, ?string $value) use (&$evidence, &$seen): void {
            $text = trim((string) $value);

            if ($text === '' || isset($seen[$text])) {
                return;
            }

            $seen[$text] = true;
            $evidence[] = "{$label}: {$text}";
        };

        $add('Error', $result['errorMessage']);
        $add('Signal', $result['terminationSignal'] === null ? null : (string) $result['terminationSignal']);
        $add('stderr', $result['stderr']);
        $add('Assistant output', self::finalOutput($result['messages']));

        return implode("\n\n", $evidence) ?: '(no output)';
    }

    /**
     * Upstream's `getFailureSummary()`: the first thing that says why.
     *
     * @param Result $result
     */
    public static function failureSummary(array $result): string
    {
        return trim((string) $result['errorMessage'])
            ?: ($result['terminationSignal'] !== null ? "Terminated by signal {$result['terminationSignal']}" : '')
            ?: trim($result['stderr'])
            ?: self::finalOutput($result['messages'])
            ?: '(no output)';
    }

    /** @param Result $result */
    public static function resultOutput(array $result): string
    {
        return self::isFailed($result) ? self::failureEvidence($result) : (self::finalOutput($result['messages']) ?: '(no output)');
    }

    /** Upstream's `truncateParallelOutput()`: a budget of its own per task. */
    public static function truncateParallelOutput(string $output): string
    {
        $bytes = strlen($output);

        if ($bytes <= self::PER_TASK_OUTPUT_CAP) {
            return $output;
        }

        // Cut on a character boundary, as upstream's loop over `slice()` ends up doing.
        $truncated = mb_strcut($output, 0, self::PER_TASK_OUTPUT_CAP);

        return $truncated . "\n\n[Output truncated: " . ($bytes - strlen($truncated)) . ' bytes omitted. Full output preserved in tool details.]';
    }

    /** Upstream's `truncateModelOutput()`: the built-in tools' limits, with room for the notice. */
    public static function truncateModelOutput(string $output): string
    {
        $initial = Truncate::head($output);

        if (!$initial->truncated) {
            return $output;
        }

        $body = Truncate::head($output, Truncate::MAX_LINES - 2, Truncate::MAX_BYTES - self::TRUNCATION_NOTICE_RESERVE);
        $omitted = $initial->totalBytes - $body->outputBytes;
        $notice = "[Output truncated: {$omitted} bytes omitted. Full output preserved in tool details.]";

        return $body->content !== '' ? "{$body->content}\n\n{$notice}" : $notice;
    }

    /**
     * Upstream's `getDisplayItems()`: the assistant's text and tool calls, in order.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array{type: 'text', text: string}|array{type: 'toolCall', name: string, args: array<string, mixed>}>
     */
    public static function displayItems(array $messages): array
    {
        $items = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            foreach ($message['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'text') {
                    $items[] = ['type' => 'text', 'text' => (string) $part['text']];
                } elseif (($part['type'] ?? null) === 'toolCall') {
                    $items[] = ['type' => 'toolCall', 'name' => (string) $part['name'], 'args' => (array) ($part['arguments'] ?? [])];
                }
            }
        }

        return $items;
    }

    /**
     * Upstream's `mapWithConcurrencyLimit()`: at most $concurrency of $fn in flight, results in
     * the items' order. Each worker is a fiber of its own, so the children really do run at once.
     *
     * @template TIn
     * @template TOut
     * @param list<TIn> $items
     * @param Closure(TIn, int): TOut $fn
     * @return list<TOut>
     */
    public static function mapWithConcurrencyLimit(array $items, int $concurrency, Closure $fn): array
    {
        if ($items === []) {
            return [];
        }

        $limit = max(1, min($concurrency, count($items)));
        $results = [];
        $next = 0;
        $workers = [];

        for ($i = 0; $i < $limit; $i++) {
            $workers[] = Async::spawn(static function () use (&$results, &$next, $items, $fn): void {
                while (true) {
                    $current = $next++;

                    if ($current >= count($items)) {
                        return;
                    }

                    $results[$current] = $fn($items[$current], $current);
                }
            });
        }

        foreach ($workers as $worker) {
            $worker->await();
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Upstream's `writePromptToTempFile()`: the agent's system prompt in a `0600` file of its own,
     * for `--append-system-prompt`, so it is neither in the process list nor in the task.
     *
     * @return array{dir: string, filePath: string}
     */
    private static function writePromptToTempFile(string $agentName, string $prompt): array
    {
        $dir = sys_get_temp_dir() . '/pig-subagent-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0700)) {
            throw new RuntimeException("cannot create {$dir}");
        }

        $safeName = preg_replace('/[^\w.-]+/', '_', $agentName) ?? $agentName;
        $filePath = "{$dir}/prompt-{$safeName}.md";

        if (file_put_contents($filePath, $prompt) === false || !chmod($filePath, 0600)) {
            throw new RuntimeException("cannot write {$filePath}");
        }

        return ['dir' => $dir, 'filePath' => $filePath];
    }

    /**
     * Upstream's `getPiInvocation()`: run the pig this extension is loaded into. `bin/pig` is
     * where `RpcClient` finds it — the working tree's, or the installed package's.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private static function pigInvocation(array $args): array
    {
        return [PHP_BINARY, RpcClient::defaultBinary(), ...$args];
    }

    /**
     * Upstream's `resolveAgent()`.
     *
     * @param 'user'|'project'|'both' $scope
     * @return Resolved
     */
    public static function resolveAgent(string $defaultCwd, string $scope, string $name, ?string $cwd): array
    {
        $effectiveCwd = $cwd === null || $cwd === '' || $cwd === '.'
            ? $defaultCwd
            : (str_starts_with($cwd, '/') ? $cwd : rtrim($defaultCwd, '/') . '/' . $cwd);
        $discovery = Agents::discover($effectiveCwd, $scope);
        $agent = null;

        foreach ($discovery['agents'] as $candidate) {
            if ($candidate['name'] === $name) {
                $agent = $candidate;
                break;
            }
        }

        return ['agent' => $agent, 'available' => $discovery['agents'], 'cwd' => $effectiveCwd];
    }

    /** @return Usage */
    public static function emptyUsage(): array
    {
        return ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0, 'cost' => 0.0, 'contextTokens' => 0, 'turns' => 0];
    }

    /**
     * Upstream's `createUnstartedResult()`.
     *
     * @param 'aborted'|'failed' $status
     * @return Result
     */
    public static function unstartedResult(string $agent, string $task, ?int $step, string $status, ?string $errorMessage = null): array
    {
        return [
            'agent' => $agent,
            'agentSource' => 'unknown',
            'task' => $task,
            'status' => $status,
            'exitCode' => null,
            'messages' => [],
            'stderr' => '',
            'usage' => self::emptyUsage(),
            'model' => null,
            'stopReason' => null,
            'errorMessage' => $errorMessage,
            'terminationSignal' => null,
            'step' => $step,
        ];
    }

    /**
     * Upstream's `runSingleAgent()`: one child `pig --mode json -p --no-session`, read as
     * `message_end` events, into one result.
     *
     * The child is on the loop through `Process::streamAsync()`, which is what `grep` uses: the
     * keyboard, the spinner and escape keep working while it runs, and the turn's `AbortSignal`
     * kills it. What that changes from upstream: a kill is pig's `Process` kill — the whole
     * process tree, at once — where upstream sends SIGTERM and SIGKILL five seconds later.
     *
     * @param array{model: ?string, thinkingLevel: ?string} $dispatchDefaults
     * @param Resolved $resolved
     * @param Closure(AgentToolResult): void|null $onUpdate
     * @param Closure(list<Result>): Details $makeDetails
     * @return Result
     */
    public static function runSingleAgent(
        array $dispatchDefaults,
        array $resolved,
        string $agentName,
        string $task,
        ?int $step,
        ?AbortSignal $signal,
        ?Closure $onUpdate,
        Closure $makeDetails,
    ): array {
        if ($signal?->aborted() ?? false) {
            return self::unstartedResult($agentName, $task, $step, 'aborted', 'Subagent was aborted before starting');
        }

        $effectiveCwd = $resolved['cwd'];
        $agent = $resolved['agent'];

        if ($agent === null) {
            $available = implode(', ', array_map(static fn (array $a): string => "\"{$a['name']}\"", $resolved['available'])) ?: 'none';

            return self::unstartedResult($agentName, $task, $step, 'failed', "Unknown agent: \"{$agentName}\". Available agents: {$available}.");
        }

        $args = ['--mode', 'json', '-p', '--no-session'];
        $inheritsDispatchConfig = $agent['model'] === null;
        $model = $agent['model'] ?? $dispatchDefaults['model'];

        if ($model !== null) {
            array_push($args, '--model', $model);
        }
        if ($inheritsDispatchConfig && $dispatchDefaults['thinkingLevel'] !== null) {
            array_push($args, '--thinking', $dispatchDefaults['thinkingLevel']);
        }
        if ($agent['tools'] !== null) {
            array_push($args, '--tools', implode(',', $agent['tools']));
        }

        $current = [
            'agent' => $agentName,
            'agentSource' => $agent['source'],
            'task' => $task,
            'status' => 'running',
            'exitCode' => null,
            'messages' => [],
            'stderr' => '',
            'usage' => self::emptyUsage(),
            'model' => $model,
            'stopReason' => null,
            'errorMessage' => null,
            'terminationSignal' => null,
            'step' => $step,
        ];

        $emitUpdate = static function () use (&$current, $onUpdate, $makeDetails): void {
            if ($onUpdate !== null) {
                $onUpdate(new AgentToolResult(
                    [new TextContent(self::finalOutput($current['messages']) ?: '(running...)')],
                    $makeDetails([$current]),
                ));
            }
        };

        $tmp = null;

        try {
            if (trim($agent['systemPrompt']) !== '') {
                $tmp = self::writePromptToTempFile($agent['name'], $agent['systemPrompt']);
                array_push($args, '--append-system-prompt', $tmp['filePath']);
            }

            if ($signal?->aborted() ?? false) {
                $current['status'] = 'aborted';
                $current['errorMessage'] = 'Subagent was aborted before starting';

                return $current;
            }

            $args[] = "Task: {$task}";
            $emitUpdate();

            $processLine = static function (string $line) use (&$current, $emitUpdate): bool {
                if (trim($line) === '') {
                    return true;
                }

                $event = json_decode($line, true);

                // The first line is the session header, and a line that is not JSON at all is
                // upstream's `catch { return; }`.
                if (!is_array($event) || ($event['type'] ?? null) !== 'message_end') {
                    return true;
                }

                $message = $event['message'] ?? null;

                if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant', 'toolResult'], true)) {
                    return true;
                }

                $current['messages'][] = $message;

                if ($message['role'] === 'assistant') {
                    $usage = (array) ($message['usage'] ?? []);
                    $current['usage']['turns']++;
                    $current['usage']['input'] += (int) ($usage['input'] ?? 0);
                    $current['usage']['output'] += (int) ($usage['output'] ?? 0);
                    $current['usage']['cacheRead'] += (int) ($usage['cacheRead'] ?? 0);
                    $current['usage']['cacheWrite'] += (int) ($usage['cacheWrite'] ?? 0);
                    $current['usage']['cost'] += (float) ($usage['cost']['total'] ?? 0);
                    $current['usage']['contextTokens'] = (int) ($usage['totalTokens'] ?? 0);
                    $current['model'] ??= isset($message['model']) ? (string) $message['model'] : null;
                    $current['stopReason'] = isset($message['stopReason']) ? (string) $message['stopReason'] : null;
                    $current['errorMessage'] = isset($message['errorMessage']) ? (string) $message['errorMessage'] : null;
                }

                $emitUpdate();

                return true;
            };

            // No deadline: a subagent runs for as long as its task takes, as upstream's does.
            [$exit, $stderr] = Process::streamAsync(self::pigInvocation($args), $processLine, 0, $signal, $effectiveCwd);
            $current['stderr'] = $stderr;
            $wasAborted = $signal?->aborted() ?? false;

            // `Process` folds "could not start", "stopped" and "aborted" into STOPPED, and
            // reports a signal as 128 + its number; upstream has each as its own field.
            $terminationSignal = $exit > 128 ? $exit - 128 : null;
            $current['exitCode'] = $exit === Process::STOPPED ? null : $exit;
            $current['terminationSignal'] = $terminationSignal;

            if ($wasAborted) {
                $current['status'] = 'aborted';
                $current['errorMessage'] = $current['errorMessage'] ?: 'Subagent was aborted';
            } elseif ($exit === Process::STOPPED) {
                $current['status'] = 'failed';
                $current['errorMessage'] = 'Failed to spawn subagent: ' . (trim($stderr) !== '' ? trim($stderr) : 'the process could not be started');
            } elseif ($terminationSignal !== null) {
                $current['status'] = 'failed';
                $current['errorMessage'] = $current['errorMessage'] ?: "Subagent terminated by signal {$terminationSignal}";
            } elseif ($exit !== 0) {
                $current['status'] = 'failed';
                $current['errorMessage'] = $current['errorMessage'] ?: "Subagent exited with code {$exit}";
            } elseif ($current['stopReason'] === 'aborted') {
                $current['status'] = 'aborted';
            } elseif ($current['stopReason'] === 'error') {
                $current['status'] = 'failed';
            } else {
                $current['status'] = 'completed';
            }

            return $current;
        } finally {
            if ($tmp !== null) {
                // Best effort, as upstream's two `try { … } catch { /* ignore */ }`: the file is
                // ours and empty of anything but the prompt, and a leftover is a leftover.
                if (is_file($tmp['filePath'])) {
                    unlink($tmp['filePath']);
                }
                if (is_dir($tmp['dir'])) {
                    rmdir($tmp['dir']);
                }
            }
        }
    }
}
