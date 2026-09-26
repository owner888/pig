<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A component made of other components, drawn one after another down the screen. */
class Container implements Component
{
    /** @var list<Component> */
    protected array $children = [];

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

    #[\Override]
    public function render(int $width): array
    {
        $lines = [];

        foreach ($this->children as $child) {
            foreach ($child->render($width) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Which row of this container's output $component's own first row lands on.
     *
     * Needed because a component reports its caret in its own coordinates and knows
     * nothing about what is drawn above it. Counted by rendering, since that is the only
     * thing that knows how tall a child turned out to be — components cache their lines,
     * so asking again inside the same frame costs nothing.
     *
     * **It only sees into containers.** `Components\Box` holds children of its own without
     * being one of these, so a focusable component put inside a box is invisible here and its
     * caret goes unplaced — and the box's own horizontal padding would shift the column even
     * if it were found. Nothing focusable lives in a box today (boxes are the coloured
     * backgrounds behind messages), which is why this is a note rather than a branch; the
     * symptom would be an input method composing at the bottom of the frame again.
     *
     * @return int|null null when $component is not in here at all
     */
    public function rowOf(Component $component, int $width): ?int
    {
        $row = 0;

        foreach ($this->children as $child) {
            if ($child === $component) {
                return $row;
            }

            if ($child instanceof self) {
                $inside = $child->rowOf($component, $width);

                if ($inside !== null) {
                    return $row + $inside;
                }
            }

            $row += count($child->render($width));
        }

        return null;
    }
}
