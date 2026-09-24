<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Timestamp;

/**
 * How long each part of starting up took, when anybody asked.
 *
 * Upstream's `core/timings.ts`, behind `PIG_TIMING=1` as upstream's is behind `PI_TIMING=1`.
 * Off, `mark()` is a comparison and a return, which is what lets the calls sit in `bin/pig`
 * unconditionally rather than behind an `if` at every step.
 *
 * It measures **gaps, not moments**: each mark records the time since the last one, so what
 * comes out is a list of steps and what each cost. A list of timestamps would need subtracting
 * by whoever read it, and that is the part nobody does.
 *
 * Output goes to standard error, like every other thing `bin/pig` says before a UI exists —
 * standard output belongs to `--mode json` and to `-p`, and a table of milliseconds in the
 * middle of a JSON stream stops whatever was parsing it.
 */
final class Timings
{
    /** @var list<array{0: string, 1: int}> label, and the milliseconds since the mark before it */
    private static array $steps = [];

    private static ?int $last = null;

    private static ?bool $enabled = null;

    /**
     * Note that a step has finished.
     *
     * Named after what just *happened*, not what is about to: a mark is the end of the thing
     * before it, so `mark('skills')` after loading them is the cost of loading them.
     */
    public static function mark(string $label): void
    {
        if (!self::enabled()) {
            return;
        }

        $now = Timestamp::nowMs();
        self::$steps[] = [$label, $now - (self::$last ?? $now)];
        self::$last = $now;
    }

    /** Print what was collected, if anything was. */
    public static function report(): void
    {
        $table = self::table();

        if ($table !== '') {
            fwrite(STDERR, $table);
        }
    }

    /**
     * The table, or an empty string when there is nothing to say.
     *
     * Separate from `report()` so the content can be asserted on: `report()` writes to standard
     * error, which `ob_start()` does not capture, so a test against it would pass whatever the
     * table said. Empty rather than a heading with no rows, because `PIG_TIMING=1 bin/pig
     * --version` should not answer with one.
     */
    public static function table(): string
    {
        if (!self::enabled() || self::$steps === []) {
            return '';
        }

        $width = 0;

        foreach (self::$steps as [$label, $ignored]) {
            $width = max($width, strlen($label));
        }

        $lines = ["\n--- Startup timings ---"];
        $total = 0;

        foreach (self::$steps as [$label, $spent]) {
            $total += $spent;
            $lines[] = '  ' . str_pad($label, $width) . '  ' . $spent . 'ms';
        }

        $lines[] = '  ' . str_pad('TOTAL', $width) . '  ' . $total . 'ms';
        $lines[] = "-----------------------\n";

        return implode("\n", $lines) . "\n";
    }

    /** For a test: forget everything measured so far. */
    public static function reset(): void
    {
        self::$steps = [];
        self::$last = null;
        self::$enabled = null;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function steps(): array
    {
        return self::$steps;
    }

    /**
     * Read once and remembered, because the environment does not change mid-startup and this
     * is asked at every step.
     */
    private static function enabled(): bool
    {
        return self::$enabled ??= getenv('PIG_TIMING') === '1';
    }
}
