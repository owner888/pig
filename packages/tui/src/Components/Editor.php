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
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Keys;
use Pig\Tui\KillRing;
use Pig\Tui\UndoStack;
use Pig\Tui\MouseHandler;
use Pig\Tui\TUI;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;
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
final class Editor implements Component, Focusable, InputHandler, MouseHandler
{
    /** Set by the renderer; while true the cursor carries `TUI::CURSOR_MARKER` for the input method. */
    public bool $focused = false;

    /** Reverse video for the block cursor, and back to normal. */
    private const string CURSOR_ON = "\x1b[7m";
    private const string CURSOR_OFF = "\x1b[27m";

    private const string PASTE_START = "\x1b[200~";
    private const string PASTE_END = "\x1b[201~";

    /** A paste past either of these is held aside and shown as a marker. */
    private const int PASTE_MARKER_LINES = 10;
    private const int PASTE_MARKER_CHARS = 1000;

    private const int HISTORY_LIMIT = 100;

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

    /**
     * Columns left blank on either side of the text — upstream's `paddingX`, the
     * `editorPaddingX` setting. The rules above and below still span the whole width.
     */
    private int $paddingX = 0;

    /** Text rows and suggestion rows in the last frame, for mouse hit-testing — upstream's render geometry. */
    private int $renderedVisibleLineCount = 1;
    private int $renderedAutocompleteHeight = 0;

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

    /** @var non-empty-list<LayoutLine>|null */
    private ?array $cachedLayout = null;

    private ?int $cachedLayoutWidth = null;

    private ?string $cachedLayoutKey = null;

    /**
     * Enter does nothing while this is set, and the text stays where it is.
     *
     * Not "while the agent is working", which is what this said and what the application does
     * *not* use it for: a message typed mid-run is queued rather than refused, because the person
     * watching a tool run is exactly who has something to add. What it is for is the window where
     * a message cannot be queued either — a conversation being summarised out from under it.
     */
    public bool $disableSubmit = false;

    private EditorTheme $theme;

    /** Rows the completion list shows — upstream's `autocompleteMaxVisible`, 3 to 20. */
    private int $autocompleteMaxVisible = 5;

    /** @var ?Closure(int): string */
    private ?Closure $borderStatus = null;

    /** Upstream's `killRing`: what the kill keys deleted, for `tui.editor.yank`. */
    private KillRing $killRing;

    /**
     * Upstream's `undoStack`, for `tui.editor.undo`.
     *
     * @var UndoStack<array{lines: non-empty-list<string>, cursorLine: int, cursorCol: int, pastes: array<int, string>, pasteCounter: int}>
     */
    private UndoStack $undoStack;

    /**
     * Upstream's `lastAction`: what the previous key did, for the three things that care —
     * consecutive kills accumulate, `yankPop` only follows a yank, and a run of typed word
     * characters is one undo step.
     */
    private ?string $lastAction = null;

    /** Upstream's `jumpMode`: `tui.editor.jumpForward`/`jumpBackward` was pressed and the next character is the target. */
    private ?string $jumpMode = null;

    /** @var ?Closure(): int the terminal's rows, for `tui.editor.pageUp`/`pageDown` — upstream reads `tui.terminal.rows` */
    private ?Closure $terminalRows = null;

    public function __construct(?EditorTheme $theme = null)
    {
        // Not a default parameter: a theme is made of closures, and a default value has
        // to be a constant expression.
        $this->theme = $theme ?? EditorTheme::default();
        $this->killRing = new KillRing();
        $this->undoStack = new UndoStack();
    }

