<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Graphemes;
use Pig\Tui\Terminal;
use Pig\Tui\Width;

/**
 * A terminal that keeps a screen — upstream's `test/virtual-terminal.ts`, which wraps a headless
 * xterm. This is a small VT emulator of its own: printable text with pending wrap, CR, LF that
 * scrolls into a scrollback at the bottom row, cursor moves (`A B C D G H`), erases (`J K`),
 * autowrap (`?7`) and cursor visibility (`?25`). Styling, OSC and APC sequences are consumed and
 * not kept. Enough to read back what a renderer's escape sequences actually leave on screen.
 */
final class VirtualTerminal implements Terminal
{
    /** @var list<list<string>> one string per cell; '' is the second half of a wide character */
    private array $screen = [];

    /** @var list<string> */
    private array $scrollback = [];

    private int $row = 0;

    private int $column = 0;

    private bool $pendingWrap = false;

    private bool $autowrap = true;

    public bool $cursorVisible = true;

    private ?Closure $onInput = null;

    private ?Closure $onResize = null;

    /** @var list<string> */
    public array $writes = [];

    public function __construct(private int $columns = 80, private int $rows = 24)
    {
        $this->screen = array_fill(0, $rows, self::blankRow($columns));
    }

    /** @return list<string> */
    private static function blankRow(int $columns): array
    {
        return array_fill(0, $columns, ' ');
    }

    #[\Override]
    public function start(Closure $onInput, Closure $onResize): void
    {
        $this->onInput = $onInput;
        $this->onResize = $onResize;
    }

    #[\Override]
    public function stop(): void
    {
        $this->onInput = null;
        $this->onResize = null;
    }

    /** Upstream's `sendInput()`. */
    public function sendInput(string $data): void
    {
        ($this->onInput ?? throw new \RuntimeException('terminal not started'))($data);
    }

    /** Upstream's `resize()`: the rows that no longer fit above the cursor go to the scrollback. */
    public function resize(int $columns, int $rows): void
    {
        if ($rows < $this->rows) {
            $drop = max(0, $this->row - ($rows - 1));
            for ($index = 0; $index < $drop; $index++) {
                $this->scrollback[] = self::text(array_shift($this->screen));
            }
            $this->row -= $drop;
            $this->screen = array_slice($this->screen, 0, $rows);
        }
        while (count($this->screen) < $rows) {
            $this->screen[] = self::blankRow($this->columns);
        }
        foreach ($this->screen as $index => $cells) {
            $this->screen[$index] = array_slice(array_pad($cells, $columns, ' '), 0, $columns);
        }
        $this->columns = $columns;
        $this->rows = $rows;
        $this->row = min($this->row, $rows - 1);
        $this->column = min($this->column, $columns - 1);
        $this->pendingWrap = false;
        if ($this->onResize !== null) {
            ($this->onResize)();
        }
    }

