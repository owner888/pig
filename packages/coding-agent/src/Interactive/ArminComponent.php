<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Component;
use Pig\Tui\Tui;

/**
 * Armin says hi. An easter egg: 31×36 pixels of XBM art, animated in by one of seven effects.
 *
 * Upstream's `components/armin.ts`, and it earns its place in a port for a reason beyond being
 * fun: it is the only thing in either tree that draws a bitmap with **half-block characters**, so
 * a row of the picture is two rows of pixels packed into one cell — `█` for both, `▀` and `▄` for
 * one, a space for neither. That is the trick worth having ported whether or not anybody types
 * `/arminsayshi`.
 *
 * The bits are upstream's, byte for byte: 144 of them, which is exactly `ceil(31/8) × 36`, LSB
 * first, and **0 means foreground** — an XBM stores 1 for the background, which is the one detail
 * that makes this look like a photographic negative if it is guessed at.
 */
final class ArminComponent implements Component
{
    private const int WIDTH = 31;
    private const int HEIGHT = 36;

    /** @var list<int> upstream's, unchanged */
    private const array BITS = [
        0xff, 0xff, 0xff, 0x7f, 0xff, 0xf0, 0xff, 0x7f, 0xff, 0xed, 0xff, 0x7f, 0xff, 0xdb, 0xff, 0x7f,
        0xff, 0xb7, 0xff, 0x7f, 0xff, 0x77, 0xfe, 0x7f, 0x3f, 0xf8, 0xfe, 0x7f, 0xdf, 0xff, 0xfe, 0x7f,
        0xdf, 0x3f, 0xfc, 0x7f, 0x9f, 0xc3, 0xfb, 0x7f, 0x6f, 0xfc, 0xf4, 0x7f, 0xf7, 0x0f, 0xf7, 0x7f,
        0xf7, 0xff, 0xf7, 0x7f, 0xf7, 0xff, 0xe3, 0x7f, 0xf7, 0x07, 0xe8, 0x7f, 0xef, 0xf8, 0x67, 0x70,
        0x0f, 0xff, 0xbb, 0x6f, 0xf1, 0x00, 0xd0, 0x5b, 0xfd, 0x3f, 0xec, 0x53, 0xc1, 0xff, 0xef, 0x57,
        0x9f, 0xfd, 0xee, 0x5f, 0x9f, 0xfc, 0xae, 0x5f, 0x1f, 0x78, 0xac, 0x5f, 0x3f, 0x00, 0x50, 0x6c,
        0x7f, 0x00, 0xdc, 0x77, 0xff, 0xc0, 0x3f, 0x78, 0xff, 0x01, 0xf8, 0x7f, 0xff, 0x03, 0x9c, 0x78,
        0xff, 0x07, 0x8c, 0x7c, 0xff, 0x0f, 0xce, 0x78, 0xff, 0xff, 0xcf, 0x7f, 0xff, 0xff, 0xcf, 0x78,
        0xff, 0xff, 0xdf, 0x78, 0xff, 0xff, 0xdf, 0x7d, 0xff, 0xff, 0x3f, 0x7e, 0xff, 0xff, 0xff, 0x7f,
    ];

    /** @var list<string> */
    public const array EFFECTS = ['typewriter', 'scanline', 'rain', 'fade', 'crt', 'glitch', 'dissolve'];

    /** What a cell can be while an effect is still working on it. */
    private const array NOISE = [' ', '░', '▒', '▓', '█', '▀', '▄'];

    private const string MESSAGE = 'ARMIN SAYS HI';

    /** @var list<list<string>> */
    private readonly array $finalGrid;

    /** @var list<list<string>> */
    private array $currentGrid;

    /**
     * Whatever the running effect needs to remember.
     *
     * One bag rather than seven typed fields, which is upstream's shape and the right one here:
     * six of the seven would be null at any moment, and the shapes have nothing in common —
     * `rain` tracks a drop per column, `fade` a shuffled list of every cell.
     *
     * @var array<string, mixed>
     */
    private array $state = [];

