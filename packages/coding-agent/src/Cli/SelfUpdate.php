<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Version;
use Pig\Tui\Style;

/**
 * Self-update CLI command (`pig update`), matching upstream pi's `pi update`.
 *
 * Updates pig to the latest release:
 * - In a Composer global installation: executes `composer global update pigagent/pig`.
 * - In a git checkout / development environment: executes `git pull`.
 * - With `--extensions`: updates installed extensions (git pull, composer, and core built-ins).
 * - With `--models`: refreshes model catalog tables.
 */
final class SelfUpdate
{
    public const string CHANGELOG_URL = 'https://pigagent.dev/changelog';

    /**
     * @param (callable(list<string>, ?string=): int)|null $runner execution handler for commands, injectable for tests
     */
    public function __construct(
        private readonly mixed $runner = null,
    ) {
    }

    /**
     * Execute the update command.
     *
     * @param list<string> $args CLI arguments following `pig update`
     * @return int exit status code (0 for success)
     */
    public function run(array $args = []): int
    {
        if (in_array('-h', $args, true) || in_array('--help', $args, true)) {
            $this->printHelp();

            return 0;
        }

        $refreshModels = in_array('--models', $args, true);
        $updateExtensions = in_array('--extensions', $args, true);
        $updateSelf = in_array('--self', $args, true);

        // Default behavior matching upstream pi: update both self and extensions when no flags given
        if (!$refreshModels && !$updateExtensions && !$updateSelf) {
            $updateSelf = true;
            $updateExtensions = true;
        }

        $status = 0;

        if ($updateSelf) {
            $status = $this->updatePig();
            if ($status !== 0) {
                return $status;
            }
        }

        if ($updateExtensions) {
            $extStatus = $this->updateExtensions();
            if ($status === 0) {
                $status = $extStatus;
            }
        }

        if ($refreshModels) {
            $modelStatus = $this->refreshModels();
            if ($status === 0) {
                $status = $modelStatus;
            }
        }

        return $status;
    }

    private function updatePig(): int
    {
        $repoRoot = self::repoRoot();
        $isGit = is_dir($repoRoot . '/.git');

        if ($isGit) {
            echo Style::dim("Updating pig from git repository at {$repoRoot}...\n");
            $code = $this->execute(['git', 'pull'], $repoRoot);
            if ($code !== 0) {
                fwrite(STDERR, Style::red("Failed to update pig via git pull (exit code {$code}).\n"));

                return $code;
            }

            if (is_file($repoRoot . '/composer.json')) {
                echo Style::dim("Running composer install...\n");
                $composer = $this->findComposer();
                if ($composer !== null) {
                    $this->execute([$composer, 'install'], $repoRoot);
                }
            }

            echo Style::green("✔ pig has been updated to the latest commit.\n");
            echo Style::dim("Changelog: " . self::CHANGELOG_URL . "\n");

            return 0;
        }

        echo Style::dim("Updating " . Version::PACKAGE . " via Composer...\n");
        $composer = $this->findComposer();

        if ($composer === null) {
            fwrite(
                STDERR,
                Style::red("Error: 'composer' executable not found in PATH.\n")
                . Style::dim("Please run: composer global require " . Version::PACKAGE . "\n"),
            );

            return 1;
        }

        // Use `composer global require` instead of `update` to safely elevate SemVer 0.x constraint
        // (e.g. ^0.1.0 will reject upgrading to 0.2.x on `update`, but `require` resolves to the latest release).
        $code = $this->execute([$composer, 'global', 'require', Version::PACKAGE]);

        if ($code !== 0) {
            fwrite(STDERR, Style::red("Failed to update pig via Composer (exit code {$code}).\n"));

            return $code;
        }

        echo Style::green("✔ pig has been updated to the latest version.\n");
        echo Style::dim("Changelog: " . self::CHANGELOG_URL . "\n");

        return 0;
    }

