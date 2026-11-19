<?php

declare(strict_types=1);

use Orders\OrderParser;
use Orders\Report;

require __DIR__ . '/../src/OrderParser.php';
require __DIR__ . '/../src/Report.php';

$failed = 0;
$check = static function (string $what, mixed $actual, mixed $expected) use (&$failed): void {
    if ($actual !== $expected) {
        $failed++;
        echo "{$what}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n";
    }
};

$csv = (string) file_get_contents(__DIR__ . '/../orders.csv');

$check('parsed rows', OrderParser::parse($csv), [
    ['sku' => 'A-100', 'quantity' => 2, 'price' => 9.5],
    ['sku' => 'B-200', 'quantity' => 1, 'price' => 120.0],
    ['sku' => 'C-300', 'quantity' => 10, 'price' => 0.25],
]);

$check('windows line ends', OrderParser::parse(str_replace("\n", "\r\n", $csv)), OrderParser::parse($csv));

$check('report', Report::render(OrderParser::parse($csv)), implode("\n", [
    'A-100       2 x    9.50',
    'B-200       1 x  120.00',
    'C-300      10 x    0.25',
    'TOTAL            141.50',
    '',
]));

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
