<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

/** One suggestion: what gets inserted, what the list shows, and what it is. */
final readonly class AutocompleteItem
{
    public function __construct(
        public string $value,
        public string $label = '',
        public ?string $description = null,
    ) {
    }

    public function display(): string
    {
        return $this->label !== '' ? $this->label : $this->value;
    }
}
