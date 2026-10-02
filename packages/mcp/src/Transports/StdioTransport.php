<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Closure;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use Pig\Tui\Process;
use RuntimeException;
use Throwable;

/**
 * An MCP server as a child process, one JSON-RPC message per line on its stdio — upstream's
 * `transports/stdio.ts`.
 *
 * The pipes are watched through `Loop::onReadable()`, so a server that takes eight seconds to
 * start (`npx -y …` cold) costs the terminal nothing. stderr is kept — the last 64KB — because
 * it is the only thing a server that failed to start has to say, and `/mcp` shows it.
 *
 * **Shutdown is the spec's three steps**: close stdin and let the server exit, then SIGTERM,
 * then SIGKILL. Upstream puts the child in its own process group so the signals reach the
 * children of an `npx` or `uvx` wrapper too; PHP cannot do that through `proc_open`, so
 * `Process::killTree()` walks the tree from `ps` instead — the same answer `bash` already has.
 */
final class StdioTransport extends TransportEvents
{
    private const int DEFAULT_MAX_STDERR_BYTES = 64 * 1024;

    private const float DEFAULT_CLOSE_TIMEOUT = 2.0;

    /** How long a server gets to exit on its own after stdin closes, before it is sent SIGTERM. */
    private const float STDIN_CLOSE_GRACE = 0.5;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private ?string $stdoutWatcher = null;

    private ?string $stderrWatcher = null;

    private string $stdoutBuffer = '';

    private string $stderrBuffer = '';

    private bool $started = false;

    private bool $closed = false;

    private ?int $pid = null;

    /**
     * @param list<string>              $args
     * @param array<string, string>     $env        added to this process's environment, or the
     *                                              whole environment when `$inheritEnv` is false
     * @param Closure(string): void|null $onStderr  told each chunk the server writes to stderr
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
        private readonly ?string $cwd = null,
        private readonly bool $inheritEnv = true,
        private readonly int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES,
        private readonly int $maxStderrBytes = self::DEFAULT_MAX_STDERR_BYTES,
        private readonly float $closeTimeout = self::DEFAULT_CLOSE_TIMEOUT,
        private readonly ?Closure $onStderr = null,
    ) {
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    /** What the server has written to stderr, the last 64KB of it. */
    public function stderr(): string
    {
        return $this->stderrBuffer;
    }

    #[\Override]
    public function start(): void
    {
        if ($this->started) {
            throw new RuntimeException('MCP stdio transport already started');
        }

        if ($this->closed) {
            throw new McpConnectionClosedError();
        }

        $this->started = true;

        $env = $this->inheritEnv ? [...self::environment(), ...$this->env] : $this->env;
        $pipes = [];

        // `proc_open()` warns as well as answering false, and the warning is the only place a
        // "no such file" for the command appears.
        $problem = null;
        set_error_handler(static function (int $no, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $process = proc_open(
                [$this->command, ...$this->args],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->cwd,
                $env,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start MCP server '{$this->command}'" . ($problem !== null ? ": {$problem}" : ''));
        }

        $this->process = $process;
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $status = proc_get_status($process);
        $this->pid = is_int($status['pid'] ?? null) ? $status['pid'] : null;

        // A command that does not exist is a child that is already gone by the time anybody
        // looks — `proc_open` with an array command execs directly, so the failure is an exit
        // code and not a warning. Upstream's `spawn` event rejects here; this is the same answer.
        if (!$status['running']) {
            $this->drainOnce();
            $code = (int) ($status['exitcode'] ?? -1);
            $this->teardown();

            throw new RuntimeException(
                "MCP server '{$this->command}' exited with code {$code} before it could be spoken to"
                . ($this->stderrBuffer !== '' ? ': ' . trim(substr($this->stderrBuffer, -600)) : ''),
            );
        }

        $loop = Loop::get();
        $this->stdoutWatcher = $loop->onReadable($this->stdout, fn () => $this->readStdout());
        $this->stderrWatcher = $loop->onReadable($this->stderr, fn () => $this->readStderr());
    }

    #[\Override]
    public function send(array $message): void
    {
        if (!$this->started || $this->closed || !is_resource($this->stdin)) {
            throw new McpConnectionClosedError();
        }

        $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new RuntimeException('Could not encode MCP message: ' . json_last_error_msg());
        }

        $payload .= "\n";
        $written = 0;
        $length = strlen($payload);

        // A pipe to a server that is not reading fills, and `fwrite()` then writes short. Loop
        // until it is all out, yielding to the loop in between rather than spinning. A server that
        // exited makes `fwrite()` warn (broken pipe) as well as answer false; the handler turns the
        // warning into the closed-connection error, since `@` is not allowed here.
        set_error_handler(static function (): never {
            throw new McpConnectionClosedError('MCP server stdin is closed');
        });

        try {
            while ($written < $length) {
                if (!is_resource($this->stdin)) {
                    throw new McpConnectionClosedError('MCP server stdin is closed');
                }

                $count = fwrite($this->stdin, substr($payload, $written));

                if ($count === false) {
                    throw new McpConnectionClosedError('MCP server stdin is closed');
                }

                $written += $count;

                if ($written < $length) {
                    Async::delay(0.005);
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->process === null) {
            $this->emitClose();

            return;
        }

        $status = proc_get_status($this->process);

        if (!$status['running']) {
            $this->teardown();
            $this->emitClose();

            return;
        }

        // Shutdown per the spec: close stdin and let the server exit, then SIGTERM, then SIGKILL.
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }

        $grace = min(self::STDIN_CLOSE_GRACE, $this->closeTimeout);
        $deadline = microtime(true) + $grace + $this->closeTimeout;
        $terminated = false;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                break;
            }

            if (!$terminated && microtime(true) >= $deadline - $this->closeTimeout) {
                $this->signalTree(15);
                $terminated = true;
            }

            Async::delay(0.05);
        }

        if (proc_get_status($this->process)['running']) {
            $this->signalTree(9);
        } else {
            // Children of the server that ignored stdin closing would otherwise outlive it.
            $this->signalTree(15);
        }

        $this->teardown();
        $this->emitClose();
    }