    /** Upstream's `waitForRender()`: run the loop until the throttled frame is out. */
    public function waitForRender(float $seconds = 0.04): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            // The queue is drained before the poll; what keeps the poll from sleeping until the
            // next timer is something the drained callback queues for the next tick.
            Loop::get()->defer(static function (): void {
                Loop::get()->defer(static function (): void {
                });
            });
            Loop::get()->tick();
            usleep(1000);
        } while (microtime(true) < $deadline);
    }

    /** @return list<string> the visible rows, trailing blanks trimmed */
    public function getViewport(): array
    {
        return array_map(self::text(...), $this->screen);
    }

    /** @return list<string> scrollback then viewport */
    public function getScrollBuffer(): array
    {
        return [...$this->scrollback, ...$this->getViewport()];
    }

    /** @return array{row: int, column: int} */
    public function cursor(): array
    {
        return ['row' => $this->row, 'column' => $this->column];
    }

    public function output(): string
    {
        return implode('', $this->writes);
    }

    public function clearWrites(): void
    {
        $this->writes = [];
    }

    /** @param list<string> $cells */
    private static function text(array $cells): string
    {
        return rtrim(implode('', $cells));
    }

    #[\Override]
    public function write(string $data): void
    {
        $this->writes[] = $data;
        $length = strlen($data);
        $index = 0;
        while ($index < $length) {
            $char = $data[$index];
            if ($char === "\x1b") {
                $index = $this->escape($data, $index);
                continue;
            }
            if ($char === "\r") {
                $this->column = 0;
                $this->pendingWrap = false;
                $index++;
                continue;
            }
            if ($char === "\n") {
                $this->lineFeed();
                $index++;
                continue;
            }
            if ($char === "\x08") {
                $this->column = max(0, $this->column - 1);
                $this->pendingWrap = false;
                $index++;
                continue;
            }
            if (ord($char) < 0x20) {
                $index++;
                continue;
            }

            $end = $index;
            while ($end < $length && $data[$end] !== "\x1b" && ord($data[$end]) >= 0x20) {
                $end++;
            }
            foreach (Graphemes::split(substr($data, $index, $end - $index)) as $grapheme) {
                $this->put($grapheme);
            }
            $index = $end;
        }
    }

    private function put(string $grapheme): void
    {
        $width = Width::visible($grapheme);
        if ($width === 0) {
            return;
        }
        if ($this->pendingWrap) {
            $this->column = 0;
            $this->lineFeed();
        }
        if ($this->column + $width > $this->columns) {
            if (!$this->autowrap) {
                return;
            }
            $this->column = 0;
            $this->lineFeed();
        }
        $this->screen[$this->row][$this->column] = $grapheme;
        for ($cell = 1; $cell < $width; $cell++) {
            $this->screen[$this->row][$this->column + $cell] = '';
        }
        $this->column += $width;
        if ($this->column >= $this->columns) {
            $this->column = $this->columns - 1;
            $this->pendingWrap = $this->autowrap;
        }
    }

    private function lineFeed(): void
    {
        $this->pendingWrap = false;
        if ($this->row === $this->rows - 1) {
            $this->scrollback[] = self::text(array_shift($this->screen));
            $this->screen[] = self::blankRow($this->columns);

            return;
        }
        $this->row++;
    }

    /** @return int the index after the sequence */
    private function escape(string $data, int $index): int
    {
        $next = $data[$index + 1] ?? '';
        if ($next === ']' || $next === '_' || $next === 'P') {
            $length = strlen($data);
            for ($j = $index + 2; $j < $length; $j++) {
                if ($data[$j] === "\x07") {
                    return $j + 1;
                }
                if ($data[$j] === "\x1b" && ($data[$j + 1] ?? '') === '\\') {
                    return $j + 2;
                }
            }

            return $length;
        }
        if ($next !== '[') {
            return $index + 2;
        }

        if (preg_match('/\G\x1b\[([?>=<]?)([\d;]*)([\x20-\x2f]*)([\x40-\x7e])/', $data, $match, 0, $index) !== 1) {
            return $index + 2;
        }
        [$all, $private, $params, , $final] = $match;
        $numbers = $params === '' ? [] : array_map('intval', explode(';', $params));
        $n = max(1, $numbers[0] ?? 1);

        if ($private === '?') {
            foreach ($numbers as $mode) {
                if ($mode === 7) {
                    $this->autowrap = $final === 'h';
                } elseif ($mode === 25) {
                    $this->cursorVisible = $final === 'h';
                }
            }

            return $index + strlen($all);
        }
        if ($private !== '') {
            return $index + strlen($all);
        }

        switch ($final) {
            case 'A':
                $this->row = max(0, $this->row - $n);
                $this->pendingWrap = false;
                break;
            case 'B':
                $this->row = min($this->rows - 1, $this->row + $n);
                $this->pendingWrap = false;
                break;
            case 'C':
                $this->column = min($this->columns - 1, $this->column + $n);
                $this->pendingWrap = false;
                break;
            case 'D':
                $this->column = max(0, $this->column - $n);
                $this->pendingWrap = false;
                break;
            case 'G':
                $this->column = min($this->columns - 1, $n - 1);
                $this->pendingWrap = false;
                break;
            case 'H':
                $this->row = min($this->rows - 1, max(1, $numbers[0] ?? 1) - 1);
                $this->column = min($this->columns - 1, max(1, $numbers[1] ?? 1) - 1);
                $this->pendingWrap = false;
                break;
            case 'J':
                $mode = $numbers[0] ?? 0;
                if ($mode === 3) {
                    $this->scrollback = [];
                } elseif ($mode === 2) {
                    $this->screen = array_fill(0, $this->rows, self::blankRow($this->columns));
                } else {
                    $this->eraseLine(0);
                    for ($row = $this->row + 1; $row < $this->rows; $row++) {
                        $this->screen[$row] = self::blankRow($this->columns);
                    }
                }
                break;
            case 'K':
                $this->eraseLine($numbers[0] ?? 0);
                break;
        }

        return $index + strlen($all);
    }

    private function eraseLine(int $mode): void
    {
        for ($column = 0; $column < $this->columns; $column++) {
            if ($mode === 2 || ($mode === 0 && $column >= $this->column) || ($mode === 1 && $column <= $this->column)) {
                $this->screen[$this->row][$column] = ' ';
            }
        }
    }

    #[\Override]
    public function columns(): int
    {
        return $this->columns;
    }

    #[\Override]
    public function rows(): int
    {
        return $this->rows;
    }

    #[\Override]
    public function moveBy(int $lines): void
    {
        $this->write($lines > 0 ? "\x1b[{$lines}B" : ($lines < 0 ? "\x1b[" . -$lines . 'A' : ''));
    }

    #[\Override]
    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    #[\Override]
    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    #[\Override]
    public function clearLine(): void
    {
        $this->write("\x1b[K");
    }

    #[\Override]
    public function clearFromCursor(): void
    {
        $this->write("\x1b[J");
    }

    #[\Override]
    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        $this->write("\x1b]0;{$title}\x07");
    }
}
