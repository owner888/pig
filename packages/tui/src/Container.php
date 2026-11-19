<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A component made of other components, drawn one after another down the screen. */
class Container implements Component, MouseHandler
{
    /** @var list<Component> */
    protected array $children = [];

    /** What the children drew last, kept so an unchanged render hands back the same rows; also where each child landed, for mouse routing. */
    protected ?ChildLines $childLines = null;

    public function addChild(Component $child): void
    {
        $this->children[] = $child;
    }

    public function removeChild(Component $child): void
    {
        $index = array_search($child, $this->children, true);

        if ($index !== false) {
            array_splice($this->children, $index, 1);
        }
    }

    /** @return list<Component> */
    public function children(): array
    {
        return $this->children;
    }

    public function clear(): void
    {
        $this->children = [];
    }

    #[\Override]
    public function invalidate(): void
    {
        foreach ($this->children as $child) {
            $child->invalidate();
        }
    }

    /** Forward to the child under the pointer — upstream's `Container.handleMouse()`. */
    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        if ($event->y < 0 || $event->y >= $event->height) {
            return null;
        }

        $layout = $this->childLines !== null && $this->childLines->width === $event->width
            ? $this->childLines
            : ChildLines::render($this->children, $event->width, null);
        $result = $layout->dispatchMouse($event);
        if ($result !== null && $result->focus && $this instanceof InputHandler) {
            return $result->withFocusTarget($this);
        }

        return $result;
    }

    /**
     * Every child, one after another. Hands back the same array as last time when no child's lines
     * changed (upstream #11968), so whatever holds this container compares it in one step.
     */
    #[\Override]
    public function render(int $width): array
    {
        $this->childLines = ChildLines::render($this->children, $width, $this->childLines);

        return $this->childLines->lines;
    }
}
