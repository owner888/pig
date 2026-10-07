<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A component made of other components, drawn one after another down the screen. */
class Container implements Component, MouseHandler
{
    /** @var list<Component> */
    protected array $children = [];

    /** @var array{width: int, children: list<array{component: Component, height: int}>}|null where each child landed in the last render, for mouse routing */
    private ?array $mouseLayout = null;

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

        $mouseChildren = $this->mouseLayout !== null && $this->mouseLayout['width'] === $event->width
            ? $this->mouseLayout['children']
            : array_map(static fn (Component $child): array => ['component' => $child, 'height' => count($child->render($event->width))], $this->children);
        $childY = 0;
        foreach ($mouseChildren as ['component' => $child, 'height' => $childHeight]) {
            if ($event->y >= $childY && $event->y < $childY + $childHeight) {
                $result = Mouse::dispatchMouseEvent($child, $event->at($event->x, $event->y - $childY, $event->width, $childHeight));
                if ($result !== null && $result->focus && $this instanceof InputHandler) {
                    return $result->withFocusTarget($this);
                }

                return $result;
            }
            $childY += $childHeight;
        }

        return null;
    }

    #[\Override]
    public function render(int $width): array
    {
        $lines = [];
        $mouseChildren = [];

        foreach ($this->children as $child) {
            $childLines = $child->render($width);
            $mouseChildren[] = ['component' => $child, 'height' => count($childLines)];
            foreach ($childLines as $line) {
                $lines[] = $line;
            }
        }
        $this->mouseLayout = ['width' => $width, 'children' => $mouseChildren];

        return $lines;
    }
}