    // ---- reading --------------------------------------------------------------------------------

    private function readStdout(): void
    {
        if (!is_resource($this->stdout)) {
            return;
        }

        $chunk = fread($this->stdout, 65536);

        if (is_string($chunk) && $chunk !== '') {
            $this->handleStdout($chunk);
        }

        if (feof($this->stdout)) {
            $this->onChildGone();
        }
    }

    private function readStderr(): void
    {
        if (!is_resource($this->stderr)) {
            return;
        }

        $chunk = fread($this->stderr, 65536);

        if (is_string($chunk) && $chunk !== '') {
            $this->handleStderr($chunk);
        }

        if (feof($this->stderr) && $this->stderrWatcher !== null) {
            Loop::get()->cancel($this->stderrWatcher);
            $this->stderrWatcher = null;
        }
    }

    /** Whatever is on the pipes right now, without the loop — for the moment of a failed start. */
    private function drainOnce(): void
    {
        foreach ([$this->stdout, $this->stderr] as $at => $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }

            $chunk = stream_get_contents($pipe);

            if (is_string($chunk) && $chunk !== '') {
                $at === 0 ? $this->handleStdout($chunk) : $this->handleStderr($chunk);
            }
        }
    }

    private function handleStdout(string $chunk): void
    {
        $this->stdoutBuffer .= $chunk;

        while (true) {
            $newline = strpos($this->stdoutBuffer, "\n");

            if ($newline === false) {
                if (strlen($this->stdoutBuffer) > $this->maxMessageBytes) {
                    $this->stdoutBuffer = '';
                    $this->emitError(new RuntimeException("MCP stdio message exceeds {$this->maxMessageBytes} bytes"));
                }

                return;
            }

            $line = substr($this->stdoutBuffer, 0, $newline);
            $this->stdoutBuffer = substr($this->stdoutBuffer, $newline + 1);

            if (strlen($line) > $this->maxMessageBytes) {
                $this->emitError(new RuntimeException("MCP stdio message exceeds {$this->maxMessageBytes} bytes"));
                continue;
            }

            $text = rtrim($line, "\r");

            if (trim($text) === '') {
                continue;
            }

            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                $this->emitMessage(JsonRpc::parse($decoded));
            } catch (Throwable $error) {
                $this->emitError($error);
            }
        }
    }

    private function handleStderr(string $chunk): void
    {
        $this->stderrBuffer .= $chunk;

        if (strlen($this->stderrBuffer) > $this->maxStderrBytes) {
            $this->stderrBuffer = substr($this->stderrBuffer, -$this->maxStderrBytes);
        }

        if ($this->onStderr !== null) {
            ($this->onStderr)($chunk);
        }
    }

    /** stdout reached EOF: the server is gone, or going. */
    private function onChildGone(): void
    {
        if (trim($this->stdoutBuffer) !== '') {
            $this->emitError(new RuntimeException('MCP stdio server closed with an incomplete JSON-RPC message'));
        }

        $this->stdoutBuffer = '';

        if (!$this->closed) {
            // The server died on its own. Reap it and tell the client, which fails every pending
            // request with "connection closed".
            $this->closed = true;
            $this->drainOnce();
            $this->teardown();
            $this->emitClose();
        }
    }

    // ---- stopping -------------------------------------------------------------------------------

    private function signalTree(int $signal): void
    {
        if ($this->pid !== null) {
            Process::killTree($this->pid, $signal);
        }
    }

    /** Cancel the watchers before the pipes close — `stream_select()` drops a closed stream silently. */
    private function teardown(): void
    {
        $loop = Loop::get();

        foreach ([$this->stdoutWatcher, $this->stderrWatcher] as $watcher) {
            if ($watcher !== null) {
                $loop->cancel($watcher);
            }
        }

        $this->stdoutWatcher = $this->stderrWatcher = null;

        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->stdin = $this->stdout = $this->stderr = null;

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->process = null;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];

        foreach (getenv() as $name => $value) {
            if (is_string($value)) {
                $env[(string) $name] = $value;
            }
        }

        return $env;
    }
}
