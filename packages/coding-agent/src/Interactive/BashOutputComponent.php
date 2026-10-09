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
 *
 * **Cached on the text, the row count and the width, like every other component here.** It was
 * not, on the grounds that "the wrap is cheap and the text changes on nearly every frame while
 * the command is running" — and both halves are wrong for a command that has *finished*. The
 * wrap is over the whole output, not the few rows that survive it, so a 600KB build log is
 * 600KB of wrapping to keep twenty lines; and a resumed transcript is nothing but finished
 * commands, whose text will never change again. Measured on a real session with 201 tool
 * results: **one keystroke cost 1.5 seconds**, because every frame re-wrapped every command
 * output in the conversation, and the renderer of the day rendered the tree more than once a
 * frame.
 *
 * Two differences from upstream's `bash-execution.ts`, both about the note:
 *
 * - **It counts the rows that were dropped.** Upstream counts *logical* lines — how many the
 *   `slice(-20)` left behind — and then truncates visually on top of that, discarding the
 *   `skippedCount` its own helper hands back. So a long wrapped line is hidden and the note
 *   says nothing was.
 * - **It goes above the output, not below it.** "N earlier lines" reads as a count of what is
 *   off the top, which is where those rows went; upstream puts it down with the exit status.
 */
final class BashOutputComponent implements Component
{
    private string $text = '';

    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?string $cachedText = null;

    private ?int $cachedRows = null;

    private ?int $cachedWidth = null;

    /**
     * @param Closure(int): string|null $note drawn above when rows were dropped
     */
    public function __construct(
        private int $rows,
        private readonly ?Closure $note = null,
        /** Columns left blank on either side — upstream's `outputPad` on `truncateToVisualLines()`. */
        private readonly int $paddingX = 0,
    ) {
    }

    public function setText(string $text): void
    {
        if ($text === $this->text) {
            return;
        }

        $this->text = $text;
        $this->invalidate();
    }

    /** How many rows to keep. `PHP_INT_MAX` for all of them. */
    public function setRows(int $rows): void
    {
        if ($rows === $this->rows) {
            return;
        }

        $this->rows = $rows;
        $this->invalidate();
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedLines = null;
        $this->cachedText = null;
        $this->cachedRows = null;
        $this->cachedWidth = null;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->text === '') {
            return [];
        }

        if (
            $this->cachedLines !== null
            && $this->cachedText === $this->text
            && $this->cachedRows === $this->rows
            && $this->cachedWidth === $width
        ) {
            return $this->cachedLines;
        }

        return $this->cache($width, $this->lines($width));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function cache(int $width, array $lines): array
    {
        $this->cachedText = $this->text;
        $this->cachedRows = $this->rows;
        $this->cachedWidth = $width;
        $this->cachedLines = $lines;

        return $lines;
    }

    /** @return list<string> */
    private function lines(int $width): array
    {
        // Wrapped from the **end**, and only as far as the rows that will be shown: this keeps
        // the tail, so the head is wrapped only to be thrown away. A 50KB build log collapsed
        // to five rows cost a full wrap of 50KB on every width change, and a transcript with
        // 280 of them spent 460ms of a 560ms resize frame here — the right-hand side of a
        // widened window stayed blank for half a second. How many rows the dropped head
        // *would* have made is the one thing the note needs, and `TextWrap::rows()` counts
        // without building them.
        // The text is wrapped to the width between the pads, and `padded()` puts them on.
        $width = max(1, $width - $this->paddingX * 2);
        $logical = explode("\n", $this->text);
        $kept = [];
        $dropped = 0;

        for ($index = count($logical) - 1; $index >= 0; $index--) {
            if (count($kept) >= $this->rows) {
                $dropped += TextWrap::rows($logical[$index], $width);

                continue;
            }

            $rows = TextWrap::wrap($logical[$index], $width);
            $kept = [...$rows, ...$kept];
        }

        if ($dropped === 0 && count($kept) <= $this->rows) {
            return $this->padded($kept, $width);
        }

        $extra = count($kept) - $this->rows;

        if ($extra > 0) {
            $dropped += $extra;
            $kept = array_slice($kept, $extra);
        }

        if ($this->note !== null) {
            // Cut to the width, not wrapped: `... (35 earlier lines)` is 22 columns and every
            // line handed to the renderer has to fit — `TuiBase::checkWidth()` throws on one that
            // does not, so a note nobody cut took the session down on a pane narrower than
            // itself. Truncated rather than wrapped for the same reason a scroll count is:
            // half of a count on a second row says nothing.
            array_unshift($kept, Width::truncate(($this->note)($dropped), $width, ''));
        }

        return $this->padded($kept, $width);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function padded(array $lines, int $width): array
    {
        $pad = str_repeat(' ', $this->paddingX);

        return array_map(
            static fn (string $line): string => $pad . $line . str_repeat(' ', max(0, $width - Width::visible($line))) . $pad,
            $lines,
        );
    }
}