    /**
     * Update installed extensions across global and project paths.
     */
    public function updateExtensions(): int
    {
        $userDir = Config::home() . '/extensions';
        $coreDir = self::coreExtensionsDir();
        $updated = 0;

        echo Style::dim("Updating extensions in {$userDir}...\n");

        if (is_dir($userDir)) {
            $items = scandir($userDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = "{$userDir}/{$item}";

                // 1. Git repository extension
                if (is_dir("{$path}/.git")) {
                    echo Style::dim("Updating git extension '{$item}'...\n");
                    $code = $this->execute(['git', 'pull'], $path);
                    if ($code === 0) {
                        $updated++;
                    }
                    continue;
                }

                // 2. Composer extension
                if (is_dir($path) && is_file("{$path}/composer.json")) {
                    $composer = $this->findComposer();
                    if ($composer !== null) {
                        echo Style::dim("Updating composer dependencies for '{$item}'...\n");
                        $code = $this->execute([$composer, 'update', '--no-dev'], $path);
                        if ($code === 0) {
                            $updated++;
                        }
                    }
                    continue;
                }

                // 3. Core built-in extension sync
                if (is_dir($path) && $coreDir !== null && is_dir("{$coreDir}/{$item}")) {
                    $changes = self::syncDirectory("{$coreDir}/{$item}", $path);
                    if ($changes > 0) {
                        echo Style::green("✔ Synced built-in extension '{$item}' ({$changes} files updated)\n");
                        $updated++;
                    }
                }
            }
        }

        // 4. Ensure all core extensions are synced to user directory
        if ($coreDir !== null && is_dir($coreDir)) {
            $coreItems = scandir($coreDir) ?: [];
            foreach ($coreItems as $coreItem) {
                if ($coreItem === '.' || $coreItem === '..' || !is_dir("{$coreDir}/{$coreItem}")) {
                    continue;
                }

                $targetPath = "{$userDir}/{$coreItem}";
                if (!file_exists($targetPath)) {
                    $count = self::syncDirectory("{$coreDir}/{$coreItem}", $targetPath);
                    if ($count > 0) {
                        echo Style::green("✔ Installed built-in extension '{$coreItem}'\n");
                        $updated++;
                    }
                }
            }
        }

        if ($updated > 0) {
            echo Style::green("✔ Extensions updated successfully ({$updated} extension(s) updated/synced).\n");
        } else {
            echo Style::dim("All extensions are up to date.\n");
        }

        return 0;
    }

    /**
     * Locate the core extensions package directory.
     */
    public static function coreExtensionsDir(): ?string
    {
        $root = self::repoRoot();
        if (is_dir("{$root}/extensions")) {
            return "{$root}/extensions";
        }

        return null;
    }

    /**
     * Pure PHP recursive directory synchronization (equivalent to rsync -a --delete).
     */
    public static function syncDirectory(string $src, string $dst): int
    {
        $changes = 0;
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $items = scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = "{$src}/{$item}";
            $dstPath = "{$dst}/{$item}";

            if (is_dir($srcPath)) {
                $changes += self::syncDirectory($srcPath, $dstPath);
            } else {
                if (!is_file($dstPath) || sha1_file($srcPath) !== sha1_file($dstPath)) {
                    copy($srcPath, $dstPath);
                    $changes++;
                }
            }
        }

        $dstItems = scandir($dst) ?: [];
        foreach ($dstItems as $dItem) {
            if ($dItem === '.' || $dItem === '..') {
                continue;
            }

            $dstPath = "{$dst}/{$dItem}";
            $srcPath = "{$src}/{$dItem}";

            if (!file_exists($srcPath)) {
                if (is_dir($dstPath)) {
                    self::deleteRecursive($dstPath);
                    $changes++;
                } else {
                    unlink($dstPath);
                    $changes++;
                }
            }
        }

        return $changes;
    }

    private static function deleteRecursive(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = "{$dir}/{$item}";
            if (is_dir($p)) {
                self::deleteRecursive($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    private function refreshModels(): int
    {
        $generator = self::repoRoot() . '/scripts/generate-models.php';
        if (is_file($generator)) {
            echo Style::dim("Refreshing model catalogs...\n");
            $code = $this->execute([PHP_BINARY, $generator]);
            if ($code === 0) {
                echo Style::green("✔ Model catalogs refreshed.\n");
            }

            return $code;
        }

        return 0;
    }

    /**
     * Run a command and pass through I/O, or use injected test runner.
     *
     * @param list<string> $command
     */
    private function execute(array $command, ?string $cwd = null): int
    {
        if ($this->runner !== null) {
            return ($this->runner)($command, $cwd);
        }

        $proc = proc_open(
            $command,
            [STDIN, STDOUT, STDERR],
            $pipes,
            $cwd,
        );

        if (!is_resource($proc)) {
            return 1;
        }

        return proc_close($proc);
    }

    private function findComposer(): ?string
    {
        $custom = getenv('COMPOSER_BINARY');
        if (is_string($custom) && $custom !== '' && is_executable($custom)) {
            return $custom;
        }

        // Check common PATH locations
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        foreach ($paths as $dir) {
            $candidate = rtrim($dir, '/') . '/composer';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function printHelp(): void
    {
        echo <<<TEXT
        Usage: pig update [options]

        Update pig, installed extensions, and model catalogs.

        Options:
          --self            Update pig itself
          --extensions      Update installed extensions (git pull, composer, and core sync)
          --models          Refresh and update model catalogs
          -h, --help        Show this help message

        By default, `pig update` updates both pig itself and installed extensions.
        Changelog: https://pigagent.dev/changelog

        TEXT;
    }
}
