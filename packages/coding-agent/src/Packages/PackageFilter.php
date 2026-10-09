<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * The `!glob` / `+path` / `-path` patterns a settings entry or a manifest narrows a list with —
 * upstream's `applyPatterns()`, `applyAutoloadDisabledPatterns()` and the matchers under them.
 *
 * A pattern is tried against the path relative to the base directory, the file's name, and the
 * absolute path; a `SKILL.md` also answers to its directory under the same three spellings, so
 * `skills/review` names the skill and not only its file.
 */
final class PackageFilter
{
    /** Upstream's `isOverridePattern()`. */
    public static function isOverride(string $pattern): bool
    {
        return str_starts_with($pattern, '!') || str_starts_with($pattern, '+') || str_starts_with($pattern, '-');
    }

    /** Upstream's `isPattern()`: an override, or a glob. */
    public static function isPattern(string $entry): bool
    {
        return self::isOverride($entry) || Glob::isPattern($entry);
    }

    /**
     * Upstream's `applyPatterns()`: plain patterns include (all when there are none), `!` excludes,
     * `+` adds one exact path back whatever excluded it, `-` takes one exact path out whatever
     * included it.
     *
     * @param list<string> $allPaths
     * @param list<string> $patterns
     * @return list<string> the enabled paths, in $allPaths' order
     */
    public static function apply(array $allPaths, array $patterns, string $baseDir): array
    {
        $includes = $excludes = $forceIncludes = $forceExcludes = [];

        foreach ($patterns as $pattern) {
            match (true) {
                str_starts_with($pattern, '+') => $forceIncludes[] = substr($pattern, 1),
                str_starts_with($pattern, '-') => $forceExcludes[] = substr($pattern, 1),
                str_starts_with($pattern, '!') => $excludes[] = substr($pattern, 1),
                default => $includes[] = $pattern,
            };
        }

        $enabled = [];

        foreach ($allPaths as $path) {
            $on = $includes === [] || self::matchesAny($path, $includes, $baseDir);

            if ($on && $excludes !== [] && self::matchesAny($path, $excludes, $baseDir)) {
                $on = false;
            }

            if (!$on && $forceIncludes !== [] && self::matchesAnyExact($path, $forceIncludes, $baseDir)) {
                $on = true;
            }

            if ($on && $forceExcludes !== [] && self::matchesAnyExact($path, $forceExcludes, $baseDir)) {
                $on = false;
            }

            if ($on) {
                $enabled[] = $path;
            }
        }

        return $enabled;
    }

    /**
     * Upstream's `applyAutoloadDisabledPatterns()`: for a project entry that is a *delta* over the
     * user's (`autoload: false`), only the paths a pattern names get a verdict; the rest keep the
     * user's. Later patterns win over earlier ones.
     *
     * @param list<string> $allPaths
     * @param list<string> $patterns
     * @return array<string, bool> path => enabled, only for the paths named
     */
    public static function applyDelta(array $allPaths, array $patterns, string $baseDir): array
    {
        $verdicts = [];

        foreach ($patterns as $pattern) {
            $exact = str_starts_with($pattern, '+') || str_starts_with($pattern, '-');
            $target = self::isOverride($pattern) ? substr($pattern, 1) : $pattern;
            $enabled = !str_starts_with($pattern, '-') && !str_starts_with($pattern, '!');

            foreach ($allPaths as $path) {
                if ($exact ? self::matchesAnyExact($path, [$target], $baseDir) : self::matchesAny($path, [$target], $baseDir)) {
                    $verdicts[$path] = $enabled;
                }
            }
        }

        return $verdicts;
    }

    /**
     * Upstream's `isEnabledByOverrides()`: a single path against the override patterns of a
     * top-level setting — on unless `!` matches it, `+` puts it back, `-` takes it out.
     *
     * @param list<string> $patterns
     */
    public static function isEnabledByOverrides(string $path, array $patterns, string $baseDir): bool
    {
        $overrides = array_values(array_filter($patterns, self::isOverride(...)));
        $excludes = self::stripped($overrides, '!');
        $forceIncludes = self::stripped($overrides, '+');
        $forceExcludes = self::stripped($overrides, '-');

        $enabled = true;

        if ($excludes !== [] && self::matchesAny($path, $excludes, $baseDir)) {
            $enabled = false;
        }

        if ($forceIncludes !== [] && self::matchesAnyExact($path, $forceIncludes, $baseDir)) {
            $enabled = true;
        }

        if ($forceExcludes !== [] && self::matchesAnyExact($path, $forceExcludes, $baseDir)) {
            $enabled = false;
        }

        return $enabled;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(string $path, array $patterns, string $baseDir): bool
    {
        foreach (self::spellings($path, $baseDir) as $candidate) {
            foreach ($patterns as $pattern) {
                if (Glob::matches($pattern, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    public static function matchesAnyExact(string $path, array $patterns, string $baseDir): bool
    {
        if ($patterns === []) {
            return false;
        }

        $spellings = self::spellings($path, $baseDir, exact: true);

        foreach ($patterns as $pattern) {
            $normalized = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;

            if (in_array($normalized, $spellings, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The forms a pattern may name a file by: relative to the base, its bare name (globs only), and
     * absolute — and for a `SKILL.md`, the same for its directory.
     *
     * @return list<string>
     */
    private static function spellings(string $path, string $baseDir, bool $exact = false): array
    {
        $relative = self::relative($path, $baseDir);
        $forms = $exact ? [$relative, $path] : [$relative, basename($path), $path];

        if (basename($path) === 'SKILL.md') {
            $dir = dirname($path);
            $forms = [...$forms, self::relative($dir, $baseDir), ...($exact ? [] : [basename($dir)]), $dir];
        }

        return $forms;
    }

    private static function relative(string $path, string $baseDir): string
    {
        $base = rtrim($baseDir, '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function stripped(array $patterns, string $prefix): array
    {
        $out = [];

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, $prefix)) {
                $out[] = substr($pattern, 1);
            }
        }

        return $out;
    }
}
