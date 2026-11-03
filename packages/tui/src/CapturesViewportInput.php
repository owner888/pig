<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component that, while focused and while `capturesViewportInput()` says so, receives the
 * fullscreen viewport keys (`tui.altScreen.*` scrolling and prompt jumps) instead of the
 * transcript scrolling, as a focused overlay does — upstream's optional
 * `Component.capturesViewportInput`. A PHP interface cannot declare an optional property, so it
 * is an interface of its own, as `Focusable` is.
 */
interface CapturesViewportInput
{
    public function capturesViewportInput(): bool;
}
