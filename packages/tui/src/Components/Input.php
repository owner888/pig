<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Caret;
use Pig\Tui\Chars;
use Pig\Tui\Component;
use Pig\Tui\Graphemes;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * One line of editable text, with the usual readline keys.
 *
 * The cursor is a byte offset into the value, and it only ever lands on a grapheme
 * boundary — every movement steps by whole clusters, so Backspace on `👨‍👩‍👧‍👦` removes the
 * family rather than one of its four people and half a joiner.
 *
 * Longer text than fits scrolls horizontally, keeping the cursor in view.
 */
final class Input implements Caret, Component, InputHandler
{
    private const string PROMPT = '> ';

    /** Reverse video for the block cursor, and back to normal. */
    private const string CURSOR_ON = "\x1b[7m";
    private const string CURSOR_OFF = "\x1b[27m";

    private const string PASTE_START = "\x1b[200~";
    private const string PASTE_END = "\x1b[201~";

    private string $value = '';

    /** Byte offset into $value, always on a grapheme boundary. */
    private int $cursor = 0;

    /** @var Closure(string): void|null */
    private ?Closure $onSubmit = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    private bool $pasting = false;

    private string $pasteBuffer = '';

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Replace the value. The cursor stays where it was, clamped to the new end.
     *
     * Upstream's behaviour, kept: a caller that wants the cursor at the end says so,
     * and one restoring a draft mid-edit would not want it moved.
     */
    public function setValue(string $value): void
    {
        $this->value = $value;
        $this->cursor = min($this->cursor, strlen($value));
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** @param Closure(string): void|null $handler called with the value when Enter is pressed */
    public function setSubmitHandler(?Closure $handler): void
    {
        $this->onSubmit = $handler;
    }

    /**
     * Escape, and Ctrl+C — the way out without an answer.
     *
     * `SelectList` has had this from the start and this had not needed it, because every
     * input pig opened was one someone had asked for. Something that opens an input
     * *while the agent is working* is different: with no way to cancel, whoever is waiting
     * for an answer waits forever, and so does the turn that asked for it.
     *
     * @param Closure(): void|null $handler
     */
    public function setCancelHandler(?Closure $handler): void
    {
        $this->onCancel = $handler;
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: one line is cheaper to draw than to remember.
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->pasting || str_contains($data, self::PASTE_START)) {
            $this->buffer($data);

            return;
        }

        if (Keys::isEnter($data) || $data === "\n") {
            if ($this->onSubmit !== null) {
                ($this->onSubmit)($this->value);
            }

            return;
        }

        // Next to Enter, because it is the other answer: one with a value, one without.
        if ($this->onCancel !== null && (Keys::isEscape($data) || Keys::isCtrlC($data))) {
            ($this->onCancel)();

            return;
        }

        if ($this->edit($data) || $this->move($data)) {
            return;
        }

        if (Chars::isPrintable($data)) {
            $this->insert($data);
        }
    }

    /** Editing keys. Returns whether one was recognised. */
    private function edit(string $data): bool
    {
        return match (true) {
            Keys::isBackspace($data) => $this->deleteBefore(),
            Keys::isDelete($data) => $this->deleteAfter(),
            Keys::isCtrlW($data), Keys::isAltBackspace($data) => $this->deleteWordBefore(),
            Keys::isCtrlU($data) => $this->deleteToStart(),
            Keys::isCtrlK($data) => $this->deleteToEnd(),
            default => false,
        };
    }

    /** Cursor keys. Returns whether one was recognised. */
    private function move(string $data): bool
    {
        return match (true) {
            Keys::isArrowLeft($data) => $this->step(-1),
            Keys::isArrowRight($data) => $this->step(1),
            Keys::isCtrlA($data), Keys::isHome($data) => $this->moveTo(0),
            Keys::isCtrlE($data), Keys::isEnd($data) => $this->moveTo(strlen($this->value)),
            Keys::isCtrlLeft($data), Keys::isAltLeft($data) => $this->moveTo(Chars::wordStart($this->value, $this->cursor)),
            Keys::isCtrlRight($data), Keys::isAltRight($data) => $this->moveTo(Chars::wordEnd($this->value, $this->cursor)),
            default => false,
        };
    }

    /**
     * Gather a bracketed paste until its end marker arrives.
     *
     * A paste comes in whatever chunks the terminal felt like, so it is collected whole
     * before being inserted — otherwise a 200-line paste would be read as 200 Enters.
     */
    private function buffer(string $data): void
    {
        if (!$this->pasting) {
            $this->pasting = true;
            $this->pasteBuffer = '';
            $data = str_replace(self::PASTE_START, '', $data);
        }

        $this->pasteBuffer .= $data;
        $end = strpos($this->pasteBuffer, self::PASTE_END);

        if ($end === false) {
            return;
        }

        $pasted = substr($this->pasteBuffer, 0, $end);
        $rest = substr($this->pasteBuffer, $end + strlen(self::PASTE_END));

        $this->pasting = false;
        $this->pasteBuffer = '';

        // Newlines are dropped rather than submitted: this is a one-line field, and a
        // pasted paragraph should land as one line, not fire Enter in the middle of itself.
        $this->insert(str_replace(["\r\n", "\r", "\n"], '', $pasted));

        if ($rest !== '') {
            $this->handleInput($rest);
        }
    }

    private function insert(string $text): void
    {
        $this->value = substr($this->value, 0, $this->cursor) . $text . substr($this->value, $this->cursor);
        $this->cursor += strlen($text);
    }

