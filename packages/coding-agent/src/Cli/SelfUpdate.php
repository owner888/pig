<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Version;
use Pig\Tui\Style;

/**
 * Self-update CLI command (`pig update`), matching upstream pi's `pi update`.
 *
 * Updates pig to the latest release:
 * - In a Composer global installation: executes `composer global update pigagent/pig`.
 * - In a git checkout / development environment: executes `git pull`.
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
        $updateSelf = in_array('--self', $args, true) || !$refreshModels;

        $status = 0;

        if ($updateSelf) {
            $status = $this->updatePig();
            if ($status !== 0) {
                return $status;
            }
        }

        if ($refreshModels) {
            $status = $this->refreshModels();
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

        Update pig to the latest version.

        Options:
          --self            Update pig itself (default)
          --models          Refresh and update model catalogs
          -h, --help        Show this help message

        Changelog: https://pigagent.dev/changelog

        TEXT;
    }
}
