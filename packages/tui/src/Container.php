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
}
