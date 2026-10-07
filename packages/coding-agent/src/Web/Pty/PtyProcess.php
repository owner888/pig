<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Pty;

use Closure;
use Pig\Async\Loop;

/**
 * PtyProcess — Manages an interactive pseudo-terminal process (pty).
 *
 * Uses PHP's native `proc_open` with `['pty']` descriptor on macOS/Linux.
 * Bridges non-blocking I/O to Pig\Async\Loop, micro-batches terminal output
 * (16ms) to prevent WebSocket frame storms, and handles window sizing via slave tty.
 */
final class PtyProcess
{
    private const MAX_SCROLLBACK = 204800; // 200 KB scrollback window matching upstream pi-web-ui
    private const FLUSH_INTERVAL_S = 0.016; // 16ms micro-batching window

    /** @var resource|null */
    private mixed $process = null;

    /** @var resource|null */
    private mixed $stream = null;

    private int $pid = 0;
    private ?string $slaveDevice = null;
    private ?string $watcherId = null;
    private ?string $flushTimer = null;
    private string $pendingOutput = '';
    private string $scrollback = '';
    private bool $running = false;
    private ?int $exitCode = null;

    /**
     * @param Closure(string $terminalId, string $data): void $onOutput
     * @param Closure(string $terminalId, ?int $exitCode): void $onExit
     */
    public function __construct(
        public readonly string $id,
        public readonly string $cwd,
        private int $cols = 80,
        private int $rows = 24,
        private readonly ?string $command = null,
        private readonly ?Closure $onOutput = null,
        private readonly ?Closure $onExit = null,
    ) {
        $this->start();
    }

    public function start(): void
    {
        $shell = $this->resolveShell();
        $cmd = $this->command !== null && $this->command !== ''
            ? sprintf('%s -c %s', escapeshellcmd($shell), escapeshellarg($this->command))
            : $shell;

        $descriptors = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pty'],
        ];

        $env = array_merge(getenv(), [
            'TERM' => 'xterm-256color',
            'COLORTERM' => 'truecolor',
            'LANG' => 'en_US.UTF-8',
            'COLORFGBG' => '15;0',
        ]);

        $workingDir = is_dir($this->cwd) ? $this->cwd : (getenv('HOME') ?: '/');

