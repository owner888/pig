<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Future;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Tui\Images\TerminalImage;

/**
 * What both renderers share — upstream's `TuiBase` in `tui.ts`: the terminal, focus, input
 * listeners, the cell-size query and when a frame is drawn. *How* a frame is drawn is
 * `doRender()`, which `TuiMainScreen` and `TuiAltScreen` each implement.
 *
 * Not ported yet: terminal-colour queries.
 */
abstract class TuiBase extends Container implements TUI
{
    /**
     * Frames are at most this far apart — upstream's `MIN_RENDER_INTERVAL_MS`. A window being
     * dragged asks for a frame per SIGWINCH, dozens a second; within the interval they become one.
     */
    public const float MIN_RENDER_INTERVAL = 0.016;

    /** `regular` or `fullscreen`, upstream's `TuiMode`; each subclass names its own as `MODE`. */
    public readonly string $mode;

    /** Global callback for the debug key (Shift+Ctrl+D), called before input reaches the focused component. */
    public ?Closure $onDebug = null;

    private ?Component $focusedComponent = null;

    private const int TERMINAL_PALETTE_SIZE = 16;

    /** OSC 10 and 11 plus OSC 4 for every palette color. */
    private const int TERMINAL_COLOR_REPLY_COUNT = 2 + self::TERMINAL_PALETTE_SIZE;

    /**
     * Default colors, palette colors 0-15, and a trailing primary device attributes (DA1) request.
     * Every terminal answers DA1 and terminals answer in order, so the DA1 reply marks the end of
     * the color replies, including for terminals that ignore the color queries.
     */
    private const string TERMINAL_COLOR_QUERY = "\x1b]10;?\x07\x1b]11;?\x07"
        . "\x1b]4;0;?\x07\x1b]4;1;?\x07\x1b]4;2;?\x07\x1b]4;3;?\x07\x1b]4;4;?\x07\x1b]4;5;?\x07\x1b]4;6;?\x07\x1b]4;7;?\x07"
        . "\x1b]4;8;?\x07\x1b]4;9;?\x07\x1b]4;10;?\x07\x1b]4;11;?\x07\x1b]4;12;?\x07\x1b]4;13;?\x07\x1b]4;14;?\x07\x1b]4;15;?\x07"
        . "\x1b[c";

    private const string DEVICE_ATTRIBUTES_RESPONSE_PATTERN = '/^\x1b\[\?[\d;]*c$/';

    /**
     * Every colour reply, DA1 reply and colour-scheme report inside one read. Upstream's input is
     * split into single sequences before `handleTerminalInput()`; pig's reads are not, so these are
     * picked out of whatever arrived, in order.
     */
    private const string TERMINAL_COLOR_REPLY = '/\x1b\](?:1[01]|4;\d{1,3});[^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[\?[\d;]*c|(?:\x1b\[\?997;[12]n)+/';

    /** The start of a colour or DA1 reply cut off at the end of a read. */
    private const string PARTIAL_TERMINAL_COLOR_REPLY = '/(?:\x1b\](?:1[01]?|4(?:;\d{0,3})?)?(?:;[^\x07\x1b]*)?|\x1b\[\?[\d;]*)$/';

    /**
     * Color queries waiting for their DA1 reply, oldest first. Terminals answer in order, so color
     * replies belong to the first one.
     *
     * @var list<PendingTerminalColorQuery>
     */
    private array $pendingTerminalColorQueries = [];

    /** @var array<int, Closure(string): void> */
    private array $terminalColorSchemeListeners = [];

    private bool $terminalColorSchemeNotificationsEnabled = false;

    /** A colour reply split across reads, kept until the rest arrives. */
    private string $terminalColorReplyBuffer = '';

    private int $focusOrderCounter = 0;

    /** @var list<OverlayStackEntry> modal components drawn over the content, in the order shown */
    private array $overlayStack = [];

    /** @var list<array{entry: OverlayStackEntry, row: int, col: int, width: int, height: int}> */
    private array $renderedOverlayLayouts = [];

    /**
     * Upstream's `OverlayFocusRestoreState`: whether focus should go back to an overlay that lost it
     * to something outside it.
     *
     * @var array{status: 'inactive'}|array{status: 'eligible', overlay: OverlayStackEntry}|array{status: 'blocked', overlay: OverlayStackEntry, blockedBy: Component, resume: array{status: 'restore-overlay'}|array{status: 'focus-target', target: ?Component}}
     */
    private array $overlayFocusRestore = ['status' => 'inactive'];

    /** @var list<Closure(string): (bool|array{consume?: bool, data?: string}|null)> */
    private array $inputListeners = [];

    private bool $renderRequested = false;

    private bool $immediateRenderScheduled = false;

    private float $lastRenderAt = 0.0;

    private ?string $renderTimer = null;

    private bool $showHardwareCursor = false;

    private bool $clearOnShrink = false;

    protected int $fullRedrawCount = 0;

    protected bool $stopped = false;

    /** The width the last frame was drawn for. */
    protected int $previousWidth = 0;

    /** The height the last frame was drawn for. */
    protected int $previousHeight = 0;

    /** The terminal was asked how big a cell is and has not answered yet. */
    private bool $awaitingCellSize = false;

    /** Input held back while that answer might still be arriving. */
    private string $cellSizeBuffer = '';

    /** @param string|null $logDirectory where crash dumps go; the system temp directory when null */
    public function __construct(
        public readonly Terminal $terminal,
        ?bool $showHardwareCursor = null,
        protected readonly ?string $logDirectory = null,
    ) {
        $this->mode = static::MODE;
        if ($showHardwareCursor !== null) {
            $this->showHardwareCursor = $showHardwareCursor;
        }
    }

    abstract protected function doRender(): void;

    protected function resetRenderState(): void
    {
    }

    protected function beforeTerminalStart(): void
    {
    }

    protected function afterTerminalStart(): void
    {
    }

    protected function beforeTerminalStop(TuiStopOptions $options): void
    {
    }

