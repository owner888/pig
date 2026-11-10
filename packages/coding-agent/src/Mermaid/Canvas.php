<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `canvas.ts`: a grid of cells. Edges accumulate as direction bits rather than
 * glyphs, so crossings and junctions resolve correctly whatever order they are drawn in;
 * `finalizeMask()` turns the bits into characters at the end. `occupied` marks cells a box has
 * claimed, which edge bits must not overwrite.
 *
 * Each cell has a role, and may carry the author classes (`tag`, space-joined) and the link
 * (`href`) of the node it belongs to, stamped while `curTag` / `curHref` are set.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Canvas
{
    /** The trailing column of a wide glyph. Never emitted. */
    public const string CONT = "\0";

    /** Connection direction bits. */
    public const int U = 1;
    public const int D = 2;
    public const int L = 4;
    public const int R = 8;

    /** Line styles, tracked per cell so crossing edges keep their own stroke. */
    public const int STY_DOT = 1;
    public const int STY_THICK = 2;
    public const int STY_SOLID = 4;

    private const array DOTTED = ['─' => '╌', '│' => '╎'];

    private const array THICK = [
        '─' => '━', '│' => '┃', '┌' => '┏', '┐' => '┓', '└' => '┗', '┘' => '┛',
        '├' => '┣', '┤' => '┫', '┬' => '┳', '┴' => '┻', '┼' => '╋',
    ];

    private const array FLIP_V = [
        '╔' => '╚', '╚' => '╔', '╗' => '╝', '╝' => '╗', '╤' => '╧', '╧' => '╤',
        '┌' => '└', '└' => '┌', '┐' => '┘', '┘' => '┐', '┏' => '┗', '┗' => '┏', '┓' => '┛', '┛' => '┓',
        '╭' => '╰', '╰' => '╭', '╮' => '╯', '╯' => '╮', '┬' => '┴', '┴' => '┬', '┳' => '┻', '┻' => '┳',
        '▼' => '▲', '▲' => '▼', '▽' => '△', '△' => '▽',
    ];

    private const array FLIP_H = [
        '╔' => '╗', '╗' => '╔', '╚' => '╝', '╝' => '╚', '╟' => '╢', '╢' => '╟',
        '┌' => '┐', '┐' => '┌', '└' => '┘', '┘' => '└', '┏' => '┓', '┓' => '┏', '┗' => '┛', '┛' => '┗',
        '╭' => '╮', '╮' => '╭', '╰' => '╯', '╯' => '╰', '├' => '┤', '┤' => '├', '┣' => '┫', '┫' => '┣',
        '▶' => '◄', '◄' => '▶', '▷' => '◁', '◁' => '▷',
    ];

    /** @var array<int, string> */
    public array $ch;

    /** @var array<int, string> */
    public array $role;

    /** @var array<int, ?string> */
    public array $tag;

    /** @var array<int, ?string> */
    public array $href;

    /** @var array<int, int> */
    public array $mask;

    /** @var array<int, int> */
    public array $style;

    /** @var array<int, int> */
    public array $occupied;

    public int $curStyle = self::STY_SOLID;

    /** Author classes stamped on cells painted while set, like `curStyle`. */
    public ?string $curTag = null;

    /** Link target stamped on cells painted while set, like `curTag`. */
    public ?string $curHref = null;

    public function __construct(public readonly int $w, public readonly int $h)
    {
        $n = max(0, $w * $h);
        $this->ch = array_fill(0, $n, ' ');
        $this->role = array_fill(0, $n, 'none');
        $this->tag = array_fill(0, $n, null);
        $this->href = array_fill(0, $n, null);
        $this->mask = array_fill(0, $n, 0);
        $this->style = array_fill(0, $n, 0);
        $this->occupied = array_fill(0, $n, 0);
    }

    public function idx(int $x, int $y): int
    {
        return $y * $this->w + $x;
    }

    public function set(int $x, int $y, string $c, string $role): void
    {
        if ($x >= $this->w || $y >= $this->h) {
            return;
        }

        $i = $this->idx($x, $y);
        // A literal tab measures one cell here but jumps to the terminal's tab stop there,
        // desyncing every column after it. Same width, safe glyph.
        $this->ch[$i] = $c === "\t" ? ' ' : $c;
        $this->role[$i] = $role;

        if ($this->curTag !== null) {
            $this->tag[$i] = $this->curTag;
        }

        if ($this->curHref !== null) {
            $this->href[$i] = $this->curHref;
        }
    }

    /** Direction bits on a free cell; a `border` cell keeps its role. */
    public function addBits(int $x, int $y, int $bits, string $role = 'edge'): void
    {
        if ($x >= $this->w || $y >= $this->h) {
            return;
        }

        $i = $this->idx($x, $y);

        if (($this->occupied[$i] ?? 0) !== 0) {
            return;
        }

        $this->mask[$i] = ($this->mask[$i] ?? 0) | $bits;
        $this->style[$i] = ($this->style[$i] ?? 0) | $this->curStyle;

        if (($this->role[$i] ?? 'none') !== 'border') {
            $this->role[$i] = $role;
        }

        if ($this->curTag !== null) {
            $this->tag[$i] = $this->curTag;
        }

        if ($this->curHref !== null) {
            $this->href[$i] = $this->curHref;
        }
    }

    /** A finished sub-canvas (a subgraph frame's contents) stamped at an offset. */
    public function blit(Canvas $sub, int $ox, int $oy): void
    {
        for ($sy = 0; $sy < $sub->h; $sy++) {
            for ($sx = 0; $sx < $sub->w; $sx++) {
                $x = $ox + $sx;
                $y = $oy + $sy;

                if ($x >= $this->w || $y >= $this->h) {
                    continue;
                }

                $si = $sub->idx($sx, $sy);
                $di = $this->idx($x, $y);
                $this->ch[$di] = $sub->ch[$si];
                $this->role[$di] = $sub->role[$si];
                $this->tag[$di] = $sub->tag[$si];
                $this->href[$di] = $sub->href[$si];
                $this->style[$di] = $sub->style[$si];
                $this->occupied[$di] = 1;
            }
        }
    }

    /** Direction bits even on an occupied cell, so an edge can meet a border. */
    public function junction(int $x, int $y, int $bits): void
    {
        if ($x >= $this->w || $y >= $this->h) {
            return;
        }

        $i = $this->idx($x, $y);
        $this->mask[$i] = ($this->mask[$i] ?? 0) | $bits;

        if (($this->role[$i] ?? 'none') !== 'border') {
            $this->role[$i] = 'edge';
        }
    }

    public function segV(int $x, int $y0, int $y1): void
    {
        $a = min($y0, $y1);
        $b = max($y0, $y1);

        for ($y = $a; $y <= $b; $y++) {
            $bits = 0;

            if ($y > $a) {
                $bits |= self::U;
            }

            if ($y < $b) {
                $bits |= self::D;
            }

            $this->addBits($x, $y, $bits);
        }
    }

    public function segH(int $y, int $x0, int $x1): void
    {
        $a = min($x0, $x1);
        $b = max($x0, $x1);

        for ($x = $a; $x <= $b; $x++) {
            $bits = 0;

            if ($x > $a) {
                $bits |= self::L;
            }

            if ($x < $b) {
                $bits |= self::R;
            }

            $this->addBits($x, $y, $bits);
        }
    }

    /** Accumulated direction bits resolved into glyphs, honouring line style. */
    public function finalizeMask(): void
    {
        $n = $this->w * $this->h;

        for ($i = 0; $i < $n; $i++) {
            if ($this->mask[$i] === 0) {
                continue;
            }

            $c = $this->ch[$i];

            if ($c === ' ') {
                $glyph = self::maskChar($this->mask[$i]);
                $this->ch[$i] = match ($this->style[$i]) {
                    self::STY_DOT => self::DOTTED[$glyph] ?? $glyph,
                    self::STY_THICK => self::THICK[$glyph] ?? $glyph,
                    default => $glyph,
                };
            } elseif ($c === '═' || $c === '║') {
                $this->ch[$i] = self::doubleTee($c, $this->mask[$i]);
            }
        }
    }

    /** Mirrored top to bottom for `BT`: rows reorder, text within a row does not. */
    public function flipVertical(): void
    {
        for ($y = 0; $y < intdiv($this->h, 2); $y++) {
            $y2 = $this->h - 1 - $y;

            for ($x = 0; $x < $this->w; $x++) {
                $this->swap($this->idx($x, $y), $this->idx($x, $y2));
            }
        }

        $n = $this->w * $this->h;

        for ($i = 0; $i < $n; $i++) {
            if (!self::textRole($this->role[$i])) {
                $this->ch[$i] = self::FLIP_V[$this->ch[$i]] ?? $this->ch[$i];
            }
        }
    }

    /** Mirrored left to right for `RL`, each text run then put back in reading order. */
    public function flipHorizontal(): void
    {
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < intdiv($this->w, 2); $x++) {
                $this->swap($this->idx($x, $y), $this->idx($this->w - 1 - $x, $y));
            }
        }

        $n = $this->w * $this->h;

        for ($i = 0; $i < $n; $i++) {
            if (!self::textRole($this->role[$i])) {
                $this->ch[$i] = self::FLIP_H[$this->ch[$i]] ?? $this->ch[$i];
            }
        }

        for ($y = 0; $y < $this->h; $y++) {
            $x = 0;

            while ($x < $this->w) {
                $role = $this->role[$this->idx($x, $y)];

                if ($role === 'text' || $role === 'edgeLabel') {
                    $start = $this->idx($x, $y);

                    while ($x < $this->w && $this->role[$this->idx($x, $y)] === $role) {
                        $x++;
                    }

                    $end = $this->idx($x, $y);

                    for ($a = $start, $b = $end - 1; $a < $b; $a++, $b--) {
                        [$this->ch[$a], $this->ch[$b]] = [$this->ch[$b], $this->ch[$a]];
                    }
                } else {
                    $x++;
                }
            }
        }
    }

    /**
     * Each row grouped into runs of one role, tag and link, wide-glyph continuations dropped,
     * blank rows above and below the drawing taken off.
     *
     * @return array{plain: list<string>, styled: list<list<Span>>, width: int}
     */
    public function toLines(): array
    {
        $plain = [];
        $styled = [];
        $width = 0;

        for ($y = 0; $y < $this->h; $y++) {
            // A trailing CONT counts as painted: the row really does reach that column.
            $last = 0;

            for ($x = $this->w - 1; $x >= 0; $x--) {
                if ($this->ch[$this->idx($x, $y)] !== ' ') {
                    $last = $x + 1;

                    break;
                }
            }

            $width = max($width, $last);
            $spans = [];
            $plainRow = '';
            $run = '';
            $runRole = 'none';
            $runTag = null;
            $runHref = null;

            for ($x = 0; $x < $last; $x++) {
                $i = $this->idx($x, $y);
                $c = $this->ch[$i];

                if ($c === self::CONT) {
                    continue;
                }

                $plainRow .= $c;

                if (($this->role[$i] !== $runRole || $this->tag[$i] !== $runTag || $this->href[$i] !== $runHref) && $run !== '') {
                    $spans[] = self::span($run, $runRole, $runTag, $runHref);
                    $run = '';
                }

                $runRole = $this->role[$i];
                $runTag = $this->tag[$i];
                $runHref = $this->href[$i];
                $run .= $c;
            }

            if ($run !== '') {
                $spans[] = self::span($run, $runRole, $runTag, $runHref);
            }

            $styled[] = $spans;
            // ASCII spaces only, which is all a blank cell holds: trimming more would eat a
            // trailing no-break space `styled` keeps.
            $plain[] = rtrim($plainRow, ' ');
        }

        $first = 0;

        while ($first < count($plain) && $plain[$first] === '') {
            $first++;
        }

        $end = count($plain);

        while ($end > $first && $plain[$end - 1] === '') {
            $end--;
        }

        return [
            'plain' => array_slice($plain, $first, $end - $first),
            'styled' => array_slice($styled, $first, $end - $first),
            'width' => $width,
        ];
    }

    /** `text` painted at `x, y`, a cluster per cell, a wide one claiming a CONT after it. */
    public static function drawText(Canvas $canvas, string $text, int $x, int $y, string $role): void
    {
        $cur = $x;

        foreach (Measure::measured($text) as [$cluster, $cw]) {
            if ($cw === 0) {
                continue;
            }

            $canvas->set($cur, $y, $cluster, $role);

            for ($k = 1; $k < $cw; $k++) {
                $canvas->set($cur + $k, $y, self::CONT, $role);
            }

            $cur += $cw;
        }
    }

    /** `text` painted at `x, y` over whatever edge bits are underneath, which it clears. */
    public static function drawTextOverEdges(Canvas $canvas, string $text, int $x, int $y, string $role): void
    {
        $cur = $x;

        foreach (Measure::measured($text) as [$cluster, $cw]) {
            if ($cw === 0) {
                continue;
            }

            for ($k = 0; $k < $cw; $k++) {
                if ($cur + $k < $canvas->w && $y < $canvas->h) {
                    $canvas->mask[$canvas->idx($cur + $k, $y)] = 0;
                }

                $canvas->set($cur + $k, $y, $k === 0 ? $cluster : self::CONT, $role);
            }

            $cur += $cw;
        }
    }

    public static function maskChar(int $mask): string
    {
        return match ($mask) {
            0 => ' ',
            self::U, self::D, self::U | self::D => '│',
            self::L, self::R, self::L | self::R => '─',
            self::D | self::R => '┌',
            self::D | self::L => '┐',
            self::U | self::R => '└',
            self::U | self::L => '┘',
            self::U | self::D | self::R => '├',
            self::U | self::D | self::L => '┤',
            self::D | self::L | self::R => '┬',
            self::U | self::L | self::R => '┴',
            default => '┼',
        };
    }

    /** An edge teeing into a double-line border: the mixed single/double glyphs. */
    private static function doubleTee(string $c, int $mask): string
    {
        if ($c === '═') {
            if (($mask & self::U) !== 0 && ($mask & self::D) !== 0) {
                return '╪';
            }

            if (($mask & self::D) !== 0) {
                return '╤';
            }

            if (($mask & self::U) !== 0) {
                return '╧';
            }
        } else {
            if (($mask & self::L) !== 0 && ($mask & self::R) !== 0) {
                return '╫';
            }

            if (($mask & self::R) !== 0) {
                return '╟';
            }

            if (($mask & self::L) !== 0) {
                return '╢';
            }
        }

        return $c;
    }

    /** User-authored cells: flips reorder them but must not remap their glyphs. */
    private static function textRole(string $role): bool
    {
        return $role === 'text' || $role === 'edgeLabel' || $role === 'title';
    }

    private function swap(int $i, int $j): void
    {
        [$this->ch[$i], $this->ch[$j]] = [$this->ch[$j], $this->ch[$i]];
        [$this->role[$i], $this->role[$j]] = [$this->role[$j], $this->role[$i]];
        [$this->tag[$i], $this->tag[$j]] = [$this->tag[$j], $this->tag[$i]];
        [$this->href[$i], $this->href[$j]] = [$this->href[$j], $this->href[$i]];
    }

    private static function span(string $text, string $role, ?string $tag, ?string $href): Span
    {
        return new Span($text, $role, $tag === null ? null : explode(' ', $tag), $href);
    }
}
