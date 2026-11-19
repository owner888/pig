<?php

declare(strict_types=1);

namespace Shop;

final class Cart
{
    /** @var list<LineItem> */
    private array $items = [];

    private ?PercentOff $discount = null;

    public function add(LineItem $item): void
    {
        $this->items[] = $item;
    }

    public function applyDiscount(PercentOff $discount): void
    {
        $this->discount = $discount;
    }

    public function subtotal(): Money
    {
        if ($this->items === []) {
            return Money::zero();
        }

        return array_reduce(
            $this->items,
            static fn (Money $sum, LineItem $item): Money => $sum->add($item->total()),
            $this->items[0]->total(),
        );
    }

    public function total(): Money
    {
        $subtotal = $this->subtotal();

        return $this->discount === null
            ? $subtotal
            : $subtotal->subtract($this->discount->amountOff($subtotal));
    }
}
