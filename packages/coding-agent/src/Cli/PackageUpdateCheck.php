<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Packages\GitSource;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Packages\PackageSource;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Settings;
use Throwable;

/**
 * Checks for available extension/package updates asynchronously at startup,
 * matching upstream pi's `checkForPackageUpdates()`.
 */
final class PackageUpdateCheck
{
    /**
     * Check installed extensions for available updates.
     *
     * @return list<string> list of extension names with available updates
     */
    public function checkForUpdates(?string $cwd = null): array
    {
        if (getenv('PIG_OFFLINE') === '1' || getenv('PI_OFFLINE') === '1') {
            return [];
        }

        try {
            return [...$this->scanForUpdates($cwd), ...$this->packagesWithUpdates($cwd)];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Upstream's `checkForPackageUpdates()`: each configured git package whose `origin` has
     * moved past the checkout, by `git ls-remote`. A pinned package is never reported — its ref
     * is where it was asked to stay.
     *
     * @return list<string>
     */
    private function packagesWithUpdates(?string $cwd): array
    {
        $cwd ??= getcwd() ?: '.';
        $settings = Settings::load($cwd, projectTrusted: ProjectTrust::decision($cwd) ?? !ProjectTrust::hasResources($cwd));
        $manager = new PackageManager($cwd, $settings);
        $updates = [];

        foreach ($manager->listConfiguredPackages() as $package) {
            $source = PackageSource::parse($package['source']);

            if (!$source instanceof GitSource || $source->pinned() || $package['installedPath'] === null) {
                continue;
            }

            if ($manager->hasAvailableUpdate($package['installedPath'])) {
                $updates[] = $package['source'];
            }
        }

        return $updates;
    }

    /**
     * @return list<string>
     */
    private function scanForUpdates(?string $cwd = null): array
    {
        $userDir = Config::home() . '/extensions';
        $coreDir = SelfUpdate::coreExtensionsDir();

        if (!is_dir($userDir)) {
            return [];
        }

        $items = scandir($userDir) ?: [];
        $updates = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = "{$userDir}/{$item}";

            // 1. Core built-in extensions (check if user installed copy differs from core package)
            if (is_dir($path) && $coreDir !== null && is_dir("{$coreDir}/{$item}")) {
                if ($this->hasDirectoryChanges("{$coreDir}/{$item}", $path)) {
                    $updates[] = $item;
                    continue;
                }
            }

            // 2. Git-based extensions: check if git repo has remote tracking differences
            if (is_dir("{$path}/.git")) {
                // Quick check without blocking network
                if ($this->hasGitUpdates($path)) {
                    $updates[] = $item;
                    continue;
                }
            }
        }

        return $updates;
    }

    /**
     * Check if source directory has newer/different files than target directory.
     */
    private function hasDirectoryChanges(string $src, string $dst): bool
    {
        $items = scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = "{$src}/{$item}";
            $dstPath = "{$dst}/{$item}";

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath) || $this->hasDirectoryChanges($srcPath, $dstPath)) {
                    return true;
                }
            } else {
                if (!is_file($dstPath) || sha1_file($srcPath) !== sha1_file($dstPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasGitUpdates(string $repoPath): bool
    {
        return (new PackageManager(getcwd() ?: '.', Settings::inMemory()))->hasAvailableUpdate($repoPath);
    }
}
