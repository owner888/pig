<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;

/**
 * The two search tools `find` and `grep` are built on.
 *
 * Upstream requires the same two and **downloads them from GitHub** when they are
 * missing. That part is not ported: fetching and running a binary at run time is not
 * something this project does to someone's machine. So a missing tool is an error that
 * says how to install it, which is one command and a decision the person makes.
 *
 * Reimplementing them in PHP was considered and measured — a walk over a 100k-file tree
 * takes about 220ms against fd's ~40 — and rejected by the developer, because matching
 * upstream's search semantics exactly is worth more than saving an install step.
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
        'fd' => 'fd (https://github.com/sharkdp/fd)',
        'rg' => 'ripgrep (https://github.com/BurntSushi/ripgrep)',
    ];

    /** @var array<string, string|false> found paths, and the ones known to be missing */
    private static array $found = [];

    /** Where `fd` is, or an error saying how to get it. */
    public static function fd(): string
    {
        return self::require('fd');
    }

    /** Where `rg` is, or an error saying how to get it. */
    public static function ripgrep(): string
    {
        return self::require('rg');
    }

    public static function has(string $tool): bool
    {
        return self::locate($tool) !== false;
    }

    /** @internal Tests, which must not inherit a lookup done under a different PATH. */
    public static function forget(): void
    {
        self::$found = [];
    }

    private static function require(string $tool): string
    {
        $path = self::locate($tool);

        if ($path !== false) {
            return $path;
        }

        $install = self::INSTALL[$tool];
        $how = match (PHP_OS_FAMILY) {
            'Darwin' => $install['brew'],
            default => "{$install['apt']}  (or {$install['pacman']} on Arch)",
        };

        throw new AgentError(
            self::DESCRIPTION[$tool] . " is not installed, and this tool needs it.\nInstall it with: {$how}",
        );
    }

    /** @return string|false */
    private static function locate(string $tool): string|false
    {
        if (array_key_exists($tool, self::$found)) {
            return self::$found[$tool];
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
