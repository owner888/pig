<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/**
 * pig's own version, read from `composer.json`.
 *
 * **One source, and the reason is the update check.** Packagist derives a package's version from
 * its git tag, and `Cli\UpdateCheck` compares what is running against what Packagist answers — so
 * a number written down twice is a number that can disagree, and the way it disagrees is the worst
 * one available: a copy installed from tag 0.2.0 whose constant still said 0.1.0 would report
 * itself as out of date for ever, on every start, with no way to make it stop.
 *
 * So `composer.json` holds it and this reads it. Bumping it belongs in the commit that carries the
 * tag, which `CHANGELOG.md` says at the top for whoever does the release.
 *
 * The same `dirname(__DIR__, 3)` as `Changelog::path()`, and for the same reason: both files sit at
 * the root of the repository beside the README, and one expression for "where the repository
 * starts" is better than two that can drift apart.
 */
final class Version
{
    private static ?string $current = null;

    /**
     * The version this build is.
     *
     * **A missing or unreadable `composer.json` throws**, rather than answering `0.0.0` or
     * `unknown`. Every other loader in this repository names a file it cannot use instead of
     * carrying on — and here the alternative is worse than a missing feature, because a made-up
     * version is a number the update check would compare against Packagist and act on.
     */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $path = self::path();

        if (!is_file($path) || !is_readable($path)) {
            throw new CodingAgentError("Cannot read pig's own version: {$path} is not there.");
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (!is_array($manifest) || !is_string($manifest['version'] ?? null) || $manifest['version'] === '') {
            throw new CodingAgentError("Cannot read pig's own version: {$path} has no \"version\".");
        }

        return self::$current = $manifest['version'];
    }

    /** Where the manifest is: the root of the repository, beside `CHANGELOG.md`. */
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/composer.json';
    }

    /** Test seam — a process has one version for its whole life. */
    public static function forget(): void
    {
        self::$current = null;
    }
}
