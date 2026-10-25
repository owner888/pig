<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Timestamp;

/**
 * Central timing instrumentation for startup profiling — upstream's `core/timings.ts`, enabled
 * with `PIG_TIMING=1` as upstream's is with `PI_TIMING=1`.
 *
 * Two namespaces, upstream's: `main`, the steps of `bin/pig` (its labels are pig's, because its
 * steps are), and `extensions`, a `<path> module import` and a `<path> factory` line for each
 * extension `ExtensionLoader` loads, reset at every load as upstream's resource loader resets it.
 * Off, `time()` is a comparison and a return, which is what lets the calls sit in `bin/pig`
 * unconditionally rather than behind an `if` at every step.
 *
 * It measures **gaps, not moments**: each `time()` records the milliseconds since the one before
 * it in the same namespace, named after what just *happened* — `time('skills')` after loading
 * them is the cost of loading them.
 *
 * Output goes to standard error, as upstream's `console.error`, and like every other thing
 * `bin/pig` says before a UI exists — standard output belongs to `--mode json` and to `-p`.
 */
final class Timings
{
    /** @var array<string, array{timings: list<array{label: string, ms: int}>, lastTime: int}> upstream's `timingNamespaces`, in first-use order */
    private static array $namespaces = [];

    private static ?bool $enabled = null;

    /** Upstream's `resetTimings()`: start a namespace over, its clock from now. */
    public static function resetTimings(string $namespace = 'main'): void
    {
        if (!self::enabled()) {
            return;
        }

        self::$namespaces[$namespace] = ['timings' => [], 'lastTime' => Timestamp::nowMs()];
    }

    /** Upstream's `time()`: a step of $namespace has finished. */
    public static function time(string $label, string $namespace = 'main'): void
    {
        if (!self::enabled()) {
            return;
        }

        $now = Timestamp::nowMs();

        if (!isset(self::$namespaces[$namespace])) {
            self::resetTimings($namespace);
        }

        self::$namespaces[$namespace]['timings'][] = ['label' => $label, 'ms' => $now - self::$namespaces[$namespace]['lastTime']];
        self::$namespaces[$namespace]['lastTime'] = $now;
    }

    /** Upstream's `printTimings()`. */
    public static function printTimings(): void
    {
        $table = self::table();

        if ($table !== '') {
            fwrite(STDERR, $table);
        }
    }

    /**
     * What `printTimings()` writes: upstream's `printTimingGroup()` for each namespace, an empty
     * string when nothing was measured.
     *
     * Separate so the content can be asserted on: standard error is not what `ob_start()`
     * captures, so a test against `printTimings()` would pass whatever the table said.
     */
    public static function table(): string
    {
        if (!self::enabled()) {
            return '';
        }

        $out = '';

        foreach (self::$namespaces as $namespace => $timingNamespace) {
            $out .= self::timingGroup("Startup Timings: {$namespace}", $timingNamespace['timings']);
        }

        return $out;
    }

    /**
     * The steps of one namespace, as recorded.
     *
     * @return list<array{label: string, ms: int}>
     */
    public static function timings(string $namespace = 'main'): array
    {
        return self::$namespaces[$namespace]['timings'] ?? [];
    }

    /** For a test: forget everything measured, and whether timing is on. */
    public static function reset(): void
    {
        self::$namespaces = [];
        self::$enabled = null;
    }

    /** @param list<array{label: string, ms: int}> $timings */
    private static function timingGroup(string $title, array $timings): string
    {
        $printable = array_values(array_filter($timings, static fn (array $timing): bool => $timing['ms'] >= 0));

        if ($printable === []) {
            return '';
        }

        $lines = ["\n--- {$title} ---"];

        foreach ($printable as $timing) {
            $lines[] = "  {$timing['label']}: {$timing['ms']}ms";
        }

        $lines[] = '  TOTAL: ' . array_sum(array_column($printable, 'ms')) . 'ms';
        $lines[] = str_repeat('-', strlen($title) + 8) . "\n";

        return implode("\n", $lines) . "\n";
    }

    /** Read once: the environment does not change mid-startup, and this is asked at every step. */
    private static function enabled(): bool
    {
        return self::$enabled ??= getenv('PIG_TIMING') === '1';
    }
}
