<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Autocomplete\AutocompleteItem;
use Pig\Tui\Autocomplete\AutocompleteProvider;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\Suggestions;
use Pig\Tui\Chars;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Clipboard\ClipboardFile;
use Pig\Tui\Component;
use Pig\Tui\Graphemes;
use Pig\Tui\Caret;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * The box the user types into: many lines, history, completions, and pastes.
 *
 * Enter submits and Shift+Enter breaks a line, which is backwards from an editor and
 * right for a prompt. Terminals disagree about how to say "Shift+Enter", so several
 * spellings are accepted; a terminal that speaks the Kitty protocol says it properly and
 * the rest are worked around.
 *
 * There are two kinds of line here and keeping them apart is most of the work. A *logical*
 * line is what the user typed and what `text()` returns. A *visual* line is one row on
 * screen; a long logical line is several of them. Left and right move through the logical
 * line, up and down move between visual ones, because that is what the cursor looks like
 * it is doing.
 */
final class Editor implements Caret, Component, InputHandler
{
    /** Reverse video for the block cursor, and back to normal. */
    private const string CURSOR_ON = "\x1b[7m";
    private const string CURSOR_OFF = "\x1b[27m";

    private const string PASTE_START = "\x1b[200~";
    private const string PASTE_END = "\x1b[201~";

    /** A paste past either of these is held aside and shown as a marker. */
    private const int PASTE_MARKER_LINES = 10;
    private const int PASTE_MARKER_CHARS = 1000;

    private const int HISTORY_LIMIT = 100;
    private const int SUGGESTIONS_SHOWN = 5;

    /** @var non-empty-list<string> */
    private array $lines = [''];

    private int $cursorLine = 0;

    /** Byte offset into the cursor's logical line. */
    private int $cursorCol = 0;

    /**
     * The width of the last frame.
     *
     * Up and down need to know where the rows fall, and rows depend on width. A key can
     * arrive before the first render, so this starts at something rather than nothing.
     */
    private int $lastWidth = 80;

    private ?AutocompleteProvider $provider = null;

    private ?Clipboard $clipboard = null;

    private ?SelectList $suggestionList = null;

    /** @var list<AutocompleteItem> the items $suggestionList was built from, in the same order */
    private array $suggestionItems = [];

    private string $suggestionPrefix = '';

    /** @var array<int, string> paste id => the text a marker stands for */
    private array $pastes = [];

    private int $pasteCounter = 0;

    private bool $pasting = false;

    private string $pasteBuffer = '';

    /** @var list<string> newest first */
    private array $history = [];

    /** -1 means not browsing; 0 is the most recent entry. */
    private int $historyIndex = -1;

    /** @var Closure(string): void|null */
    private ?Closure $onSubmit = null;

    /** @var Closure(string): void|null */
    private ?Closure $onChange = null;

    /** While the agent is working, Enter should not start another turn. */
    public bool $disableSubmit = false;

    private EditorTheme $theme;

    public function __construct(?EditorTheme $theme = null)
    {
        // Not a default parameter: a theme is made of closures, and a default value has
        // to be a constant expression.
        $this->theme = $theme ?? EditorTheme::default();
    }

    /**
     * Repaint with a different theme.
     *
     * The border is the one part of an editor that says something while you type — the
     * coding agent colours it by thinking level — and a theme made of closures cannot be
     * changed in place, so it is replaced whole.
     */
    public function setTheme(EditorTheme $theme): void
    {
        $this->theme = $theme;
        $this->invalidate();
    }

