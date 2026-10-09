<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * The part of `minimatch` a package pattern uses: `*` within one segment, `**` across segments,
 * `?`, `[…]` classes and `{a,b}` alternatives. `fnmatch()` has no `**`, which is the one thing a
 * manifest entry like `src/** /*.php` needs, so this is a small regex instead of the C call.
 */
final class Glob
{
    public static function isPattern(string $value): bool
    {
        return str_contains($value, '*') || str_contains($value, '?');
    }

    public static function matches(string $pattern, string $path): bool
    {
        return preg_match(self::regex($pattern), $path) === 1;
    }

    /** Every file under $root the pattern names, absolute, sorted; dot segments are never matched. */
    public static function expand(string $pattern, string $root): array
    {
        $regex = self::regex($pattern);
        $matches = [];

        foreach (self::walk($root) as $relative => $absolute) {
            if (preg_match($regex, $relative) === 1) {
                $matches[] = $absolute;
            }
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @return iterable<string, string> relative => absolute, files and directories, no dot segments */
    private static function walk(string $root, string $prefix = ''): iterable
    {
        $target = $root . ($prefix === '' ? '' : "/{$prefix}");

        if (!is_dir($target) || !is_readable($target)) {
            return;
        }

        $entries = scandir($target);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $relative = $prefix === '' ? $entry : "{$prefix}/{$entry}";
            $absolute = "{$root}/{$relative}";
            yield $relative => $absolute;

            if (is_dir($absolute) && !is_link($absolute)) {
                yield from self::walk($root, $relative);
            }
        }
    }

    private static function regex(string $pattern): string
    {
        $pattern = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
        $out = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    // `**/` matches zero or more whole segments; a bare `**` matches the rest.
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        $out .= '(?:.*/)?';
                    } else {
                        $out .= '.*';
                    }
                } else {
                    $out .= '[^/]*';
                }
            } elseif ($char === '?') {
                $out .= '[^/]';
            } elseif ($char === '[') {
                $close = strpos($pattern, ']', $i + 1);
                if ($close === false) {
                    $out .= '\\[';
                } else {
                    $class = substr($pattern, $i + 1, $close - $i - 1);
                    $out .= '[' . str_replace('\\', '\\\\', str_starts_with($class, '!') ? '^' . substr($class, 1) : $class) . ']';
                    $i = $close;
                }
            } elseif ($char === '{') {
                $close = strpos($pattern, '}', $i + 1);
                if ($close === false) {
                    $out .= '\\{';
                } else {
                    $alternatives = array_map(preg_quote(...), explode(',', substr($pattern, $i + 1, $close - $i - 1)));
                    $out .= '(?:' . implode('|', $alternatives) . ')';
                    $i = $close;
                }
            } else {
                $out .= preg_quote($char, '#');
            }
        }

        return "#^{$out}$#";
    }
}
