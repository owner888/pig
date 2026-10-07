<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Converts wheel events into line counts.
 *
 * Ported from upstream's `wheel-scroll.ts`. In `'auto'` mode on terminals that do not accelerate
 * wheel input, the count follows event velocity: an isolated notch moves one line, while a fast
 * spin moves up to six lines per event. Notches 100 ms apart move 1 line each, 50 ms apart move 2,
 * and 20 ms apart move 5.
 */
final class WheelScrollAccelerator
{
    /** Several events closer than this belong to one physical notch (Ghostty emits them ~4 ms apart) or come from a high-resolution source. They move one line each and do not accelerate. */
    private const float BURST_GAP_MS = 5.0;

    /** A pause longer than this ends a scroll gesture. */
    private const float GESTURE_GAP_MS = 200.0;

    /** Average event gap that maps to one line per event. Faster events scale up proportionally. */
    private const float REFERENCE_GAP_MS = 100.0;

    private const int MAX_AUTO_LINES = 6;

    private readonly bool $accelerate;

    private float $lastTime = -INF;

    private int $lastDirection = 0;

    private ?float $averageGap = null;

    private float $carry = 0.0;

    /** @param int|'auto' $lines lines moved per wheel event */
    public function __construct(private int|string $lines = 'auto', ?bool $accelerate = null)
    {
        $this->accelerate = $accelerate ?? !self::terminalAcceleratesWheel();
    }

    /** @param int|'auto' $lines */
    public function setLines(int|string $lines): void
    {
        $this->lines = $lines;
        $this->reset();
    }

    /**
     * The positive line count for a wheel event in `$direction` at time `$now` (milliseconds).
     *
     * @param -1|1 $direction
     */
    public function next(int $direction, float $now): int
    {
        if ($this->lines !== 'auto') {
            return max(1, (int) $this->lines);
        }

        if (!$this->accelerate) {
            return 1;
        }

        $gap = $now - $this->lastTime;
        $sameGesture = $direction === $this->lastDirection && $gap <= self::GESTURE_GAP_MS;
        $this->lastTime = $now;
        $this->lastDirection = $direction;

        if (!$sameGesture) {
            $this->averageGap = null;
            $this->carry = 0.0;

            return 1;
        }

        if ($gap < self::BURST_GAP_MS) {
            return 1;
        }

        $this->averageGap = $this->averageGap === null ? $gap : ($this->averageGap + $gap) / 2;
        $lines = min(self::MAX_AUTO_LINES, max(1, self::REFERENCE_GAP_MS / $this->averageGap)) + $this->carry;
        $whole = (int) floor($lines);
        $this->carry = $lines - $whole;

        return $whole;
    }

    /**
     * Local macOS terminals receive wheel and trackpad deltas that the OS has already accelerated,
     * and they emit one event per line. Other platforms, and SSH sessions where the client platform
     * is unknown, usually send one event per wheel notch.
     */
    private static function terminalAcceleratesWheel(): bool
    {
        return PHP_OS_FAMILY === 'Darwin'
            && getenv('SSH_CONNECTION') === false
            && getenv('SSH_CLIENT') === false
            && getenv('SSH_TTY') === false;
    }

    private function reset(): void
    {
        $this->lastTime = -INF;
        $this->lastDirection = 0;
        $this->averageGap = null;
        $this->carry = 0.0;
    }
}
