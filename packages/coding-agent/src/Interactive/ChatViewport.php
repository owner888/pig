<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\Caret;
use Pig\Tui\Component;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Container;

/**
 * Shared fullscreen transcript and fixed input-dock layout.
 *
 * Ported from upstream's `modes/interactive/chat-viewport.ts`.
 * - Top: ScrollView of the conversation transcript, supporting follow-end and floating indicator.
 * - Bottom: Dock container holding pending queue, editor, and footer, pinned strictly to bottom.
 */
final class ChatViewport extends Container implements Caret
{
    private readonly ScrollView $transcript;

    private readonly Container $dock;

    private int $lastTranscriptHeight = 0;

    public function __construct(
        Component $document,
        Component $pending,
        Component $status,
        Component $overlay,
        Component $widgetsAbove,
        private readonly Component $editor,
        Component $widgetsBelow,
        Component $footer,
    ) {
        $this->transcript = new ScrollView($document, followEnd: true);
        $this->dock = new Container();

        $this->dock->addChild($pending);
        $this->dock->addChild($status);
        $this->dock->addChild($overlay);
        $this->dock->addChild($widgetsAbove);
        $this->dock->addChild(new \Pig\Tui\Components\Spacer(1));
        $this->dock->addChild($this->editor);
        $this->dock->addChild($widgetsBelow);
        $this->dock->addChild($footer);

        $this->addChild($this->transcript);
        $this->addChild($this->dock);
    }

    public function transcript(): ScrollView
    {
        return $this->transcript;
    }

    public function dock(): Container
    {
        return $this->dock;
    }

    /** @return array{row: int, column: int, width: int}|null */
    public function indicatorRect(): ?array
    {
        return $this->transcript->indicatorRect();
    }

    /**
     * Render the whole viewport for a given full-screen dimension.
     *
     * @param int $width
     * @param int $height
     * @param (Closure(): string)|null $indicator
     * @return list<string>
     */
    public function renderViewport(int $width, int $height, ?Closure $indicator = null): array
    {
        $dockLines = $this->dock->render($width);
        $dockHeight = count($dockLines);

        $this->lastTranscriptHeight = max(1, $height - $dockHeight);
        $transcriptLines = $this->transcript->renderViewport($width, $this->lastTranscriptHeight, $indicator);

        return array_values([...$transcriptLines, ...$dockLines]);
    }

    #[\Override]
    public function render(int $width): array
    {
        return array_values([...$this->transcript->render($width), ...$this->dock->render($width)]);
    }

    #[\Override]
    public function rowOf(Component $component, int $width): ?int
    {
        $inDock = $this->dock->rowOf($component, $width);
        if ($inDock !== null) {
            return $this->lastTranscriptHeight + $inDock;
        }

        $inTranscript = $this->transcript->rowOf($component, $width);
        if ($inTranscript !== null) {
            return $inTranscript - $this->transcript->scrollTop();
        }

        return null;
    }

    #[\Override]
    public function caret(int $width): ?array
    {
        if (!$this->editor instanceof Caret) {
            return null;
        }

        $editorCaret = $this->editor->caret($width);
        if ($editorCaret === null) {
            return null;
        }

        // Calculate editor's row offset inside dock
        $rowInDock = $this->dock->rowOf($this->editor, $width) ?? 0;

        return [$this->lastTranscriptHeight + $rowInDock + $editorCaret[0], $editorCaret[1]];
    }
}
