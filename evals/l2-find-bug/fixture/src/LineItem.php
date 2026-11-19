<?php

declare(strict_types=1);

namespace Shop;

final readonly class LineItem
{
    public function __construct(
        public string $name,
        public Money $unitPrice,
        public int $quantity,
    ) {
    }

    public function total(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }
}
