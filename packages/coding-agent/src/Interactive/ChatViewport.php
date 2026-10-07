<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\StackEntry;
use Pig\Tui\Components\VStack;

/**
 * Shared fullscreen transcript and fixed input-dock layout — upstream's
 * `modes/interactive/chat-viewport.ts`.
 *
 * The transcript takes whatever rows are left; the dock under it takes what it needs, every part
 * of it able to shrink (the editor to no fewer than three rows) so the editor and footer stay on
 * screen however much is queued above them.
 */
final readonly class ChatViewport
{
    private function __construct(
        public Component $root,
        public ScrollView $transcript,
    ) {
    }

    /**
     * Upstream's `createChatViewport()`.
     *
     * @param 'hidden'|'auto'|'always' $scrollbar
     * @param (Closure(string): string)|null $scrollbarTrackStyle
     * @param (Closure(string): string)|null $scrollbarThumbStyle
     */
    public static function create(
        Component $document,
        Component $pendingMessages,
        Component $status,
        Component $editor,
        Component $footer,
        ?Component $widgetsAbove = null,
        ?Component $widgetsBelow = null,
        string $scrollbar = 'auto',
        ?Closure $scrollbarTrackStyle = null,
        ?Closure $scrollbarThumbStyle = null,
    ): self {
        $transcript = new ScrollView(
            $document,
            follow: 'end',
            primary: true,
            overscroll: 'chain',
            scrollbar: $scrollbar,
            scrollbarTrackStyle: $scrollbarTrackStyle,
            scrollbarThumbStyle: $scrollbarThumbStyle,
        );
        $dock = new VStack(array_values(array_filter([
            new StackEntry($pendingMessages, shrink: 1, minSize: 0),
            new StackEntry($status, shrink: 1, minSize: 0),
            $widgetsAbove === null ? null : new StackEntry($widgetsAbove, shrink: 1, minSize: 0),
            new StackEntry($editor, shrink: 1, minSize: 3),
            $widgetsBelow === null ? null : new StackEntry($widgetsBelow, shrink: 1, minSize: 0),
            new StackEntry($footer, shrink: 1, minSize: 0),
        ])));

        return new self(
            new VStack([
                new StackEntry($transcript, basis: 0, grow: 1, shrink: 1, minSize: 1),
                new StackEntry($dock, basis: 'auto', grow: 0, shrink: 1, minSize: 1),
            ]),
            $transcript,
        );
    }
}
