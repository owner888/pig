<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The regular-mode renderer — upstream's `TuiMainScreen` in `tui-main-screen.ts`.
 *
 * Renders into the terminal's main screen and its scrollback, so what the agent said stays
 * where the scroll wheel reaches it after exit. Each frame is compared with the last and only
 * the rows from the first change to the last change are rewritten; a change above the visible
 * window, a width change or a height change is a full redraw with the scrollback cleared.
 * The hardware cursor goes wherever the focused component put `TUI::CURSOR_MARKER`.
 *
 * Upstream's `BoundedTerminalWriter` (1 MiB chunks, for V8's string limit) and its debug-log
 * environment variables are not ported: PHP strings have no such limit.
 */
class TuiMainScreen extends TuiBase
{
    public const string MODE = 'regular';

    private const string KITTY_SEQUENCE_PREFIX = "\x1b_G";

    /** @var list<string> */
    private array $previousLines = [];

    /** @var array<int, true> */
    private array $previousKittyImageIds = [];

    /** Where the end of the content is, counted from the first line this drew. */
    private int $cursorRow = 0;

    /** Where the terminal's cursor actually is, which `positionHardwareCursor()` moves. */
    private int $hardwareCursorRow = 0;

    /** The terminal's working area: grows with the content, shrinks only on a cleared redraw. */
    private int $maxLinesRendered = 0;

    private int $previousViewportTop = 0;

    /**
     * Upstream's `captureRenderState()`, for handing the screen to another renderer and back.
     *
     * @return array{previousLines: list<string>, previousWidth: int, previousHeight: int, cursorRow: int, hardwareCursorRow: int, maxLinesRendered: int, previousViewportTop: int}
     */
    public function captureRenderState(): array
    {
        return [
            'previousLines' => $this->previousLines,
            'previousWidth' => $this->previousWidth,
            'previousHeight' => $this->previousHeight,
            'cursorRow' => $this->cursorRow,
            'hardwareCursorRow' => $this->hardwareCursorRow,
            'maxLinesRendered' => $this->maxLinesRendered,
            'previousViewportTop' => $this->previousViewportTop,
        ];
    }

    /** @param array{previousLines: list<string>, previousWidth: int, previousHeight: int, cursorRow: int, hardwareCursorRow: int, maxLinesRendered: int, previousViewportTop: int} $state */
    public function restoreRenderState(array $state): void
    {
        $this->previousLines = array_map(static fn (string $line): string => self::isImageLine($line) ? '' : $line, $state['previousLines']);
        $this->previousKittyImageIds = [];
        $this->previousWidth = $state['previousWidth'];
        $this->previousHeight = $state['previousHeight'];
        $this->cursorRow = $state['cursorRow'];
        $this->hardwareCursorRow = $state['hardwareCursorRow'];
        $this->maxLinesRendered = $state['maxLinesRendered'];
        $this->previousViewportTop = $state['previousViewportTop'];
    }

    #[\Override]
    protected function resetRenderState(): void
    {
        $this->previousLines = [];
        $this->previousWidth = -1;
        $this->previousHeight = -1;
        $this->cursorRow = 0;
        $this->hardwareCursorRow = 0;
        $this->maxLinesRendered = 0;
        $this->previousViewportTop = 0;
    }

    /** Leave the cursor on a fresh line under the content, so the shell prompt does not overwrite it. */
    #[\Override]
    protected function beforeTerminalStop(TuiStopOptions $options): void
    {
        if ($options->preserveScreen || $this->previousLines === []) {
            return;
        }

        $this->terminal->write(' ');
        $lineDiff = count($this->previousLines) - $this->hardwareCursorRow;
        if ($lineDiff > 0) {
            $this->terminal->write("\x1b[{$lineDiff}B");
        } elseif ($lineDiff < 0) {
            $this->terminal->write("\x1b[" . -$lineDiff . 'A');
        }
        $this->terminal->write("\r\n");
    }

