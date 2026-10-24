<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Tui\Process;

/**
 * One running command.
 *
 * Split out because a command has a good deal of state while it runs — two pipes, a
 * rolling buffer, a spill file, watchers, a timer — and threading all of it through one
 * method is how the upstream version ended up two hundred lines of nested callbacks.
 *
 * @internal
 */
final class Run
{
    /** Past this, output is spilled to a file so nothing is lost when it is truncated. */
    private const int SPILL_AFTER = Truncate::MAX_BYTES;

    /** Kept in memory: twice the limit, so truncation always has whole lines to work with. */
    private const int KEEP_BYTES = Truncate::MAX_BYTES * 2;

    private const int READ_CHUNK = 65536;

    /** Upstream's `MAX_PENDING_ANSI_LENGTH`: the longest unfinished escape sequence held back between reads. */
    private const int MAX_PENDING_ANSI_LENGTH = 256;

    /**
     * The least time between two live updates of the output (`$onUpdate`).
     *
     * Each one is the whole tail — up to `Truncate::MAX_BYTES` — and a build log arrives in many
     * small reads a second, so updating on every read sent megabytes a second of the same text:
     * over `pig web` to a page across a tunnel, more than it could take, and the server's send
     * buffer for that page passed its limit again and again. Ten a second is live to a reader;
     * the last one is never lost (`flushUpdate()`), and the result at the end is complete anyway.
     */
    private const float UPDATE_EVERY = 0.1;

    private float $lastUpdateAt = 0.0;

    private ?string $updateTimer = null;

    public bool $aborted = false;

    public bool $timedOut = false;

    public ?string $spillPath = null;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var array<int, string> loop watcher ids, by pipe */
    private array $watchers = [];

    /**
     * Upstream's `pendingAnsi` (#10504), by pipe: the end of the last read that the next one
     * completes — an unfinished escape sequence, or a UTF-8 character cut short (upstream's
     * `TextDecoder` with `stream: true` holds that back for it).
     *
     * @var array<int, string>
     */
    private array $pending = [];

    /** @var resource|null */
    private mixed $spill = null;

    /** The tail of the output, trimmed as it grows. */
    private string $buffer = '';

    private int $totalBytes = 0;

    private int $totalLines = 0;

    private int $open = 0;

    private ?Deferred $finished = null;

    /** What the command reported, once `wait()` has waited for it. */
    private int $exit = 0;

