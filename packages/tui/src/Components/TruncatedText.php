<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\Component;
use Pig\Tui\Width;

/**
 * One line, cut to fit rather than wrapped.
 *
 * For things whose height has to stay fixed — a status line, a file path, a header. Text
 * after the first newline is dropped, because a component that promises one line and
 * returns two moves everything below it.
 */
final class TruncatedText implements Component
{
    public function __construct(
        private string $text,
        private readonly int $paddingX = 0,
        private readonly int $paddingY = 0,
    ) {
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: truncating one line is cheaper than remembering it.
    }

    #[\Override]
    public function render(int $width): array
    {
        $blank = str_repeat(' ', $width);
        $lines = array_fill(0, $this->paddingY, $blank);

        $available = max(1, $width - $this->paddingX * 2);
        $newline = strpos($this->text, "\n");
        $first = $newline === false ? $this->text : substr($this->text, 0, $newline);

        $margin = str_repeat(' ', $this->paddingX);
        $line = $margin . Width::truncate($first, $available) . $margin;

        $lines[] = $line . str_repeat(' ', max(0, $width - Width::visible($line)));

        for ($index = 0; $index < $this->paddingY; $index++) {
            $lines[] = $blank;
        }

        return $lines;
    }
}
