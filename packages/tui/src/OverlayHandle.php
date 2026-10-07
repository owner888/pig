<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Controls one shown overlay — upstream's `OverlayHandle`. */
interface OverlayHandle
{
    /** Remove the overlay for good. */
    public function hide(): void;

    /** Hide or show it again without removing it. */
    public function setHidden(bool $hidden): void;

    public function isHidden(): bool;

    /** Focus it and bring it to the front. */
    public function focus(): void;

    /**
     * Give focus back — to the next visible capturing overlay or what had focus before, or to
     * `$target` when one is passed (`$hasTarget` says so, since null is a valid target).
     */
    public function unfocus(?Component $target = null, bool $hasTarget = false): void;

    public function isFocused(): bool;

    /** Where it was drawn last, while it is visible. */
    public function getBounds(): ?OverlayBounds;
}