    protected function afterTerminalStop(TuiStopOptions $options): void
    {
    }

    #[\Override]
    public function getFocusedComponent(): ?Component
    {
        return $this->focusedComponent;
    }

    #[\Override]
    public function setFocus(?Component $component): void
    {
        $this->setFocusInternal($component, 'clear');
    }

    /** @param 'clear'|'preserve' $overlayFocusRestore */
    private function setFocusInternal(?Component $component, string $overlayFocusRestore): void
    {
        $previousFocus = $this->focusedComponent;
        $nextFocus = $component;
        $previousFocusedOverlay = null;
        if ($previousFocus !== null) {
            foreach ($this->overlayStack as $entry) {
                if ($entry->component === $previousFocus && $this->isOverlayVisible($entry)) {
                    $previousFocusedOverlay = $entry;
                    break;
                }
            }
        }
        $nextFocusIsOverlay = $nextFocus !== null && $this->overlayFor($nextFocus) !== null;
        $restoreState = $this->getVisibleOverlayFocusRestore();
        if ($nextFocus !== null && !$nextFocusIsOverlay) {
            if ($restoreState['status'] === 'blocked' && $restoreState['blockedBy'] === $previousFocus) {
                if ($restoreState['resume']['status'] === 'focus-target' || !$this->isComponentMounted($restoreState['blockedBy'])) {
                    $nextFocus = $this->resolveBlockedOverlayFocusResume($restoreState);
                } else {
                    $this->overlayFocusRestore = ['status' => 'blocked', 'overlay' => $restoreState['overlay'], 'blockedBy' => $nextFocus, 'resume' => $restoreState['resume']];
                }
            } elseif ($previousFocusedOverlay !== null
                && $restoreState['status'] !== 'inactive'
                && $restoreState['overlay'] === $previousFocusedOverlay
                && !$this->isOverlayFocusAncestor($previousFocusedOverlay, $nextFocus)) {
                $this->overlayFocusRestore = ['status' => 'blocked', 'overlay' => $previousFocusedOverlay, 'blockedBy' => $nextFocus, 'resume' => ['status' => 'restore-overlay']];
            }
        } elseif ($nextFocus === null) {
            if ($restoreState['status'] === 'blocked' && $restoreState['blockedBy'] === $previousFocus) {
                $nextFocus = $this->resolveBlockedOverlayFocusResume($restoreState);
            } elseif ($overlayFocusRestore === 'clear') {
                $this->clearOverlayFocusRestore();
            }
        }

        if ($this->focusedComponent instanceof Focusable) {
            $this->focusedComponent->focused = false;
        }

        $this->focusedComponent = $nextFocus;

        if ($nextFocus instanceof Focusable) {
            $nextFocus->focused = true;
        }

        if ($nextFocus !== null) {
            $focusedOverlay = $this->overlayFor($nextFocus);
            if ($focusedOverlay !== null && $this->isOverlayVisible($focusedOverlay)) {
                $this->overlayFocusRestore = ['status' => 'eligible', 'overlay' => $focusedOverlay];
            }
        }
    }

    private function overlayFor(Component $component): ?OverlayStackEntry
    {
        foreach ($this->overlayStack as $entry) {
            if ($entry->component === $component) {
                return $entry;
            }
        }

        return null;
    }

    private function clearOverlayFocusRestore(): void
    {
        $this->overlayFocusRestore = ['status' => 'inactive'];
    }

    private function clearOverlayFocusRestoreFor(OverlayStackEntry $overlay): void
    {
        if ($this->overlayFocusRestore['status'] !== 'inactive' && $this->overlayFocusRestore['overlay'] === $overlay) {
            $this->clearOverlayFocusRestore();
        }
    }

    /** @param array{status: 'blocked', overlay: OverlayStackEntry, blockedBy: Component, resume: array{status: string, target?: ?Component}} $restoreState */
    private function resolveBlockedOverlayFocusResume(array $restoreState): ?Component
    {
        if ($restoreState['resume']['status'] === 'restore-overlay') {
            return $restoreState['overlay']->component;
        }
        $this->clearOverlayFocusRestore();

        return $restoreState['resume']['target'] ?? null;
    }

    /** @return array{status: string, overlay?: OverlayStackEntry, blockedBy?: Component, resume?: array{status: string, target?: ?Component}} */
    private function getVisibleOverlayFocusRestore(): array
    {
        $restoreState = $this->overlayFocusRestore;
        if ($restoreState['status'] === 'inactive') {
            return $restoreState;
        }
        if (!in_array($restoreState['overlay'], $this->overlayStack, true) || !$this->isOverlayVisible($restoreState['overlay'])) {
            return ['status' => 'inactive'];
        }

        return $restoreState;
    }

    private function isOverlayFocusAncestor(OverlayStackEntry $entry, Component $component): bool
    {
        $visited = [];
        $current = $entry->preFocus;
        while ($current !== null && !in_array($current, $visited, true)) {
            $visited[] = $current;
            if ($current === $component) {
                return true;
            }
            $current = $this->overlayFor($current)?->preFocus;
        }

        return false;
    }

    private function retargetOverlayPreFocus(OverlayStackEntry $removed): void
    {
        foreach ($this->overlayStack as $overlay) {
            if ($overlay !== $removed && $overlay->preFocus === $removed->component) {
                $overlay->preFocus = $removed->preFocus;
            }
        }
    }

    /** @return list<Component> the trees drawn as the document, which the overlays sit over */
    protected function getMountedRoots(): array
    {
        return $this->children;
    }

    private function isComponentMounted(Component $component): bool
    {
        foreach ($this->getMountedRoots() as $child) {
            if (self::containsComponent($child, $component)) {
                return true;
            }
        }

        return false;
    }

    private static function containsComponent(Component $root, Component $target): bool
    {
        if ($root === $target) {
            return true;
        }
        if (!$root instanceof Container) {
            return false;
        }
        foreach ($root->children() as $child) {
            if (self::containsComponent($child, $target)) {
                return true;
            }
        }

        return false;
    }

