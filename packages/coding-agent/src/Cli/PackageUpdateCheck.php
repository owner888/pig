<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\CodingAgent\Config;
use Pig\Tui\Env;
use Throwable;

/**
 * Check for available extension package updates, matching upstream pi's checkForPackageUpdates().
 *
 * Scans configured `packages` in settings.json (e.g. `npm:pi-antigravity`), queries the npm registry
 * for the latest version, and reports any package that is behind.
 */
final class PackageUpdateCheck
{
    private const float TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClient $http = new HttpClient(self::TIMEOUT),
        private readonly string $npmRegistry = 'https://registry.npmjs.org/',
    ) {
    }

    /**
     * Check if any configured packages have newer versions on npm.
     *
     * @param string $cwd project working directory
     * @return list<string> list of package display names with available updates (e.g. ['pi-antigravity'])
     */
    public function checkForUpdates(string $cwd): array
    {
        if (Env::isSet('PIG_OFFLINE') || Env::isSet('PI_OFFLINE')) {
            return [];
        }

        $sources = $this->loadPackageSources($cwd);
        if ($sources === []) {
            return [];
        }

        $updates = [];

        foreach ($sources as $source) {
            $name = $this->normalizeNpmName($source);
            if ($name === null) {
                continue;
            }

            $installed = $this->getInstalledVersion($name, $cwd);
            if ($installed === null) {
                continue;
            }

            try {
                $latest = $this->getLatestNpmVersion($name);
            } catch (Throwable) {
                continue;
            }

            if ($latest !== null && version_compare($latest, $installed, '>')) {
                $updates[] = $name;
            }
        }

        return $updates;
    }

    /** @return list<string> */
    public function loadPackageSources(string $cwd): array
    {
        $home = Config::userHome();
        $candidates = [
            $home . '/.pi/agent/settings.json',
            $home . '/.pig/agent/settings.json',
            $cwd . '/.pi/settings.json',
            $cwd . '/.pig/settings.json',
        ];

        $packages = [];

        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
                continue;
            }

            foreach ($data['packages'] as $pkg) {
                if (is_string($pkg)) {
                    $packages[] = $pkg;
                } elseif (is_array($pkg) && isset($pkg['source']) && is_string($pkg['source'])) {
                    $packages[] = $pkg['source'];
                }
            }
        }

        return array_values(array_unique($packages));
    }

    private function normalizeNpmName(string $source): ?string
    {
        $source = trim($source);
        if (str_starts_with($source, 'npm:')) {
            $source = substr($source, 4);
        }

        // Drop version range or spec if any (e.g. pi-antigravity@^0.8.0 -> pi-antigravity)
        if (preg_match('/^(@?[^@]+)(?:@.+)?$/', $source, $m) === 1) {
            return trim($m[1]);
        }

        return $source !== '' ? $source : null;
    }

    public function getInstalledVersion(string $name, string $cwd): ?string
    {
        $home = Config::userHome();
        $searchDirs = [
            $home . '/.pig/agent/npm/node_modules/' . $name,
            $home . '/.pi/agent/npm/node_modules/' . $name,
            $cwd . '/.pig/npm/node_modules/' . $name,
            $cwd . '/.pi/npm/node_modules/' . $name,
            $cwd . '/node_modules/' . $name,
        ];

        foreach ($searchDirs as $dir) {
            $pkgJson = $dir . '/package.json';
            if (is_file($pkgJson) && is_readable($pkgJson)) {
                $raw = file_get_contents($pkgJson);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                        return $data['version'];
                    }
                }
            }
        }

        return null;
    }

    private function getLatestNpmVersion(string $name): ?string
    {
        $url = rtrim($this->npmRegistry, '/') . '/' . rawurlencode($name) . '/latest';
        $response = $this->http->follow(new Request('GET', $url, [
            'accept' => 'application/json',
        ]));

        if (!$response->isSuccessful()) {
            return null;
        }

        $body = $response->body->all();
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            return $data['version'];
        }

        return null;
    }
}