    private function moveTo(int $offset): bool
    {
        $this->cursor = max(0, min($offset, strlen($this->value)));

        return true;
    }

    /** One grapheme left (-1) or right (1). */
    private function step(int $direction): bool
    {
        if ($direction < 0) {
            $this->cursor -= $this->lengthBefore();
        } else {
            $this->cursor += $this->lengthAfter();
        }

        return true;
    }

    private function deleteBefore(): bool
    {
        $length = $this->lengthBefore();

        if ($length === 0) {
            return true;
        }

        $this->value = substr($this->value, 0, $this->cursor - $length) . substr($this->value, $this->cursor);
        $this->cursor -= $length;

        return true;
    }

    private function deleteAfter(): bool
    {
        $length = $this->lengthAfter();

        if ($length > 0) {
            $this->value = substr($this->value, 0, $this->cursor) . substr($this->value, $this->cursor + $length);
        }

        return true;
    }

    private function deleteWordBefore(): bool
    {
        $from = Chars::wordStart($this->value, $this->cursor);

        $this->value = substr($this->value, 0, $from) . substr($this->value, $this->cursor);
        $this->cursor = $from;

        return true;
    }

    private function deleteToStart(): bool
    {
        $this->value = substr($this->value, $this->cursor);
        $this->cursor = 0;

        return true;
    }

    private function deleteToEnd(): bool
    {
        $this->value = substr($this->value, 0, $this->cursor);

        return true;
    }

    /** Bytes in the grapheme just before the cursor. */
    private function lengthBefore(): int
    {
        $graphemes = Graphemes::split(substr($this->value, 0, $this->cursor));

        return $graphemes === [] ? 0 : strlen($graphemes[count($graphemes) - 1]);
    }

    /** Bytes in the grapheme just after the cursor. */
    private function lengthAfter(): int
    {
        $graphemes = Graphemes::split(substr($this->value, $this->cursor));

        return $graphemes === [] ? 0 : strlen($graphemes[0]);
    }

    /**
     * Where the terminal's own cursor belongs, so an input method composes in the right place.
     *
     * `Editor` has answered this since the trap was found; `Input` never did, and it is the
     * focused component every time a hook asks a question and every time the session picker
     * is open. Typing Chinese into either drew the candidate list at the bottom of the frame
     * — which is where writing a frame leaves the cursor — rather than at the box being typed
     * into. One line, one row: this component is always exactly one.
     */
    #[\Override]
    public function caret(int $width): ?array
    {
        $available = $width - Width::visible(self::PROMPT);

        if ($available <= 0) {
            return null;
        }

        $column = Width::visible(substr($this->value, 0, $this->cursor));

        return [0, Width::visible(self::PROMPT) + $column - $this->scrollStart($available)];
    }

    #[\Override]
    public function render(int $width): array
    {
        $available = $width - Width::visible(self::PROMPT);

        if ($available <= 0) {
            return [self::PROMPT];
        }

        $start = $this->scrollStart($available);
        $end = $start + $available;
        $text = '';
        $column = 0;
        $offset = 0;
        $drawnCursor = false;

        foreach (Graphemes::split($this->value) as $grapheme) {
            $cells = Width::visible($grapheme);
            $isCursor = $offset === $this->cursor;
            $offset += strlen($grapheme);

            if ($column + $cells > $start && $column < $end) {
                if ($column < $start || $column + $cells > $end) {
                    // A wide character straddling the edge is drawn as the spaces it
                    // covers: half a character is not something a terminal can show.
                    $text .= str_repeat(' ', min($column + $cells, $end) - max($column, $start));
                } else {
                    $text .= $isCursor ? self::CURSOR_ON . $grapheme . self::CURSOR_OFF : $grapheme;
                    $drawnCursor = $drawnCursor || $isCursor;
                }
            }

            $column += $cells;
        }

        if (!$drawnCursor && $this->cursor >= strlen($this->value)) {
            // The cursor sits past the last character, on the blank cell the scroll
            // window always leaves room for.
            $text .= self::CURSOR_ON . ' ' . self::CURSOR_OFF;
        }

        $line = self::PROMPT . $text;

        return [$line . str_repeat(' ', max(0, $width - Width::visible($line)))];
    }

    /**
     * The first visible column.
     *
     * The value is treated as one column longer than it is, so the cursor always has a
     * cell of its own to sit in at the end of the line.
     *
     * **Unconditionally, where upstream reserves that cell only when the cursor *is* at the
     * end** — so while the field is scrolled and the cursor is somewhere in the middle, this
     * shows one character fewer than upstream would. Deliberate: upstream's window therefore
     * shifts sideways by a column the moment the cursor reaches the end of the line and back
     * again when it leaves, and in a one-line field where most typing happens at the end, a
     * line that jumps under the cursor costs more than the character it buys. Found by a
     * differential run against `input.ts`, which is also where the other direction came from:
     * upstream slices its window by character index, so on CJK or emoji it draws lines of the
     * wrong width — 79 of 5103 renders in that run — and this one measures in columns.
     */
    private function scrollStart(int $available): int
    {
        $needed = Width::visible($this->value) + 1;

        if ($needed <= $available) {
            return 0;
        }

        $cursorColumn = Width::visible(substr($this->value, 0, $this->cursor));

        return max(0, min($cursorColumn - intdiv($available, 2), $needed - $available));
    }
}