    /**
     * Where `tui.editor.pageUp`/`pageDown` learn how tall the terminal is. Upstream's editor holds
     * the TUI and reads `tui.terminal.rows`; this one is built without it, so whoever has the
     * terminal says. Without it a page is upstream's minimum, five rows.
     *
     * @param ?Closure(): int $rows
     */
    public function setTerminalRows(?Closure $rows): void
    {
        $this->terminalRows = $rows;
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

    /**
     * Something to show in the rule above the text.
     *
     * Upstream's `CustomEditor.renderTopBorder()` with `embedWorkingStatus`: the working, retry,
     * compaction and branch-summary spinners live **inside the top border** of the prompt
     * (`── ⠋ Working... ────`) rather than on a line of their own above it, so a turn starting
     * and ending does not grow and shrink the frame by two rows every time. The closure answers
     * the already-styled text for the room it is given, or `''` for a plain rule; it is asked on
     * every frame, which is what lets a spinner tick without the editor knowing one is there.
     *
     * @param ?Closure(int): string $status
     */
    public function setBorderStatus(?Closure $status): void
    {
        $this->borderStatus = $status;
        $this->invalidate();
    }

    /** The rule above the text, with the status worked into it when there is one. */
    private function topBorder(int $width, string $rule): string
    {
        if ($this->borderStatus === null || $width < 6) {
            return $rule;
        }

        // `── ` in front and ` ─` behind are five columns; the status gets the rest.
        $status = Width::truncate(($this->borderStatus)($width - 5), $width - 5, '');
        $statusWidth = Width::visible($status);

        if ($statusWidth === 0) {
            return $rule;
        }

        $border = $this->theme->border;

        return $border('── ') . $status . $border(' ' . str_repeat('─', $width - $statusWidth - 4));
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
        $this->lastAction = null;

        // Upstream: a programmatic change is undoable too, when it changes anything.
        if ($this->text() !== str_replace(["\r\n", "\r"], "\n", $text)) {
            $this->pushUndo();
        }

        $this->replaceText($text);
    }

    /** Put $text in at the cursor, as if it had been typed. */
    public function insertAtCursor(string $text): void
    {
        if ($text !== '') {
            $this->pushUndo();
            $this->lastAction = null;
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
        $this->cachedLayout = null;
        $this->cachedLayoutKey = null;
        $this->cachedLayoutWidth = null;
    }

    #[\Override]
    public function render(int $width): array
    {
        // Upstream: the padding is capped so at least one column of text is left, and the text —
        // and the suggestion rows under it — are laid out at the width between the pads. The
        // rules are not: they span the whole width, as upstream's do.
        $paddingX = $this->paddingX($width);
        $contentWidth = max(1, $width - $paddingX * 2);
        $pad = str_repeat(' ', $paddingX);

        $this->lastWidth = $contentWidth;
        // The style goes round the whole rule, not round each dash: styled per character, a
        // 140-column rule was 3,360 bytes and 280 escape sequences — 40% of a frame, on two
        // lines, on every frame. Upstream styles `"─".repeat(width)` once.
        $rule = ($this->theme->border)(str_repeat('─', $width));
        $lines = [$this->topBorder($width, $rule)];

        $layoutLines = $this->layout($contentWidth);
        $this->renderedVisibleLineCount = count($layoutLines);

        foreach ($layoutLines as $layoutLine) {
            $lines[] = $pad . $this->draw($layoutLine, $contentWidth) . $pad;
        }

        $lines[] = $rule;

        $suggestions = $this->suggestionList?->render($contentWidth) ?? [];
        $this->renderedAutocompleteHeight = count($suggestions);

        foreach ($suggestions as $suggestion) {
            $lines[] = $pad . $suggestion . str_repeat(' ', max(0, $contentWidth - Width::visible($suggestion))) . $pad;
        }

        return $lines;
    }

    /** Upstream's `setPaddingX()`: whole columns, never negative. */
    public function setPaddingX(int $padding): void
    {
        $this->paddingX = max(0, $padding);
    }

    public function autocompleteMaxVisible(): int
    {
        return $this->autocompleteMaxVisible;
    }

    /** Upstream's `setAutocompleteMaxVisible()`, clamped to 3–20 as upstream clamps it. */
    public function setAutocompleteMaxVisible(int $maxVisible): void
    {
        $this->autocompleteMaxVisible = max(3, min(20, $maxVisible));
    }

    public function paddingX(?int $width = null): int
    {
        if ($width === null) {
            return $this->paddingX;
        }

        // Upstream's `maxPadding`: at least one column stays for the text.
        return min($this->paddingX, max(0, intdiv($width - 1, 2)));
    }

    /**
     * Suggestion rows go to the list; a click on a text row puts the cursor under it — upstream's
     * `handleMouse()`.
     *
     * Upstream's scroll offset is zero here, because pig's editor has none: every row is drawn.
     * The padding is taken off `x`, as upstream takes it, so a click lands on the column it is over.
     */
    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        $autocompleteStartRow = $this->renderedVisibleLineCount + 2;
        $paddingX = $this->paddingX($event->width);
        if (
            $this->suggestionList !== null
            && $event->y >= $autocompleteStartRow
            && $event->y < $autocompleteStartRow + $this->renderedAutocompleteHeight
        ) {
            $contentWidth = max(1, $event->width - $paddingX * 2);
            $result = $this->suggestionList->handleMouse($event->at($event->x - $paddingX, $event->y - $autocompleteStartRow, $contentWidth, $this->renderedAutocompleteHeight));

            return $result?->withFocus();
        }

        // Leave press/drag/release unhandled so the renderer's screen-level text
        // selection can run over the editor rows (drag to select, release to copy).
        // The renderer synthesizes a click when press and release land on the same
        // cell without movement, which is the gesture that positions the cursor.
        if ($event->type !== 'click' || $event->button !== 'left') {
            return null;
        }
        if ($event->y <= 0 || $event->y > $this->renderedVisibleLineCount) {
            return new TuiMouseEventResult(handled: true, focus: true);
        }

        $visualLines = $this->visualLines($this->lastWidth);
        $visualLineIndex = $event->y - 1;
        $visualLine = $visualLines[$visualLineIndex] ?? null;
        if ($visualLine === null) {
            return new TuiMouseEventResult(handled: true, focus: true);
        }
        $logicalLine = $this->lines[$visualLine->logicalLine] ?? '';
        $chunk = substr($logicalLine, $visualLine->startCol, $visualLine->length);
        $targetColumn = max(0, $event->x - $paddingX);
        $visibleColumn = 0;
        $targetIndex = strlen($chunk);
        $lastGraphemeIndex = 0;
        $offset = 0;
        foreach (Graphemes::split($chunk) as $grapheme) {
            $nextColumn = $visibleColumn + Width::visible($grapheme);
            $lastGraphemeIndex = $offset;
            if ($targetColumn < $nextColumn) {
                $targetIndex = $offset;
                break;
            }
            $visibleColumn = $nextColumn;
            $offset += strlen($grapheme);
        }
        $isLastSegment = $visualLineIndex === count($visualLines) - 1
            || ($visualLines[$visualLineIndex + 1] ?? null)?->logicalLine !== $visualLine->logicalLine;
        if (!$isLastSegment && $targetIndex === strlen($chunk) && $chunk !== '') {
            $targetIndex = $lastGraphemeIndex;
        }

        $this->cursorLine = $visualLine->logicalLine;
        $this->cursorCol = $visualLine->startCol + $targetIndex;
        $this->historyIndex = -1;
        if ($this->suggestionList !== null) {
            $this->refreshSuggestions();
        }

        return new TuiMouseEventResult(handled: true, focus: true);
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
        // Zero-width, just before the drawn cursor: where the hardware cursor goes, so an input
        // method composes here even while a suggestion list is open under the editor.
        $marker = $this->focused ? TUI::CURSOR_MARKER : '';

        if ($after !== '') {
            // On a character: invert it, which costs no columns.
            $grapheme = Graphemes::split($after)[0];
            $text = $before . $marker . self::CURSOR_ON . $grapheme . self::CURSOR_OFF . substr($after, strlen($grapheme));
        } elseif ($visible < $width) {
            $text = $before . $marker . self::CURSOR_ON . ' ' . self::CURSOR_OFF;
            $visible++;
        } else {
            // The row is already full, so there is no cell to add. Invert the last
            // character instead of drawing a cursor that would wrap the line.
            $graphemes = Graphemes::split($before);

            if ($graphemes !== []) {
                $last = array_pop($graphemes);
                $text = implode('', $graphemes) . $marker . self::CURSOR_ON . $last . self::CURSOR_OFF;
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

        $key = "{$this->cursorLine}:{$this->cursorCol}:" . md5(implode("\n", $this->lines));
        if ($this->cachedLayout !== null && $this->cachedLayoutWidth === $width && $this->cachedLayoutKey === $key) {
            return $this->cachedLayout;
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

        $result = $rows === [] ? [new LayoutLine('', 0)] : $rows;

        $this->cachedLayout = $result;
        $this->cachedLayoutWidth = $width;
        $this->cachedLayoutKey = $key;

        return $result;
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

    /**
     * Upstream's `Editor.handleInput()`: every key is looked up through the global keybinding
     * registry (`Keybindings::getKeybindings()`), in upstream's order, so `keybindings.json` moves
     * any of them — undo, the kill ring, the jumps and the history keys included.
     */
    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();

        // Character jump mode: the key after jumpForward/jumpBackward is the character to go to.
        if ($this->jumpMode !== null) {
            if ($kb->matches($data, 'tui.editor.jumpForward') || $kb->matches($data, 'tui.editor.jumpBackward')) {
                $this->jumpMode = null;

                return;
            }

            if (Chars::isPrintable($data)) {
                $direction = $this->jumpMode;
                $this->jumpMode = null;
                $this->jumpToChar($data, $direction);

                return;
            }

            // A control key cancels the jump and is handled as itself.
            $this->jumpMode = null;
        }

        if ($this->pasting || str_contains($data, self::PASTE_START)) {
            $this->bufferPaste($data);

            return;
        }

        // Ctrl+C belongs to whatever is hosting the editor: at an empty prompt it means
        // quit, and only the application knows whether it is empty enough to quit from.
        if ($kb->matches($data, 'tui.input.copy')) {
            return;
        }

        if ($kb->matches($data, 'tui.editor.undo')) {
            $this->undo();

            return;
        }

        if ($this->suggestionList !== null && $this->suggestionKey($data)) {
            return;
        }

        if ($kb->matches($data, 'tui.input.tab')) {
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
        $kb = Keybindings::getKeybindings();

        if ($kb->matches($data, 'tui.select.cancel')) {
            $this->cancelSuggestions();

            return true;
        }

        if ($kb->matches($data, 'tui.select.up') || $kb->matches($data, 'tui.select.down')) {
            $this->suggestionList?->handleInput($data);

            return true;
        }

        if ($kb->matches($data, 'tui.input.tab')) {
            $this->applySuggestion();

            return true;
        }

        if (!$kb->matches($data, 'tui.select.confirm')) {
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

    /**
     * The rest of upstream's `handleInput()`, after the completion list: deletion, the kill ring,
     * the dedicated history keys, movement, new line, submit, the arrows, paging and the jumps.
     * Each arm names the action it answers to; the order is upstream's, which matters where two
     * actions share a key.
     */
    private function editingKey(string $data): void
    {
        $kb = Keybindings::getKeybindings();

        /** @var list<array{0: string|Closure(string): bool, 1: Closure(): void}> $actions */
        $actions = [
            ['tui.editor.deleteToLineEnd', $this->deleteToLineEnd(...)],
            ['tui.editor.deleteToLineStart', $this->deleteToLineStart(...)],
            ['tui.editor.deleteWordBackward', $this->deleteWordBackwards(...)],
            ['tui.editor.deleteWordForward', $this->deleteWordForward(...)],
            [static fn (string $d): bool => $kb->matches($d, 'tui.editor.deleteCharBackward') || Keys::matchesName($d, 'shift+backspace'), $this->backspace(...)],
            [static fn (string $d): bool => $kb->matches($d, 'tui.editor.deleteCharForward') || Keys::matchesName($d, 'shift+delete'), $this->forwardDelete(...)],
            ['tui.editor.yank', $this->yank(...)],
            ['tui.editor.yankPop', $this->yankPop(...)],
            ['tui.editor.historyPrevious', function (): void {
                $this->cancelSuggestions();
                $this->recall(-1);
            }],
            ['tui.editor.historyNext', function (): void {
                $this->cancelSuggestions();
                $this->recall(1);
            }],
            ['tui.editor.cursorLineStart', function (): void {
                $this->cursorCol = 0;
            }],
            ['tui.editor.cursorLineEnd', function (): void {
                $this->cursorCol = strlen($this->lines[$this->cursorLine]);
            }],
            ['tui.editor.cursorWordLeft', $this->moveWordBackwards(...)],
            ['tui.editor.cursorWordRight', $this->moveWordForwards(...)],
            [static fn (string $d): bool => $kb->matches($d, 'tui.input.newLine') || self::isNewLine($d), $this->breakLine(...)],
            ['tui.input.submit', $this->submit(...)],
            ['tui.editor.cursorUp', $this->up(...)],
            ['tui.editor.cursorDown', $this->down(...)],
            ['tui.editor.cursorRight', fn () => $this->moveHorizontally(1)],
            ['tui.editor.cursorLeft', fn () => $this->moveHorizontally(-1)],
            ['tui.editor.pageUp', fn () => $this->pageScroll(-1)],
            ['tui.editor.pageDown', fn () => $this->pageScroll(1)],
            ['tui.editor.jumpForward', function (): void {
                $this->jumpMode = 'forward';
            }],
            ['tui.editor.jumpBackward', function (): void {
                $this->jumpMode = 'backward';
            }],
        ];

        foreach ($actions as [$test, $action]) {
            if (is_string($test) ? $kb->matches($data, $test) : $test($data)) {
                $before = $this->lastAction;
                $this->lastAction = null;
                // The kill and yank actions read what came before them and set their own.
                if (in_array($test, ['tui.editor.deleteToLineEnd', 'tui.editor.deleteToLineStart', 'tui.editor.deleteWordBackward', 'tui.editor.deleteWordForward', 'tui.editor.yank', 'tui.editor.yankPop'], true)) {
                    $this->lastAction = $before;
                }
                $action();

                return;
            }
        }

        // Upstream's Shift+Space: a plain space.
        if (Keys::matchesName($data, 'shift+space')) {
            $this->type(' ');

            return;
        }

        if (Chars::isPrintable($data)) {
            $this->type($data);
        }
    }

    /**
     * `app.clipboard.pasteImage` (Ctrl+V): a picture if there is one on the clipboard, otherwise
     * the text. `CustomEditor` calls this for the key, the way upstream's calls `onPasteImage`; the
     * editor itself does not claim an `app.*` key.
     *
     * A picture cannot go into a line of text, so it is written to a file and the file's
     * path is what gets typed — which the agent's tools can then read, and which reads
     * as a sentence in the prompt.
     *
     * Note that most terminals never send this: Cmd+V on macOS and Ctrl+Shift+V on Linux
     * are handled by the terminal itself and arrive as a bracketed paste, which is a
     * different path entirely and cannot carry an image. This is the one that can.
     */
    public function pasteFromClipboard(): void
    {
        if ($this->clipboard === null) {
            return;
        }

        // Upstream's `handleClipboardPaste()`: copied files first, by their own paths — Finder's, or
        // Telegram's photo — one a line, or quoted and on one line in a `!` command; with a space
        // either side where the path would otherwise run into a word.
        $paths = $this->clipboard->filePaths();

        if ($paths !== null && !array_filter($paths, static fn (string $path): bool => preg_match('/\p{Cc}/u', $path) === 1)) {
            $line = $this->lines[$this->cursorLine];
            $bash = str_starts_with(ltrim($this->text()), '!');
            $joined = $bash ? implode(' ', array_map(self::quoteIfNeeded(...), $paths)) : implode("\n", $paths);
            $before = $this->cursorCol > 0 ? mb_substr(substr($line, 0, $this->cursorCol), -1) : '';
            $after = mb_substr(substr($line, $this->cursorCol), 0, 1);
            $leading = $before !== '' && !preg_match('/\s/u', $before) ? ' ' : '';
            $trailing = $after !== '' && !preg_match('/\s/u', $after) ? ' ' : '';
            $this->insertAtCursor($leading . $joined . $trailing);

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

    /** Upstream's `quoteIfNeeded()`: anything but `[a-zA-Z0-9_\-./~:@]`, or nothing, goes in single quotes. */
    private static function quoteIfNeeded(string $value): string
    {
        return $value !== '' && preg_match('#[^a-zA-Z0-9_\-./~:@]#', $value) !== 1
            ? $value
            : "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * Every spelling of "Enter, but I meant a new line".
     *
     * The Kitty protocol gives the first two properly; the rest are what particular
     * terminals send instead, and each one is somebody's Shift+Enter.
     */
    private static function isNewLine(string $data): bool
    {
        return Keys::isAltEnter($data)
            || $data === "\n"
            || $data === "\x1b\r"
            || $data === "\x1b[13;2~"
            || $data === "\\\r"
            || (strlen($data) > 1 && $data[0] === "\n")
            || (strlen($data) > 1 && str_contains($data, "\x1b") && str_contains($data, "\r"));
    }

    // ---- editing -------------------------------------------------------------------

    /**
     * A typed character — upstream's `insertCharacter()` with its fish-style undo coalescing: a
     * run of word characters is one undo step, and each space is one of its own.
     */
    private function type(string $text): void
    {
        // A chunk of several characters (a fast typist, a terminal that batches) is judged by
        // its first, as the key that started it.
        if (Chars::isWhitespace(mb_substr($text, 0, 1)) || $this->lastAction !== 'type-word') {
            $this->pushUndo();
        }

        $this->lastAction = 'type-word';
        $this->insert($text);
    }

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
        $this->pushUndo();
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

        if ($this->cursorCol > 0 || $this->cursorLine > 0) {
            $this->pushUndo();
        }

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

        if ($this->cursorCol < strlen($line) || $this->cursorLine < count($this->lines) - 1) {
            $this->pushUndo();
        }

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
        $accumulate = $this->lastAction === 'kill';

        if ($this->cursorCol > 0) {
            $this->pushUndo();
            $this->killRing->push(substr($this->lines[$this->cursorLine], 0, $this->cursorCol), prepend: true, accumulate: $accumulate);
            $this->lastAction = 'kill';
            $this->lines[$this->cursorLine] = substr($this->lines[$this->cursorLine], $this->cursorCol);
            $this->cursorCol = 0;
        } elseif ($this->cursorLine > 0) {
            // At the start of a line the newline is what is deleted, and it is killed too.
            $this->pushUndo();
            $this->killRing->push("\n", prepend: true, accumulate: $accumulate);
            $this->lastAction = 'kill';
            $this->joinWithPrevious();
        }

        $this->changed();
    }

    private function deleteToLineEnd(): void
    {
        $this->historyIndex = -1;
        $accumulate = $this->lastAction === 'kill';

        if ($this->cursorCol < strlen($this->lines[$this->cursorLine])) {
            $this->pushUndo();
            $this->killRing->push(substr($this->lines[$this->cursorLine], $this->cursorCol), prepend: false, accumulate: $accumulate);
            $this->lastAction = 'kill';
            $this->lines[$this->cursorLine] = substr($this->lines[$this->cursorLine], 0, $this->cursorCol);
        } elseif ($this->cursorLine < count($this->lines) - 1) {
            $this->pushUndo();
            $this->killRing->push("\n", prepend: false, accumulate: $accumulate);
            $this->lastAction = 'kill';
            $this->joinWithNext();
        }

        $this->changed();
    }

    private function deleteWordBackwards(): void
    {
        $this->historyIndex = -1;
        $accumulate = $this->lastAction === 'kill';

        if ($this->cursorCol === 0) {
            if ($this->cursorLine > 0) {
                $this->pushUndo();
                $this->killRing->push("\n", prepend: true, accumulate: $accumulate);
                $this->lastAction = 'kill';
                $this->joinWithPrevious();
            }

            $this->changed();

            return;
        }

        $this->pushUndo();
        $line = $this->lines[$this->cursorLine];
        $from = Chars::wordStart($line, $this->cursorCol);

        $this->killRing->push(substr($line, $from, $this->cursorCol - $from), prepend: true, accumulate: $accumulate);
        $this->lastAction = 'kill';
        $this->lines[$this->cursorLine] = substr($line, 0, $from) . substr($line, $this->cursorCol);
        $this->cursorCol = $from;
        $this->changed();
    }

    /** Upstream's `deleteWordForward()` (`tui.editor.deleteWordForward`, alt+d): the word ahead, into the kill ring. */
    private function deleteWordForward(): void
    {
        $this->historyIndex = -1;
        $accumulate = $this->lastAction === 'kill';
        $line = $this->lines[$this->cursorLine];

        if ($this->cursorCol >= strlen($line)) {
            if ($this->cursorLine < count($this->lines) - 1) {
                $this->pushUndo();
                $this->killRing->push("\n", prepend: false, accumulate: $accumulate);
                $this->lastAction = 'kill';
                $this->joinWithNext();
            }

            $this->changed();

            return;
        }

        $this->pushUndo();
        $to = Chars::wordEnd($line, $this->cursorCol);

        $this->killRing->push(substr($line, $this->cursorCol, $to - $this->cursorCol), prepend: false, accumulate: $accumulate);
        $this->lastAction = 'kill';
        $this->lines[$this->cursorLine] = substr($line, 0, $this->cursorCol) . substr($line, $to);
        $this->changed();
    }

    // ---- kill ring and undo --------------------------------------------------------

    /** Upstream's `yank()` (`tui.editor.yank`): the newest kill, put back at the cursor. */
    private function yank(): void
    {
        $text = $this->killRing->peek();

        if ($text === null) {
            return;
        }

        $this->pushUndo();
        $this->insertYankedText($text);
        $this->lastAction = 'yank';
    }

    /**
     * Upstream's `yankPop()` (`tui.editor.yankPop`): straight after a yank, swap what was yanked
     * for the kill before it.
     */
    private function yankPop(): void
    {
        if ($this->lastAction !== 'yank' || $this->killRing->length() <= 1) {
            return;
        }

        $this->pushUndo();
        $this->deleteYankedText();
        $this->killRing->rotate();
        $this->insertYankedText((string) $this->killRing->peek());
        $this->lastAction = 'yank';
    }

    private function insertYankedText(string $text): void
    {
        $this->historyIndex = -1;
        $pieces = explode("\n", $text);
        $line = $this->lines[$this->cursorLine];
        $before = substr($line, 0, $this->cursorCol);
        $after = substr($line, $this->cursorCol);
        $last = $pieces[count($pieces) - 1];

        if (count($pieces) === 1) {
            $this->lines[$this->cursorLine] = $before . $text . $after;
            $this->cursorCol += strlen($text);
        } else {
            $replacement = [$before . $pieces[0], ...array_slice($pieces, 1, -1), $last . $after];
            array_splice($this->lines, $this->cursorLine, 1, $replacement);
            $this->cursorLine += count($pieces) - 1;
            $this->cursorCol = strlen($last);
        }

        $this->changed();
    }

    /** The text the last yank put in, which ends at the cursor — upstream's `deleteYankedText()`. */
    private function deleteYankedText(): void
    {
        $yanked = $this->killRing->peek();

        if ($yanked === null) {
            return;
        }

        $pieces = explode("\n", $yanked);

        if (count($pieces) === 1) {
            $line = $this->lines[$this->cursorLine];
            $from = max(0, $this->cursorCol - strlen($yanked));
            $this->lines[$this->cursorLine] = substr($line, 0, $from) . substr($line, $this->cursorCol);
            $this->cursorCol = $from;
        } else {
            $startLine = $this->cursorLine - (count($pieces) - 1);
            $startCol = strlen($this->lines[$startLine]) - strlen($pieces[0]);
            $afterCursor = substr($this->lines[$this->cursorLine], $this->cursorCol);
            $beforeYank = substr($this->lines[$startLine], 0, $startCol);
            array_splice($this->lines, $startLine, count($pieces), [$beforeYank . $afterCursor]);
            $this->cursorLine = $startLine;
            $this->cursorCol = $startCol;
        }

        $this->changed();
    }

    /** Upstream's `pushUndoSnapshot()`: the text, the cursor, and the pastes the markers stand for. */
    private function pushUndo(): void
    {
        $this->undoStack->push([
            'lines' => $this->lines,
            'cursorLine' => $this->cursorLine,
            'cursorCol' => $this->cursorCol,
            'pastes' => $this->pastes,
            'pasteCounter' => $this->pasteCounter,
        ]);
    }

    /** Upstream's `undo()` (`tui.editor.undo`). */
    private function undo(): void
    {
        $this->historyIndex = -1;
        $snapshot = $this->undoStack->pop();

        if ($snapshot === null) {
            return;
        }

        $this->lines = $snapshot['lines'];
        $this->cursorLine = $snapshot['cursorLine'];
        $this->cursorCol = $snapshot['cursorCol'];
        $this->pastes = $snapshot['pastes'];
        $this->pasteCounter = $snapshot['pasteCounter'];
        $this->lastAction = null;
        $this->changed();
    }

    /**
     * Upstream's `jumpToChar()`: the next (or previous) occurrence of $char, across lines,
     * case-sensitive, skipping the one under the cursor. No match leaves the cursor where it is.
     */
    private function jumpToChar(string $char, string $direction): void
    {
        $forward = $direction === 'forward';
        $count = count($this->lines);

        for ($index = $this->cursorLine; $forward ? $index < $count : $index >= 0; $index += $forward ? 1 : -1) {
            $line = $this->lines[$index];
            $current = $index === $this->cursorLine;

            if ($forward) {
                $from = $current ? $this->cursorCol + 1 : 0;
                $at = $from > strlen($line) ? false : strpos($line, $char, $from);
            } else {
                $haystack = $current ? substr($line, 0, max(0, $this->cursorCol)) : $line;
                // `cursorCol - 1` upstream is the last place a match may *start*.
                $at = $haystack === '' ? false : strrpos($haystack, $char);
                if ($current && $at !== false && $at > $this->cursorCol - 1) {
                    $at = false;
                }
            }

            if ($at !== false) {
                $this->cursorLine = $index;
                $this->cursorCol = $at;

                return;
            }
        }
    }

    /**
     * Upstream's `pageScroll()` (`tui.editor.pageUp`/`pageDown`): the cursor moves by a page of
     * drawn rows — 30% of the terminal, at least five.
     */
    private function pageScroll(int $direction): void
    {
        $rows = $this->terminalRows === null ? 0 : ($this->terminalRows)();
        $pageSize = max(5, intdiv($rows * 3, 10));
        $visual = $this->visualLines($this->lastWidth);
        $current = $this->currentRow($visual);
        $target = max(0, min(count($visual) - 1, $current + $direction * $pageSize));

        if ($target !== $current) {
            $this->moveVertically($visual, $target - $current);
        }
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
     * Up or down one drawn row, keeping the offset into the row.
     *
     * **Counted in characters, not bytes**, which is the whole substance of this method.
     * Upstream carries `cursorCol - startCol` straight across and clamps it; its offsets are
     * UTF-16 code units, so on everything either project edits that is a character and lands
     * on one. Translating the arithmetic literally made it a byte offset, and a byte offset
     * carried onto a line with a multi-byte character in it lands *inside* the character: 你
     * is three bytes, so Up from `ab` onto 你 put the cursor between its first and second
     * byte. From there `Graphemes::split()` is handed half a character and throws — out of
     * `render()`, inside the loop's input callback, which is the session — and anything typed
     * first split 你 into two invalid bytes with a letter wedged between them. Four keystrokes
     * in a Chinese prompt reached it.
     *
     * Counting graphemes rather than codepoints costs nothing and is the same answer one step
     * safer: the cursor lands between clusters, so it never separates a combining mark or one
     * person of a family emoji from the rest.
     *
     * @param list<VisualLine> $rows
     */
    private function moveVertically(array $rows, int $delta): void
    {
        $current = $this->currentRow($rows);
        $target = $rows[$current + $delta] ?? null;

        if ($target === null) {
            return;
        }

        $start = ($rows[$current] ?? null)?->startCol ?? 0;
        $offset = count(Graphemes::split(substr($this->lines[$this->cursorLine], $start, $this->cursorCol - $start)));
        $row = substr($this->lines[$target->logicalLine], $target->startCol, $target->length);

        $this->cursorLine = $target->logicalLine;
        $this->cursorCol = $target->startCol + self::lengthOfFirst($row, $offset);
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
        $this->undoStack->clear();
        $this->lastAction = null;

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
        $this->lastAction = null;
        $text = self::cleanPaste($text);

        if ($text === '') {
            return;
        }

        $this->pushUndo();

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
            // does not pop a completion list the user did not ask for — and so the change
            // handler is called once for the paste rather than once per character, which is
            // the only difference a differential run against `editor.ts` found in the whole
            // editing model: upstream pastes through `insertCharacter` and fires `onChange`
            // twenty times for `[paste #1 +12 lines]`, once per letter of its own marker.
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

        if (!$this->provider instanceof CombinedAutocompleteProvider) {
            $this->openSuggestions();

            return;
        }

        // **The provider decides**, because it is the thing that knows what completes here: which
        // commands exist, and which of them answer for their own argument. This used to spell the
        // rule out again — "starts with a slash and has no space" — which made
        // `shouldCompleteFiles()` a public method with no caller but its own tests, and left Tab
        // after `/model ` going to the filesystem while typing there offered models. The trap in
        // docs/dev/traps/tui.md about a line starting with `/` is this same predicate; that entry gave it one
        // home and this is the reader that never started using it.
        if (!$this->provider->shouldCompleteFiles($this->lines, $this->cursorLine, $this->cursorCol)) {
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
            $this->autocompleteMaxVisible,
            $this->theme->selectList,
            // Upstream's `SLASH_COMMAND_SELECT_LIST_LAYOUT`, for a command list only.
            str_starts_with($suggestions->prefix, '/') ? SelectListLayout::compact() : null,
        );
    }

    private function applySuggestion(): void
    {
        $item = $this->suggestionItems[$this->suggestionList?->selectedIndex() ?? -1] ?? null;

        if ($item === null || $this->provider === null) {
            $this->cancelSuggestions();

            return;
        }

        $this->pushUndo();
        $this->lastAction = null;
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

    /** Bytes in the first $count graphemes of $text, or all of it when there are fewer. */
    private static function lengthOfFirst(string $text, int $count): int
    {
        $length = 0;

        foreach (Graphemes::split($text) as $index => $grapheme) {
            if ($index >= $count) {
                break;
            }

            $length += strlen($grapheme);
        }

        return $length;
    }
}
