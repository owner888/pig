<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\CodingAgent\Config;
use Pig\Tui\Process;

/**
 * Fetching `fd` and `rg` when the machine has not got them.
 *
 * Ported from upstream's `ensureTool()`, which is why the agent works on a machine where
 * `which fd` finds nothing. The binaries land in `~/.pig/tools/`, not on the PATH, so
 * nothing outside pig is touched.
 *
 * Two things are added on top of upstream. `PIG_OFFLINE=1` turns it off, for a machine
 * that should not be reaching GitHub at all — upstream has the same switch at HEAD. And
 * the download says what it is fetching and from where before it starts, because pulling
 * an executable off the internet is not something that should happen quietly.
 *
 * Nothing verifies a signature, because neither project publishes one. That is worth
 * knowing: this trusts GitHub and the two repositories named below, no more and no less
 * than upstream does.
 */
final class ToolInstaller
{
    /** @var array<string, array{repo: string, binary: string, tagPrefix: string, archive: string}> */
    private const array TOOLS = [
        'fd' => ['repo' => 'sharkdp/fd', 'binary' => 'fd', 'tagPrefix' => 'v', 'archive' => 'fd-v{version}-{arch}-{platform}'],
        'rg' => ['repo' => 'BurntSushi/ripgrep', 'binary' => 'rg', 'tagPrefix' => '', 'archive' => 'ripgrep-{version}-{arch}-{platform}'],
    ];

    private const float API_TIMEOUT = 10.0;
    private const float DOWNLOAD_TIMEOUT = 120.0;

    /** Where a downloaded binary lives, whether or not it is there yet. */
    public static function path(string $tool): string
    {
        $binary = self::TOOLS[$tool]['binary'] ?? $tool;

        return self::directory() . '/' . $binary . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    public static function directory(): string
    {
        return Config::home() . '/tools';
    }

    /** Whether downloading is allowed at all. */
    public static function enabled(): bool
    {
        $offline = strtolower((string) getenv('PIG_OFFLINE'));

        return !in_array($offline, ['1', 'true', 'yes'], true);
    }

    /**
     * Download $tool and return where it ended up.
     *
     * @param callable(string): void|null $say progress, for whoever is watching
     */
    public static function install(string $tool, ?callable $say = null): string
    {
        $config = self::TOOLS[$tool] ?? throw new AgentError("Nothing known about '{$tool}'");
        $target = self::target();

        if ($target === null) {
            throw new AgentError(
                'No published build of ' . $config['repo'] . ' for ' . PHP_OS_FAMILY . '/' . php_uname('m')
                    . '. Install it with your package manager instead.',
            );
        }

        $version = self::latestVersion($config['repo']);
        $name = strtr($config['archive'], ['{version}' => $version, '{arch}' => $target[0], '{platform}' => $target[1]])
            . $target[2];
        $url = "https://github.com/{$config['repo']}/releases/download/{$config['tagPrefix']}{$version}/{$name}";

        // Said before the fetch, not after: pulling an executable off the internet is
        // not something that should happen quietly.
        if ($say !== null) {
            $say("Downloading {$tool} {$version} from {$url}");
        }

        $directory = self::directory();

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory}");
        }

        $archive = $directory . '/' . $name;
        file_put_contents($archive, self::fetch($url, self::DOWNLOAD_TIMEOUT));

        try {
            $path = self::extract($archive, $directory, $config['binary'], $name);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }

        if ($say !== null) {
            $say("Installed {$tool} to {$path}");
        }

        return $path;
    }

    /**
     * The archive naming for this machine.
     *
     * @return array{0: string, 1: string, 2: string}|null arch, platform triple, extension
     */
    private static function target(): ?array
    {
        $machine = php_uname('m');
        $arch = match (true) {
            $machine === 'arm64', $machine === 'aarch64' => 'aarch64',
            $machine === 'x86_64', $machine === 'amd64' => 'x86_64',
            default => null,
        };

        if ($arch === null) {
            return null;
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => [$arch, 'apple-darwin', '.tar.gz'],
            // musl, so the binary does not depend on the host's glibc version.
            'Linux' => [$arch, 'unknown-linux-musl', '.tar.gz'],
            'Windows' => [$arch, 'pc-windows-msvc', '.zip'],
            default => null,
        };
    }

    private static function latestVersion(string $repo): string
    {
        $json = self::fetch("https://api.github.com/repos/{$repo}/releases/latest", self::API_TIMEOUT);
        $data = json_decode($json, true);
        $tag = is_array($data) ? ($data['tag_name'] ?? null) : null;

        if (!is_string($tag) || $tag === '') {
            throw new AgentError("GitHub did not say what the latest release of {$repo} is");
        }

        return ltrim($tag, 'v');
    }

    /** Over pig's own HTTP client, following the redirect GitHub answers with. */
    private static function fetch(string $url, float $timeout): string
    {
        $response = (new HttpClient($timeout))->follow(new Request('GET', $url, [
            // GitHub refuses an API request with no user agent.
            'User-Agent' => 'pig',
            'Accept' => '*/*',
        ]));

        if (!$response->isSuccessful()) {
            $response->body->close();

            throw new AgentError("GET {$url} answered {$response->status}");
        }

        return $response->body->all();
    }

    /**
     * Unpack the archive and put the binary where it belongs.
     *
     * `tar` and `unzip` rather than PHP: ext-zip is not always built, PharData does not
     * read every tar variant, and both of these are on any machine that has a shell.
     */
    private static function extract(string $archive, string $directory, string $binary, string $name): string
    {
        $temporary = $directory . '/unpack-' . bin2hex(random_bytes(4));
        mkdir($temporary, 0o755, true);

        try {
            $unpacked = str_ends_with($name, '.zip')
                ? Process::capture(['unzip', '-o', '-q', $archive, '-d', $temporary], 60.0)
                : Process::capture(['tar', 'xzf', $archive, '-C', $temporary], 60.0);

            if ($unpacked === null) {
                throw new AgentError("Could not unpack {$archive}");
            }

            $found = self::findBinary($temporary, $binary);

            if ($found === null) {
                throw new AgentError("No {$binary} inside {$name}");
            }

            $path = $directory . '/' . basename($found);

            if (!rename($found, $path)) {
                throw new AgentError("Could not move {$binary} into {$directory}");
            }

            chmod($path, 0o755);

            return $path;
        } finally {
            self::remove($temporary);
        }
    }

    /** The binary is one directory down in both archives, but that is not promised. */
    private static function findBinary(string $directory, string $binary): ?string
    {
        foreach ([$binary, $binary . '.exe'] as $name) {
            foreach ([$directory . '/' . $name, ...(glob($directory . '/*/' . $name) ?: [])] as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
