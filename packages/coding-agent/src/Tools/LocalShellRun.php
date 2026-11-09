<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Tui\Process;

/**
 * One command's process, pipes and timers, for `LocalShellOperations::exec()`.
 *
 * Split out because a command has a good deal of state while it runs — two pipes, watchers, a
 * timer — and threading all of it through one method is how the upstream version ended up two
 * hundred lines of nested callbacks.
 *
 * @internal
 */
final class LocalShellRun
{
    private const int READ_CHUNK = 65536;

    public bool $aborted = false;

    public bool $timedOut = false;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var array<int, string> loop watcher ids, by pipe */
    private array $watchers = [];

    private int $open = 0;

    private ?Deferred $finished = null;

    /** @param Closure(string, int): void $onData */
    public function __construct(private readonly Closure $onData)
    {
    }

    /** @param array<string, string>|null $env */
    public function start(string $command, string $cwd, ShellConfig $shell, ?array $env): void
    {
        $fromStdin = $shell->commandTransport === 'stdin';

        // stdin is /dev/null rather than inherited: a command that decides to prompt
        // would otherwise sit there waiting for a person who is not watching it. The one
        // exception is a shell that reads its command from there (the legacy WSL bash).
        $descriptors = [
            0 => $fromStdin ? ['pipe', 'r'] : ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $argv = $shell->argv($command);

        // Upstream's `spawn(..., { detached: true })` calls `setsid()` in the child before exec,
        // which detaches `/dev/tty`. Without it, `proc_open()` leaves the child in pig's session
        // with pig's controlling terminal, so a command that opens `/dev/tty` directly (`ssh`
        // asking about a host key, `git` or `sudo` asking for a password) writes onto the raw TUI
        // screen — scrolling the alternate screen behind the differential renderer's back — and
        // hangs waiting for an answer nobody can type.
        // `Process::inNewSession()`, which the clipboard writers go through too.
        if (str_contains($argv[0], '/')) {
            $argv = Process::inNewSession($argv);
        }

        $process = proc_open($argv, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new AgentError("Could not start a shell ({$shell->shell})");
        }

        if ($fromStdin) {
            fwrite($pipes[0], $command);
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $this->finished = new Deferred();
        $this->open = 2;

        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
            $this->watchers[$fd] = Loop::get()->onReadable($pipes[$fd], function () use ($fd): void {
                $this->read($fd);
            });
        }
    }

    /** Suspend until the command finishes, is aborted, or runs out of time; its exit code. */
    public function wait(?AbortSignal $signal, ?float $timeout): int
    {
        $listener = $signal?->onAbort(function (): void {
            $this->aborted = true;
            $this->stop();
        });

        $timer = $timeout !== null && $timeout > 0
            ? Loop::get()->delay($timeout, function (): void {
                $this->timedOut = true;
                $this->stop();
            })
            : null;

        // A background grandchild (`(cmd) &`) inherits the pipes unless explicitly closed.
        // If the main shell has already exited, waiting for pipe EOF hangs indefinitely.
        // Periodically check if the primary process has finished; once it exits, drain
        // remaining output and resolve without waiting for orphaned background grandchildren.
        $pollTimer = null;
        $drainTimer = null;
        $finalStatus = null;
        $pollProcess = function () use (&$pollProcess, &$pollTimer, &$drainTimer, &$finalStatus): void {
            $pollTimer = Loop::get()->delay(0.1, function () use (&$pollProcess, &$drainTimer, &$finalStatus): void {
                if (!is_resource($this->process)) {
                    return;
                }

                $status = proc_get_status($this->process);

                if ($status['running'] !== true) {
                    $finalStatus = $status;
                    $drainTimer = Loop::get()->delay(0.05, function (): void {
                        if ($this->finished !== null && !$this->finished->future->isComplete()) {
                            foreach ([1, 2] as $fd) {
                                if (isset($this->pipes[$fd]) && is_resource($this->pipes[$fd])) {
                                    $this->read($fd);
                                }
                            }
                            $this->finished->complete(null);
                        }
                    });

                    return;
                }

                $pollProcess();
            });
        };
        $pollProcess();

        try {
            $this->finished?->future->await();

            if ($pollTimer !== null) {
                Loop::get()->cancel($pollTimer);
                $pollTimer = null;
            }

            if ($drainTimer !== null) {
                Loop::get()->cancel($drainTimer);
                $drainTimer = null;
            }

            // The pipes closing is not the command exiting, and the code is a fact about the
            // process — see `Process::awaitFinish()`, which is also what keeps that wait from
            // being a freeze. Inside the try, so the timer and the signal above still reach a
            // command that shut its own output and carried on.
            $exit = Process::awaitFinish($this->process, $finalStatus);
            $this->process = null;
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
            }

            if ($pollTimer !== null) {
                Loop::get()->cancel($pollTimer);
            }

            if ($drainTimer !== null) {
                Loop::get()->cancel($drainTimer);
            }

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            foreach (array_keys($this->watchers) as $fd) {
                $this->closePipe($fd);
            }
        }

        return $exit;
    }

    /** Read what is there and hand it on, as it came, with the stream it came from. */
    private function read(int $fd): void
    {
        $pipe = $this->pipes[$fd] ?? null;

        if (!is_resource($pipe)) {
            return;
        }

        $chunk = fread($pipe, self::READ_CHUNK);

        if ($chunk === false || $chunk === '') {
            if (feof($pipe)) {
                $this->closePipe($fd);
            }

            return;
        }

        ($this->onData)($chunk, $fd);
    }

    private function closePipe(int $fd): void
    {
        // Cancelled before the close: stream_select() drops a closed stream silently and
        // then fails with an error that names nothing.
        if (isset($this->watchers[$fd])) {
            Loop::get()->cancel($this->watchers[$fd]);
            unset($this->watchers[$fd]);
        }

        if (is_resource($this->pipes[$fd] ?? null)) {
            fclose($this->pipes[$fd]);
        }

        unset($this->pipes[$fd]);
        $this->open--;

        if ($this->open <= 0 && $this->finished !== null && !$this->finished->future->isComplete()) {
            $this->finished->complete(null);
        }
    }

    /** Kill the command and everything it started. */
    private function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running'] === true) {
            Shell::killTree((int) $status['pid']);
        }
    }
}
