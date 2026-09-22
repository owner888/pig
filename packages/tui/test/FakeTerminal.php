<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Closure;
use Pig\Tui\Terminal;

/**
 * A terminal that records instead of drawing.
 *
 * The differential renderer's whole job is deciding what *not* to write, so the only way
 * to test it is to keep every byte it emitted and look at them.
 */
final class FakeTerminal implements Terminal
{
    /** @var list<string> one entry per write() call */
    public array $writes = [];

    public bool $cursorVisible = true;

    public bool $started = false;

    private ?Closure $onInput = null;

    private ?Closure $onResize = null;

    public function __construct(private int $columns = 40, private int $rows = 10)
    {
    }

    #[\Override]
    public function start(Closure $onInput, Closure $onResize): void
    {
        $this->started = true;
        $this->onInput = $onInput;
        $this->onResize = $onResize;
    }

    #[\Override]
    public function stop(): void
    {
        $this->started = false;
        $this->onInput = null;
        $this->onResize = null;
    }

    /** Pretend the user typed. */
    public function type(string $data): void
    {
        ($this->onInput ?? throw new \RuntimeException('terminal not started'))($data);
    }

    public function resize(int $columns, int $rows): void
    {
        $this->columns = $columns;
        $this->rows = $rows;
        ($this->onResize ?? throw new \RuntimeException('terminal not started'))();
    }

    /** Everything written so far, as one string. */
    public function output(): string
    {
        return implode('', $this->writes);
    }

    public function clearWrites(): void
    {
        $this->writes = [];
    }

    #[\Override]
    public function write(string $data): void
    {
        $this->writes[] = $data;
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
        $this->cursorVisible = false;
    }

    #[\Override]
    public function showCursor(): void
    {
        $this->cursorVisible = true;
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