    /** @return array{ids: list<int>, rows: int}|null */
    private static function parseKittyImageHeader(string $line): ?array
    {
        $sequenceStart = strpos($line, self::KITTY_SEQUENCE_PREFIX);
        if ($sequenceStart === false) {
            return null;
        }

        $paramsStart = $sequenceStart + strlen(self::KITTY_SEQUENCE_PREFIX);
        $paramsEnd = strpos($line, ';', $paramsStart);
        if ($paramsEnd === false) {
            return null;
        }

        $ids = [];
        $rows = 1;
        foreach (explode(',', substr($line, $paramsStart, $paramsEnd - $paramsStart)) as $param) {
            $parts = explode('=', $param, 2);
            if (count($parts) < 2 || preg_match('/^\d+$/', $parts[1]) !== 1) {
                continue;
            }
            $value = (int) $parts[1];
            if ($value <= 0 || $value > 0xffffffff) {
                continue;
            }
            if ($parts[0] === 'i') {
                $ids[] = $value;
            } elseif ($parts[0] === 'r') {
                $rows = $value;
            }
        }

        return ['ids' => $ids, 'rows' => $rows];
    }

    /** @return list<int> */
    private static function extractKittyImageIds(string $line): array
    {
        return self::parseKittyImageHeader($line)['ids'] ?? [];
    }

    private static function extractKittyImageRows(string $line): int
    {
        return self::parseKittyImageHeader($line)['rows'] ?? 1;
    }

    private static function isTermuxSession(): bool
    {
        return (string) getenv('TERMUX_VERSION') !== '';
    }

    private static function deleteKittyImage(int $imageId): string
    {
        return "\x1b_Ga=d,d=I,i={$imageId},q=2\x1b\\";
    }