    private ?string $timer = null;

    /** Bumped on every frame, so `render()` knows its cache is stale without comparing grids. */
    private int $version = 0;

    /** @var list<string> */
    private array $cachedLines = [];

    private int $cachedWidth = 0;

    private int $cachedVersion = -1;

    private readonly string $effect;

    /**
     * @param string|null $effect which one to play; a random one when not given. The seam exists
     *        because seven effects cannot be tested by starting this seven times and hoping.
     */
    public function __construct(
        private readonly Tui $tui,
        private readonly Palette $palette,
        ?string $effect = null,
    ) {
        $this->effect = $effect !== null && in_array($effect, self::EFFECTS, true)
            ? $effect
            : self::EFFECTS[array_rand(self::EFFECTS)];

        $this->finalGrid = self::buildGrid();
        $this->currentGrid = self::emptyGrid();

        $this->initEffect();
        $this->schedule();
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedWidth = 0;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($width === $this->cachedWidth && $this->cachedVersion === $this->version) {
            return $this->cachedLines;
        }

        $available = $width - 1;
        $lines = [];

        foreach ([...$this->currentGrid, [self::MESSAGE]] as $row) {
            // The message row arrives as one string and the picture rows as one cell each, so
            // both are joined the same way and clipped the same way.
            $clipped = mb_substr(implode('', $row), 0, max(0, $available), 'UTF-8');
            $pad = max(0, $available - mb_strlen($clipped, 'UTF-8'));

            // Padded so every line is the full width: a line that is sometimes padded and
            // sometimes not differs from itself, and the renderer redraws it for nothing.
            $lines[] = ' ' . $this->palette->fg('accent', $clipped) . str_repeat(' ', $pad);
        }

        $this->cachedLines = $lines;
        $this->cachedWidth = $width;
        $this->cachedVersion = $this->version;

        return $lines;
    }

    /**
     * Stop animating.
     *
     * The same not-a-nicety as `BorderedLoader::dispose()`: the frames reschedule themselves, so
     * one of these left running is a loop that never runs out of work.
     */
    public function dispose(): void
    {
        if ($this->timer !== null) {
            Loop::get()->cancel($this->timer);
            $this->timer = null;
        }
    }

    /** How tall the picture is in cells — two pixel rows to a cell. */
    public static function displayHeight(): int
    {
        return (int) ceil(self::HEIGHT / 2);
    }

    // ---- the picture ---------------------------------------------------------------------

    /** @return list<list<string>> */
    private static function buildGrid(): array
    {
        $grid = [];

        for ($row = 0; $row < self::displayHeight(); $row++) {
            $line = [];

            for ($x = 0; $x < self::WIDTH; $x++) {
                $line[] = self::cell($x, $row);
            }

            $grid[] = $line;
        }

        return $grid;
    }

    /** @return list<list<string>> */
    private static function emptyGrid(): array
    {
        return array_fill(0, self::displayHeight(), array_fill(0, self::WIDTH, ' '));
    }

    /** Two vertical pixels packed into one character. */
    private static function cell(int $x, int $row): string
    {
        return match (true) {
            self::pixel($x, $row * 2) && self::pixel($x, $row * 2 + 1) => '█',
            self::pixel($x, $row * 2) => '▀',
            self::pixel($x, $row * 2 + 1) => '▄',
            default => ' ',
        };
    }

    /** True where the ink is. **A zero bit is foreground** — an XBM stores 1 for background. */
    private static function pixel(int $x, int $y): bool
    {
        if ($y >= self::HEIGHT) {
            return false;
        }

        $byte = self::BITS[$y * (int) ceil(self::WIDTH / 8) + intdiv($x, 8)];

        return (($byte >> ($x % 8)) & 1) === 0;
    }

    // ---- the animation ------------------------------------------------------------------

