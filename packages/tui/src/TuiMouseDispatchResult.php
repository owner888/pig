<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A handled event and the concrete component that handled it — upstream's `TuiMouseDispatchResult`. */
final readonly class TuiMouseDispatchResult
{
    /** @param Component|null $focusTarget the keyboard focus owner, which may be a delegating parent */
    public function __construct(
        public TuiMouseDispatchTarget $target,
        public bool $capture = false,
        public bool $focus = false,
        public ?bool $render = null,
        public ?Component $focusTarget = null,
    ) {
    }

    public function withFocusTarget(?Component $focusTarget): self
    {
        return new self($this->target, $this->capture, $this->focus, $this->render, $focusTarget);
    }
}