    /**
     * @param list<string> $lines
     * @return array<int, true>
     */
    private static function collectKittyImageIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach (self::extractKittyImageIds($line) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** @param iterable<int> $ids */
    private static function deleteKittyImages(iterable $ids): string
    {
        $buffer = '';
        foreach ($ids as $id) {
            $buffer .= self::deleteKittyImage($id);
        }

        return $buffer;
    }

    /** @param list<string> $lines */
    private static function getKittyImageReservedRows(array $lines, int $index, ?int $maxIndex = null): int
    {
        $maxIndex ??= count($lines) - 1;
        $rows = self::extractKittyImageRows($lines[$index] ?? '');
        if ($rows <= 1) {
            return 1;
        }

        $maxRows = min($rows, $maxIndex - $index + 1, count($lines) - $index);
        $reservedRows = 1;
        while ($reservedRows < $maxRows) {
            $line = $lines[$index + $reservedRows] ?? '';
            if (self::isImageLine($line) || Width::visible($line) > 0) {
                break;
            }
            $reservedRows++;
        }

        return $reservedRows;
    }

    /**
     * @param list<string> $newLines
     * @return array{0: int, 1: int}
     */
    private function expandChangedRangeForKittyImages(int $firstChanged, int $lastChanged, array $newLines): array
    {
        $expandedFirst = $firstChanged;
        $expandedLast = $lastChanged;
        foreach ([$this->previousLines, $newLines] as $lines) {
            foreach ($lines as $index => $line) {
                if (self::extractKittyImageIds($line) === []) {
                    continue;
                }
                $blockEnd = $index + self::getKittyImageReservedRows($lines, $index) - 1;
                if ($index >= $firstChanged || ($index <= $lastChanged && $blockEnd >= $firstChanged)) {
                    $expandedFirst = min($expandedFirst, $index);
                    $expandedLast = max($expandedLast, $blockEnd);
                }
            }
        }

        return [$expandedFirst, $expandedLast];
    }

    private function deleteChangedKittyImages(int $firstChanged, int $lastChanged): string
    {
        if ($firstChanged < 0 || $lastChanged < $firstChanged) {
            return '';
        }

        $ids = [];
        $maxLine = min($lastChanged, count($this->previousLines) - 1);
        for ($index = $firstChanged; $index <= $maxLine; $index++) {
            foreach (self::extractKittyImageIds($this->previousLines[$index] ?? '') as $id) {
                $ids[$id] = true;
            }
        }

        return self::deleteKittyImages(array_keys($ids));
    }

    #[\Override]
    protected function doRender(): void
    {
        if ($this->stopped) {
            return;
        }

        $width = $this->terminal->columns();
        $height = $this->terminal->rows();
        $widthChanged = $this->previousWidth !== 0 && $this->previousWidth !== $width;
        $heightChanged = $this->previousHeight !== 0 && $this->previousHeight !== $height;
        $previousBufferLength = $this->previousHeight > 0 ? $this->previousViewportTop + $this->previousHeight : $height;
        $prevViewportTop = $heightChanged ? max(0, $previousBufferLength - $height) : $this->previousViewportTop;
        $viewportTop = $prevViewportTop;
        $hardwareCursorRow = $this->hardwareCursorRow;
        $computeLineDiff = static function (int $targetRow) use (&$hardwareCursorRow, &$prevViewportTop, &$viewportTop): int {
            return ($targetRow - $viewportTop) - ($hardwareCursorRow - $prevViewportTop);
        };

        $newLines = $this->render($width);

        // Found before the line resets, which would otherwise be appended after the marker.
        $cursorPos = $this->extractCursorPosition($newLines, $height);
        $newLines = $this->applyLineResets($newLines);

        $fullRender = function (bool $clear) use (&$newLines, $cursorPos, $width, $height): void {
            $this->fullRedrawCount++;
            $output = "\x1b[?2026h";
            if ($clear) {
                $output .= self::deleteKittyImages(array_keys($this->previousKittyImageIds));
                $output .= "\x1b[2J\x1b[H\x1b[3J";
            }
            $count = count($newLines);
            for ($index = 0; $index < $count; $index++) {
                if ($index > 0) {
                    $output .= "\r\n";
                }
                $line = $newLines[$index];
                $reserved = self::isImageLine($line) ? self::getKittyImageReservedRows($newLines, $index) : 1;
                if ($reserved > 1 && $reserved <= $height) {
                    $output .= str_repeat("\r\n", $reserved - 1);
                    $output .= "\x1b[" . ($reserved - 1) . 'A' . $line . "\x1b[" . ($reserved - 1) . 'B';
                    $index += $reserved - 1;
                    continue;
                }
                $output .= $line;
            }
            $output .= "\x1b[?2026l";
            $this->terminal->write($output);
            $this->cursorRow = max(0, $count - 1);
            $this->hardwareCursorRow = $this->cursorRow;
            $this->maxLinesRendered = $clear ? $count : max($this->maxLinesRendered, $count);
            $this->previousViewportTop = max(0, max($height, $count) - $height);
            $this->positionHardwareCursor($cursorPos, $count);
            $this->previousLines = $newLines;
            $this->previousKittyImageIds = self::collectKittyImageIds($newLines);
            $this->previousWidth = $width;
            $this->previousHeight = $height;
        };

        // First render: everything, without clearing (the screen is assumed clean).
        if ($this->previousLines === [] && !$widthChanged && !$heightChanged) {
            $fullRender(false);

            return;
        }

        // A width change re-wraps everything.
        if ($widthChanged) {
            $fullRender(true);

            return;
        }

        // A height change misaligns the visible window, except in Termux, where the software
        // keyboard changes the height on every toggle and a redraw would replay the history.
        if ($heightChanged && !self::isTermuxSession()) {
            $fullRender(true);

            return;
        }

        if ($this->getClearOnShrink() && count($newLines) < $this->maxLinesRendered) {
            $fullRender(true);

            return;
        }

        $firstChanged = -1;
        $lastChanged = -1;
        $maxLines = max(count($newLines), count($this->previousLines));
        for ($index = 0; $index < $maxLines; $index++) {
            if (($this->previousLines[$index] ?? '') !== ($newLines[$index] ?? '')) {
                if ($firstChanged === -1) {
                    $firstChanged = $index;
                }
                $lastChanged = $index;
            }
        }
        $appendedLines = count($newLines) > count($this->previousLines);
        if ($appendedLines) {
            if ($firstChanged === -1) {
                $firstChanged = count($this->previousLines);
            }
            $lastChanged = count($newLines) - 1;
        }
        if ($firstChanged !== -1) {
            [$firstChanged, $lastChanged] = $this->expandChangedRangeForKittyImages($firstChanged, $lastChanged, $newLines);
        }
        $appendStart = $appendedLines && $firstChanged === count($this->previousLines) && $firstChanged > 0;

        // Nothing changed, but the cursor may still have moved.
        if ($firstChanged === -1) {
            $this->positionHardwareCursor($cursorPos, count($newLines));
            $this->previousViewportTop = $prevViewportTop;
            $this->previousHeight = $height;

            return;
        }

        // Every change is in deleted lines: nothing to draw, only rows to clear.
        if ($firstChanged >= count($newLines)) {
            if (count($this->previousLines) > count($newLines)) {
                $output = "\x1b[?2026h" . $this->deleteChangedKittyImages($firstChanged, $lastChanged);
                $targetRow = max(0, count($newLines) - 1);
                if ($targetRow < $prevViewportTop) {
                    $fullRender(true);

                    return;
                }
                $lineDiff = $computeLineDiff($targetRow);
                if ($lineDiff > 0) {
                    $output .= "\x1b[{$lineDiff}B";
                } elseif ($lineDiff < 0) {
                    $output .= "\x1b[" . -$lineDiff . 'A';
                }
                $output .= "\r";
                $extraLines = count($this->previousLines) - count($newLines);
                if ($extraLines > $height) {
                    $fullRender(true);

                    return;
                }
                $clearStartOffset = $newLines === [] ? 0 : 1;
                if ($extraLines > 0 && $clearStartOffset > 0) {
                    $output .= "\x1b[{$clearStartOffset}B";
                }
                for ($index = 0; $index < $extraLines; $index++) {
                    $output .= "\r\x1b[2K";
                    if ($index < $extraLines - 1) {
                        $output .= "\x1b[1B";
                    }
                }
                $moveBack = max(0, $extraLines - 1 + $clearStartOffset);
                if ($moveBack > 0) {
                    $output .= "\x1b[{$moveBack}A";
                }
                $this->terminal->write($output . "\x1b[?2026l");
                $this->cursorRow = $targetRow;
                $this->hardwareCursorRow = $targetRow;
            }
            $this->positionHardwareCursor($cursorPos, count($newLines));
            $this->previousLines = $newLines;
            $this->previousKittyImageIds = self::collectKittyImageIds($newLines);
            $this->previousWidth = $width;
            $this->previousHeight = $height;
            $this->previousViewportTop = $prevViewportTop;

            return;
        }

        // A change above what was visible cannot be reached with the cursor.
        if ($firstChanged < $prevViewportTop) {
            $fullRender(true);

            return;
        }

        $output = "\x1b[?2026h" . $this->deleteChangedKittyImages($firstChanged, $lastChanged);
        $prevViewportBottom = $prevViewportTop + $height - 1;
        $moveTargetRow = $appendStart ? $firstChanged - 1 : $firstChanged;
        if ($moveTargetRow > $prevViewportBottom) {
            $currentScreenRow = max(0, min($height - 1, $hardwareCursorRow - $prevViewportTop));
            $moveToBottom = $height - 1 - $currentScreenRow;
            if ($moveToBottom > 0) {
                $output .= "\x1b[{$moveToBottom}B";
            }
            $scroll = $moveTargetRow - $prevViewportBottom;
            $output .= str_repeat("\r\n", $scroll);
            $prevViewportTop += $scroll;
            $viewportTop += $scroll;
            $hardwareCursorRow = $moveTargetRow;
        }

        $lineDiff = $computeLineDiff($moveTargetRow);
        if ($lineDiff > 0) {
            $output .= "\x1b[{$lineDiff}B";
        } elseif ($lineDiff < 0) {
            $output .= "\x1b[" . -$lineDiff . 'A';
        }
        $output .= $appendStart ? "\r\n" : "\r";

        // Only the changed rows, not everything below them: a spinner tick is one line.
        $renderEnd = min($lastChanged, count($newLines) - 1);
        for ($index = $firstChanged; $index <= $renderEnd; $index++) {
            if ($index > $firstChanged) {
                $output .= "\r\n";
            }
            $line = $newLines[$index];
            $isImage = self::isImageLine($line);
            $reserved = $isImage ? self::getKittyImageReservedRows($newLines, $index, $renderEnd) : 1;
            if ($reserved > 1) {
                $imageStartScreenRow = $index - $viewportTop;
                if ($imageStartScreenRow < 0 || $imageStartScreenRow + $reserved > $height) {
                    $fullRender(true);

                    return;
                }
                $output .= "\x1b[2K" . str_repeat("\r\n\x1b[2K", $reserved - 1);
                $output .= "\x1b[" . ($reserved - 1) . 'A' . $line . "\x1b[" . ($reserved - 1) . 'B';
                $index += $reserved - 1;
                continue;
            }

            $output .= "\x1b[2K";
            if (!$isImage && Width::visible($line) > $width) {
                $this->stop();
                $this->checkWidth($newLines, $index, $width);
            }
            $output .= $line;
        }

        $finalCursorRow = $renderEnd;

        // The frame shrank: clear what is left below it and come back.
        if (count($this->previousLines) > count($newLines)) {
            if ($renderEnd < count($newLines) - 1) {
                $moveDown = count($newLines) - 1 - $renderEnd;
                $output .= "\x1b[{$moveDown}B";
                $finalCursorRow = count($newLines) - 1;
            }
            $extraLines = count($this->previousLines) - count($newLines);
            $output .= str_repeat("\r\n\x1b[2K", $extraLines);
            $output .= "\x1b[{$extraLines}A";
        }

        $this->terminal->write($output . "\x1b[?2026l");

        $this->cursorRow = max(0, count($newLines) - 1);
        $this->hardwareCursorRow = $finalCursorRow;
        $this->maxLinesRendered = max($this->maxLinesRendered, count($newLines));
        $this->previousViewportTop = max($prevViewportTop, $finalCursorRow - $height + 1);
        $this->positionHardwareCursor($cursorPos, count($newLines));
        $this->previousLines = $newLines;
        $this->previousKittyImageIds = self::collectKittyImageIds($newLines);
        $this->previousWidth = $width;
        $this->previousHeight = $height;
    }

    /**
     * Put the hardware cursor where the focused component's marker was, for the input method's
     * candidate window — upstream's `positionHardwareCursor()`.
     *
     * @param array{row: int, col: int}|null $cursorPos
     */
    private function positionHardwareCursor(?array $cursorPos, int $totalLines): void
    {
        if ($cursorPos === null || $totalLines <= 0) {
            $this->terminal->hideCursor();

            return;
        }

        $targetRow = max(0, min($cursorPos['row'], $totalLines - 1));
        $targetCol = max(0, $cursorPos['col']);
        $rowDelta = $targetRow - $this->hardwareCursorRow;
        $buffer = $rowDelta > 0 ? "\x1b[{$rowDelta}B" : ($rowDelta < 0 ? "\x1b[" . -$rowDelta . 'A' : '');
        $buffer .= "\x1b[" . ($targetCol + 1) . 'G';
        $this->terminal->write($buffer);

        $this->hardwareCursorRow = $targetRow;
        if ($this->getShowHardwareCursor()) {
            $this->terminal->showCursor();
        } else {
            $this->terminal->hideCursor();
        }
    }
}
