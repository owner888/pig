<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The concatenated output of a container's children, kept across frames — upstream's `ChildLines`
 * with `renderChildLines()` and `dispatchMouseToChildLines()` (#11968).
 *
 * When the children and their lines are unchanged, the previous instance is handed back, and with
 * it the same `$lines` array: every ancestor's check is then one comparison of an array with itself,
 * and none of them copies the rows again. Without this, each frame of a long transcript copied every
 * rendered row once per level of nesting.
 */
final readonly class ChildLines
{
    /**
     * @param list<Component> $children
     * @param list<list<string>> $parts each child's lines, in order
     * @param list<string> $lines `$parts` one after another
     */
    public function __construct(
        public int $width,
        public array $children,
        public array $parts,
        public array $lines,
    ) {
    }

    /**
     * Render the children and concatenate their lines — upstream's `renderChildLines()`.
     *
     * @param list<Component> $children
     */
    public static function render(array $children, int $width, ?self $previous): self
    {
        return self::join($children, array_map(static fn (Component $child): array => $child->render($width), $children), $width, $previous);
    }

    /**
     * Concatenate lines the children already gave, or hand back `$previous` when nothing changed.
     *
     * A child that answers its cached array answers the same array, and comparing an array with
     * itself does not look at its elements; one that drew again is compared row by row.
     *
     * @param list<Component> $children
     * @param list<list<string>> $parts
     */
    public static function join(array $children, array $parts, int $width, ?self $previous): self
    {
        if ($previous !== null && $previous->width === $width && $previous->children === $children && $previous->parts === $parts) {
            return $previous;
        }

        $lines = [];
        foreach ($parts as $part) {
            foreach ($part as $line) {
                $lines[] = $line;
            }
        }

        return new self($width, $children, $parts, $lines);
    }

    /** Send a mouse event to the child that covers its row — upstream's `dispatchMouseToChildLines()`. */
    public function dispatchMouse(TuiMouseEvent $event): ?TuiMouseDispatchResult
    {
        $childY = 0;
        foreach ($this->children as $index => $child) {
            $childHeight = count($this->parts[$index]);
            if ($event->y >= $childY && $event->y < $childY + $childHeight) {
                return Mouse::dispatchMouseEvent($child, $event->at($event->x, $event->y - $childY, $event->width, $childHeight));
            }
            $childY += $childHeight;
        }

        return null;
    }
}
