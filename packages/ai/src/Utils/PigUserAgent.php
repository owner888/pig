<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `utils/pi-user-agent.ts`: `<product> (<platform> <release>; <arch>)`, the `User-Agent` every
 * built-in provider sends first, under the model's and the request's own headers (so Copilot's
 * `GitHubCopilotChat/…` and a subscription token's `claude-cli/…` still win).
 *
 * The three parts are Node's `os.platform()`, `os.release()` and `os.arch()`, spelt the way Node
 * spells them from what PHP reports: `darwin`/`linux`/`win32`, the kernel release, `x64`/`arm64`.
 * The product name is `pig`, not upstream's `pi` (开发者要求): pig's requests say what sent them.
 */
final class PigUserAgent
{
    private static ?string $value = null;

    public static function get(): string
    {
        return self::$value ??= 'pig (' . self::platform() . ' ' . self::release() . '; ' . self::arch() . ')';
    }

    /**
     * Node's `os.release()`. On Windows Node answers `major.minor.build` (`10.0.22631`, libuv's
     * `RtlGetVersion()`), where PHP's `php_uname('r')` stops at `10.0` and keeps the build in
     * `php_uname('v')` (`build 22631 (Windows 11 …)`); everywhere else both are `uname -r`.
     */
    public static function release(): string
    {
        return self::releaseFrom(PHP_OS_FAMILY, php_uname('r'), php_uname('v'));
    }

    /**
     * `release()` from what PHP reports — the OS family, `php_uname('r')` and `php_uname('v')` —
     * separate so the Windows arm can be tested on a machine that is not Windows.
     */
    public static function releaseFrom(string $osFamily, string $release, string $version): string
    {
        if ($osFamily === 'Windows' && preg_match('/\bbuild (\d+)/i', $version, $match) === 1) {
            return "{$release}.{$match[1]}";
        }

        return $release;
    }

    /** Node's `os.platform()`. */
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'win32',
            'Linux' => 'linux',
            'Solaris' => 'sunos',
            default => strtolower(PHP_OS),
        };
    }

    /** Node's `os.arch()`. */
    public static function arch(): string
    {
        $machine = strtolower(php_uname('m'));

        return match (true) {
            in_array($machine, ['x86_64', 'amd64'], true) => 'x64',
            in_array($machine, ['aarch64', 'arm64', 'armv8l'], true) => 'arm64',
            in_array($machine, ['i386', 'i486', 'i586', 'i686', 'x86'], true) => 'ia32',
            str_starts_with($machine, 'arm') => 'arm',
            $machine === 'ppc64le' || $machine === 'ppc64' => 'ppc64',
            default => $machine,
        };
    }
}
