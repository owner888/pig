<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\TextWrap;
use Pig\Tui\Width;

/**
 * The tail of a command's output, cut to a few rows.
 *
 * The tail rather than the head, because a build or a test run says what happened at the
 * end. And cut by *visual* rows, not by newlines: one 400-character line is a dozen rows
 * on screen, and counting it as one would push everything else off.
 *
 * Which is why this is a component and not a string prepared in advance — only `render()`
 * knows how wide the terminal is. Upstream passes the whole TUI into the tool component
 * to reach `terminal.columns`; asking at render time is the same answer without the
 * dependency.
 */
final class BashOutputComponent implements Component
{
    private string $text = '';

    /**
     * @param Closure(int): string|null $note drawn above when rows were dropped
     */
    public function __construct(
        private int $rows,
        private readonly ?Closure $note = null,
    ) {
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    /** How many rows to keep. `PHP_INT_MAX` for all of them. */
    public function setRows(int $rows): void
    {
        $this->rows = $rows;
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing cached: the wrap is cheap and the text changes on nearly every frame
        // while the command is running.
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->text === '') {
            return [];
        }

        $visual = [];

        foreach (explode("\n", $this->text) as $line) {
            foreach (TextWrap::wrap($line, max(1, $width)) as $row) {
                $visual[] = $row;
            }
        }

        if (count($visual) <= $this->rows) {
            return self::padded($visual, $width);
        }

        $dropped = count($visual) - $this->rows;
        $kept = array_slice($visual, $dropped);

        if ($this->note !== null) {
            array_unshift($kept, ($this->note)($dropped));
        }

        return self::padded($kept, $width);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function padded(array $lines, int $width): array
    {
        return array_map(
            static fn (string $line): string => $line . str_repeat(' ', max(0, $width - Width::visible($line))),
            $lines,
        );
    }
}
