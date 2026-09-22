<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

/** One row of a SelectList: what it is called, what it returns, and what it does. */
final readonly class SelectItem
{
    public function __construct(
        public string $value,
        public string $label = '',
        public ?string $description = null,
    ) {
    }

    /** What to draw: the label when there is one, the value otherwise. */
    public function display(): string
    {
        return $this->label !== '' ? $this->label : $this->value;
    }
}
