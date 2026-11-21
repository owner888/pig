<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

/**
 * The labels of the extensions that loaded, for [Extensions] — upstream's
 * `resourceLoader.getExtensions().extensions`, less the `hidden` built-ins.
 *
 * Only what loaded: an extension in `~/.pig/agent/extensions` that a setting or `pig web`'s custom
 * session left out is not named, as it once was when every file in the folders was listed.
 */
final class ExtensionDiscovery
{
    /**
     * @param list<string> $paths the loaded extensions' entry files (`ExtensionRunner::paths()`)
     * @return list<string> sorted unique labels: a file by its name, a folder's `index.php` by the folder
     */
    public static function discover(array $paths): array
    {
        $labels = [];

        foreach ($paths as $path) {
            if (BuiltinExtensions::nameOf($path) !== null) {
                continue;
            }

            $labels[] = basename($path) === 'index.php' ? basename(dirname($path)) : basename($path);
        }

        $labels = array_values(array_unique(array_filter($labels, static fn (string $s): bool => trim($s) !== '')));
        sort($labels);

        return $labels;
    }
}
