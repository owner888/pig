<?php

declare(strict_types=1);

/** php -l over every source and test file. */

$root = dirname(__DIR__);
$files = [];

foreach (['packages', 'test', 'bin'] as $dir) {
    if (!is_dir("{$root}/{$dir}")) {
        continue;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/vendor/')) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = 0;

foreach ($files as $file) {
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo '  ' . implode("\n  ", $output) . "\n";
    }

    $output = [];
}

printf(
    "%s%d files linted, %d with syntax errors\033[0m\n",
    $failed === 0 ? "\033[32m" : "\033[31m",
    count($files),
    $failed,
);

exit($failed === 0 ? 0 : 1);
