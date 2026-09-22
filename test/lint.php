<?php

declare(strict_types=1);

/** php -l over every source and test file. */

$root = dirname(__DIR__);
$files = [];

foreach (['packages', 'test', 'bin', 'examples'] as $dir) {
    if (!is_dir("{$root}/{$dir}")) {
        continue;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
        if (!$file->isFile() || str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }

        // An extension, or a `#!` line saying it is PHP anyway — `bin/pig` is the second
        // kind, and leaving an executable unlinted is leaving the entry point unlinted.
        if ($file->getExtension() === 'php' || str_starts_with((string) file_get_contents($file->getPathname(), false, null, 0, 20), '#!/usr/bin/env php')) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = 0;

/**
 * Functions newer than the declared floor in composer.json.
 *
 * php -l parses; it does not resolve function names, so a call to one of these sails
 * through the lint and fails at run time only on the line that happens to reach it.
 * Syntax above the floor is caught by linting with the floor's own php binary; this
 * covers the other half.
 */
$tooNew = [
    // All 8.4. Raise the floor and this list shrinks.
    'array_find', 'array_find_key', 'array_any', 'array_all',
    'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
    'fpow', 'request_parse_body', 'http_get_last_response_headers',
];

foreach ($files as $file) {
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo '  ' . implode("\n  ", $output) . "\n";
    }

    $output = [];

    $source = (string) file_get_contents($file);

    foreach ($tooNew as $function) {
        if (preg_match('/(?<![\w$>])' . $function . '\s*\(/', $source) === 1) {
            $failed++;
            echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
            echo "  {$function}() is newer than the floor in composer.json\n";
        }
    }

    // `@` hides every other thing that could have gone wrong in the same call, and the
    // one it was reached for is always the one you already knew about.
    if (preg_match('/(?<![\'"\w])@[a-z_]\w*\s*\(/i', (string) preg_replace('/(?s)\/\*.*?\*\/|\/\/[^\n]*|\'[^\']*\'|"[^"]*"/', '', $source), $match) === 1) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo "  error suppression: {$match[0]}\n";
    }
}

printf(
    "%s%d files linted, %d with syntax errors\033[0m\n",
    $failed === 0 ? "\033[32m" : "\033[31m",
    count($files),
    $failed,
);

exit($failed === 0 ? 0 : 1);