        // Safe error handler capture rather than @ suppression
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });

        try {
            $proc = proc_open($cmd, $descriptors, $pipes, $workingDir, $env);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($proc) || !isset($pipes[0]) || !is_resource($pipes[0])) {
            // Fallback to standard pipes if PTY descriptor is unsupported
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, $workingDir, $env);
            if (!is_resource($proc)) {
                $this->running = false;
                $this->exitCode = 1;
                if ($this->onExit !== null) {
                    ($this->onExit)($this->id, 1);
                }
                return;
            }
        }

        $this->process = $proc;
        $this->stream = $pipes[0];
        stream_set_blocking($this->stream, false);

        $status = proc_get_status($this->process);
        $this->pid = (int) ($status['pid'] ?? 0);
        $this->running = (bool) ($status['running'] ?? true);
        $this->slaveDevice = $this->findSlaveDevice($this->pid);

        // Apply initial dimensions
        $this->resize($this->cols, $this->rows);

        // Attach to Event Loop
        $this->attachLoop();
    }

    public function input(string $data): void
    {
        if (!$this->running || $this->stream === null || !is_resource($this->stream)) {
            return;
        }

        fwrite($this->stream, $data);
    }

    public function resize(int $cols, int $rows): void
    {
        $this->cols = max(1, min(500, $cols));
        $this->rows = max(1, min(200, $rows));

        if ($this->slaveDevice !== null && file_exists($this->slaveDevice)) {
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            exec(sprintf('stty %s %s rows %d cols %d 2>/dev/null', $flag, escapeshellarg($this->slaveDevice), $this->rows, $this->cols));
        }

        if ($this->pid > 0 && function_exists('posix_kill')) {
            posix_kill($this->pid, SIGWINCH);
        }
    }

    public function kill(): void
    {
        if ($this->running && $this->pid > 0 && function_exists('posix_kill')) {
            posix_kill($this->pid, SIGTERM);
            // Grace period before SIGKILL
            Loop::get()->delay(0.2, function (): void {
                if ($this->running && $this->pid > 0 && function_exists('posix_kill')) {
                    posix_kill($this->pid, SIGKILL);
                }
            });
        }

        $this->cleanup();
    }

    public function scrollback(): string
    {
        return $this->scrollback;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    private function attachLoop(): void
    {
        if ($this->stream === null || !is_resource($this->stream)) {
            return;
        }

        $this->watcherId = Loop::get()->onReadable($this->stream, function (): void {
            if ($this->stream === null || !is_resource($this->stream)) {
                $this->cleanup();
                return;
            }

            $chunk = fread($this->stream, 16384);

            if ($chunk !== false && $chunk !== '') {
                $this->queueOutput($chunk);
            }

            if ($chunk === '' && feof($this->stream)) {
                $this->cleanup();
            }
        });
    }

    private function queueOutput(string $chunk): void
    {
        $this->pendingOutput .= $chunk;
        $this->scrollback .= $chunk;

        if (strlen($this->scrollback) > self::MAX_SCROLLBACK) {
            $this->scrollback = substr($this->scrollback, -self::MAX_SCROLLBACK);
        }

        if ($this->flushTimer === null) {
            $this->flushTimer = Loop::get()->delay(self::FLUSH_INTERVAL_S, function (): void {
                $this->flushTimer = null;
                $this->flushOutput();
            });
        }
    }

    private function flushOutput(): void
    {
        if ($this->pendingOutput === '') {
            return;
        }

        $out = $this->pendingOutput;
        $this->pendingOutput = '';

        if ($this->onOutput !== null) {
            ($this->onOutput)($this->id, $out);
        }
    }

    private function cleanup(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        // Flush any remaining output before announcing exit
        if ($this->flushTimer !== null) {
            Loop::get()->cancel($this->flushTimer);
            $this->flushTimer = null;
        }
        $this->flushOutput();

        if ($this->watcherId !== null) {
            Loop::get()->cancel($this->watcherId);
            $this->watcherId = null;
        }

        if ($this->process !== null && is_resource($this->process)) {
            $status = proc_get_status($this->process);
            $exitCode = (int) ($status['exitcode'] ?? 0);
            if ($status['signaled'] ?? false) {
                $exitCode = 128 + (int) ($status['termsig'] ?? 0);
            }

            // Only kill if the child process is still running
            if (($status['running'] ?? false) && $this->pid > 0 && function_exists('posix_kill')) {
                posix_kill($this->pid, SIGKILL);
            }

            if ($this->stream !== null && is_resource($this->stream)) {
                fclose($this->stream);
                $this->stream = null;
            }

            $closeCode = proc_close($this->process);
            if ($exitCode === -1 && $closeCode !== -1) {
                $exitCode = $closeCode;
            }
            $this->exitCode = $exitCode;
            $this->process = null;
        }

        if ($this->onExit !== null) {
            ($this->onExit)($this->id, $this->exitCode);
        }
    }

    private function resolveShell(): string
    {
        $custom = getenv('SHELL') ?: (getenv('PIG_SHELL') ?: null);
        if ($custom && is_executable($custom)) {
            return $custom;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            if (is_executable('/bin/zsh')) {
                return '/bin/zsh';
            }
            if (is_executable('/bin/bash')) {
                return '/bin/bash';
            }
        }

        if (is_executable('/bin/bash')) {
            return '/bin/bash';
        }

        if (is_executable('/bin/sh')) {
            return '/bin/sh';
        }

        return 'bash';
    }

    private function findSlaveDevice(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $out = shell_exec("lsof -a -p {$pid} -d 0 2>/dev/null");
            if ($out && preg_match("#(/dev/\\S+)#", $out, $m)) {
                return $m[1];
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            if (is_link("/proc/{$pid}/fd/0")) {
                $link = readlink("/proc/{$pid}/fd/0");
                if ($link && str_starts_with($link, '/dev/')) {
                    return $link;
                }
            }
        }

        return null;
    }
}
