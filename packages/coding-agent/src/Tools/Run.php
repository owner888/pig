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

    public bool $aborted = false;

    public bool $timedOut = false;

    public ?string $spillPath = null;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var array<int, string> loop watcher ids, by pipe */
    private array $watchers = [];

    /** @var resource|null */
    private mixed $spill = null;

    /** The tail of the output, trimmed as it grows. */
    private string $buffer = '';

    private int $totalBytes = 0;

    private int $totalLines = 0;

    private int $open = 0;

    private ?Deferred $finished = null;

    /** @param Closure(AgentToolResult): void|null $onUpdate */
    public function __construct(
        private readonly string $cwd,
        private readonly string $command,
        private readonly ?Closure $onUpdate,
    ) {
    }

    public function start(): void
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // stdin is /dev/null rather than inherited: a command that decides to prompt
        // would otherwise sit there waiting for a person who is not watching it.
        $process = proc_open([Shell::bash(), '-c', $this->command], $descriptors, $pipes, $this->cwd);

        if (!is_resource($process)) {
            throw new AgentError('Could not start a shell');
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

        try {
            $this->finished?->future->await();
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
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
                $this->closePipe($fd);
            }

            return;
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

        if ($this->onUpdate !== null) {
            $partial = Truncate::tail($this->buffer);
            ($this->onUpdate)(new AgentToolResult([new TextContent($partial->content)]));
        }
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
            $path = sys_get_temp_dir() . '/pig-bash-' . bin2hex(random_bytes(8)) . '.log';
            $handle = fopen($path, 'wb');

            if ($handle === false) {
                return;
            }

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
        foreach (array_keys($this->watchers) as $fd) {
            $this->closePipe($fd);
        }

        if (is_resource($this->spill)) {
            fclose($this->spill);
            $this->spill = null;
        }

        if (!is_resource($this->process)) {
            return 0;
        }

        $exit = proc_close($this->process);
        $this->process = null;

        return $exit;
    }
}