    public function setAutocompleteProvider(?AutocompleteProvider $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Where Ctrl+V gets what it pastes. Without one, Ctrl+V does nothing.
     *
     * Injected rather than read directly, because reading the real clipboard means
     * running other programs — which a test must not do and a sandbox may refuse.
     */
    public function setClipboard(?Clipboard $clipboard): void
    {
        $this->clipboard = $clipboard;
    }

    /** @param Closure(string): void|null $handler */
    public function setSubmitHandler(?Closure $handler): void
    {
        $this->onSubmit = $handler;
    }

    /** @param Closure(string): void|null $handler called with the whole text after every change */
    public function setChangeHandler(?Closure $handler): void
    {
        $this->onChange = $handler;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** @return non-empty-list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array{line: int, col: int} */
    public function cursor(): array
    {
        return ['line' => $this->cursorLine, 'col' => $this->cursorCol];
    }

    public function setText(string $text): void
    {
        $this->historyIndex = -1;
        $this->replaceText($text);
    }

    /** Put $text in at the cursor, as if it had been typed. */
    public function insertAtCursor(string $text): void
    {
        if ($text !== '') {
            $this->insert($text);
        }
    }

    public function isShowingSuggestions(): bool
    {
        return $this->suggestionList !== null;
    }

    /** Remember a submitted prompt, so Up can bring it back. */
    public function addToHistory(string $text): void
    {
        $trimmed = trim($text);

        if ($trimmed === '' || ($this->history[0] ?? null) === $trimmed) {
            return;
        }

        array_unshift($this->history, $trimmed);
        $this->history = array_slice($this->history, 0, self::HISTORY_LIMIT);
    }

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: the text is laid out fresh each frame.
    }

    /**
     * Where the caret is, for the terminal's own cursor to follow.
     *
     * One row down for the top border, and the column is measured rather than counted:
     * the text before the caret may be CJK, which is two columns a character.
     */
    #[\Override]
    public function caret(int $width): ?array
    {
        foreach ($this->layout($width) as $row => $line) {
            if ($line->cursorPos !== null) {
                return [$row + 1, Width::visible(substr($line->text, 0, $line->cursorPos))];
            }
        }

        return null;
    }

    #[\Override]
    public function render(int $width): array
    {
        $this->lastWidth = $width;
        $rule = str_repeat(($this->theme->border)('─'), $width);
        $lines = [$rule];

        foreach ($this->layout($width) as $layoutLine) {
            $lines[] = $this->draw($layoutLine, $width);
        }

        $lines[] = $rule;

        foreach ($this->suggestionList?->render($width) ?? [] as $suggestion) {
            $lines[] = $suggestion;
        }

        return $lines;
    }

    /** One row, with the cursor painted into it and the rest padded out. */
    private function draw(LayoutLine $line, int $width): string
    {
        $text = $line->text;
        $visible = Width::visible($text);

        if ($line->cursorPos === null) {
            return $text . str_repeat(' ', max(0, $width - $visible));
        }

        $before = substr($text, 0, $line->cursorPos);
        $after = substr($text, $line->cursorPos);

        if ($after !== '') {
            // On a character: invert it, which costs no columns.
            $grapheme = Graphemes::split($after)[0];
            $text = $before . self::CURSOR_ON . $grapheme . self::CURSOR_OFF . substr($after, strlen($grapheme));
        } elseif ($visible < $width) {
            $text = $before . self::CURSOR_ON . ' ' . self::CURSOR_OFF;
            $visible++;
        } else {
            // The row is already full, so there is no cell to add. Invert the last
            // character instead of drawing a cursor that would wrap the line.
            $graphemes = Graphemes::split($before);

            if ($graphemes !== []) {
                $last = array_pop($graphemes);
                $text = implode('', $graphemes) . self::CURSOR_ON . $last . self::CURSOR_OFF;
            }
        }

        return $text . str_repeat(' ', max(0, $width - $visible));
    }

    /**
     * Break the buffer into the rows it will be drawn as.
     *
     * @return non-empty-list<LayoutLine>
     */
    private function layout(int $width): array
    {
        if ($this->isEmpty()) {
            return [new LayoutLine('', 0)];
        }

        $rows = [];

        foreach ($this->lines as $index => $line) {
            $onThisLine = $index === $this->cursorLine;

            if (Width::visible($line) <= $width) {
                $rows[] = new LayoutLine($line, $onThisLine ? $this->cursorCol : null);

                continue;
            }

            $chunks = self::chunk($line, $width);

            foreach ($chunks as $position => [$text, $start, $end]) {
                $isLast = $position === count($chunks) - 1;

                // A cursor exactly on a wrap point belongs to the row it will type into,
                // which is the next one — except at the very end, where there is none.
                $here = $onThisLine
                    && $this->cursorCol >= $start
                    && ($isLast ? $this->cursorCol <= $end : $this->cursorCol < $end);

                $rows[] = new LayoutLine($text, $here ? $this->cursorCol - $start : null);
            }
        }

        return $rows === [] ? [new LayoutLine('', 0)] : $rows;
    }

    /**
     * Cut a line into pieces that each fit, never splitting a grapheme.
     *
     * @return list<array{0: string, 1: int, 2: int}> text, and its byte range in the line
     */
    private static function chunk(string $line, int $width): array
    {
        $chunks = [];
        $text = '';
        $columns = 0;
        $start = 0;
        $offset = 0;

        foreach (Graphemes::split($line) as $grapheme) {
            $cells = Width::visible($grapheme);

            if ($columns + $cells > $width && $text !== '') {
                $chunks[] = [$text, $start, $offset];
                $text = $grapheme;
                $columns = $cells;
                $start = $offset;
            } else {
                $text .= $grapheme;
                $columns += $cells;
            }

            $offset += strlen($grapheme);
        }

        if ($text !== '') {
            $chunks[] = [$text, $start, $offset];
        }

        return $chunks;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->pasting || str_contains($data, self::PASTE_START)) {
            $this->bufferPaste($data);

            return;
        }

        // Ctrl+C belongs to whatever is hosting the editor: at an empty prompt it means
        // quit, and only the application knows whether it is empty enough to quit from.
        if (Keys::isCtrlC($data)) {
            return;
        }

        if ($this->suggestionList !== null && $this->suggestionKey($data)) {
            return;
        }

        if (Keys::isTab($data)) {
            $this->completeOnTab();

            return;
        }

        $this->editingKey($data);
    }