    /** Whether an overlay is shown at all, hidden or not — upstream's `hasOverlayEntries`. */
    public function hasOverlayEntries(): bool
    {
        return $this->overlayStack !== [];
    }

    /** Show a component over the content; the handle hides, focuses and moves it — upstream's `showOverlay()`. */
    #[\Override]
    public function showOverlay(Component $component, ?OverlayOptions $options = null): OverlayHandle
    {
        $entry = new OverlayStackEntry($component, $options, $this->focusedComponent, false, ++$this->focusOrderCounter);
        $this->overlayStack[] = $entry;
        if (!($options?->nonCapturing ?? false) && $this->isOverlayVisible($entry)) {
            $this->setFocus($component);
        }
        $this->hideTerminalCursor();
        $this->requestRender();

        $hide = function () use ($entry, $component): void {
            $index = array_search($entry, $this->overlayStack, true);
            if ($index === false) {
                return;
            }
            $this->clearOverlayFocusRestoreFor($entry);
            $this->retargetOverlayPreFocus($entry);
            array_splice($this->overlayStack, $index, 1);
            if ($this->focusedComponent === $component) {
                $this->setFocus($this->getTopmostVisibleOverlay()?->component ?? $entry->preFocus);
            }
            if ($this->overlayStack === []) {
                $this->hideTerminalCursor();
            }
            $this->requestRender();
        };
        $setHidden = function (bool $hidden) use ($entry, $component, $options): void {
            if ($entry->hidden === $hidden) {
                return;
            }
            $entry->hidden = $hidden;
            if ($hidden) {
                $this->clearOverlayFocusRestoreFor($entry);
                if ($this->focusedComponent === $component) {
                    $this->setFocus($this->getTopmostVisibleOverlay()?->component ?? $entry->preFocus);
                }
            } elseif (!($options?->nonCapturing ?? false) && $this->isOverlayVisible($entry)) {
                $entry->focusOrder = ++$this->focusOrderCounter;
                $this->setFocus($component);
            }
            $this->requestRender();
        };
        $focus = function () use ($entry, $component): void {
            if (!in_array($entry, $this->overlayStack, true) || !$this->isOverlayVisible($entry)) {
                return;
            }
            $entry->focusOrder = ++$this->focusOrderCounter;
            $this->setFocus($component);
            $this->requestRender();
        };
        $unfocus = function (?Component $target, bool $hasTarget) use ($entry, $component): void {
            $isFocused = $this->focusedComponent === $component;
            $restoreState = $this->overlayFocusRestore;
            $hasPendingRestore = $restoreState['status'] !== 'inactive' && $restoreState['overlay'] === $entry;
            if (!$isFocused && !$hasPendingRestore) {
                return;
            }
            if ($restoreState['status'] === 'blocked' && $restoreState['overlay'] === $entry && $this->focusedComponent === $restoreState['blockedBy']) {
                if ($hasTarget) {
                    $this->overlayFocusRestore = ['status' => 'blocked', 'overlay' => $entry, 'blockedBy' => $restoreState['blockedBy'], 'resume' => ['status' => 'focus-target', 'target' => $target]];
                } else {
                    $this->clearOverlayFocusRestore();
                }
                $this->requestRender();

                return;
            }
            $this->clearOverlayFocusRestoreFor($entry);
            if ($isFocused || $hasTarget) {
                $topVisible = $this->getTopmostVisibleOverlay();
                $fallback = $topVisible !== null && $topVisible !== $entry ? $topVisible->component : $entry->preFocus;
                $this->setFocus($hasTarget ? $target : $fallback);
            }
            $this->requestRender();
        };
        $isFocused = fn (): bool => $this->focusedComponent === $component;
        $getBounds = fn (): ?OverlayBounds => in_array($entry, $this->overlayStack, true) && $this->isOverlayVisible($entry) ? $entry->bounds : null;

        return new class ($hide, $setHidden, $entry, $focus, $unfocus, $isFocused, $getBounds) implements OverlayHandle {
            public function __construct(
                private readonly \Closure $hide,
                private readonly \Closure $setHidden,
                private readonly OverlayStackEntry $entry,
                private readonly \Closure $focus,
                private readonly \Closure $unfocus,
                private readonly \Closure $isFocused,
                private readonly \Closure $getBounds,
            ) {
            }

            #[\Override]
            public function hide(): void
            {
                ($this->hide)();
            }

            #[\Override]
            public function setHidden(bool $hidden): void
            {
                ($this->setHidden)($hidden);
            }

            #[\Override]
            public function isHidden(): bool
            {
                return $this->entry->hidden;
            }

            #[\Override]
            public function focus(): void
            {
                ($this->focus)();
            }

            #[\Override]
            public function unfocus(?Component $target = null, bool $hasTarget = false): void
            {
                ($this->unfocus)($target, $hasTarget);
            }

            #[\Override]
            public function isFocused(): bool
            {
                return ($this->isFocused)();
            }

            #[\Override]
            public function getBounds(): ?OverlayBounds
            {
                return ($this->getBounds)();
            }
        };
    }

    /** Hide the topmost overlay and give focus back — upstream's `hideOverlay()`. */
    #[\Override]
    public function hideOverlay(): void
    {
        $overlay = $this->overlayStack[count($this->overlayStack) - 1] ?? null;
        if ($overlay === null) {
            return;
        }
        $this->clearOverlayFocusRestoreFor($overlay);
        $this->retargetOverlayPreFocus($overlay);
        array_pop($this->overlayStack);
        if ($this->focusedComponent === $overlay->component) {
            $this->setFocus($this->getTopmostVisibleOverlay()?->component ?? $overlay->preFocus);
        }
        if ($this->overlayStack === []) {
            $this->hideTerminalCursor();
        }
        $this->requestRender();
    }

    /** Hide the cursor while running; after stop() the shell owns it and it must stay visible. */
    private function hideTerminalCursor(): void
    {
        if (!$this->stopped) {
            $this->terminal->hideCursor();
        }
    }

