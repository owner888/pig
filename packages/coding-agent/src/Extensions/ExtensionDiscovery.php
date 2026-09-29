<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Pig\CodingAgent\Config;

/**
 * Discover PHP extensions and hooks available to pig.
 *
 * Pig is a pure PHP runtime and does not load or mix JavaScript/TypeScript
 * files from pi's directory (~/.pi/agent/extensions).
 *
 * It discovers:
 * 1. Global pig extensions: ~/.pig/extensions (*.php)
 * 2. Project-local pig extensions: <cwd>/.pig/extensions (*.php)
 * 3. Loaded PHP hooks
 */
final class ExtensionDiscovery
{
    /**
     * Discover extension labels for display.
     *
     * @param list<string> $extraPaths additional paths from settings or hooks
     * @return list<string> sorted unique compact labels
     */
    public static function discover(string $cwd, array $extraPaths = []): array
    {
        $cwd = rtrim($cwd, '/');
        $dirs = [
            Config::home() . '/extensions',
            $cwd . '/.pig/extensions',
            $cwd . '/extensions',
        ];

        $labels = [];
        $seen = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '' || $entry[0] === '.') {
                    continue;
                }

                $path = $dir . '/' . $entry;
                $real = realpath($path) ?: $path;
                if (isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;

                if (is_file($path) && str_ends_with($entry, '.php')) {
                    $labels[] = $entry;
                } elseif (is_dir($path) && is_file($path . '/index.php')) {
                    $labels[] = $entry;
                }
            }
        }

        foreach ($extraPaths as $extra) {
            $base = basename($extra);
            if ($base === 'index.php') {
                $labels[] = basename(dirname($extra));
            } else {
                $labels[] = $base;
            }
        }

        $labels = array_values(array_unique(array_filter($labels, static fn (string $s): bool => trim($s) !== '')));
        sort($labels);

        return $labels;
    }
}