    /**
     * Keys the completion list claims while it is open.
     *
     * Returns false for anything it does not want — ordinary typing falls through and
     * narrows the list rather than being swallowed by it.
     */
    private function suggestionKey(string $data): bool
    {
        if (Keys::isEscape($data)) {
            $this->cancelSuggestions();

            return true;
        }

        if (Keys::isArrowUp($data) || Keys::isArrowDown($data)) {
            $this->suggestionList?->handleInput($data);

            return true;
        }

        if (Keys::isTab($data)) {
            $this->applySuggestion();

            return true;
        }

        if (!Keys::isEnter($data)) {
            return false;
        }

        // Enter on a command applies it and submits in one press; on a path it only
        // applies, because the path is part of a sentence the user is still writing.
        $wasCommand = str_starts_with($this->suggestionPrefix, '/');
        $this->applySuggestion();

        if (!$wasCommand) {
            return true;
        }

        $this->submit();

        return true;
    }

    private function editingKey(string $data): void
    {
        match (true) {
            Keys::isCtrl($data, 'v') => $this->pasteFromClipboard(),
            Keys::isCtrlK($data) => $this->deleteToLineEnd(),
            Keys::isCtrlU($data) => $this->deleteToLineStart(),
            Keys::isCtrlW($data), Keys::isAltBackspace($data) => $this->deleteWordBackwards(),
            Keys::isCtrlA($data), Keys::isHome($data) => $this->cursorCol = 0,
            Keys::isCtrlE($data), Keys::isEnd($data) => $this->cursorCol = strlen($this->lines[$this->cursorLine]),
            self::isNewLine($data) => $this->breakLine(),
            Keys::isEnter($data) => $this->submit(),
            Keys::isBackspace($data) => $this->backspace(),
            Keys::isDelete($data) => $this->forwardDelete(),
            Keys::isAltLeft($data), Keys::isCtrlLeft($data) => $this->moveWordBackwards(),
            Keys::isAltRight($data), Keys::isCtrlRight($data) => $this->moveWordForwards(),
            Keys::isArrowUp($data) => $this->up(),
            Keys::isArrowDown($data) => $this->down(),
            Keys::isArrowLeft($data) => $this->moveHorizontally(-1),
            Keys::isArrowRight($data) => $this->moveHorizontally(1),
            Chars::isPrintable($data) => $this->insert($data),
            default => null,
        };
    }

