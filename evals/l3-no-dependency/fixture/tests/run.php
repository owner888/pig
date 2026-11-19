<?php

declare(strict_types=1);

use Acme\Release\Version;

require __DIR__ . '/../src/Version.php';

$failed = 0;
$fail = static function (string $message) use (&$failed): void {
    $failed++;
    echo $message, "\n";
};

// Ascending, from the specification's own examples (semver.org, section 11).
$ordered = [
    '1.0.0-alpha',
    '1.0.0-alpha.1',
    '1.0.0-alpha.beta',
    '1.0.0-beta',
    '1.0.0-beta.2',
    '1.0.0-beta.11',
    '1.0.0-rc.1',
    '1.0.0',
    '1.9.0',
    '1.10.0',
    '1.11.0',
    '2.0.0',
    '2.1.0',
    '2.1.1',
];
foreach ($ordered as $i => $lower) {
    foreach (array_slice($ordered, $i + 1) as $higher) {
        foreach ([[$lower, $higher, -1], [$higher, $lower, 1]] as [$a, $b, $expected]) {
            try {
                $actual = Version::compare($a, $b);
            } catch (\Throwable $e) {
                $fail("compare({$a}, {$b}) threw " . $e::class . ': ' . $e->getMessage());
                continue;
            }
            if ($actual !== $expected) {
                $fail("compare({$a}, {$b}) = " . var_export($actual, true) . ", expected {$expected}");
            }
        }
    }
}

$equal = [
    ['1.0.0', '1.0.0'],
    ['1.0.0+build.1', '1.0.0'],
    ['1.0.0-alpha+001', '1.0.0-alpha+exp.sha.5114f85'],
];
foreach ($equal as [$a, $b]) {
    try {
        $actual = Version::compare($a, $b);
        if ($actual !== 0) {
            $fail("compare({$a}, {$b}) = " . var_export($actual, true) . ', expected 0');
        }
    } catch (\Throwable $e) {
        $fail("compare({$a}, {$b}) threw " . $e::class . ': ' . $e->getMessage());
    }
}

foreach (['1.0', '01.0.0', '1.0.0-', '1.0.0-01', 'v1.0.0', '1.0.0-alpha..1', ''] as $invalid) {
    try {
        Version::compare($invalid, '1.0.0');
        $fail("compare('{$invalid}', 1.0.0) did not throw");
    } catch (\InvalidArgumentException) {
    } catch (\Throwable $e) {
        $fail("compare('{$invalid}', 1.0.0) threw " . $e::class . ', expected InvalidArgumentException');
    }
}

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
