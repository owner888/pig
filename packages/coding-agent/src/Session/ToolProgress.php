<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\Truncate;
use RuntimeException;

/**
 * What the running tool has printed so far, kept beside the session file, so a run a stopped
 * process left inside a tool call can tell the model how far it got.
 *
 * pig's own, from pi-durable, which commits a tool's output to its `pi.live` document as it runs and
 * puts it in the `interrupted` result after a restart. pig's session file is pi's and append-only, so
 * the output goes in a file of its own instead, `<session>.running.json`, rewritten whole as the tool
 * reports and removed when the tool's result is written. It holds the tail `Truncate::tail()` keeps,
 * which is what a result would have shown.
 *
 * **Written at pi-durable's pace** (`Progress` in its `harness/output.ts`, with its defaults): the first
 * change after a quiet spell is written at once, and each write holds the next back by at least
 * `MIN_INTERVAL` and by its own size at `BYTES_PER_SECOND`, so a build log is not rewritten ten times a
 * second at 50KB a time.
 */
final class ToolProgress
{
    /** pi-durable's `outputIntervalMs` default. */
    private const float MIN_INTERVAL = 0.1;

    /** pi-durable's `PROGRESS_BYTES_PER_SECOND`. */
    private const int BYTES_PER_SECOND = 100 * 1024;

    /** @var array{toolCallId: string, output: string}|null what is waiting to be written */
    private ?array $pending = null;

    private ?string $timer = null;

    private float $nextAt = 0.0;

    /** @param Closure(): ?string $sessionPath the session file now, null when nothing is saved */
    public function __construct(private readonly Closure $sessionPath)
    {
    }

    /** Where a session file's running tool output is kept. */
    public static function pathOf(string $sessionPath): string
    {
        return (str_ends_with($sessionPath, '.jsonl') ? substr($sessionPath, 0, -6) : $sessionPath) . '.running.json';
    }

    /**
     * The output a stopped process's tool call had printed, or null when nothing was kept.
     *
     * @return array{toolCallId: string, output: string}|null
     */
    public static function read(string $sessionPath): ?array
    {
        $path = self::pathOf($sessionPath);

        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data) || !is_string($data['toolCallId'] ?? null) || !is_string($data['output'] ?? null)) {
            throw new RuntimeException("{$path} is not a tool's running output");
        }

        return ['toolCallId' => $data['toolCallId'], 'output' => $data['output']];
    }

    /** The tool reported: keep what it has printed so far. */
    public function update(string $toolCallId, AgentToolResult $partial): void
    {
        $text = '';

        foreach ($partial->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        $this->pending = ['toolCallId' => $toolCallId, 'output' => Truncate::tail($text)->content];

        if ($this->timer !== null) {
            return;
        }

        $wait = $this->nextAt - microtime(true);

        if ($wait <= 0) {
            $this->flush();

            return;
        }

        $this->timer = Loop::get()->delay($wait, function (): void {
            $this->timer = null;
            $this->flush();
        });
    }

    /** The tool's result is written: what it printed on the way is not needed any more. */
    public function clear(): void
    {
        if ($this->timer !== null) {
            Loop::get()->cancel($this->timer);
            $this->timer = null;
        }

        $this->pending = null;
        $this->nextAt = 0.0;
        $session = ($this->sessionPath)();

        if ($session === null) {
            return;
        }

        $path = self::pathOf($session);

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Could not remove {$path}");
        }
    }

    private function flush(): void
    {
        $session = ($this->sessionPath)();
        $pending = $this->pending;
        $this->pending = null;

        if ($session === null || $pending === null) {
            return;
        }

        $path = self::pathOf($session);
        $json = json_encode($pending, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $temp = $path . '.tmp';

        // Readable by the user alone, as the spill file is: a command's output can be a `.env`.
        // Renamed into place, so a crash mid-write leaves the last whole copy rather than half of one.
        if (file_put_contents($temp, $json) === false || !chmod($temp, 0600) || !rename($temp, $path)) {
            throw new RuntimeException("Could not write {$path}");
        }

        $this->nextAt = microtime(true) + max(self::MIN_INTERVAL, strlen($json) / self::BYTES_PER_SECOND);
    }
}
