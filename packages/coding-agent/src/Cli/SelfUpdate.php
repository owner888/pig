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
 * - Either way, a bundled extension added since the last update is installed (`installNewBundled()`).
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
     * `pig update …`, as a method — upstream's parser lives in `PackageCommands`, and this is the
     * same call `bin/pig` makes, kept so a caller with a `SelfUpdate` in hand needs nothing else.
     *
     * @param list<string> $args CLI arguments following `pig update`
     * @return int exit status code (0 for success)
     */
    public function run(array $args = []): int
    {
        return PackageCommands::main(['update', ...$args], getcwd() ?: '.', selfUpdate: $this);
    }

    public function updatePig(): int
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
            self::announceInstalled(self::installNewBundled());
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
        self::announceInstalled(self::installNewBundled());
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

                // 3. Core built-in extension sync. Upstream's built-ins load from pig itself, so a
                // copy of one is left alone — the loader skips it and says so.
                if (in_array($item, \Pig\CodingAgent\Extensions\BuiltinExtensions::FOLDERS, true)) {
                    continue;
                }

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
                if ($coreItem === '.' || $coreItem === '..' || !is_dir("{$coreDir}/{$coreItem}")
                    || in_array($coreItem, \Pig\CodingAgent\Extensions\BuiltinExtensions::FOLDERS, true)) {
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

            // Asked for by name, so every one counts as offered from here on.
            self::writeOffered($userDir, [...self::readOffered($userDir) ?? [], ...self::bundledFolders($coreDir)]);
        }

        if ($updated > 0) {
            echo Style::green("✔ Extensions updated successfully ({$updated} extension(s) updated/synced).\n");
        } else {
            echo Style::dim("All extensions are up to date.\n");
        }

        return 0;
    }

    /**
     * Ensure bundled extensions (antigravity, computer, web-search, etc.) are populated
     * in the user's home directory on initial install if missing.
     */
    public static function ensureBundledExtensions(?string $home = null): int
    {
        $coreDir = self::coreExtensionsDir();
        $userDir = ($home ?? Config::home()) . '/extensions';

        if ($coreDir === null || !is_dir($coreDir)) {
            return 0;
        }

        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }

        $coreItems = scandir($coreDir) ?: [];
        $synced = 0;

        foreach ($coreItems as $coreItem) {
            if ($coreItem === '.' || $coreItem === '..' || !is_dir("{$coreDir}/{$coreItem}")
                || in_array($coreItem, \Pig\CodingAgent\Extensions\BuiltinExtensions::FOLDERS, true)) {
                continue;
            }

            $targetPath = "{$userDir}/{$coreItem}";
            if (!file_exists($targetPath)) {
                $count = self::syncDirectory("{$coreDir}/{$coreItem}", $targetPath);
                if ($count > 0) {
                    $synced++;
                }
            }
        }

        self::writeOffered($userDir, [...self::readOffered($userDir) ?? [], ...self::bundledFolders($coreDir)]);

        return $synced;
    }

    /**
     * The bundled extensions shipped before pig kept a record of which it had offered: an install
     * that has no record yet already had its chance at each of these, so one missing from it was
     * removed on purpose and stays removed. Only what came after is new to it.
     */
    private const array BUNDLED_BEFORE_RECORD = ['pig-android-use', 'pig-antigravity', 'pig-computer', 'pig-vless', 'pig-web-search'];

    /** Where the record of offered bundled extensions lives, beside them. */
    private const string OFFERED_FILE = '.bundled.json';

    /**
     * Install the bundled extensions this install has never been offered, and only those — run by
     * every `pig update`, not just `--extensions`.
     *
     * The first-run copy (`ensureBundledExtensions()`) happens once, when there is no extensions
     * directory, and after that only `pig update --extensions` installed anything: a bundled
     * extension added in a release reached nobody who already had pig until they knew to ask —
     * `pig-gemini-video` was the case. So a plain update installs what is new. "New" is kept in a
     * record rather than read off what is missing, because a missing one may have been deleted on
     * purpose, and an update that put it back every time would be one nobody could say no to.
     *
     * @return list<string> the folders installed
     */
    public static function installNewBundled(?string $home = null): array
    {
        $coreDir = self::coreExtensionsDir();
        $userDir = ($home ?? Config::home()) . '/extensions';

        // A machine that has never run pig gets everything on first run instead.
        if ($coreDir === null || !is_dir($userDir)) {
            return [];
        }

        $offered = self::readOffered($userDir) ?? self::BUNDLED_BEFORE_RECORD;
        $installed = [];

        foreach (self::bundledFolders($coreDir) as $folder) {
            if (in_array($folder, $offered, true)) {
                continue;
            }

            if (!file_exists("{$userDir}/{$folder}") && self::syncDirectory("{$coreDir}/{$folder}", "{$userDir}/{$folder}") > 0) {
                $installed[] = $folder;
            }

            $offered[] = $folder;
        }

        self::writeOffered($userDir, $offered);

        return $installed;
    }

    /** @param list<string> $installed */
    private static function announceInstalled(array $installed): void
    {
        foreach ($installed as $folder) {
            echo Style::green("✔ Installed new built-in extension '{$folder}'\n");
        }
    }

    /** @return list<string> the bundled extension folders that are copied out, not loaded from pig */
    private static function bundledFolders(string $coreDir): array
    {
        $folders = [];

        foreach (scandir($coreDir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && is_dir("{$coreDir}/{$item}")
                && !in_array($item, \Pig\CodingAgent\Extensions\BuiltinExtensions::FOLDERS, true)) {
                $folders[] = $item;
            }
        }

        return $folders;
    }

    /** @return list<string>|null null when there is no record yet */
    private static function readOffered(string $userDir): ?array
    {
        $path = "{$userDir}/" . self::OFFERED_FILE;
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    /** @param list<string> $offered */
    private static function writeOffered(string $userDir, array $offered): void
    {
        if (!is_dir($userDir)) {
            return;
        }

        $offered = array_values(array_unique($offered));
        sort($offered);
        file_put_contents("{$userDir}/" . self::OFFERED_FILE, json_encode($offered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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

    public static function deleteRecursive(string $dir): void
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

    public function refreshModels(): int
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
}