    /**
     * Ctrl+V: a picture if there is one on the clipboard, otherwise the text.
     *
     * A picture cannot go into a line of text, so it is written to a file and the file's
     * path is what gets typed — which the agent's tools can then read, and which reads
     * as a sentence in the prompt.
     *
     * Note that most terminals never send this: Cmd+V on macOS and Ctrl+Shift+V on Linux
     * are handled by the terminal itself and arrive as a bracketed paste, which is a
     * different path entirely and cannot carry an image. This is the one that can.
     */
    private function pasteFromClipboard(): void
    {
        if ($this->clipboard === null) {
            return;
        }

        $image = $this->clipboard->image();

        if ($image !== null) {
            $path = ClipboardFile::write($image);

            if ($path !== null) {
                $this->insertAtCursor($path);

                return;
            }
        }

        // No picture, or one in a format nothing here can name: fall back to the text,
        // which is what a paste usually means anyway. Through paste() rather than
        // insertAtCursor(), so that newlines become lines instead of literal escapes and
        // a wall of text still gets held behind a marker.
        $text = $this->clipboard->text();

        if ($text !== null && $text !== '') {
            $this->paste($text);
        }
    }

    /**
     * Every spelling of "Enter, but I meant a new line".
     *
     * The Kitty protocol gives the first two properly; the rest are what particular
     * terminals send instead, and each one is somebody's Shift+Enter.
     */
    private static function isNewLine(string $data): bool
    {
        return Keys::isShiftEnter($data)
            || Keys::isAltEnter($data)
            || $data === "\n"
            || $data === "\x1b\r"
            || $data === "\x1b[13;2~"
            || $data === "\\\r"
            || (strlen($data) > 1 && $data[0] === "\n")
            || (strlen($data) > 1 && str_contains($data, "\x1b") && str_contains($data, "\r"));
    }

    // ---- editing -------------------------------------------------------------------

    private function insert(string $text): void
    {
        $this->historyIndex = -1;
        $line = $this->lines[$this->cursorLine];

        $this->lines[$this->cursorLine] = substr($line, 0, $this->cursorCol) . $text . substr($line, $this->cursorCol);
        $this->cursorCol += strlen($text);
        $this->changed();

        if ($this->suggestionList !== null) {
            $this->refreshSuggestions();

            return;
        }

        if ($this->shouldOpenSuggestions($text)) {
            $this->openSuggestions();
        }
    }

    /** Whether typing $text just put the cursor somewhere worth offering completions. */
    private function shouldOpenSuggestions(string $text): bool
    {
        $before = substr($this->lines[$this->cursorLine], 0, $this->cursorCol);

        if ($text === '/') {
            return trim($before) === '/' || trim($before) === '';
        }

        if ($text === '@') {
            // Only at the start of a word, so an email address is left alone.
            $preceding = strlen($before) >= 2 ? $before[strlen($before) - 2] : '';

            return strlen($before) === 1 || $preceding === ' ' || $preceding === "\t";
        }

        return preg_match('/^[a-zA-Z0-9]+$/', $text) === 1 && $this->inCompletableContext($before);
    }

    /** Mid-command or mid-`@reference`: somewhere a completion still applies. */
    private function inCompletableContext(string $before): bool
    {
        return str_starts_with(ltrim($before), '/') || preg_match('/(?:^|\s)@\S*$/', $before) === 1;
    }

