<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Pig\Tui\Component;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\TextWrap;
use Pig\Tui\TUI;
use Pig\Tui\Width;

/** Lines of text that a test can change between frames, and that records what it was typed. */
final class TextComponent implements Component, Focusable, InputHandler
{
    /** @var list<string> */
    public array $typed = [];

    public bool $focused = false;

    /** @var array{0:int,1:int}|null row and column where this puts `TUI::CURSOR_MARKER` while focused */
    public ?array $cursor = null;

    public int $invalidated = 0;

    /** @param bool $wrap false makes it ignore the width, which the renderer should catch. */
    public function __construct(public string $text = '', public bool $wrap = true)
    {
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->text === '') {
            return [];
        }

        $lines = $this->wrap ? TextWrap::wrap($this->text, $width) : explode("\n", $this->text);

        if ($this->focused && $this->cursor !== null && isset($lines[$this->cursor[0]])) {
            [$row, $column] = $this->cursor;
            $lines[$row] = Width::sliceByColumn($lines[$row], 0, $column) . TUI::CURSOR_MARKER . Width::sliceByColumn($lines[$row], $column);
        }

        return $lines;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->invalidated++;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->typed[] = $data;
    }
}
