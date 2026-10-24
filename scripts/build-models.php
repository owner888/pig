<?php

declare(strict_types=1);

/**
 * Writes the built-in model catalogue — `packages/ai/data/models/<provider>.json`, in pi's model
 * format, and `providers.json`, their order — from `BuiltinCatalog`, the generated rows and the
 * rules upstream's generator applies to them.
 *
 * `generate-models.php` runs this after it rewrites the rows; run it by hand after editing
 * `BuiltinCatalog.php`. `ModelsCatalogueTest` fails while the two disagree.
 *
 *     php scripts/build-models.php
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/BuiltinCatalog.php';

use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Scripts\BuiltinCatalog;

$files = BuiltinCatalog::files();
$dir = Models::CATALOGUE_DIR;

if (!is_dir($dir) && !mkdir($dir, 0o777, true)) {
    fwrite(STDERR, "could not create {$dir}\n");

    exit(1);
}

foreach (glob($dir . '/*.json') ?: [] as $stale) {
    if (!array_key_exists(basename($stale), $files)) {
        unlink($stale);
    }
}

foreach ($files as $name => $text) {
    if (file_put_contents("{$dir}/{$name}", $text) === false) {
        fwrite(STDERR, "could not write {$dir}/{$name}\n");

        exit(1);
    }
}

printf("written: %d files in %s\n", count($files), realpath($dir));