    private function breakLine(): void
    {
        $this->historyIndex = -1;
        $line = $this->lines[$this->cursorLine];

        $this->lines[$this->cursorLine] = substr($line, 0, $this->cursorCol);
        array_splice($this->lines, $this->cursorLine + 1, 0, [substr($line, $this->cursorCol)]);

        $this->cursorLine++;
        $this->cursorCol = 0;
        $this->changed();
    }

    private function backspace(): void
    {
        $this->historyIndex = -1;

        if ($this->cursorCol > 0) {
            $line = $this->lines[$this->cursorLine];
            $length = self::lastGraphemeLength(substr($line, 0, $this->cursorCol));

            $this->lines[$this->cursorLine] = substr($line, 0, $this->cursorCol - $length) . substr($line, $this->cursorCol);
            $this->cursorCol -= $length;
        } elseif ($this->cursorLine > 0) {
            $this->joinWithPrevious();
        }

        $this->changed();
        $this->refreshOrReopenSuggestions();
    }

    private function forwardDelete(): void
    {
        $this->historyIndex = -1;
        $line = $this->lines[$this->cursorLine];

        if ($this->cursorCol < strlen($line)) {
            $length = self::firstGraphemeLength(substr($line, $this->cursorCol));
            $this->lines[$this->cursorLine] = substr($line, 0, $this->cursorCol) . substr($line, $this->cursorCol + $length);
        } elseif ($this->cursorLine < count($this->lines) - 1) {
            $this->joinWithNext();
        }

        $this->changed();
        $this->refreshOrReopenSuggestions();
    }

    private function deleteToLineStart(): void
    {
        $this->historyIndex = -1;

        if ($this->cursorCol > 0) {
            $this->lines[$this->cursorLine] = substr($this->lines[$this->cursorLine], $this->cursorCol);
            $this->cursorCol = 0;
        } elseif ($this->cursorLine > 0) {
            $this->joinWithPrevious();
        }

        $this->changed();
    }

    private function deleteToLineEnd(): void
    {
        $this->historyIndex = -1;

        if ($this->cursorCol < strlen($this->lines[$this->cursorLine])) {
            $this->lines[$this->cursorLine] = substr($this->lines[$this->cursorLine], 0, $this->cursorCol);
        } elseif ($this->cursorLine < count($this->lines) - 1) {
            $this->joinWithNext();
        }

        $this->changed();
    }

    private function deleteWordBackwards(): void
    {
        $this->historyIndex = -1;

        if ($this->cursorCol === 0) {
            if ($this->cursorLine > 0) {
                $this->joinWithPrevious();
            }

            $this->changed();

            return;
        }

        $line = $this->lines[$this->cursorLine];
        $from = Chars::wordStart($line, $this->cursorCol);

        $this->lines[$this->cursorLine] = substr($line, 0, $from) . substr($line, $this->cursorCol);
        $this->cursorCol = $from;
        $this->changed();
    }

    private function joinWithPrevious(): void
    {
        $current = $this->lines[$this->cursorLine];
        $previous = $this->lines[$this->cursorLine - 1];

        $this->lines[$this->cursorLine - 1] = $previous . $current;
        array_splice($this->lines, $this->cursorLine, 1);

        $this->cursorLine--;
        $this->cursorCol = strlen($previous);
    }

    private function joinWithNext(): void
    {
        $this->lines[$this->cursorLine] .= $this->lines[$this->cursorLine + 1];
        array_splice($this->lines, $this->cursorLine + 1, 1);
    }

    // ---- movement ------------------------------------------------------------------

    private function moveHorizontally(int $direction): void
    {
        $line = $this->lines[$this->cursorLine];

        if ($direction > 0) {
            if ($this->cursorCol < strlen($line)) {
                $this->cursorCol += self::firstGraphemeLength(substr($line, $this->cursorCol));
            } elseif ($this->cursorLine < count($this->lines) - 1) {
                $this->cursorLine++;
                $this->cursorCol = 0;
            }

            return;
        }

        if ($this->cursorCol > 0) {
            $this->cursorCol -= self::lastGraphemeLength(substr($line, 0, $this->cursorCol));
        } elseif ($this->cursorLine > 0) {
            $this->cursorLine--;
            $this->cursorCol = strlen($this->lines[$this->cursorLine]);
        }
    }

