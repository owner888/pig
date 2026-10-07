<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A renderer that owns a scrollable viewport — upstream's `ViewportTUI`; `instanceof` replaces `isViewportTUI()`. */
interface ViewportTUI extends TUI
{
    public function setLayoutRoot(?Component $component): void;
}
