<?php

declare(strict_types=1);

/*
 * Count, sum and mean of the numbers in a file, one per line.
 *
 *   php bin/stats.php <file>
 */

$file = $argv[1] ?? null;
if ($file === null) {
    fwrite(STDERR, "usage: php bin/stats.php <file>\n");
    exit(2);
}
if (!is_readable($file)) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(1);
}

$numbers = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $numbers[] = (float) $line;
}

$count = count($numbers);
$sum = array_sum($numbers);

echo "count: {$count}\n";
echo "sum: {$sum}\n";
echo 'mean: ' . ($count === 0 ? '-' : $sum / $count) . "\n";