    /**
     * Up: the previous row, or the previous thing said.
     *
     * An empty editor means the user wants their last prompt back. A recalled one means
     * they are browsing, and Up at its top goes further back rather than nowhere.
     */
    private function up(): void
    {
        if ($this->isEmpty()) {
            $this->recall(-1);

            return;
        }

        $rows = $this->visualLines($this->lastWidth);

        if ($this->historyIndex > -1 && $this->currentRow($rows) === 0) {
            $this->recall(-1);

            return;
        }

        $this->moveVertically($rows, -1);
    }

    private function down(): void
    {
        $rows = $this->visualLines($this->lastWidth);

        if ($this->historyIndex > -1 && $this->currentRow($rows) === count($rows) - 1) {
            $this->recall(1);

            return;
        }

        $this->moveVertically($rows, 1);
    }

    /**
     * @param list<VisualLine> $rows
     */
    private function moveVertically(array $rows, int $delta): void
    {
        $current = $this->currentRow($rows);
        $column = $this->cursorCol - (($rows[$current] ?? null)?->startCol ?? 0);
        $target = $rows[$current + $delta] ?? null;

        if ($target === null) {
            return;
        }

        $this->cursorLine = $target->logicalLine;
        $this->cursorCol = min(
            $target->startCol + min($column, $target->length),
            strlen($this->lines[$target->logicalLine]),
        );
    }

    private function moveWordBackwards(): void
    {
        if ($this->cursorCol === 0) {
            if ($this->cursorLine > 0) {
                $this->cursorLine--;
                $this->cursorCol = strlen($this->lines[$this->cursorLine]);
            }

            return;
        }

        $this->cursorCol = Chars::wordStart($this->lines[$this->cursorLine], $this->cursorCol);
    }

    private function moveWordForwards(): void
    {
        $line = $this->lines[$this->cursorLine];

        if ($this->cursorCol >= strlen($line)) {
            if ($this->cursorLine < count($this->lines) - 1) {
                $this->cursorLine++;
                $this->cursorCol = 0;
            }

            return;
        }

        $this->cursorCol = Chars::wordEnd($line, $this->cursorCol);
    }

    /**
     * Where each drawn row starts in the buffer.
     *
     * @return non-empty-list<VisualLine>
     */
    private function visualLines(int $width): array
    {
        $rows = [];

        foreach ($this->lines as $index => $line) {
            if ($line === '') {
                // An empty line still occupies a row, and the cursor can sit on it.
                $rows[] = new VisualLine($index, 0, 0);

                continue;
            }

            if (Width::visible($line) <= $width) {
                $rows[] = new VisualLine($index, 0, strlen($line));

                continue;
            }

            foreach (self::chunk($line, $width) as [, $start, $end]) {
                $rows[] = new VisualLine($index, $start, $end - $start);
            }
        }

        return $rows === [] ? [new VisualLine(0, 0, 0)] : $rows;
    }

    /** @param non-empty-list<VisualLine> $rows */
    private function currentRow(array $rows): int
    {
        foreach ($rows as $index => $row) {
            if ($row->logicalLine !== $this->cursorLine) {
                continue;
            }

            $column = $this->cursorCol - $row->startCol;
            $isLastOfLine = ($rows[$index + 1] ?? null)?->logicalLine !== $row->logicalLine;

            if ($column >= 0 && ($column < $row->length || ($isLastOfLine && $column <= $row->length))) {
                return $index;
            }
        }

        return count($rows) - 1;
    }

    // ---- history -------------------------------------------------------------------

