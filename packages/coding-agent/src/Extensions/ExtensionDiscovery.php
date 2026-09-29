<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Pig\CodingAgent\Config;

/**
 * Discover extensions available to this session.
 *
 * Upstream pi loads TypeScript extensions via jiti from:
 * 1. Global extensions: ~/.pig/extensions, and ~/.pi/agent/extensions
 * 2. Project extensions: <cwd>/.pig/extensions, and <cwd>/.pi/extensions
 * 3. Declared packages in settings.json (e.g. npm:pi-antigravity)
 *
 * In pig, native PHP hooks (~/.pig/hooks) also act as extensions.
 * This class discovers both PHP hooks and pi-compatible extensions
 * so the startup screen can display what is loaded.
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
            Config::piHome() . '/extensions',
            $cwd . '/.pi/extensions',
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

                if (is_file($path)) {
                    $labels[] = $entry;
                } elseif (is_dir($path)) {
                    $pkgJson = $path . '/package.json';
                    if (is_file($pkgJson) && is_readable($pkgJson)) {
                        $pkg = json_decode((string) file_get_contents($pkgJson), true);
                        if (is_array($pkg) && !empty($pkg['pi']['extensions']) && is_array($pkg['pi']['extensions'])) {
                            foreach ($pkg['pi']['extensions'] as $ext) {
                                if (is_string($ext)) {
                                    $labels[] = $entry . '/' . basename($ext);
                                }
                            }
                            continue;
                        }
                    }
                    $labels[] = $entry;
                }
            }
        }

        // Check packages configured in settings files
        $settingsFiles = [
            Config::home() . '/settings.json',
            $cwd . '/.pig/settings.json',
            Config::piHome() . '/settings.json',
            $cwd . '/.pi/settings.json',
        ];

        foreach ($settingsFiles as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || empty($data['packages']) || !is_array($data['packages'])) {
                continue;
            }

            foreach ($data['packages'] as $pkg) {
                $name = is_string($pkg) ? $pkg : ($pkg['source'] ?? '');
                if (!is_string($name) || $name === '') {
                    continue;
                }

                if (str_starts_with($name, 'npm:')) {
                    $pkgName = substr($name, 4);
                    $pkgDir = Config::piHome() . '/npm/node_modules/' . $pkgName;
                    if (is_dir($pkgDir)) {
                        $pkgJson = $pkgDir . '/package.json';
                        if (is_file($pkgJson) && is_readable($pkgJson)) {
                            $manifest = json_decode((string) file_get_contents($pkgJson), true);
                            if (is_array($manifest) && !empty($manifest['pi']['extensions']) && is_array($manifest['pi']['extensions'])) {
                                foreach ($manifest['pi']['extensions'] as $ext) {
                                    if (is_string($ext)) {
                                        $dirPart = trim(dirname($ext), './');
                                        $label = ($dirPart === '.' || $dirPart === '') ? $pkgName : "{$pkgName}:{$dirPart}";
                                        $labels[] = $label;
                                    }
                                }
                                continue;
                            }
                        }
                    }
                    $labels[] = $pkgName;
                } else {
                    $labels[] = basename($name);
                }
            }
        }

        foreach ($extraPaths as $extra) {
            $labels[] = basename($extra);
        }

        $labels = array_values(array_unique(array_filter($labels, static fn (string $s): bool => trim($s) !== '')));
        sort($labels);

        return $labels;
    }
}
