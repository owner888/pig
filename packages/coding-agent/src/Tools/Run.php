<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use Pig\CodingAgent\Utils\Ansi;

/**
 * One command, and what it prints: upstream's `OutputAccumulator` with the live updates and the
 * spill file. The command itself is run by `BashOperations` — `LocalShellOperations` on this
 * machine, the default — which hands each piece of output here as it arrives.
 *
 * @internal
 */
final class Run
{
    /** Past this, output is spilled to a file so nothing is lost when it is truncated. */
    private const int SPILL_AFTER = Truncate::MAX_BYTES;

    /** Kept in memory: twice the limit, so truncation always has whole lines to work with. */
    private const int KEEP_BYTES = Truncate::MAX_BYTES * 2;

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

    /**
     * Upstream's `pendingAnsi` (#10504), by stream: the end of the last read that the next one
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

    private readonly BashOperations $operations;

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
     * @param string|null $spillDirectory where the spill file goes — a private session's own
     *        temporary directory (`AgentSession::scratchDirectory()`), removed with it; null is the
     *        system's
     * @param BashOperations|null $operations what runs the command; null is this machine's bash with
     *        `$shell`
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $command,
        private readonly ?Closure $onUpdate,
        private readonly ?ShellConfig $shell = null,
        private readonly ?array $env = null,
        private readonly string $spillPrefix = 'pig-bash',
        private readonly ?Closure $onChunk = null,
        private readonly ?string $spillDirectory = null,
        ?BashOperations $operations = null,
    ) {
        $shell = $this->shell;
        $this->operations = $operations ?? new LocalShellOperations('bash', static fn (): ShellConfig => $shell ?? Shell::config());
    }

    /**
     * Run the command and keep what it prints, until it finishes, is aborted or runs out of time —
     * `$aborted` and `$timedOut` say which of the last two.
     *
     * @return int|null its exit code; for a stopped command, what it reported when stopped
     * @throws AgentError when it could not be started
     */
    public function run(?AbortSignal $signal, ?float $timeout): ?int
    {
        try {
            return $this->operations->exec($this->command, $this->cwd, $this->accept(...), $signal, $timeout, $this->env);
        } catch (CommandStopped $stopped) {
            if ($stopped->timedOut) {
                $this->timedOut = true;
            } else {
                $this->aborted = true;
            }

            return $stopped->exitCode;
        } finally {
            $this->close();
        }
    }

    public function output(): string
    {
        return $this->buffer;
    }

    /**
     * Upstream's `OutputAccumulator.readFullOutput(maxBytes)`, for the structured result a codemode
     * script gets: the whole output when it is at most $maxBytes, otherwise its first and last
     * halves around `[... N bytes omitted ...]`, cut at character boundaries. Read from the spill
     * file when there is one, since the buffer holds only the tail; called after `run()`, which
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

    /**
     * A piece of output from `$stream` (1 stdout, 2 stderr). Upstream's
     * `splitIncompleteAnsiSuffix(pendingAnsi + chunk)`: an escape sequence split across two reads
     * is held back until the rest arrives, so it is stripped whole instead of leaving its halves in
     * the output.
     */
    private function accept(string $chunk, int $stream = 1): void
    {
        [$complete, $this->pending[$stream]] = self::splitIncompleteEnd(($this->pending[$stream] ?? '') . $chunk);
        $this->keep($complete);
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
        [$complete, $pending] = Ansi::splitIncompleteSuffix($whole);

        return [$complete, $pending . $partial];
    }

    /** Clean what was read, spill it, keep the tail, and tell the UI. */
    private function keep(string $chunk): void
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
            $path = ($this->spillDirectory ?? sys_get_temp_dir()) . '/' . $this->spillPrefix . '-' . bin2hex(random_bytes(8)) . '.log';
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

    private function close(): void
    {
        // Upstream's `flushOutput()`: what was held back goes out as it is.
        foreach (array_keys($this->pending) as $stream) {
            $rest = $this->pending[$stream];
            unset($this->pending[$stream]);
            $this->keep($rest);
        }

        // An update still waiting goes out now, before the result does.
        if ($this->updateTimer !== null) {
            Loop::get()->cancel($this->updateTimer);
            $this->updateTimer = null;
            $this->flushUpdate();
        }

        if (is_resource($this->spill)) {
            fclose($this->spill);
            $this->spill = null;
        }
    }
}
