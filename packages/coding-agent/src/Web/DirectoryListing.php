<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

/**
 * `/api/dirs`: a directory's subdirectories, for the page's working-directory picker — browsing
 * and completing a path before a new conversation starts there, as the terminal's `pig` simply
 * starts wherever it was run.
 *
 * Any directory this process can read: the page already has a terminal on this machine, so listing
 * folders gives it nothing it did not have. Hidden folders are left out unless asked for, and a
 * long listing is cut, so a home with ten thousand entries does not become a ten-thousand-row list.
 */
final class DirectoryListing
{
    /** More than anybody scrolls through in a picker. */
    public const int LIMIT = 500;

    /**
     * @param string $path as typed: absolute, `~` or `~/…`, or relative to `$base`; empty is `$base`
     * @return array{ok: true, path: string, parent: ?string, home: string, dirs: list<string>, truncated: bool}|array{ok: false, error: string}
     */
    public static function list(string $path, string $base, string $home, bool $hidden = false): array
    {
        $real = realpath(self::wanted($path, $base, $home));
        if ($real === false || !is_dir($real)) {
            return ['ok' => false, 'error' => "Not a directory: {$path}"];
        }

        if (!is_readable($real)) {
            return ['ok' => false, 'error' => "Cannot read {$real}"];
        }

        set_error_handler(static fn (): bool => true);
        try {
            $entries = scandir($real);
        } finally {
            restore_error_handler();
        }
        if ($entries === false) {
            return ['ok' => false, 'error' => "Cannot read {$real}"];
        }

        return self::of($real, $home, array_values(array_filter($entries, static fn (string $entry): bool => is_dir("{$real}/{$entry}"))), $hidden);
    }

    /** The directory `$path` names, as typed (`list()`), before it is resolved. */
    public static function wanted(string $path, string $base, string $home): string
    {
        $path = trim($path);

        return match (true) {
            $path === '' => $base,
            $path === '~' => $home,
            str_starts_with($path, '~/') => $home . substr($path, 1),
            str_starts_with($path, '/') => $path,
            default => rtrim($base, '/') . '/' . $path,
        };
    }

    /**
     * The answer for the directory `$real` (resolved), whose subdirectories are `$dirs`: in order,
     * the hidden ones left out unless asked for, cut at `LIMIT`. Also what another machine's
     * listing is answered with (`Sandbox\SshSandbox`).
     *
     * @param list<string> $dirs
     * @return array{ok: true, path: string, parent: ?string, home: string, dirs: list<string>, truncated: bool}
     */
    public static function of(string $real, string $home, array $dirs, bool $hidden): array
    {
        $dirs = array_filter($dirs, static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && ($hidden || !str_starts_with($entry, '.')));
        natcasesort($dirs);
        $dirs = array_values($dirs);

        return [
            'ok' => true,
            'path' => $real,
            'parent' => $real === '/' ? null : dirname($real),
            'home' => $home,
            'dirs' => array_slice($dirs, 0, self::LIMIT),
            'truncated' => count($dirs) > self::LIMIT,
        ];
    }
}
