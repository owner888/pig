<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Pty;

use Closure;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\Shell;

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
        $argv = $this->command !== null && $this->command !== '' ? [$shell, '-c', $this->command] : [$shell];

        $descriptors = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pty'],
        ];

        $env = array_merge(getenv(), [
            'TERM' => 'xterm-256color',
            'COLORTERM' => 'truecolor',
            'COLORFGBG' => '15;0',
        ], self::utf8Locale());

        $workingDir = is_dir($this->cwd) ? $this->cwd : (getenv('HOME') ?: '/');

        // Safe error handler capture rather than @ suppression
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });

        try {
            $proc = proc_open(self::launcher($argv, $this->cols, $this->rows), $descriptors, $pipes, $workingDir, $env);
        } finally {
            restore_error_handler();
        }

        $notice = null;
        if (!is_resource($proc) || !isset($pipes[0]) || !is_resource($pipes[0])) {
            // No pty on this PHP: the shell still runs, over plain pipes, and the terminal says so
            // on its first line — vim, less and anything else that wants a terminal will refuse.
            $notice = "\x1b[33m[pig] 这台机器上的 PHP 打不开 pty（" . ($warning ?? 'proc_open failed')
                . "），终端以管道模式运行：vim / less 等全屏程序无法使用。\x1b[0m\r\n";
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($argv, $descriptors, $pipes, $workingDir, $env);
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
        stream_set_write_buffer($this->stream, 0);

        // Close redundant master descriptors created by proc_open
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $status = proc_get_status($this->process);
        $this->pid = (int) ($status['pid'] ?? 0);
        $this->running = (bool) ($status['running'] ?? true);

        // The size is already on the pty: the launcher set it before the shell started. The slave's
        // name is looked up on the first resize, not here — right after the fork the child may not
        // have its pty on fd 0 yet, and what is there is this server's own terminal.

        // Attach to Event Loop
        $this->attachLoop();

        if ($notice !== null) {
            $this->queueOutput($notice);
        }
    }

    /**
     * The command line that starts `$argv` the way forkpty() / node-pty do: in a session of its
     * own with the pty as its controlling terminal, at the right size.
     *
     * proc_open() only points fds 0–2 at the pty's slave; the child stays in this server's
     * session. Without a controlling terminal nothing reaches the shell's foreground job: Ctrl+C
     * and Ctrl+Z are plain bytes, a resize sends no SIGWINCH, bash says "no job control", and
     * /dev/tty is either missing (the daemon) or the terminal `pig web` was started from. So a
     * short PHP program goes in between: setsid(), open the slave (a session leader's first open
     * of a terminal makes it the controlling one — Linux and XNU alike), `stty` the size on its
     * stdin, then exec the shell. When the session cannot be had, it says so on the terminal and
     * runs the shell anyway.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function launcher(array $argv, int $cols, int $rows): array
    {
        $code = <<<'PHP'
            $cols = (int) $argv[1];
            $rows = (int) $argv[2];
            $command = array_slice($argv, 3);
            $failed = null;
            if (!function_exists('posix_setsid') || !function_exists('posix_ttyname')) {
                $failed = 'ext-posix is not loaded';
            } elseif (posix_setsid() < 0) {
                $failed = 'setsid: ' . posix_strerror(posix_get_last_error());
            } else {
                $tty = posix_ttyname(STDIN);
                set_error_handler(static fn (): bool => true);
                $handle = is_string($tty) ? fopen($tty, 'r+') : false;
                restore_error_handler();
                if ($handle === false) {
                    $failed = 'cannot open ' . (is_string($tty) ? $tty : 'the pty');
                } else {
                    fclose($handle);
                }
            }
            if ($failed !== null) {
                fwrite(STDERR, "\033[33m[pig] 终端没有控制终端（{$failed}）：Ctrl+C / Ctrl+Z 与作业控制不可用。\033[0m\r\n");
            }
            exec(sprintf('stty rows %d cols %d 2>/dev/null', $rows, $cols));
            $program = $command[0];
            if (!str_contains($program, '/')) {
                foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                    if ($dir !== '' && is_executable("{$dir}/{$program}")) {
                        $program = "{$dir}/{$program}";
                        break;
                    }
                }
            }
            pcntl_exec($program, array_slice($command, 1));
            fwrite(STDERR, "[pig] cannot run {$command[0]}: " . pcntl_strerror(pcntl_get_last_error()) . "\r\n");
            exit(127);
            PHP;

        return [PHP_BINARY, '-r', $code, '--', (string) $cols, (string) $rows, ...$argv];
    }

    /**
     * The locale the shell gets: the server's own when it is already UTF-8, otherwise one that
     * exists on the platform. A forced `en_US.UTF-8` where it is not installed (most Linux images
     * only ship `C.UTF-8`) leaves vim in latin1, and latin1 output is not UTF-8 text.
     *
     * @return array<string, string>
     */
    private static function utf8Locale(): array
    {
        foreach (['LC_ALL', 'LC_CTYPE', 'LANG'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return preg_match('/utf-?8/i', $value) === 1 ? [] : ['LC_ALL' => self::defaultUtf8Locale()];
            }
        }

        return ['LANG' => self::defaultUtf8Locale()];
    }

    private static function defaultUtf8Locale(): string
    {
        return PHP_OS_FAMILY === 'Darwin' ? 'en_US.UTF-8' : 'C.UTF-8';
    }

    public function input(string $data): void
    {
        if (!$this->running || $this->stream === null || !is_resource($this->stream)) {
            return;
        }

        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
        fflush($this->stream);
    }

    public function resize(int $cols, int $rows): void
    {
        $this->cols = max(10, min(500, $cols));
        $this->rows = max(4, min(200, $rows));

        if ($this->slaveDevice === null && $this->pid > 0) {
            $this->slaveDevice = $this->findSlaveDevice($this->pid);
        }

        if ($this->slaveDevice !== null && file_exists($this->slaveDevice)) {
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            // The pty is the shell's controlling terminal (see launcher()), so the kernel sends
            // SIGWINCH to whatever job is in the foreground — vim, not the shell waiting on it.
            exec(sprintf('stty %s %s rows %d cols %d 2>/dev/null', $flag, escapeshellarg($this->slaveDevice), $this->rows, $this->cols));
        }
    }

    public function kill(): void
    {
        if ($this->running && $this->pid > 0 && function_exists('posix_kill')) {
            Shell::killTree($this->pid);
            posix_kill($this->pid, SIGTERM);
            // Grace period before SIGKILL
            Loop::get()->delay(0.2, function (): void {
                if ($this->running && $this->pid > 0 && function_exists('posix_kill')) {
                    Shell::killTree($this->pid);
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

            // Once the shell is gone, reading the master is EIO on Linux (false, with a notice)
            // and EOF on macOS. Both are the end: a false that is not acted on fires this
            // watcher again at once, forever, with the CPU pinned.
            set_error_handler(static fn (): bool => true);
            try {
                $chunk = fread($this->stream, 16384);
            } finally {
                restore_error_handler();
            }

            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                $this->cleanup();

                return;
            }

            if ($chunk !== '') {
                $this->queueOutput($chunk);
            }
        });
    }

    private function queueOutput(string $chunk): void
    {
        $this->pendingOutput .= $chunk;
        $this->scrollback .= $chunk;

        if (strlen($this->scrollback) > self::MAX_SCROLLBACK) {
            // Cut on a character: a scrollback that starts mid-character is not UTF-8 text.
            $this->scrollback = ltrim(substr($this->scrollback, -self::MAX_SCROLLBACK), "\x80..\xBF");
        }

        if ($this->flushTimer === null) {
            $this->flushTimer = Loop::get()->delay(self::FLUSH_INTERVAL_S, function (): void {
                $this->flushTimer = null;
                $this->flushOutput();
            });
        }
    }

    /**
     * Hands what was read to `$onOutput` as text — whole characters only.
     *
     * The pty is a byte stream and the reads cut it wherever they like, so a character can be
     * split between two flushes; vim's start-up probes write bytes that are no character at all.
     * The bytes go out as a JSON string, and one invalid byte used to make `json_encode()` give
     * up on the whole message: vim's first screen, or any frame cut inside a 你, never arrived.
     * So the tail of an unfinished character waits for the next flush (node-pty's StringDecoder
     * does the same), and what is not UTF-8 at all becomes U+FFFD — at the end, `$final`, the
     * tail goes too.
     */
    private function flushOutput(bool $final = false): void
    {
        if ($this->pendingOutput === '') {
            return;
        }

        $cut = $final ? strlen($this->pendingOutput) : self::wholeCharacters($this->pendingOutput);
        if ($cut === 0) {
            return;
        }

        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $out = mb_scrub(substr($this->pendingOutput, 0, $cut), 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
        $this->pendingOutput = (string) substr($this->pendingOutput, $cut);

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
        $this->flushOutput(final: true);

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

    /**
     * How many leading bytes of `$bytes` end on a character boundary: all of them, unless the
     * last one to four are the start of a UTF-8 sequence that has not finished arriving.
     */
    private static function wholeCharacters(string $bytes): int
    {
        $length = strlen($bytes);
        for ($back = 1; $back <= min(4, $length); $back++) {
            $byte = ord($bytes[$length - $back]);
            if (($byte & 0xC0) === 0x80) {
                continue; // a continuation byte: the lead is further back
            }
            $needs = match (true) {
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                default => 1,
            };

            return $needs > $back ? $length - $back : $length;
        }

        return $length;
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

    /**
     * The slave end of this pty, as the shell's fd 0 names it. Only a pty slave counts, and never
     * the terminal this server itself runs on: `stty` on that would resize the person's own window.
     */
    private function findSlaveDevice(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }

        $device = null;
        if (PHP_OS_FAMILY === 'Darwin') {
            $out = shell_exec("lsof -a -p {$pid} -d 0 2>/dev/null");
            if (is_string($out) && preg_match('#(/dev/ttys\d+)#', $out, $m) === 1) {
                $device = $m[1];
            }
        } elseif (PHP_OS_FAMILY === 'Linux' && is_link("/proc/{$pid}/fd/0")) {
            $link = readlink("/proc/{$pid}/fd/0");
            if (is_string($link) && preg_match('#^/dev/pts/\d+$#', $link) === 1) {
                $device = $link;
            }
        }

        $own = function_exists('posix_ttyname') && stream_isatty(STDIN) ? posix_ttyname(STDIN) : false;

        return $device !== null && $device !== $own ? $device : null;
    }
}