    /**
     * @param Closure(AgentToolResult): void|null $onUpdate
     * @param ShellConfig|null $shell what to run the command with; null is `Shell::config()`, the
     *        resolved bash
     * @param array<string, string>|null $env the command's whole environment; null inherits this
     *        process's
     * @param string $spillPrefix the spill file's name prefix — upstream's `tempFilePrefix`,
     *        `pig-bash` or `pig-powershell`
     * @param (Closure(string): void)|null $onChunk each piece of output as it arrives, cleaned —
     *        upstream's `onChunk`, which `AgentSession::executeBash()` turns into
     *        `bash_execution_update` deltas; `$onUpdate` is the tail so far, for a screen
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $command,
        private readonly ?Closure $onUpdate,
        private readonly ?ShellConfig $shell = null,
        private readonly ?array $env = null,
        private readonly string $spillPrefix = 'pig-bash',
        private readonly ?Closure $onChunk = null,
    ) {
    }

    public function start(): void
    {
        $shell = $this->shell ?? Shell::config();
        $fromStdin = $shell->commandTransport === 'stdin';

        // stdin is /dev/null rather than inherited: a command that decides to prompt
        // would otherwise sit there waiting for a person who is not watching it. The one
        // exception is a shell that reads its command from there (the legacy WSL bash).
        $descriptors = [
            0 => $fromStdin ? ['pipe', 'r'] : ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($shell->argv($this->command), $descriptors, $pipes, $this->cwd, $this->env);

        if (!is_resource($process)) {
            throw new AgentError("Could not start a shell ({$shell->shell})");
        }

        if ($fromStdin) {
            fwrite($pipes[0], $this->command);
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

    /** Suspend until the command finishes, is aborted, or runs out of time. */
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
            $this->exit = Process::awaitFinish($this->process, $finalStatus);
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
        }

        return $this->close();
    }

    public function output(): string
    {
        return $this->buffer;
    }

    /**
     * Upstream's `OutputAccumulator.readFullOutput(maxBytes)`, for the structured result a codemode
     * script gets: the whole output when it is at most $maxBytes, otherwise its first and last
     * halves around `[... N bytes omitted ...]`, cut at character boundaries. Read from the spill
     * file when there is one, since the buffer holds only the tail; called after `wait()`, which
     * has closed it.
     *
     * @return array{content: string, truncated: bool}
     */
    public function fullOutput(int $maxBytes): array
    {
        if ($this->spillPath === null || !is_file($this->spillPath)) {
            return ['content' => $this->buffer, 'truncated' => false];
        }

        $size = (int) filesize($this->spillPath);

        if ($size <= $maxBytes) {
            $whole = file_get_contents($this->spillPath);

            if ($whole === false) {
                throw new AgentError("Could not read the command's output back from {$this->spillPath}");
            }

            return ['content' => $whole, 'truncated' => false];
        }

        $headBytes = intdiv($maxBytes, 2);
        $tailBytes = $maxBytes - $headBytes;
        $head = file_get_contents($this->spillPath, false, null, 0, $headBytes);
        $tail = file_get_contents($this->spillPath, false, null, $size - $tailBytes, $tailBytes);

        if ($head === false || $tail === false) {
            throw new AgentError("Could not read the command's output back from {$this->spillPath}");
        }

        // A head cut inside a character loses the incomplete sequence, and the tail skips the
        // continuation bytes it starts with — upstream's streaming decode and its loop.
        $head = self::withoutPartialEnd($head);
        $tail = ltrim($tail, "\x80..\xBF");
        $omitted = $size - $headBytes - $tailBytes;

        return ['content' => "{$head}\n\n[... {$omitted} bytes omitted ...]\n\n{$tail}", 'truncated' => true];
    }

    /** $bytes without a UTF-8 sequence its end cuts short. */
    private static function withoutPartialEnd(string $bytes): string
    {
        $length = strlen($bytes);

        for ($back = 1; $back <= min(4, $length); $back++) {
            $byte = ord($bytes[$length - $back]);

            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            $needs = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $needs > $back ? substr($bytes, 0, $length - $back) : $bytes;
        }

        return $bytes;
    }

    /** Read what is there, spill it, keep the tail, and tell the UI. */
    private function read(int $fd): void
    {
        $pipe = $this->pipes[$fd] ?? null;

        if (!is_resource($pipe)) {
            return;
        }

        $chunk = fread($pipe, self::READ_CHUNK);

        if ($chunk === false || $chunk === '') {
            if (feof($pipe)) {
                // Upstream's `flushOutput()`: what was held back goes out as it is.
                $rest = $this->pending[$fd] ?? '';
                unset($this->pending[$fd]);
                $this->accept($rest);
                $this->closePipe($fd);
            }

            return;
        }

        // Upstream's `splitIncompleteAnsiSuffix(pendingAnsi + chunk)`: an escape sequence split
        // across two reads is held back until the rest arrives, so it is stripped whole instead of
        // leaving its halves in the output.
        [$complete, $this->pending[$fd]] = self::splitIncompleteEnd(($this->pending[$fd] ?? '') . $chunk);
        $this->accept($complete);
    }

    /**
     * Upstream's `splitIncompleteAnsiSuffix()`, plus the UTF-8 character a read can cut short: the
     * text that is safe to clean now, and the unfinished end to put before the next read. An
     * unfinished sequence longer than upstream's `MAX_PENDING_ANSI_LENGTH` is not held back.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitIncompleteEnd(string $text): array
    {
        $whole = self::withoutPartialEnd($text);
        $partial = substr($text, strlen($whole));

        if (!str_contains($whole, "\x1b")) {
            return [$whole, $partial];
        }

        // The sequences `Ansi::strip()` takes out, unfinished at the end: OSC or APC without its
        // BEL or ST (a trailing ESC may start the ST), a CSI without its final byte, a bare ESC.
        $windowStart = max(0, strlen($whole) - self::MAX_PENDING_ANSI_LENGTH);

        if (preg_match('/(?:\x1b[\]_](?:[^\x07\x1b]|\x1b(?!\\\\))*|\x1b\[[0-9;]*|\x1b)$/', substr($whole, $windowStart), $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$whole, $partial];
        }

        $splitAt = $windowStart + $match[0][1];

        return [substr($whole, 0, $splitAt), substr($whole, $splitAt) . $partial];
    }

    /** Clean what was read, spill it, keep the tail, and tell the UI. */
    private function accept(string $chunk): void
    {
        // Cleaned here, at the source, which is upstream's own words for it in
        // `bash-executor.ts`: what reaches the model, the session file, the spill file and the
        // screen is then the same text. Cleaning at the display boundary — which is where pig had
        // it — leaves the escapes in the two places nobody looks at until a conversation is
        // replayed, and `npm`, `cargo` and `docker` colour their output whether or not anybody
        // is watching.
        $chunk = Shell::sanitize($chunk);

        if ($chunk === '') {
            return;
        }

        if ($this->onChunk !== null) {
            ($this->onChunk)($chunk);
        }

        $this->totalBytes += strlen($chunk);
        $this->totalLines += substr_count($chunk, "\n");
        $this->spillIfLarge($chunk);

        $this->buffer .= $chunk;

        // The tail is what gets reported, so only the tail is kept — twice the limit, so
        // truncation always has whole lines to work with.
        if (strlen($this->buffer) > self::KEEP_BYTES) {
            $this->buffer = substr($this->buffer, -self::KEEP_BYTES);
        }

        if ($this->onUpdate === null || $this->updateTimer !== null) {
            return;
        }

        $wait = $this->lastUpdateAt + self::UPDATE_EVERY - microtime(true);

        if ($wait <= 0) {
            $this->flushUpdate();

            return;
        }

        $this->updateTimer = Loop::get()->delay($wait, function (): void {
            $this->updateTimer = null;
            $this->flushUpdate();
        });
    }

    /** Tell the screen the tail as it is now. */
    private function flushUpdate(): void
    {
        if ($this->onUpdate === null) {
            return;
        }

        $this->lastUpdateAt = microtime(true);
        $partial = Truncate::tail($this->buffer);
        ($this->onUpdate)(new AgentToolResult([new TextContent($partial->content)]));
    }

    /**
     * Once the output is past what will be reported, write all of it to a file.
     *
     * The path goes in the truncation notice, so a model that needs the part that was
     * cut has somewhere to go and look rather than running the command again.
     *
     * Both thresholds, not just the byte one: two thousand short lines is well under
     * 50KB and still gets truncated, and upstream's byte-only check leaves that case
     * with nowhere to look.
     */
    private function spillIfLarge(string $chunk): void
    {
        if ($this->spill === null && ($this->totalBytes > self::SPILL_AFTER || $this->totalLines > Truncate::MAX_LINES)) {
            $path = sys_get_temp_dir() . '/' . $this->spillPrefix . '-' . bin2hex(random_bytes(8)) . '.log';
            $handle = fopen($path, 'wb');

            if ($handle === false) {
                return;
            }

            // Readable by the user alone, as pi 1.0.3 makes its output files: a command's
            // output is whatever the command printed, which on a bad day is a `.env`, and the
            // temp directory is shared. Set before the first byte goes in rather than after.
            chmod($path, 0600);

            $this->spillPath = $path;
            $this->spill = $handle;
            // Everything seen so far, so the file holds the whole output and not just
            // what arrived after the threshold.
            fwrite($handle, $this->buffer);
        }

        if (is_resource($this->spill)) {
            fwrite($this->spill, $chunk);
        }
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

    private function close(): int
    {
        // An update still waiting goes out now, before the result does.
        if ($this->updateTimer !== null) {
            Loop::get()->cancel($this->updateTimer);
            $this->updateTimer = null;
            $this->flushUpdate();
        }

        foreach (array_keys($this->watchers) as $fd) {
            $this->closePipe($fd);
        }

        if (is_resource($this->spill)) {
            fclose($this->spill);
            $this->spill = null;
        }

        // The process was waited for and closed in `wait()`, where the timer was still armed and
        // the status was still readable — asked a second time it says `signaled: false`, so
        // there is nothing left to ask here. What "$? would have said" is `Process`'s one
        // answer, so a command reports the same whether it came through here or `runAsync()`.
        return $this->exit;
    }
}
