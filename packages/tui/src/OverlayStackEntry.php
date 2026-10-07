<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * One shown overlay — upstream's `OverlayStackEntry` type in `tui.ts`.
 *
 * @internal
 */
final class OverlayStackEntry
{
    public ?OverlayBounds $bounds = null;

    public function __construct(
        public readonly Component $component,
        public readonly ?OverlayOptions $options,
        public ?Component $preFocus,
        public bool $hidden,
        public int $focusOrder,
    ) {
    }
}
