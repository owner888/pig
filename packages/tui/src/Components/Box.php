<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Mouse;
use Pig\Tui\MouseHandler;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;
use Pig\Tui\Width;

/**
 * Children indented and given a common background.
 *
 * No border is drawn. The box is a region of colour with padding — which is what the
 * agent UI uses it for: one background per kind of message, so the eye can tell what the
 * user said from what the model said without reading either.
 */
final class Box implements Component, MouseHandler
{
    /** @var list<Component> */
    private array $children = [];

    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?string $cachedChildren = null;

    private ?int $cachedWidth = null;

    private ?string $cachedBackground = null;

    /** @var array{width: int, children: list<array{component: Component, height: int}>}|null where each child landed in the last render, for mouse routing */
    private ?array $mouseLayout = null;

    /** @param Closure(string): string|null $background */
    public function __construct(
        private readonly int $paddingX = 1,
        private readonly int $paddingY = 1,
        private ?Closure $background = null,
    ) {
    }

    public function addChild(Component $child): void
    {
        $this->children[] = $child;
        $this->invalidateSelf();
    }

    public function removeChild(Component $child): void
    {
        $index = array_search($child, $this->children, true);

        if ($index !== false) {
            array_splice($this->children, $index, 1);
            $this->invalidateSelf();
        }
    }

    public function clear(): void
    {
        $this->children = [];
        $this->invalidateSelf();
    }

    /**
     * A theme change swaps the closure without touching the children, so the cache is
     * left alone here and the sample taken in render() catches it instead.
     *
     * @param Closure(string): string|null $background
     */
    public function setBackground(?Closure $background): void
    {
        $this->background = $background;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->invalidateSelf();

        foreach ($this->children as $child) {
            $child->invalidate();
        }
    }

    private function invalidateSelf(): void
    {
        $this->cachedLines = null;
        $this->cachedChildren = null;
        $this->cachedWidth = null;
        $this->cachedBackground = null;
    }

    /** Forward to the child under the pointer, inside the padding — upstream's `Box.handleMouse()`. */
    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        $contentWidth = max(1, $event->width - $this->paddingX * 2);
        $contentY = $event->y - $this->paddingY;
        $contentX = $event->x - $this->paddingX;
        if ($contentY < 0 || $contentX < 0 || $contentX >= $contentWidth) {
            return null;
        }

        $mouseChildren = $this->mouseLayout !== null && $this->mouseLayout['width'] === $contentWidth
            ? $this->mouseLayout['children']
            : array_map(static fn (Component $child): array => ['component' => $child, 'height' => count($child->render($contentWidth))], $this->children);
        $childY = 0;
        foreach ($mouseChildren as ['component' => $child, 'height' => $childHeight]) {
            if ($contentY >= $childY && $contentY < $childY + $childHeight) {
                return Mouse::dispatchMouseEvent($child, $event->at($contentX, $contentY - $childY, $contentWidth, $childHeight));
            }
            $childY += $childHeight;
        }

        return null;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->children === []) {
            return [];
        }

        $contentWidth = max(1, $width - $this->paddingX * 2);
        $margin = str_repeat(' ', $this->paddingX);
        $content = [];
        $mouseChildren = [];

        foreach ($this->children as $child) {
            $childLines = $child->render($contentWidth);
            $mouseChildren[] = ['component' => $child, 'height' => count($childLines)];
            foreach ($childLines as $line) {
                $content[] = $margin . $line;
            }
        }
        $this->mouseLayout = ['width' => $contentWidth, 'children' => $mouseChildren];

        if ($content === []) {
            return [];
        }

        // The background is a closure, so it cannot be compared — but what it does to a
        // known string can be, which is enough to notice a theme change.
        $sample = $this->background === null ? null : ($this->background)('sample');
        $key = implode("\n", $content);

        if (
            $this->cachedLines !== null
            && $this->cachedWidth === $width
            && $this->cachedChildren === $key
            && $this->cachedBackground === $sample
        ) {
            return $this->cachedLines;
        }

        $blank = $this->paint('', $width);
        $lines = array_fill(0, $this->paddingY, $blank);

        foreach ($content as $line) {
            $lines[] = $this->paint($line, $width);
        }

        for ($index = 0; $index < $this->paddingY; $index++) {
            $lines[] = $blank;
        }

        $this->cachedLines = $lines;
        $this->cachedChildren = $key;
        $this->cachedWidth = $width;
        $this->cachedBackground = $sample;

        return $lines;
    }

    private function paint(string $line, int $width): string
    {
        $padded = $line . str_repeat(' ', max(0, $width - Width::visible($line)));

        return $this->background === null ? $padded : Width::background($padded, $width, $this->background);
    }
}
