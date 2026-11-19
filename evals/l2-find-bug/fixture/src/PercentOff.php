<?php

declare(strict_types=1);

namespace Shop;

/** A discount of a whole percentage, rounded down to the cent. */
final readonly class PercentOff
{
    public function __construct(public int $percent)
    {
    }

    public function amountOff(Money $subtotal): Money
    {
        return new Money(intdiv($subtotal->cents * $this->percent, 100));
    }
}
