<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;

/**
 * The two search tools `find` and `grep` are built on.
 *
 * Looked for in the order upstream uses: pig's own `tools/` directory first, then the
 * PATH, and only then fetched from GitHub — which is why the agent works on a machine
 * where `which fd` finds nothing. `ToolInstaller` does the fetching and says what it is
 * doing while it happens.
 *
 * Reimplementing them in PHP was considered and measured — a walk over a 100k-file tree
 * takes about 220ms against fd's ~40 — and rejected by the developer, because matching
 * upstream's search semantics exactly is worth more than the saved dependency.
 */
final class ExternalTool
{
    /**
     * Names to look for, in order. Debian packages fd as `fdfind`, because `fd` was
     * already taken by something else.
     *
     * @var array<string, list<string>>
     */
    private const array NAMES = [
        'fd' => ['fd', 'fdfind'],
        'rg' => ['rg'],
    ];

    /** @var array<string, string> how to install each one, by package manager */
    private const array INSTALL = [
        'fd' => ['brew' => 'brew install fd', 'apt' => 'apt install fd-find', 'pacman' => 'pacman -S fd'],
        'rg' => ['brew' => 'brew install ripgrep', 'apt' => 'apt install ripgrep', 'pacman' => 'pacman -S ripgrep'],
    ];

    private const array DESCRIPTION = [
        'fd' => 'fd',
        'rg' => 'ripgrep (rg)',
    ];

    private const array HOMEPAGE = [
        'fd' => 'https://github.com/sharkdp/fd',
        'rg' => 'https://github.com/BurntSushi/ripgrep',
    ];

    /** @var array<string, string|false> found paths, and the ones known to be missing */
    private static array $found = [];

    /**
     * Where `fd` is, fetching it if need be.
     *
     * @param callable(string): void|null $say progress, for whoever is watching
     */
    public static function fd(?callable $say = null): string
    {
        return self::require('fd', $say);
    }

    /** @param callable(string): void|null $say */
    public static function ripgrep(?callable $say = null): string
    {
        return self::require('rg', $say);
    }

    /** Whether it is already here — without downloading anything to find out. */
    public static function has(string $tool): bool
    {
        return self::locate($tool) !== false;
    }

    /** @internal Tests, which must not inherit a lookup done under a different PATH. */
    public static function forget(): void
    {
        self::$found = [];
    }

    /** @param callable(string): void|null $say */
    private static function require(string $tool, ?callable $say = null): string
    {
        $path = self::locate($tool);

        if ($path !== false) {
            return $path;
        }

        if (ToolInstaller::enabled()) {
            $installed = ToolInstaller::install($tool, $say);
            self::$found[$tool] = $installed;

            return $installed;
        }

        $install = self::INSTALL[$tool];
        $how = match (PHP_OS_FAMILY) {
            'Darwin' => $install['brew'],
            default => "{$install['apt']}  (or {$install['pacman']} on Arch)",
        };

        // Only reachable with PIG_OFFLINE set, so say which of the two things to do.
        // The command comes first and on the first line: anything that shows a tool
        // error as a single truncated line — which a compact UI will — must still show
        // the person what to do about it.
        throw new AgentError(
            self::DESCRIPTION[$tool] . " is not installed. Install it with: {$how}\n"
                . 'Or unset PIG_OFFLINE to let pig download it. See ' . self::HOMEPAGE[$tool],
        );
    }

    /** @return string|false */
    private static function locate(string $tool): string|false
    {
        if (array_key_exists($tool, self::$found)) {
            return self::$found[$tool];
        }

        if (!isset(self::NAMES[$tool])) {
            return self::$found[$tool] = false;
        }

        // pig's own copy first, so a downloaded one is used even if something else on
        // the PATH later shadows it.
        $downloaded = ToolInstaller::path($tool);

        if (is_file($downloaded) && is_executable($downloaded)) {
            return self::$found[$tool] = $downloaded;
        }

        $path = getenv('PATH');
        $directories = $path === false ? [] : explode(PATH_SEPARATOR, $path);

        foreach (self::NAMES[$tool] as $name) {
            foreach ($directories as $directory) {
                $candidate = rtrim($directory, '/') . '/' . $name;

                // Looked up here rather than by running `which`: a process launch per
                // tool call is real time, and this is a handful of stat() calls.
                if (is_file($candidate) && is_executable($candidate)) {
                    return self::$found[$tool] = $candidate;
                }
            }
        }

        return self::$found[$tool] = false;
    }
}