    /**
     * One frame, then the next.
     *
     * Upstream uses `setInterval`. The loop here has no repeating timer and does not need one:
     * a frame that reschedules from its own callback stops costing anything the moment it stops
     * being drawn, which is the same shape `Loader` uses.
     */
    private function schedule(): void
    {
        // Glitch at 60, everything else at 30 — upstream's two numbers.
        $seconds = 1 / ($this->effect === 'glitch' ? 60 : 30);

        $this->timer = Loop::get()->delay($seconds, function (): void {
            // Spent before anything else can ask to cancel it.
            $this->timer = null;

            $done = $this->tick();
            $this->version++;
            $this->tui->requestRender();

            if (!$done) {
                $this->schedule();
            }
        });
    }

    private function tick(): bool
    {
        return match ($this->effect) {
            'typewriter' => $this->reveal(3),
            'scanline' => $this->tickScanline(),
            'rain' => $this->tickRain(),
            'fade' => $this->reveal(15),
            'crt' => $this->tickCrt(),
            'glitch' => $this->tickGlitch(),
            'dissolve' => $this->reveal(20),
            default => true,
        };
    }

    private function initEffect(): void
    {
        $this->state = match ($this->effect) {
            // Cell by cell in reading order, which is what makes it a typewriter.
            'typewriter' => ['order' => self::readingOrder(), 'at' => 0],
            'scanline' => ['row' => 0],
            'rain' => ['drops' => array_map(
                // A negative start is a drop still above the picture, so the columns do not all
                // begin together.
                static fn (): array => ['y' => -random_int(0, self::displayHeight() * 2), 'settled' => 0],
                array_fill(0, self::WIDTH, null),
            )],
            'fade' => ['order' => self::shuffled(), 'at' => 0],
            'crt' => ['expansion' => 0],
            'glitch' => ['phase' => 0, 'frames' => 8],
            'dissolve' => ['order' => self::shuffled(), 'at' => 0],
            default => [],
        };

        if ($this->effect === 'dissolve') {
            // Dissolve starts from noise rather than from nothing, which is the whole look.
            $this->currentGrid = array_map(
                static fn (array $row): array => array_map(
                    static fn (): string => self::NOISE[array_rand(self::NOISE)],
                    $row,
                ),
                $this->currentGrid,
            );
        }
    }

    /**
     * Reveal a few cells per frame, in whatever order the effect chose.
     *
     * Three of upstream's seven are this function with a different order and a different number:
     * typewriter is reading order three at a time, fade is shuffled fifteen at a time, dissolve
     * is shuffled twenty at a time over noise. Written once because they are one thing — and the
     * only reason upstream has three copies is that the order lives in three differently-named
     * state bags.
     */
    private function reveal(int $perFrame): bool
    {
        for ($i = 0; $i < $perFrame; $i++) {
            if ($this->state['at'] >= count($this->state['order'])) {
                return true;
            }

            [$row, $x] = $this->state['order'][$this->state['at']];
            $this->currentGrid[$row][$x] = $this->finalGrid[$row][$x];
            $this->state['at']++;
        }

        return false;
    }

    private function tickScanline(): bool
    {
        if ($this->state['row'] >= self::displayHeight()) {
            return true;
        }

        $this->currentGrid[$this->state['row']] = $this->finalGrid[$this->state['row']];
        $this->state['row']++;

        return false;
    }

