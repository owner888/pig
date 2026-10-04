<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Config;
use Pig\Tui\Style;

/**
 * `pig web start|stop|status|restart [-d] [--port N] [--host H]` — the web UI as a daemon.
 *
 * Step ① of the web mode's new process model (the entry in CLAUDE.md under "Three ways in"):
 * the subcommand and the daemon, over `--mode web` exactly as it stands. The session pool and
 * the per-tab children are steps ② and ③ and change what runs *inside* the daemon, not this.
 *
 * Five decisions, each the smaller of two:
 *
 * - **The daemon is `pig --mode web` re-exec'd, not a second mode.** `start -d` double-forks,
 *   `setsid()`s, redirects the three standard streams and then `pcntl_exec()`s the same binary
 *   with `--mode web` — so a daemonised server and a foreground one run byte-identical code,
 *   and `WebMode`, which was tested as the foreground, needs no second test for the background.
 *   The two `fork()`s are the classic recipe: the first so the child can `setsid()` (a group
 *   leader cannot), the second so the grandchild can never reacquire a controlling terminal.
 * - **One pid file, under `Config::home()`**, so `PIG_HOME` moves it with everything else and two
 *   developers on one machine do not fight over `/tmp/pig-web.pid`. One daemon per home; a
 *   second `start` says so rather than binding the next port up, because two daemons on
 *   adjacent ports is the thing nobody can tell apart from the browser.
 * - **`status` trusts the pid file only after `kill -0`.** A pid file left by a daemon that died
 *   (power cut, `kill -9`) names a pid the kernel has since reused or freed; `kill(pid, 0)`
 *   answers whether *something* is there, and a stale file is removed on the way past rather
 *   than reported as a running server. This is `Loop::poll()`'s `is_resource()` rule one
 *   layer up: check the thing, not the record of the thing.
 * - **`stop` is SIGTERM, a wait, then SIGKILL.** `WebMode` has no signal handler yet, so
 *   SIGTERM is the default disposition — immediate exit — which is fine for a server whose
 *   children are the ones holding state. The wait is bounded (3s) and the kill is the floor
 *   under it: a `stop` that can hang is a `stop` nobody trusts.
 * - **`ext-posix` is probed, not required.** `posix_setsid()` and `posix_kill()` are the two
 *   calls there is no other spelling of; everything else in pig gets by on `pcntl`. A build
 *   without posix (rare — it ships enabled everywhere pcntl does) refuses `start -d` and `stop`
 *   by name and still runs `start` in the foreground, rather than making the whole binary
 *   require an extension for one subcommand.
 *
 * Standard streams in the daemon go to a log file beside the pid file, not to `/dev/null`:
 * a server that dies at 3am with its last words thrown away is the one failure this class
 * exists to make debuggable. The log is truncated on each `start`, so it is the current run's.
 */
final class WebDaemon
{
    public const int DEFAULT_PORT = 8088;
    public const string DEFAULT_HOST = '127.0.0.1';

    /** How long `stop` waits for SIGTERM before reaching for SIGKILL. */
    private const float STOP_TIMEOUT = 3.0;

    /**
     * @param list<string> $argv the arguments after `pig web`
     * @param string $binary the `pig` to re-exec for `-d`; `bin/pig` passes `$argv[0]`
     * @param (callable(list<string>): never)|null $exec stand-in for `pcntl_exec()` in tests
     */
    private readonly string $binary;

    public function __construct(
        string $binary,
        private readonly ?string $home = null,
        private readonly mixed $exec = null,
    ) {
        // Absolute before anything else happens: the daemon `chdir('/')`s before it execs, and
        // `$argv[0]` is `bin/pig` when pig was started from its checkout — which from `/` names
        // nothing. Found live: `start -d` printed ✔, the grandchild's exec failed on `/bin/pig`,
        // and `status` two seconds later said "not running".
        $this->binary = realpath($binary) ?: $binary;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'start';
        $rest = array_slice($argv, 1);

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $this->help();

            return 0;
        }