    #[\Override]
    public function hasOverlay(): bool
    {
        foreach ($this->overlayStack as $entry) {
            if ($this->isOverlayVisible($entry)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the focused component is a visible overlay. */
    protected function isOverlayFocused(): bool
    {
        foreach ($this->overlayStack as $entry) {
            if ($entry->component === $this->focusedComponent && $this->isOverlayVisible($entry)) {
                return true;
            }
        }

        return false;
    }

    /** Keep an overlay as the keyboard focus owner when a control inside it is clicked. */
    protected function resolveMouseFocusTarget(Component $component): Component
    {
        for ($index = count($this->overlayStack) - 1; $index >= 0; $index--) {
            $overlay = $this->overlayStack[$index];
            if ($this->isOverlayVisible($overlay) && self::containsComponent($overlay->component, $component)) {
                return $overlay->component;
            }
        }

        return $component;
    }

    /**
     * Dispatch to the visually topmost overlay under the pointer.
     *
     * @return array{hit: bool, result?: TuiMouseDispatchResult}
     */
    protected function dispatchMouseToOverlay(TuiMouseEvent $event): array
    {
        for ($index = count($this->renderedOverlayLayouts) - 1; $index >= 0; $index--) {
            $layout = $this->renderedOverlayLayouts[$index];
            if ($event->screenX < $layout['col'] || $event->screenX >= $layout['col'] + $layout['width']
                || $event->screenY < $layout['row'] || $event->screenY >= $layout['row'] + $layout['height']) {
                continue;
            }
            $result = Mouse::dispatchMouseEvent(
                $layout['entry']->component,
                $event->at($event->screenX - $layout['col'], $event->screenY - $layout['row'], $layout['width'], $layout['height']),
            );

            return $result === null
                ? ['hit' => true]
                : ['hit' => true, 'result' => $result->focus ? $result->withFocusTarget($layout['entry']->component) : $result];
        }

        return ['hit' => false];
    }

    private function isOverlayVisible(OverlayStackEntry $entry): bool
    {
        if ($entry->hidden) {
            return false;
        }

        return $entry->options?->visible === null || ($entry->options->visible)($this->terminal->columns(), $this->terminal->rows());
    }

    /** The visually frontmost visible capturing overlay. */
    private function getTopmostVisibleOverlay(): ?OverlayStackEntry
    {
        $topmost = null;
        foreach ($this->overlayStack as $overlay) {
            if (($overlay->options?->nonCapturing ?? false) || !$this->isOverlayVisible($overlay)) {
                continue;
            }
            if ($topmost === null || $overlay->focusOrder > $topmost->focusOrder) {
                $topmost = $overlay;
            }
        }

        return $topmost;
    }

    #[\Override]
    public function invalidate(): void
    {
        foreach ($this->getMountedRoots() as $root) {
            $root->invalidate();
        }
        foreach ($this->overlayStack as $overlay) {
            $overlay->component->invalidate();
        }
    }

    private static function parseSizeValue(int|string|null $value, int $referenceSize): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        return preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $match) === 1 ? (int) floor($referenceSize * (float) $match[1] / 100) : null;
    }

    /** @return array{width: int, row: int, col: int, maxHeight: ?int} */
    private function resolveOverlayLayout(?OverlayOptions $options, int $overlayHeight, int $termWidth, int $termHeight): array
    {
        $opt = $options ?? new OverlayOptions();
        $margin = is_int($opt->margin)
            ? ['top' => $opt->margin, 'right' => $opt->margin, 'bottom' => $opt->margin, 'left' => $opt->margin]
            : ($opt->margin ?? []);
        $marginTop = max(0, $margin['top'] ?? 0);
        $marginRight = max(0, $margin['right'] ?? 0);
        $marginBottom = max(0, $margin['bottom'] ?? 0);
        $marginLeft = max(0, $margin['left'] ?? 0);
        $availWidth = max(1, $termWidth - $marginLeft - $marginRight);
        $availHeight = max(1, $termHeight - $marginTop - $marginBottom);

        $width = self::parseSizeValue($opt->width, $termWidth) ?? min(80, $availWidth);
        if ($opt->minWidth !== null) {
            $width = max($width, $opt->minWidth);
        }
        $width = max(1, min($width, $availWidth));

        $maxHeight = self::parseSizeValue($opt->maxHeight, $termHeight);
        if ($maxHeight !== null) {
            $maxHeight = max(1, min($maxHeight, $availHeight));
        }
        $effectiveHeight = $maxHeight !== null ? min($overlayHeight, $maxHeight) : $overlayHeight;

        if ($opt->row !== null) {
            if (is_string($opt->row)) {
                $row = preg_match('/^(\d+(?:\.\d+)?)%$/', $opt->row, $match) === 1
                    ? $marginTop + (int) floor(max(0, $availHeight - $effectiveHeight) * (float) $match[1] / 100)
                    : self::resolveAnchorRow('center', $effectiveHeight, $availHeight, $marginTop);
            } else {
                $row = $opt->row;
            }
        } else {
            $row = self::resolveAnchorRow($opt->anchor, $effectiveHeight, $availHeight, $marginTop);
        }

        if ($opt->col !== null) {
            if (is_string($opt->col)) {
                $col = preg_match('/^(\d+(?:\.\d+)?)%$/', $opt->col, $match) === 1
                    ? $marginLeft + (int) floor(max(0, $availWidth - $width) * (float) $match[1] / 100)
                    : self::resolveAnchorCol('center', $width, $availWidth, $marginLeft);
            } else {
                $col = $opt->col;
            }
        } else {
            $col = self::resolveAnchorCol($opt->anchor, $width, $availWidth, $marginLeft);
        }

        $row += $opt->offsetY ?? 0;
        $col += $opt->offsetX ?? 0;
        $row = max($marginTop, min($row, $termHeight - $marginBottom - $effectiveHeight));
        $col = max($marginLeft, min($col, $termWidth - $marginRight - $width));

        return ['width' => $width, 'row' => $row, 'col' => $col, 'maxHeight' => $maxHeight];
    }

    private static function resolveAnchorRow(string $anchor, int $height, int $availHeight, int $marginTop): int
    {
        return match ($anchor) {
            'top-left', 'top-center', 'top-right' => $marginTop,
            'bottom-left', 'bottom-center', 'bottom-right' => $marginTop + $availHeight - $height,
            default => $marginTop + intdiv($availHeight - $height, 2),
        };
    }

    private static function resolveAnchorCol(string $anchor, int $width, int $availWidth, int $marginLeft): int
    {
        return match ($anchor) {
            'top-left', 'left-center', 'bottom-left' => $marginLeft,
            'top-right', 'right-center', 'bottom-right' => $marginLeft + $availWidth - $width,
            default => $marginLeft + intdiv($availWidth - $width, 2),
        };
    }

    /**
     * Draw every visible overlay over the content, the most recently focused on top — upstream's
     * `compositeOverlays()`.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    protected function compositeOverlays(array $lines, int $termWidth, int $termHeight): array
    {
        if ($this->overlayStack === []) {
            $this->renderedOverlayLayouts = [];

            return $lines;
        }

        $result = $lines;
        foreach ($this->overlayStack as $entry) {
            $entry->bounds = null;
        }

        $rendered = [];
        $minLinesNeeded = count($result);
        $visibleEntries = array_values(array_filter($this->overlayStack, $this->isOverlayVisible(...)));
        usort($visibleEntries, static fn (OverlayStackEntry $a, OverlayStackEntry $b): int => $a->focusOrder <=> $b->focusOrder);
        foreach ($visibleEntries as $entry) {
            // Width and maxHeight do not depend on the height, so lay out with 0 first.
            $first = $this->resolveOverlayLayout($entry->options, 0, $termWidth, $termHeight);
            $overlayLines = $entry->component->render($first['width']);
            if ($first['maxHeight'] !== null && count($overlayLines) > $first['maxHeight']) {
                $overlayLines = array_slice($overlayLines, 0, $first['maxHeight']);
            }
            $final = $this->resolveOverlayLayout($entry->options, count($overlayLines), $termWidth, $termHeight);
            $entry->bounds = new OverlayBounds($final['row'], $final['col'], $first['width'], count($overlayLines));
            $rendered[] = ['entry' => $entry, 'lines' => $overlayLines, 'row' => $final['row'], 'col' => $final['col'], 'width' => $first['width']];
            $minLinesNeeded = max($minLinesNeeded, $final['row'] + count($overlayLines));
        }
        $this->renderedOverlayLayouts = array_map(
            static fn (array $item): array => ['entry' => $item['entry'], 'row' => $item['row'], 'col' => $item['col'], 'width' => $item['width'], 'height' => count($item['lines'])],
            $rendered,
        );

        // Padded to at least the terminal height, so overlays have screen-relative positions.
        $workingHeight = max(count($result), $termHeight, $minLinesNeeded);
        while (count($result) < $workingHeight) {
            $result[] = '';
        }
        $viewportStart = max(0, $workingHeight - $termHeight);

        foreach ($rendered as $item) {
            foreach ($item['lines'] as $index => $overlayLine) {
                $row = $viewportStart + $item['row'] + $index;
                if ($row < 0 || $row >= count($result)) {
                    continue;
                }
                $truncated = Width::visible($overlayLine) > $item['width'] ? Width::sliceByColumn($overlayLine, 0, $item['width'], true) : $overlayLine;
                $result[$row] = Width::composite($result[$row], $truncated, $item['col'], $item['width'], $termWidth);
            }
        }

        return $result;
    }

    /** How many full redraws there have been — for a test to ask whether a frame was one. */
    public function fullRedraws(): int
    {
        return $this->fullRedrawCount;
    }

    #[\Override]
    public function getShowHardwareCursor(): bool
    {
        return $this->showHardwareCursor;
    }

    #[\Override]
    public function setShowHardwareCursor(bool $enabled): void
    {
        if ($this->showHardwareCursor === $enabled) {
            return;
        }

        $this->showHardwareCursor = $enabled;
        if (!$enabled && !$this->stopped) {
            $this->terminal->hideCursor();
        }
        $this->requestRender();
    }

    #[\Override]
    public function getClearOnShrink(): bool
    {
        return $this->clearOnShrink;
    }

    /**
     * Whether to redraw everything when content shrinks. When true, rows the content no longer
     * reaches are cleared; when false (the default) they stay, which redraws less on slow terminals.
     */
    #[\Override]
    public function setClearOnShrink(bool $enabled): void
    {
        $this->clearOnShrink = $enabled;
    }

    #[\Override]
    public function start(): void
    {
        $this->stopped = false;
        $this->beforeTerminalStart();
        $this->terminal->start(
            function (string $data): void {
                $this->handleTerminalInput($data);
            },
            function (): void {
                // Not forced: the renderer compares sizes itself and redraws what a resize needs.
                $this->requestRender();
            },
        );
        $this->afterTerminalStart();
        $this->terminal->hideCursor();
        if ($this->terminalColorSchemeNotificationsEnabled) {
            $this->terminal->write("\x1b[?2031h");
        }
        $this->queryCellSize();
        $this->requestRender();
    }

    /**
     * Ask the terminal how big a character cell is, in pixels.
     *
     * Only worth asking on a terminal that draws images, because that is the only thing
     * the answer is used for. The reply comes back as *input*, so the next few keystrokes
     * have to be sifted for it before they reach a component.
     */
    private function queryCellSize(): void
    {
        if (TerminalImage::getCapabilities()->images === null) {
            return;
        }

        $this->awaitingCellSize = true;
        $this->terminal->write("\x1b[16t");
    }

    /**
     * Pull the cell-size reply out of the input stream, if it is in there.
     *
     * Returns what is left for the components. The reply may arrive split across reads,
     * so an incomplete escape sequence is held back rather than delivered as keystrokes —
     * but only until something that looks like a finished sequence turns up, because a
     * terminal that never answers must not swallow the user's typing forever.
     *
     * Once the reply is found, whatever followed it goes straight out even if *that* looks
     * half-finished. Upstream runs its held-back check on the remainder instead and then
     * stops buffering, so the bytes it decided to wait for are never delivered at all: a
     * `\e[` arriving on the heels of the reply is dropped and the `A` behind it is typed as a
     * letter. One press of an arrow key at exactly the wrong moment either way, and handing
     * the bytes over is the half that cannot type something nobody pressed.
     */
    /** Upstream's `onTerminalColorSchemeChange()`: called with `'dark'` or `'light'` on each DEC 2031 report. */
    #[\Override]
    public function onTerminalColorSchemeChange(Closure $listener): Closure
    {
        $this->terminalColorSchemeListeners[spl_object_id($listener)] = $listener;

        return function () use ($listener): void {
            unset($this->terminalColorSchemeListeners[spl_object_id($listener)]);
        };
    }

    #[\Override]
    public function setTerminalColorSchemeNotifications(bool $enabled): void
    {
        if ($this->terminalColorSchemeNotificationsEnabled === $enabled) {
            return;
        }
        $this->terminalColorSchemeNotificationsEnabled = $enabled;
        if (!$this->stopped) {
            $this->terminal->write($enabled ? "\x1b[?2031h" : "\x1b[?2031l");
        }
    }

    /**
     * Query the terminal's theme colors — upstream's `queryTerminalColors()`: the default
     * foreground (OSC 10), the default background (OSC 11), and ANSI colors 0-15 (OSC 4), followed
     * by a DA1 request that marks the end of the replies. Completes when the DA1 reply or all color
     * replies arrive, or when the timeout expires. Colors the terminal did not report are null; the
     * palette is only set when all 16 arrived.
     *
     * @param int $timeoutMs for terminals that do not answer DA1 either
     * @param (Closure(TerminalColors): void)|null $onLateReply receives the replies if the query completes after the timeout
     *
     * @return Future<TerminalColors>
     */
    #[\Override]
    public function queryTerminalColors(int $timeoutMs, ?Closure $onLateReply = null): Future
    {
        $deferred = new Deferred();
        $query = new PendingTerminalColorQuery();
        $query->palette = array_fill(0, self::TERMINAL_PALETTE_SIZE, null);
        $query->deliver = static function (TerminalColors $colors) use ($deferred): void {
            $deferred->complete($colors);
        };
        // Complete with the replies so far, and keep collecting late replies for `onLateReply`.
        $query->timer = Loop::get()->delay($timeoutMs / 1000, function () use ($query, $deferred, $onLateReply): void {
            $query->timer = null;
            $query->deliver = $onLateReply;
            if (!$deferred->isComplete()) {
                $deferred->complete($this->terminalColorQueryResult($query));
            }
        });
        $this->pendingTerminalColorQueries[] = $query;
        $this->terminal->write(self::TERMINAL_COLOR_QUERY);

        return $deferred->future;
    }

    /**
     * Upstream's `consumeTerminalColorResponse()` and `consumeTerminalColorSchemeReport()`, applied to
     * every reply in the read; what is not theirs is handed back.
     */
    private function consumeTerminalColorReplies(string $data): string
    {
        if ($this->terminalColorReplyBuffer !== '') {
            $data = $this->terminalColorReplyBuffer . $data;
            $this->terminalColorReplyBuffer = '';
        }
        if (!str_contains($data, "\x1b") || str_contains($data, "\x1b[200~")) {
            return $data;
        }

        $rest = preg_replace_callback(
            self::TERMINAL_COLOR_REPLY,
            fn (array $match): string => $this->consumeTerminalColorResponse($match[0]) || $this->consumeTerminalColorSchemeReport($match[0]) ? '' : $match[0],
            $data,
        );
        if ($rest === null) {
            throw new TuiError('Splitting terminal colour replies failed: ' . preg_last_error_msg());
        }

        if ($this->pendingTerminalColorQueries !== [] && preg_match(self::PARTIAL_TERMINAL_COLOR_REPLY, $rest, $partial) === 1 && strlen($partial[0]) >= 2) {
            $this->terminalColorReplyBuffer = $partial[0];
            $rest = substr($rest, 0, -strlen($partial[0]));
        }

        return $rest;
    }

    private function consumeTerminalColorResponse(string $data): bool
    {
        $query = $this->pendingTerminalColorQueries[0] ?? null;
        if ($query === null) {
            return false;
        }
        if (preg_match(self::DEVICE_ATTRIBUTES_RESPONSE_PATTERN, $data) === 1) {
            array_shift($this->pendingTerminalColorQueries);
            $this->completeTerminalColorQuery($query);

            return true;
        }

        $response = TerminalColors::parseOscColorResponse($data);
        if ($response === null) {
            return false;
        }
        ['target' => $target, 'rgb' => $rgb] = $response;
        $key = (string) $target;
        if ($query->deliver === null || isset($query->replied[$key])) {
            return true;
        }
        $query->replied[$key] = true;
        if ($target === 'foreground') {
            $query->foreground = $rgb;
        } elseif ($target === 'background') {
            $query->background = $rgb;
        } elseif ($target < self::TERMINAL_PALETTE_SIZE) {
            $query->palette[$target] = $rgb;
        }
        if (count($query->replied) === self::TERMINAL_COLOR_REPLY_COUNT) {
            $this->completeTerminalColorQuery($query);
        }

        return true;
    }

    private function terminalColorQueryResult(PendingTerminalColorQuery $query): TerminalColors
    {
        $complete = !in_array(null, $query->palette, true);

        return new TerminalColors(
            $query->foreground,
            $query->background,
            $complete ? array_values(array_filter($query->palette, static fn (?RgbColor $color): bool => $color !== null)) : null,
        );
    }

    private function completeTerminalColorQuery(PendingTerminalColorQuery $query): void
    {
        $deliver = $query->deliver;
        $query->deliver = null;
        if ($query->timer !== null) {
            Loop::get()->cancel($query->timer);
            $query->timer = null;
        }
        if ($deliver !== null) {
            $deliver($this->terminalColorQueryResult($query));
        }
    }

    private function consumeTerminalColorSchemeReport(string $data): bool
    {
        $scheme = TerminalColors::parseTerminalColorSchemeReport($data);
        if ($scheme === null) {
            return false;
        }

        foreach ($this->terminalColorSchemeListeners as $listener) {
            $listener($scheme);
        }

        return true;
    }

    private function takeCellSizeReply(string $data): string
    {
        $this->cellSizeBuffer .= $data;
        $size = TerminalImage::parseCellSizeReply($this->cellSizeBuffer);

        if ($size !== null) {
            TerminalImage::setCellDimensions($size);
            $this->awaitingCellSize = false;
            $rest = (string) preg_replace('/\x1b\[6;\d+;\d+t/', '', $this->cellSizeBuffer, 1);
            $this->cellSizeBuffer = '';

            // Every image was measured against the wrong cell size until now, so their
            // caches go — and then an ordinary render, **not a forced one**. Forcing empties
            // previousLines, which `doRender()` reads as "first frame ever" and writes with no
            // clear from wherever the cursor already is: the whole startup screen came out
            // twice on every terminal that answers this query, which is every terminal that
            // draws pictures. Upstream asks for a plain render here for the same reason the
            // resize handler does, and the trap entry about `force` is the long version.
            $this->invalidate();
            $this->requestRender();

            return $rest;
        }

        // Still mid-sequence: wait for the rest.
        if (preg_match('/\x1b(\[6?;?[\d;]*)?$/', $this->cellSizeBuffer) === 1) {
            return '';
        }

        $rest = $this->cellSizeBuffer;
        $this->cellSizeBuffer = '';
        $this->awaitingCellSize = false;

        return $rest;
    }

    #[\Override]
    public function stop(?TuiStopOptions $options = null): void
    {
        $options ??= new TuiStopOptions();
        $this->stopped = true;
        $this->cancelRenderTimer();
        if ($this->terminalColorSchemeNotificationsEnabled) {
            $this->terminal->write("\x1b[?2031l");
        }
        $this->beforeTerminalStop($options);
        $this->terminal->showCursor();
        $this->terminal->stop();
        $this->afterTerminalStop($options);
    }

    /** Draw now, not on the next turn of the loop — upstream's `renderNow()`. */
    #[\Override]
    public function renderNow(bool $force = false): void
    {
        if ($force) {
            $this->resetRenderState();
        }

        $this->renderRequested = false;
        $this->cancelRenderTimer();
        $this->lastRenderAt = microtime(true);
        $this->doRender();
    }

    /**
     * Draw soon — upstream's `requestRender()`.
     *
     * A plain request is throttled: frames are at most `MIN_RENDER_INTERVAL` apart, so a burst of
     * requests becomes one frame. **`$force` means "the screen is not ours any more"** — coming
     * back from `$VISUAL` or a suspend: what the screen is believed to hold is thrown away, so the
     * next frame redraws everything with a clear, and it is drawn without waiting.
     */
    #[\Override]
    public function requestRender(bool $force = false): void
    {
        if ($force) {
            $this->resetRenderState();
            $this->requestImmediateRender();

            return;
        }

        if ($this->renderRequested) {
            return;
        }

        $this->renderRequested = true;
        Loop::get()->defer($this->scheduleRender(...));
    }

    /** Draw on the next turn, ahead of any throttled frame — keyboard input does not wait for one. */
    private function requestImmediateRender(): void
    {
        $this->cancelRenderTimer();
        $this->renderRequested = true;
        if ($this->immediateRenderScheduled) {
            return;
        }

        $this->immediateRenderScheduled = true;
        Loop::get()->defer(function (): void {
            $this->immediateRenderScheduled = false;
            if ($this->stopped || !$this->renderRequested) {
                return;
            }
            // A previously queued scheduleRender() can create a timer before this runs; input
            // preempts that throttled frame.
            $this->cancelRenderTimer();
            $this->renderRequested = false;
            $this->lastRenderAt = microtime(true);
            $this->doRender();
        });
    }

    private function cancelRenderTimer(): void
    {
        if ($this->renderTimer === null) {
            return;
        }

        Loop::get()->cancel($this->renderTimer);
        $this->renderTimer = null;
    }

    private function scheduleRender(): void
    {
        if ($this->stopped || $this->renderTimer !== null || !$this->renderRequested) {
            return;
        }

        $delay = max(0.0, self::MIN_RENDER_INTERVAL - (microtime(true) - $this->lastRenderAt));
        $this->renderTimer = Loop::get()->delay($delay, function (): void {
            $this->renderTimer = null;
            if ($this->stopped || !$this->renderRequested) {
                return;
            }
            $this->renderRequested = false;
            $this->lastRenderAt = microtime(true);
            $this->doRender();
            if ($this->renderRequested) {
                $this->scheduleRender();
            }
        });
    }

    /**
     * Intercept or observe raw terminal input before components see it. Upstream's `addInputListener()`.
     *
     * A listener that returns true or `['consume' => true]` stops the keystroke from reaching
     * whatever holds focus. A returned `['data' => $text]` replaces the input for subsequent
     * listeners and the focused component.
     *
     * @param Closure(string): (bool|array{consume?: bool, data?: string}|null) $listener
     * @return Closure(): void call it to stop listening
     */
    #[\Override]
    public function addInputListener(Closure $listener): Closure
    {
        $this->inputListeners[] = $listener;

        return function () use ($listener): void {
            $this->removeInputListener($listener);
        };
    }

    #[\Override]
    public function removeInputListener(Closure $listener): void
    {
        $this->inputListeners = array_values(array_filter(
            $this->inputListeners,
            static fn (Closure $registered): bool => $registered !== $listener,
        ));
    }

    private function handleTerminalInput(string $data): void
    {
        $data = $this->consumeTerminalColorReplies($data);
        if ($data === '') {
            return;
        }

        foreach ($this->inputListeners as $listener) {
            $res = $listener($data);

            if ($res === true || (is_array($res) && ($res['consume'] ?? false))) {
                return;
            }

            if (is_array($res) && isset($res['data'])) {
                $data = (string) $res['data'];
            }
        }

        if ($data === '') {
            return;
        }

        if ($this->awaitingCellSize) {
            $data = $this->takeCellSizeReply($data);

            if ($data === '') {
                return;
            }
        }

        if ($this->onDebug !== null && Keys::isShiftCtrlD($data)) {
            ($this->onDebug)();

            return;
        }

        // A focused overlay that stopped being visible (a resize, its visible() callback) hands
        // focus to the topmost visible one, or back to what it took focus from.
        $focusedOverlay = $this->focusedComponent === null ? null : $this->overlayFor($this->focusedComponent);
        if ($focusedOverlay !== null && !$this->isOverlayVisible($focusedOverlay)) {
            $topVisible = $this->getTopmostVisibleOverlay();
            if ($topVisible !== null) {
                $this->setFocus($topVisible->component);
            } else {
                $this->setFocusInternal($focusedOverlay->preFocus, 'preserve');
            }
        }

        $focusIsOverlay = $this->focusedComponent !== null && $this->overlayFor($this->focusedComponent) !== null;
        if (!$focusIsOverlay) {
            $restoreState = $this->getVisibleOverlayFocusRestore();
            if ($restoreState['status'] === 'eligible') {
                $this->setFocus($restoreState['overlay']->component);
            } elseif ($restoreState['status'] === 'blocked' && $restoreState['blockedBy'] !== $this->focusedComponent) {
                if ($restoreState['resume']['status'] === 'restore-overlay') {
                    $this->setFocus($restoreState['overlay']->component);
                } else {
                    $this->clearOverlayFocusRestore();
                    $this->setFocus($restoreState['resume']['target'] ?? null);
                }
            }
        }

        // Ctrl+C included: the focused component decides what it means, because in an
        // editor it is "copy" and at an empty prompt it is "quit".
        if ($this->focusedComponent instanceof InputHandler) {
            $this->focusedComponent->handleInput($data);
            // Keyboard input is latency-sensitive: not the throttled path.
            $this->requestImmediateRender();
        }
    }

    /** The width the last frame was drawn for — for a test to ask which of a burst of sizes won. */
    public function renderedWidth(): int
    {
        return $this->previousWidth;
    }

    /**
     * Find `CURSOR_MARKER` in the visible part of a frame, strip it, and say where it was —
     * upstream's `extractCursorPosition()`. Only the bottom `$height` lines are searched.
     *
     * @param list<string> $lines
     * @return array{row: int, col: int}|null
     */
    protected function extractCursorPosition(array &$lines, int $height): ?array
    {
        $viewportTop = max(0, count($lines) - $height);
        for ($row = count($lines) - 1; $row >= $viewportTop; $row--) {
            $markerIndex = strpos($lines[$row], self::CURSOR_MARKER);
            if ($markerIndex !== false) {
                $before = substr($lines[$row], 0, $markerIndex);
                $lines[$row] = $before . substr($lines[$row], $markerIndex + strlen(self::CURSOR_MARKER));

                return ['row' => $row, 'col' => Width::visible($before)];
            }
        }

        return null;
    }

    /**
     * Close every line's styling and hyperlink at its end, and turn what a terminal draws
     * differently from `Width::visible()` into what it measures — upstream's `applyLineResets()`.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    protected function applyLineResets(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (!TerminalImage::isImageLine($line)) {
                $lines[$index] = Width::normalizeTerminalOutput($line) . Width::SEGMENT_RESET;
            }
        }

        return $lines;
    }

    /**
     * Refuse to draw a line wider than the terminal.
     *
     * The terminal would wrap it, putting every subsequent cursor move one line out and
     * corrupting the display in a way that is almost impossible to read back from the
     * screen. Failing here names the component that produced it instead.
     *
     * @param list<string> $lines
     */
    protected function checkWidth(array $lines, int $index, int $width): void
    {
        $line = $lines[$index];

        if (TerminalImage::isImageLine($line)) {
            return;
        }

        $visible = Width::visible($line);

        if ($visible <= $width) {
            return;
        }

        $log = ($this->logDirectory ?? sys_get_temp_dir()) . '/pig-render-' . getmypid() . '.log';
        $dump = "Line {$index} is {$visible} columns wide\n" . self::widths($lines, $width);

        file_put_contents($log, $dump);

        throw new TuiError("Rendered line {$index} is {$visible} columns wide, terminal is {$width}. Lines written to {$log}");
    }

    /**
     * The frame as it stands, line by line, with the columns each one takes.
     *
     * **Two readers, which is why this is a method.** `checkWidth()` writes it when a line is too
     * wide to draw, and the application's debug key writes it on demand — the same question asked
     * after a fault and before one. Every width bug in this repository was found by looking at
     * exactly this, and until now the only way to see it was to cause the fault.
     */
    public function frame(): string
    {
        $width = $this->terminal->columns();

        return self::widths($this->render($width), $width);
    }

    /**
     * One line per rendered line, with its width and its bytes.
     *
     * The escapes are kept as escapes — `\e` and not an escape that moves the cursor of whatever
     * is reading the log. A column count next to a line whose codes are invisible is the pair that
     * makes a padding bug obvious.
     *
     * @param list<string> $lines
     */
    private static function widths(array $lines, int $width): string
    {
        $dump = ["Terminal width: {$width}", 'Lines: ' . count($lines), ''];

        foreach ($lines as $number => $line) {
            $dump[] = "[{$number}] (w=" . Width::visible($line) . ') ' . json_encode($line);
        }

        return implode("\n", $dump) . "\n";
    }
}
