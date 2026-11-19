<?php

declare(strict_types=1);

use Shop\Cart;
use Shop\LineItem;
use Shop\Money;
use Shop\PercentOff;

require __DIR__ . '/../src/autoload.php';

$failed = 0;
$expect = static function (string $what, string $actual, string $expected) use (&$failed): void {
    if ($actual !== $expected) {
        $failed++;
        echo "{$what}: got {$actual}, expected {$expected}\n";
    }
};

$expect('empty cart', (new Cart())->total()->format(), '0.00');

$one = new Cart();
$one->add(new LineItem('pen', new Money(150), 2));
$expect('one line', $one->total()->format(), '3.00');

$three = new Cart();
$three->add(new LineItem('pen', new Money(150), 2));
$three->add(new LineItem('notebook', new Money(499), 1));
$three->add(new LineItem('stapler', new Money(1250), 1));
$expect('three lines', $three->subtotal()->format(), '20.49');

$three->applyDiscount(new PercentOff(10));
$expect('ten percent off', $three->total()->format(), '18.45');

$expect('line total', (new LineItem('cup', new Money(333), 3))->total()->format(), '9.99');

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
