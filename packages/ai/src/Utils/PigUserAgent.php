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
        return self::$value ??= 'pig (' . self::platform() . ' ' . php_uname('r') . '; ' . self::arch() . ')';
    }

    /** Node's `os.platform()`. */
    private static function platform(): string
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
    private static function arch(): string
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
