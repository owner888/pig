<?php

declare(strict_types=1);

namespace Pig\Tui;

/** What a component's mouse handler answers — upstream's `TuiMouseEventResult`. */
final readonly class TuiMouseEventResult
{
    /**
     * @param bool $handled stop propagation and suppress the renderer's own fallback
     * @param bool $capture route the following drag/release events to this component; implies handled
     * @param bool $focus give keyboard focus to this component; implies handled
     * @param bool|null $render request or suppress a render; move and release default to false, the rest to true
     */
    public function __construct(
        public bool $handled = false,
        public bool $capture = false,
        public bool $focus = false,
        public ?bool $render = null,
    ) {
    }
}
