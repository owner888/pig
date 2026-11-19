<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;

/** @return array{string, int} standard output and exit code */
function stats(string $root, string $args): array
{
    exec('php ' . escapeshellarg("{$root}/bin/stats.php") . " {$args} 2>/dev/null", $lines, $code);

    return [implode("\n", $lines), $code];
}

/** The decoded JSON with its numbers made comparable: 43 and 43.0 are the same sum. */
function normalized(string $json): mixed
{
    $data = json_decode($json, true);
    if (!is_array($data) || array_keys($data) !== ['count', 'sum', 'mean'] || !is_int($data['count'])) {
        return $data;
    }
    foreach (['sum', 'mean'] as $key) {
        if (is_int($data[$key]) || is_float($data[$key])) {
            $data[$key] = (float) $data[$key];
        }
    }

    return $data;
}

$check = static function (string $what, mixed $actual, mixed $expected) use (&$failed): void {
    if ($actual !== $expected) {
        $failed++;
        echo "{$what}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n";
    }
};

[$out, $code] = stats($root, escapeshellarg("{$root}/numbers.txt"));
$check('plain output', $out, "count: 4\nsum: 43\nmean: 10.75");
$check('plain exit', $code, 0);

foreach (['--json numbers.txt', 'numbers.txt --json'] as $args) {
    [$out, $code] = stats($root, str_replace('numbers.txt', escapeshellarg("{$root}/numbers.txt"), $args));
    $check("{$args}: exit", $code, 0);
    $check("{$args}: json", normalized($out), ['count' => 4, 'sum' => 43.0, 'mean' => 10.75]);
}

[$out, $code] = stats($root, '--json ' . escapeshellarg("{$root}/empty.txt"));
$check('empty json', normalized($out), ['count' => 0, 'sum' => 0.0, 'mean' => null]);

[, $code] = stats($root, '--json');
$check('--json without a file', $code, 2);

[, $code] = stats($root, '--yaml ' . escapeshellarg("{$root}/numbers.txt"));
$check('unknown option', $code, 2);

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
