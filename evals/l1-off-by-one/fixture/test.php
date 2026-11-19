<?php

declare(strict_types=1);

require __DIR__ . '/paginate.php';

$items = range(1, 10);
$cases = [
    [1, 3, [1, 2, 3]],
    [2, 3, [4, 5, 6]],
    [4, 3, [10]],
    [5, 3, []],
    [1, 20, $items],
];

$failed = 0;
foreach ($cases as [$page, $perPage, $expected]) {
    $actual = paginate($items, $page, $perPage);
    if ($actual !== $expected) {
        $failed++;
        echo "paginate(1..10, {$page}, {$perPage}) = " . json_encode($actual) . ', expected ' . json_encode($expected) . "\n";
    }
}

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
