<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `dispatchMouseEvent()` and `retargetMouseEvent()` from `tui.ts`. */
final class Mouse
{
    /**
     * Dispatch an event to a component and keep the exact target and coordinate transform.
     * Containers use this when forwarding events to children.
     */
    public static function dispatchMouseEvent(Component $component, TuiMouseEvent $event): ?TuiMouseDispatchResult
    {
        if (!$component instanceof MouseHandler) {
            return null;
        }

        $result = $component->handleMouse($event);
        if ($result === null) {
            return null;
        }

        if ($result instanceof TuiMouseDispatchResult) {
            // The component forwarded the event to a child it hosts. Like a delegating container,
            // it routes keys to that child itself, so it keeps keyboard focus.
            return $result->focus && $component instanceof InputHandler ? $result->withFocusTarget($component) : $result;
        }

        if (!$result->handled && !$result->capture && !$result->focus) {
            return null;
        }

        return new TuiMouseDispatchResult(
            new TuiMouseDispatchTarget($component, $event->screenX - $event->x, $event->screenY - $event->y, $event->width, $event->height),
            $result->capture,
            $result->focus,
            $result->render,
            $result->focus ? $component : null,
        );
    }

    /** Recreate local coordinates for a previously dispatched target. */
    public static function retargetMouseEvent(TuiMouseEvent $event, TuiMouseDispatchTarget $target): TuiMouseEvent
    {
        return $event->at($event->screenX - $target->originX, $event->screenY - $target->originY, $target->width, $target->height);
    }
}