        return match ($command) {
            'start' => $this->start($rest),
            'stop' => $this->stop(),
            'status' => $this->status(),
            'restart' => $this->restart($rest),
            default => $this->unknown($command),
        };
    }

    public function pidFile(): string
    {
        return ($this->home ?? Config::home()) . '/web.pid';
    }

    public function logFile(): string
    {
        return ($this->home ?? Config::home()) . '/web.log';
    }

    /**
     * The daemon's pid, or null when there is none — a pid file naming a process that has gone
     * is removed here and reads as none, not as a running server.
     */
    public function runningPid(): ?int
    {
        $file = $this->pidFile();

        if (!is_file($file)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($file));

        if ($pid <= 0 || !self::alive($pid)) {
            unlink($file);

            return null;
        }

        return $pid;
    }

    // ---- the four commands ----------------------------------------------------------------

    /** @param list<string> $args */
    private function start(array $args): int
    {
        $daemon = in_array('-d', $args, true) || in_array('--daemon', $args, true);
        [$host, $port, $problem] = self::hostAndPort($args);

        if ($problem !== null) {
            fwrite(STDERR, Style::red("Error: {$problem}\n"));

            return 1;
        }

        if (($pid = $this->runningPid()) !== null) {
            fwrite(STDERR, Style::yellow("The web UI is already running (pid {$pid}). `pig web stop` first, or `pig web restart`.\n"));

            return 1;
        }

        // `--cwd` pinned to where `pig web start` was typed, because the daemon `chdir('/')`s
        // before it execs (a daemon must not hold a directory somebody wants to unmount) and
        // the session would otherwise belong to `/`. Found live: `cwd: /` in `/api/state`.
        $cwd = getcwd() ?: '.';
        $argv = [$this->binary, '--mode', 'web', '--cwd', $cwd, '--web-host', $host, '--web-port', (string) $port];

        if (!$daemon) {
            // Foreground: become `pig --mode web`. No pid file, because there is a terminal
            // holding this one and Ctrl+C is its `stop`.
            return $this->exec($argv);
        }

        if (!self::hasPosix()) {
            fwrite(STDERR, Style::red("Error: `pig web start -d` needs ext-posix (for setsid). Run `pig web start` in the foreground, or install the extension.\n"));

            return 1;
        }

        $home = $this->home ?? Config::home();
        if (!is_dir($home) && !mkdir($home, 0700, true) && !is_dir($home)) {
            fwrite(STDERR, Style::red("Error: cannot create {$home}\n"));

            return 1;
        }

        try {
            $pid = $this->daemonise($argv);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, Style::red("Error: {$e->getMessage()}\n"));

            return 1;
        }

        echo Style::green('✔ Web UI daemon started') . Style::dim(" (pid {$pid}) at ") . Style::bold("http://{$host}:{$port}") . "\n";
        echo Style::dim("  log: {$this->logFile()}\n  stop: pig web stop\n");

        return 0;
    }

    private function stop(): int
    {
        $pid = $this->runningPid();

        if ($pid === null) {
            echo Style::dim("The web UI is not running.\n");

            return 0;
        }

        if (!self::hasPosix()) {
            fwrite(STDERR, Style::red("Error: `pig web stop` needs ext-posix (for kill). The daemon is pid {$pid}; `kill {$pid}` stops it.\n"));

            return 1;
        }

        posix_kill($pid, SIGTERM);

        $deadline = microtime(true) + self::STOP_TIMEOUT;
        while (self::alive($pid) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if (self::alive($pid)) {
            posix_kill($pid, SIGKILL);
            usleep(100_000);
        }

        if (is_file($this->pidFile())) {
            unlink($this->pidFile());
        }

        echo Style::green("✔ Web UI daemon stopped") . Style::dim(" (pid {$pid})") . "\n";

        return 0;
    }

    private function status(): int
    {
        $pid = $this->runningPid();

        if ($pid === null) {
            echo "pig web: " . Style::dim('not running') . "\n";

            return 3; // LSB's "program is not running"
        }

        echo "pig web: " . Style::green('running') . Style::dim(" (pid {$pid}, log {$this->logFile()})") . "\n";

        return 0;
    }

    /** @param list<string> $args */
    private function restart(array $args): int
    {
        if ($this->runningPid() !== null) {
            $status = $this->stop();
            if ($status !== 0) {
                return $status;
            }
        }

        // A restart is a daemon restart: nobody types `restart` for a foreground server, and
        // one in the foreground would have had no pid file to stop.
        if (!in_array('-d', $args, true) && !in_array('--daemon', $args, true)) {
            $args[] = '-d';
        }

        return $this->start($args);
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, Style::red("Error: unknown command `pig web {$command}`.\n\n"));
        $this->help(STDERR);

        return 1;
    }

    // ---- the daemon ------------------------------------------------------------------------

    /**
     * Double-fork into the background and exec `$argv` there; returns the daemon's pid to the
     * parent. The grandchild never returns from here.
     *
     * @param list<string> $argv
     */
    private function daemonise(array $argv): int
    {
        // The pid has to travel back from the grandchild, and the parent cannot `wait()` for a
        // grandchild. A pipe is the channel: the grandchild writes its pid before exec and the
        // parent reads it after the middle child has exited.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Could not create a pipe to the daemon.');
        }
        [$parentEnd, $childEnd] = $pair;

        $first = pcntl_fork();
        if ($first === -1) {
            throw new \RuntimeException('fork() failed.');
        }

        if ($first > 0) {
            // Parent: reap the middle child, then read the grandchild's pid.
            fclose($childEnd);
            pcntl_waitpid($first, $status);
            $pid = (int) trim((string) fgets($parentEnd));
            fclose($parentEnd);

            if ($pid <= 0) {
                throw new \RuntimeException("The daemon did not start; see {$this->logFile()}.");
            }

            // The pid arrives *before* the exec, so a daemon whose exec failed has already
            // reported itself alive. Give it a moment and ask the kernel: a ✔ for a process
            // that is gone is the first version of this, and it cost a live run to find.
            usleep(300_000);
            if (!self::alive($pid)) {
                if (is_file($this->pidFile())) {
                    unlink($this->pidFile());
                }
                $tail = is_file($this->logFile()) ? trim((string) file_get_contents($this->logFile())) : '';

                throw new \RuntimeException("The daemon exited at once" . ($tail !== '' ? ": {$tail}" : "; see {$this->logFile()}."));
            }

            return $pid;
        }

        // Middle child: new session, fork again, exit — so the grandchild is not a session
        // leader and can never reacquire a controlling terminal.
        fclose($parentEnd);
        posix_setsid();

        $second = pcntl_fork();
        if ($second === -1) {
            exit(1);
        }
        if ($second > 0) {
            exit(0);
        }

        // Grandchild: the daemon. Write the pid file and tell the parent, then point the three
        // standard streams at the log and become `pig --mode web`.
        $pid = getmypid();
        file_put_contents($this->pidFile(), $pid . "\n");
        fwrite($childEnd, $pid . "\n");
        fclose($childEnd);

        chdir('/');
        umask(0022);

        $log = $this->logFile();
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        // Reopened in order, so they land on fds 0, 1, 2 — the ones the exec'd process inherits.
        $GLOBALS['__pig_stdin'] = fopen('/dev/null', 'r');
        $GLOBALS['__pig_stdout'] = fopen($log, 'w');
        $GLOBALS['__pig_stderr'] = fopen($log, 'a');

        $this->exec($argv);
    }

    /**
     * Replace this process with `$argv`; never returns on success. Separate so a test can
     * hand in a closure and watch what would have been exec'd.
     *
     * @param list<string> $argv
     */
    private function exec(array $argv): never
    {
        if ($this->exec !== null) {
            ($this->exec)($argv);
        }

        // `pcntl_exec` wants the program and its arguments apart, and PHP scripts are run through
        // the interpreter — `$argv[0]` is `bin/pig`, not an ELF.
        $php = PHP_BINARY;
        pcntl_exec($php, $argv);

        // Only reached when exec failed.
        fwrite(STDERR, "exec failed: {$php} " . implode(' ', $argv) . "\n");
        exit(127);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function alive(int $pid): bool
    {
        if (self::hasPosix()) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1; // EPERM: alive, not ours
        }

        // Without posix: `kill -0` through the shell. Same question, slower.
        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private static function hasPosix(): bool
    {
        return function_exists('posix_setsid') && function_exists('posix_kill');
    }

    /**
     * @param list<string> $args
     * @return array{string, int, ?string} host, port, and a complaint when one was malformed
     */
    private static function hostAndPort(array $args): array
    {
        $host = self::DEFAULT_HOST;
        $port = self::DEFAULT_PORT;

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--port' || $arg === '-p') {
                $value = $args[++$i] ?? '';
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    return [$host, $port, "--port wants a number from 1 to 65535, not '{$value}'"];
                }
                $port = (int) $value;
            } elseif (str_starts_with($arg, '--port=')) {
                $value = substr($arg, 7);
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    return [$host, $port, "--port wants a number from 1 to 65535, not '{$value}'"];
                }
                $port = (int) $value;
            } elseif ($arg === '--host') {
                $host = $args[++$i] ?? '';
                if ($host === '') {
                    return [$host, $port, '--host wants an address'];
                }
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }
        }

        return [$host, $port, null];
    }

    /** @param resource $to */
    private function help($to = STDOUT): void
    {
        fwrite($to, <<<TXT
        pig web — the browser UI as a server

          pig web start [-d] [--port N] [--host H]   start (foreground, or -d for a daemon)
          pig web stop                                stop the daemon
          pig web status                              is it running? (exit 0 yes, 3 no)
          pig web restart [--port N] [--host H]       stop, then start -d

        Defaults: --host 127.0.0.1 --port 8088. The daemon writes its pid and log under
        \$PIG_HOME (~/.pig/agent): web.pid and web.log.

        TXT);
    }
}
