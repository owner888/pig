<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `canvas.ts`: a grid of cells. Edges accumulate as direction bits rather than
 * glyphs, so crossings and junctions resolve correctly whatever order they are drawn in;
 * `finalizeMask()` turns the bits into characters at the end. `occupied` marks cells a box has
 * claimed, which edge bits must not overwrite.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
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
        '┌' => '└', '└' => '┌', '┐' => '┘', '┘' => '┐', '┏' => '┗', '┗' => '┏', '┓' => '┛', '┛' => '┓',
        '╭' => '╰', '╰' => '╭', '╮' => '╯', '╯' => '╮', '┬' => '┴', '┴' => '┬', '┳' => '┻', '┻' => '┳',
        '▼' => '▲', '▲' => '▼', '▽' => '△', '△' => '▽',
    ];

    private const array FLIP_H = [
        '┌' => '┐', '┐' => '┌', '└' => '┘', '┘' => '└', '┏' => '┓', '┓' => '┏', '┗' => '┛', '┛' => '┗',
        '╭' => '╮', '╮' => '╭', '╰' => '╯', '╯' => '╰', '├' => '┤', '┤' => '├', '┣' => '┫', '┫' => '┣',
        '▶' => '◄', '◄' => '▶', '▷' => '◁', '◁' => '▷',
    ];

    /** @var list<string> */
    public array $ch;

    /** @var list<string> */
    public array $cls;

    /** @var list<int> */
    public array $mask;

    /** @var list<int> */
    public array $style;

    /** @var list<int> */
    public array $occupied;

    public int $curStyle = self::STY_SOLID;

    public function __construct(public readonly int $w, public readonly int $h)
    {
        $n = max(0, $w * $h);
        $this->ch = array_fill(0, $n, ' ');
        $this->cls = array_fill(0, $n, 'none');
        $this->mask = array_fill(0, $n, 0);
        $this->style = array_fill(0, $n, 0);
        $this->occupied = array_fill(0, $n, 0);
    }

    public function idx(int $x, int $y): int
    {
        return $y * $this->w + $x;
    }

    public function set(int $x, int $y, string $c, string $cls): void
    {
        if ($x >= $this->w || $y >= $this->h) {
            return;
        }

        $i = $this->idx($x, $y);
        $this->ch[$i] = $c;
        $this->cls[$i] = $cls;
    }

    /** Direction bits on a free cell; a `border` cell keeps its class. */
    public function addBits(int $x, int $y, int $bits, string $cls = 'edge'): void
    {
        if ($x >= $this->w || $y >= $this->h) {
            return;
        }

        $i = $this->idx($x, $y);

        if ($this->occupied[$i] !== 0) {
            return;
        }

        $this->mask[$i] |= $bits;
        $this->style[$i] |= $this->curStyle;

        if ($this->cls[$i] !== 'border') {
            $this->cls[$i] = $cls;
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
                $this->cls[$di] = $sub->cls[$si];
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
        $this->mask[$i] |= $bits;

        if ($this->cls[$i] !== 'border') {
            $this->cls[$i] = 'edge';
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
        foreach ($this->ch as $i => $c) {
            if ($this->mask[$i] !== 0 && $c === ' ') {
                $glyph = self::maskChar($this->mask[$i]);
                $this->ch[$i] = match ($this->style[$i]) {
                    self::STY_DOT => self::dottedChar($glyph),
                    self::STY_THICK => self::thickChar($glyph),
                    default => $glyph,
                };
            }
        }
    }

    /** Mirrored top to bottom for `BT`: rows reorder, text within a row does not. */
    public function flipVertical(): void
    {
        for ($y = 0; $y < intdiv($this->h, 2); $y++) {
            $y2 = $this->h - 1 - $y;

            for ($x = 0; $x < $this->w; $x++) {
                $i = $this->idx($x, $y);
                $j = $this->idx($x, $y2);
                [$this->ch[$i], $this->ch[$j]] = [$this->ch[$j], $this->ch[$i]];
                [$this->cls[$i], $this->cls[$j]] = [$this->cls[$j], $this->cls[$i]];
            }
        }

        foreach ($this->ch as $i => $c) {
            $this->ch[$i] = self::flipGlyphV($c);
        }
    }

    /** Mirrored left to right for `RL`, each text run then put back in reading order. */
    public function flipHorizontal(): void
    {
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < intdiv($this->w, 2); $x++) {
                $x2 = $this->w - 1 - $x;
                $i = $this->idx($x, $y);
                $j = $this->idx($x2, $y);
                [$this->ch[$i], $this->ch[$j]] = [$this->ch[$j], $this->ch[$i]];
                [$this->cls[$i], $this->cls[$j]] = [$this->cls[$j], $this->cls[$i]];
            }
        }

        foreach ($this->ch as $i => $c) {
            $this->ch[$i] = self::flipGlyphH($c);
        }

        for ($y = 0; $y < $this->h; $y++) {
            $x = 0;

            while ($x < $this->w) {
                $cls = $this->cls[$this->idx($x, $y)];

                if ($cls === 'text' || $cls === 'edgeLabel') {
                    $start = $this->idx($x, $y);

                    while ($x < $this->w && $this->cls[$this->idx($x, $y)] === $cls) {
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
     * Each row grouped into runs of one class, wide-glyph continuations dropped, blank rows above
     * and below the drawing taken off.
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
            $runCls = 'none';

            for ($x = 0; $x < $last; $x++) {
                $i = $this->idx($x, $y);
                $c = $this->ch[$i];

                if ($c === self::CONT) {
                    continue;
                }

                $cls = $this->cls[$i];
                $plainRow .= $c;

                if ($cls !== $runCls && $run !== '') {
                    $spans[] = new Span($run, $runCls);
                    $run = '';
                }

                $runCls = $cls;
                $run .= $c;
            }

            if ($run !== '') {
                $spans[] = new Span($run, $runCls);
            }

            $styled[] = $spans;
            // ASCII spaces only, which is all a blank cell holds.
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
    public static function drawText(Canvas $canvas, string $text, int $x, int $y, string $cls): void
    {
        $cur = $x;

        foreach (Measure::measured($text) as [$cluster, $cw]) {
            if ($cw === 0) {
                continue;
            }

            $canvas->set($cur, $y, $cluster, $cls);

            for ($k = 1; $k < $cw; $k++) {
                $canvas->set($cur + $k, $y, self::CONT, $cls);
            }

            $cur += $cw;
        }
    }

    /** `text` painted at `x, y` over whatever edge bits are underneath, which it clears. */
    public static function drawTextOverEdges(Canvas $canvas, string $text, int $x, int $y, string $cls): void
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

                $canvas->set($cur + $k, $y, $k === 0 ? $cluster : self::CONT, $cls);
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

    public static function dottedChar(string $c): string
    {
        return self::DOTTED[$c] ?? $c;
    }

    public static function thickChar(string $c): string
    {
        return self::THICK[$c] ?? $c;
    }

    public static function flipGlyphV(string $c): string
    {
        return self::FLIP_V[$c] ?? $c;
    }

    public static function flipGlyphH(string $c): string
    {
        return self::FLIP_H[$c] ?? $c;
    }
}
