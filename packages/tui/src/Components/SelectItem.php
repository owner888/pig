<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

/** One row of a SelectList: what it is called, what it returns, and what it does. */
final readonly class SelectItem
{
    /**
     * @param string|null $searchText what typing in the list matches against, when the label and
     *        the description are not the right thing to rank on. The model picker's rows are
     *        numbered `0`, `1`, `2` — a value nobody could search for — and upstream deliberately
     *        orders the provider ahead of the bare id so that `openai/gpt-5` beats a proxy's
     *        `openrouter/openai/gpt-5`. Null means the label and description, which is what a
     *        list whose rows read the way they are searched wants.
     */
    public function __construct(
        public string $value,
        public string $label = '',
        public ?string $description = null,
        public ?string $searchText = null,
    ) {
    }

    /** What typing in the list is matched against. */
    public function haystack(): string
    {
        return $this->searchText ?? trim($this->display() . ' ' . ($this->description ?? ''));
    }

    /** What to draw: the label when there is one, the value otherwise. */
    public function display(): string
    {
        return $this->label !== '' ? $this->label : $this->value;
    }
}