    private function tickRain(): bool
    {
        $settledEverywhere = true;
        $this->currentGrid = self::emptyGrid();
        $height = self::displayHeight();

        for ($x = 0; $x < self::WIDTH; $x++) {
            $drop = $this->state['drops'][$x];

            // What has already landed, which piles up from the bottom.
            for ($row = $height - 1; $row >= $height - $drop['settled']; $row--) {
                if ($row >= 0) {
                    $this->currentGrid[$row][$x] = $this->finalGrid[$row][$x];
                }
            }

            if ($drop['settled'] >= $height) {
                continue;
            }

            // The lowest cell in this column that still has ink in it, which is where this drop
            // is heading.
            $target = -1;

            for ($row = $height - 1 - $drop['settled']; $row >= 0; $row--) {
                if ($this->finalGrid[$row][$x] !== ' ') {
                    $target = $row;

                    break;
                }
            }

            // **Upstream's rain never ends, and this is the line that fixes it.** There, a column
            // is finished when `settled >= height`, and `settled` is `height - target` — so it
            // only ever reaches `height` for a column with ink in row 0. Four of these 31 columns
            // have that; two have no ink at all. The other 27 can never satisfy it, so
            // `allSettled` is never true, the effect never returns done, and — because pi creates
            // this component and never calls `dispose()` — a one-in-seven roll leaves a 30fps
            // redraw running for the rest of the session.
            //
            // A column is done when there is nothing left above what has landed, which is what
            // `settled >= height` was reaching for. Nothing about the finished picture changes:
            // every cell with ink in it has already been placed by the time this is true.
            if ($target < 0) {
                $this->state['drops'][$x] = ['y' => $drop['y'], 'settled' => $height];

                continue;
            }

            $settledEverywhere = false;
            $drop['y']++;

            if ($drop['y'] >= 0 && $drop['y'] < $height) {
                if ($drop['y'] >= $target) {
                    $drop['settled'] = $height - $target;
                    // Back above the picture, a random distance up, so the next one does not
                    // arrive on the same beat.
                    $drop['y'] = -random_int(1, 5);
                } else {
                    $this->currentGrid[$drop['y']][$x] = '▓';
                }
            }

            $this->state['drops'][$x] = $drop;
        }

        return $settledEverywhere;
    }

    private function tickCrt(): bool
    {
        $height = self::displayHeight();
        $middle = intdiv($height, 2);
        $this->currentGrid = self::emptyGrid();

        // Outwards from the middle, like a tube warming up.
        for (
            $row = max(0, $middle - $this->state['expansion']);
            $row <= min($height - 1, $middle + $this->state['expansion']);
            $row++
        ) {
            $this->currentGrid[$row] = $this->finalGrid[$row];
        }

        $this->state['expansion']++;

        return $this->state['expansion'] > $height;
    }

    private function tickGlitch(): bool
    {
        if ($this->state['phase'] >= $this->state['frames']) {
            // One clean frame at the end, which is the point of the whole effect.
            $this->currentGrid = $this->finalGrid;

            return true;
        }

        $grid = [];

        foreach ($this->finalGrid as $row) {
            $grid[] = $this->corrupt($row);
        }

        $this->currentGrid = $grid;
        $this->state['phase']++;

        return false;
    }

    /**
     * One row of the glitch, which is three outcomes with upstream's odds.
     *
     * @param list<string> $row
     * @return list<string>
     */
    private function corrupt(array $row): array
    {
        // Rolled once per row and used by whichever branch is taken, as upstream does — the
        // offset is drawn before the dice, so the two are not independent there either.
        $offset = random_int(0, 6) - 3;

        // Three in ten: rotated sideways. A negative offset takes from the end, which
        // `array_slice` does for the same reason JavaScript's `slice` does.
        if (random_int(1, 100) <= 30) {
            return array_slice([...array_slice($row, $offset), ...array_slice($row, 0, $offset)], 0, self::WIDTH);
        }

        // Two in ten: somebody else's row entirely.
        if (random_int(1, 100) <= 20) {
            return $this->finalGrid[random_int(0, self::displayHeight() - 1)];
        }

        return $row;
    }

    /**
     * Every cell, top to bottom and left to right.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function readingOrder(): array
    {
        $order = [];

        for ($row = 0; $row < self::displayHeight(); $row++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $order[] = [$row, $x];
            }
        }

        return $order;
    }

    /**
     * Every cell, in no order at all.
     *
     * `shuffle()` rather than upstream's hand-written Fisher-Yates, which is what it already is —
     * the platform call is the port of the loop, not the loop.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function shuffled(): array
    {
        $order = self::readingOrder();
        shuffle($order);

        return $order;
    }
}