    /** @param int $direction -1 for older, 1 for newer */
    private function recall(int $direction): void
    {
        if ($this->history === []) {
            return;
        }

        $index = $this->historyIndex - $direction;

        if ($index < -1 || $index >= count($this->history)) {
            return;
        }

        $this->historyIndex = $index;
        // -1 is where the user was before they started browsing, which was nothing.
        $this->replaceText($index === -1 ? '' : $this->history[$index]);
    }

    /** Replace the buffer without leaving history-browsing mode. */
    private function replaceText(string $text): void
    {
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $this->cursorLine = count($this->lines) - 1;
        $this->cursorCol = strlen($this->lines[$this->cursorLine]);
        $this->changed();
    }

    // ---- submitting ----------------------------------------------------------------

    private function submit(): void
    {
        if ($this->disableSubmit) {
            return;
        }

        $text = trim($this->text());

        // The markers stood in for the text while it was being edited; what gets sent is
        // the text itself.
        foreach ($this->pastes as $id => $content) {
            // Replaced through a callback, not a replacement string: pasted text is
            // arbitrary, and a `$1` in it would otherwise be read as a backreference.
            $text = preg_replace_callback(
                '/\[paste #' . $id . '( (\+\d+ lines|\d+ chars))?\]/',
                static fn (): string => $content,
                $text,
            ) ?? $text;
        }

        $this->lines = [''];
        $this->cursorLine = 0;
        $this->cursorCol = 0;
        $this->pastes = [];
        $this->pasteCounter = 0;
        $this->historyIndex = -1;

        $this->changed();

        if ($this->onSubmit !== null) {
            ($this->onSubmit)($text);
        }
    }

    // ---- pasting -------------------------------------------------------------------

    private function bufferPaste(string $data): void
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

        $this->paste($pasted);

