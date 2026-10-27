<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;

/**
 * How fast the model is writing, for the footer's top line: `⚡ …` while an answer streams, then
 * `⚡ 329 tok/s · avg 460 · TTFT 820ms` when it ends.
 *
 * pig's own, not upstream's: the developer's `token-speed` extension, made built-in so it is there
 * on every machine. A reading of the moment, so it is never written to the session file.
 *
 * - **The rate is over the streaming, not the wait.** From the first chunk to the end when the
 *   answer came in several chunks over at least 100ms, which is the speed of writing; from the
 *   request otherwise, since an answer delivered in one piece has no streaming to time.
 * - **An impossible rate is not shown as one.** Past 3,000 tok/s the answer was buffered somewhere
 *   and arrived at once, so the reading says how many tokens and, if it has one, the time to the
 *   first — never a number that only measures the network.
 * - **`avg` is this session's**, over every answer measured, shown when it differs from this one.
 */
final class TokenSpeed
{
    /** Past this the tokens arrived together, and dividing by the time measures nothing. */
    private const int MAX_PLAUSIBLE_TPS = 3000;

    /** Streaming shorter than this is one delivery, timed from the request instead. */
    private const float MIN_STREAM_MS = 100.0;

    /** What the footer shows while an answer is being written. */
    public const string WRITING = '⚡ …';

    private ?float $startedAt = null;

    private ?float $firstChunkAt = null;

    private int $chunks = 0;

    private int $totalTokens = 0;

    private float $totalMs = 0.0;

    /** @var Closure(): float milliseconds */
    private readonly Closure $now;

    /** @param (Closure(): float)|null $now milliseconds, for tests; a monotonic clock otherwise */
    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e6;
    }

    /** An answer began. @return string what to show until it ends */
    public function start(): string
    {
        $this->startedAt = ($this->now)();
        $this->firstChunkAt = null;
        $this->chunks = 0;

        return self::WRITING;
    }

    /** A piece of it arrived. */
    public function chunk(): void
    {
        if ($this->startedAt === null) {
            return;
        }

        $this->firstChunkAt ??= ($this->now)();
        $this->chunks++;
    }

    /** It ended with this many output tokens. @return string|null the reading, or null when nothing was started */
    public function end(int $outputTokens): ?string
    {
        if ($this->startedAt === null) {
            return null;
        }

        $end = ($this->now)();
        $streamMs = $this->firstChunkAt !== null ? $end - $this->firstChunkAt : 0.0;
        $ms = $this->chunks > 1 && $streamMs >= self::MIN_STREAM_MS ? $streamMs : $end - $this->startedAt;
        $ms = $ms > 0.0 ? $ms : 1.0;
        $ttft = $this->firstChunkAt !== null ? $this->firstChunkAt - $this->startedAt : null;

        $this->startedAt = null;
        $this->firstChunkAt = null;
        $this->chunks = 0;

        $unmeasurable = $outputTokens > 0 && $outputTokens / ($ms / 1000.0) > self::MAX_PLAUSIBLE_TPS;
        $speed = $unmeasurable ? null : self::rate($outputTokens, $ms);

        if ($speed === null) {
            $parts = ['⚡ ' . self::tokens($outputTokens) . ' tok'];
            if ($unmeasurable && $ttft !== null) {
                $parts[] = 'TTFT ' . self::duration($ttft);
            }

            return implode(' · ', $parts);
        }

        $this->totalTokens += $outputTokens;
        $this->totalMs += $ms;

        $parts = ["⚡ {$speed} tok/s"];
        $average = self::rate($this->totalTokens, $this->totalMs);
        if ($average !== null && $average !== $speed) {
            $parts[] = "avg {$average}";
        }
        if ($ttft !== null) {
            $parts[] = 'TTFT ' . self::duration($ttft);
        }

        return implode(' · ', $parts);
    }

    /** A new session: nothing measured yet, and no answer under way. */
    public function reset(): void
    {
        $this->startedAt = null;
        $this->firstChunkAt = null;
        $this->chunks = 0;
        $this->totalTokens = 0;
        $this->totalMs = 0.0;
    }

    /** Whole numbers from 100 up, one decimal below; null for nothing to divide. */
    public static function rate(int $tokens, float $ms): ?string
    {
        if ($tokens <= 0 || $ms <= 0.0) {
            return null;
        }

        $perSecond = $tokens / ($ms / 1000.0);

        return $perSecond >= 100 ? (string) round($perSecond) : sprintf('%.1f', $perSecond);
    }

    public static function tokens(int $count): string
    {
        return $count < 1000 ? (string) $count : sprintf('%.1fk', $count / 1000);
    }

    public static function duration(float $ms): string
    {
        return $ms < 1000 ? round($ms) . 'ms' : sprintf('%.2fs', $ms / 1000.0);
    }
}
