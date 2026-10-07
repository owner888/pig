<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component that takes mouse events — upstream's optional `Component.handleMouse()`, which an
 * interface cannot leave optional, so it is an interface of its own like `InputHandler`.
 */
interface MouseHandler
{
    /** A forwarded child's `TuiMouseDispatchResult`, this component's own `TuiMouseEventResult`, or null to pass. */
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null;
}
