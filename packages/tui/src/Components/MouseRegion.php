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

/** Adds mouse handling to an existing component without changing how it renders — upstream's `MouseRegion`. */
final class MouseRegion implements Component, MouseHandler
{
    /** @param Closure(TuiMouseEvent): ?TuiMouseEventResult $onMouse */
    public function __construct(
        private readonly Component $child,
        private readonly Closure $onMouse,
    ) {
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->child->render($width);
    }

    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        return Mouse::dispatchMouseEvent($this->child, $event) ?? ($this->onMouse)($event);
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->child->invalidate();
    }
}