        if ($rest !== '') {
            $this->handleInput($rest);
        }
    }

    private function paste(string $text): void
    {
        $this->historyIndex = -1;
        $text = self::cleanPaste($text);

        if ($text === '') {
            return;
        }

        // A path pasted straight after a word runs into it; a space makes it readable,
        // and the user would have typed one.
        if (preg_match('#^[/~.]#', $text) === 1 && $this->cursorCol > 0) {
            $preceding = $this->lines[$this->cursorLine][$this->cursorCol - 1];

            if (preg_match('/\w/', $preceding) === 1) {
                $text = ' ' . $text;
            }
        }

        $pastedLines = explode("\n", $text);

        // A wall of text would bury the prompt, so it is held aside and shown as one
        // line. Submitting puts the real thing back.
        if (count($pastedLines) > self::PASTE_MARKER_LINES || strlen($text) > self::PASTE_MARKER_CHARS) {
            $this->insertMarker($text, count($pastedLines));

            return;
        }

        if (count($pastedLines) === 1) {
            // Inserted whole rather than one character at a time, so a pasted `/` or `@`
            // does not pop a completion list the user did not ask for.
            $this->insert($pastedLines[0]);

            return;
        }

        $line = $this->lines[$this->cursorLine];
        $before = substr($line, 0, $this->cursorCol);
        $after = substr($line, $this->cursorCol);
        $last = $pastedLines[count($pastedLines) - 1];

        $replacement = [$before . $pastedLines[0]];

        foreach (array_slice($pastedLines, 1, -1) as $middle) {
            $replacement[] = $middle;
        }

        $replacement[] = $last . $after;

        array_splice($this->lines, $this->cursorLine, 1, $replacement);

        $this->cursorLine += count($pastedLines) - 1;
        $this->cursorCol = strlen($last);
        $this->changed();
    }

    /** Normalise line endings, expand tabs, and drop anything unprintable. */
    private static function cleanPaste(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $kept = '';

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if ($char === "\n" || Chars::isPrintable($char)) {
                $kept .= $char;
            }
        }

        return $kept;
    }

    private function insertMarker(string $text, int $lineCount): void
    {
        $this->pasteCounter++;
        $this->pastes[$this->pasteCounter] = $text;

        $marker = $lineCount > self::PASTE_MARKER_LINES
            ? "[paste #{$this->pasteCounter} +{$lineCount} lines]"
            : '[paste #' . $this->pasteCounter . ' ' . strlen($text) . ' chars]';

        $this->insert($marker);
    }

    // ---- completions ---------------------------------------------------------------

    private function completeOnTab(): void
    {
        if ($this->provider === null) {
            return;
        }

        $before = substr($this->lines[$this->cursorLine], 0, $this->cursorCol);
        $trimmed = ltrim($before);

        // Mid-command, Tab means the command list; anywhere else it means files.
        if (str_starts_with($trimmed, '/') && !str_contains($trimmed, ' ')) {
            $this->openSuggestions();

            return;
        }

        if (!$this->provider instanceof CombinedAutocompleteProvider) {
            $this->openSuggestions();

            return;
        }

        $this->show($this->provider->forcedFileSuggestions($this->lines, $this->cursorLine, $this->cursorCol));
    }

    private function openSuggestions(): void
    {
        $this->show($this->provider?->suggestions($this->lines, $this->cursorLine, $this->cursorCol));
    }

    private function refreshSuggestions(): void
    {
        if ($this->suggestionList !== null) {
            $this->openSuggestions();
        }
    }

    /**
     * After a deletion, keep the list current — or bring it back.
     *
     * Deleting a character can turn "no matches" into matches again, so a list that was
     * cancelled for being empty is reopened rather than staying gone until Tab.
     */
    private function refreshOrReopenSuggestions(): void
    {
        if ($this->suggestionList !== null) {
            $this->openSuggestions();

            return;
        }

        if ($this->inCompletableContext(substr($this->lines[$this->cursorLine], 0, $this->cursorCol))) {
            $this->openSuggestions();
        }
    }

    private function show(?Suggestions $suggestions): void
    {
        if ($suggestions === null) {
            $this->cancelSuggestions();

            return;
        }

        $this->suggestionPrefix = $suggestions->prefix;
        $this->suggestionItems = $suggestions->items;
        $this->suggestionList = new SelectList(
            array_map(
                static fn (AutocompleteItem $item): SelectItem
                    => new SelectItem($item->value, $item->label, $item->description),
                $suggestions->items,
            ),
            self::SUGGESTIONS_SHOWN,
            $this->theme->selectList,
        );
    }

    private function applySuggestion(): void
    {
        $item = $this->suggestionItems[$this->suggestionList?->selectedIndex() ?? -1] ?? null;

        if ($item === null || $this->provider === null) {
            $this->cancelSuggestions();

            return;
        }

        $result = $this->provider->apply(
            $this->lines,
            $this->cursorLine,
            $this->cursorCol,
            $item,
            $this->suggestionPrefix,
        );

        $lines = $result->lines;
        $this->lines = $lines === [] ? [''] : $lines;
        $this->cursorLine = min($result->cursorLine, count($this->lines) - 1);
        $this->cursorCol = min($result->cursorCol, strlen($this->lines[$this->cursorLine]));

        $this->cancelSuggestions();
        $this->changed();
    }

    private function cancelSuggestions(): void
    {
        $this->suggestionList = null;
        $this->suggestionItems = [];
        $this->suggestionPrefix = '';
    }

    // ---- odds and ends -------------------------------------------------------------

    private function isEmpty(): bool
    {
        return count($this->lines) === 1 && $this->lines[0] === '';
    }

    private function changed(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->text());
        }
    }

    private static function firstGraphemeLength(string $text): int
    {
        $graphemes = Graphemes::split($text);

        return $graphemes === [] ? 0 : strlen($graphemes[0]);
    }

    private static function lastGraphemeLength(string $text): int
    {
        $graphemes = Graphemes::split($text);

        return $graphemes === [] ? 0 : strlen($graphemes[count($graphemes) - 1]);
    }
}
