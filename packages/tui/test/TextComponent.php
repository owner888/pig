<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\TextWrap;

/** Lines of text that a test can change between frames, and that records what it was typed. */
final class TextComponent implements Component, InputHandler
{
    /** @var list<string> */
    public array $typed = [];

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

        return $this->wrap ? TextWrap::wrap($this->text, $width) : explode("\n", $this->text);
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
