<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Settings;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Style;
use Pig\Tui\TuiMainScreen;

/**
 * `pig install | remove | update | list` — upstream's `package-manager-cli.ts`.
 *
 * The parser is upstream's rule for rule: which flag each command takes, which combinations
 * refuse, and the wording of every refusal. `update` keeps pig's own halves — the self-update
 * and the bundled extensions under `~/.pig/agent/extensions` are `SelfUpdate`'s — and gains
 * upstream's targets on top: a package by source, `--extensions`, `--all`, `--extension <src>`.
 * Without a target it updates pig alone and says that the packages were skipped, as upstream does.
 *
 * `pig config` — upstream's screen for switching single resources on and off — is not here yet.
 */
final class PackageCommands
{
    public const array COMMANDS = ['install', 'remove', 'uninstall', 'update', 'list', 'config'];

    private const array USAGE = [
        'install' => 'pig install <source> [-l] [--approve|--no-approve]',
        'remove' => 'pig remove <source> [-l] [--approve|--no-approve]',
        'update' => 'pig update [source|self|pig] [--self|--extensions|--models|--all] [--extension <source>] [--approve|--no-approve] [--force]',
        'list' => 'pig list [--approve|--no-approve]',
        'config' => 'pig config [-l] [--approve|--no-approve]',
    ];

    /** @param list<string> $argv the words after `pig`, the command first */
    public static function main(array $argv, string $cwd, ?string $home = null, ?SelfUpdate $selfUpdate = null): int
    {
        if (($argv[0] ?? '') === 'config') {
            return self::config(array_slice($argv, 1), $cwd, $home);
        }

        $options = self::parse($argv);

        if ($options === null) {
            return 1;
        }

        $command = $options['command'];
        $usage = self::USAGE[$command];

        if ($options['help']) {
            self::help($command);

            return 0;
        }

        foreach (['invalidOption' => 'Unknown option %s for "' . $command . '".', 'missingOptionValue' => 'Missing value for %s.', 'invalidArgument' => 'Unexpected argument %s.', 'conflictingOptions' => '%s'] as $key => $format) {
            if ($options[$key] !== null) {
                fwrite(STDERR, Style::red(sprintf($format, $options[$key])) . "\n");
                fwrite(STDERR, Style::dim($key === 'invalidOption' ? "Use \"pig --help\" or \"{$usage}\"." : "Usage: {$usage}") . "\n");

                return 1;
            }
        }

        $source = $options['source'];

        if (in_array($command, ['install', 'remove'], true) && $source === null) {
            fwrite(STDERR, Style::red("Missing {$command} source.") . "\n");
            fwrite(STDERR, Style::dim("Usage: {$usage}") . "\n");

            return 1;
        }

        $target = $options['updateTarget'];

        if ($command === 'update' && $target['type'] === 'models') {
            return (new SelfUpdate())->refreshModels();
        }

        // Trust: `--approve` / `--no-approve` for this command, else what was saved. `update` reads
        // only what was saved, as upstream does — it is not the moment to ask.
        $trusted = $options['projectTrustOverride'] ?? ProjectTrust::decision($cwd, $home) ?? !ProjectTrust::hasResources($cwd);
        $writesProject = in_array($command, ['install', 'remove'], true) && $options['local'];

        if ($writesProject && !$trusted) {
            fwrite(STDERR, Style::red('Project is not trusted. Use --approve to modify local package config.') . "\n");

            return 1;
        }

        $settings = Settings::load($cwd, $home, $trusted);

        foreach ($settings->problems() as $problem) {
            fwrite(STDERR, Style::yellow("Warning (package command): {$problem}") . "\n");
        }

        $manager = new PackageManager($cwd, $settings, $home);
        $manager->setProgressCallback(static function (string $type, string $action, string $packageSource, ?string $message): void {
            if ($type === 'start' && $message !== null) {
                echo Style::dim($message), "\n";
            }
        });

        try {
            switch ($command) {
                case 'install':
                    $manager->installAndPersist($source, $options['local']);
                    echo Style::green("Installed {$source}"), "\n";

                    return 0;

                case 'remove':
                    if (!$manager->removeAndPersist($source, $options['local'])) {
                        fwrite(STDERR, Style::red("No matching package found for {$source}") . "\n");

                        return 1;
                    }

                    echo Style::green("Removed {$source}"), "\n";

                    return 0;

                case 'list':
                    self::list($manager->listConfiguredPackages());

                    return 0;

                case 'update':
                    return self::update($manager, $target, $options['showExtensionsSkippedNote'], $options['force'], $selfUpdate);
            }
        } catch (PackageError $error) {
            fwrite(STDERR, Style::red("Error: {$error->getMessage()}") . "\n");

            return 1;
        }

        return 1;
    }

    /**
     * `pig config [-l]` — upstream's `handleConfigCommand()`: the resource screen, over the
     * user's packages and, when the project is trusted, the project's too.
     *
     * @param list<string> $rest
     */
    private static function config(array $rest, string $cwd, ?string $home): int
    {
        $usage = self::USAGE['config'];

        if (in_array('-h', $rest, true) || in_array('--help', $rest, true)) {
            self::help('config');

            return 0;
        }

        $local = false;
        $trustOverride = null;

        foreach ($rest as $arg) {
            if ($arg === '-l' || $arg === '--local') {
                $local = true;
            } elseif ($arg === '-a' || $arg === '--approve') {
                $trustOverride = true;
            } elseif ($arg === '-na' || $arg === '--no-approve') {
                $trustOverride = false;
            } elseif (str_starts_with($arg, '-')) {
                fwrite(STDERR, Style::red("Unknown option {$arg} for \"config\".") . "\n");
                fwrite(STDERR, Style::dim("Use \"pig --help\" or \"{$usage}\".") . "\n");

                return 1;
            } else {
                fwrite(STDERR, Style::red("Unexpected argument {$arg}.") . "\n");
                fwrite(STDERR, Style::dim("Usage: {$usage}") . "\n");

                return 1;
            }
        }

        $trusted = $trustOverride ?? ProjectTrust::decision($cwd, $home) ?? !ProjectTrust::hasResources($cwd);

        if ($local && !$trusted) {
            fwrite(STDERR, Style::red('Project is not trusted. Use --approve to modify local resource config.') . "\n");

            return 1;
        }

        $settings = Settings::load($cwd, $home, $trusted);

        foreach ($settings->problems() as $problem) {
            fwrite(STDERR, Style::yellow("Warning (config command): {$problem}") . "\n");
        }

        // Two resolutions, as upstream makes them: the user's packages alone give the inherited
        // state the project screen dims; both scopes give what the project actually loads.
        try {
            $global = (new PackageManager($cwd, Settings::load($cwd, $home, projectTrusted: false), $home))->resolve();
            $project = $trusted ? (new PackageManager($cwd, $settings, $home))->resolve() : $global;
        } catch (PackageError $error) {
            fwrite(STDERR, Style::red("Error: {$error->getMessage()}") . "\n");

            return 1;
        }

        $terminal = new ProcessTerminal();
        $tui = new TuiMainScreen($terminal);
        $selector = new ConfigSelector(
            $global,
            $project,
            $settings,
            $cwd,
            $home ?? Config::home(),
            $local ? 'project' : 'global',
            projectModeAvailable: $trusted,
            terminalHeight: $terminal->rows(),
        );
        $selector->setCloseHandler(static fn () => Loop::get()->stop());
        $selector->setExitHandler(static fn () => Loop::get()->stop());
        $selector->setChangeHandler(static fn () => $tui->requestRender());
        $tui->addChild($selector);
        $tui->setFocus($selector);

        Async::run(static function () use ($tui): void {
            $tui->start();
            Loop::get()->run();
        });

        $tui->stop();

        return 0;
    }

    /** @param list<array{source: string, scope: string, filtered: bool, installedPath: string|null}> $packages */
    private static function list(array $packages): void
    {
        if ($packages === []) {
            echo Style::dim('No packages installed.'), "\n";

            return;
        }

        foreach (['user' => 'User packages:', 'project' => 'Project packages:'] as $scope => $heading) {
            $own = array_values(array_filter($packages, static fn (array $package): bool => $package['scope'] === $scope));

            if ($own === []) {
                continue;
            }

            if ($scope === 'project' && count($own) !== count($packages)) {
                echo "\n";
            }

            echo Style::bold($heading), "\n";

            foreach ($own as $package) {
                echo '  ', $package['filtered'] ? "{$package['source']} (filtered)" : $package['source'], "\n";

                if ($package['installedPath'] !== null) {
                    echo Style::dim("    {$package['installedPath']}"), "\n";
                }
            }
        }
    }

    /** @param array{type: string, source?: string} $target */
    private static function update(PackageManager $manager, array $target, bool $skippedNote, bool $force, ?SelfUpdate $selfUpdate = null): int
    {
        if ($skippedNote) {
            echo Style::dim('Extensions are skipped. Run pig update --extensions to update extensions.'), "\n";
        }

        $status = 0;
        $updater = $selfUpdate ?? new SelfUpdate();

        if ($target['type'] === 'all' || $target['type'] === 'extensions') {
            $one = $target['source'] ?? null;
            $manager->update($one);
            echo Style::green($one !== null ? "Updated {$one}" : 'Updated packages'), "\n";

            // pig's own half of `--extensions`: the bundled extensions' copies under
            // `~/.pig/agent/extensions`, which are not packages and have no other updater.
            if ($one === null) {
                $status = $updater->updateExtensions();
            }
        }

        if ($target['type'] === 'all' || $target['type'] === 'self') {
            $selfStatus = $updater->updatePig();
            $status = $status === 0 ? $selfStatus : $status;
        }

        return $status;
    }

    /**
     * Upstream's `parsePackageCommand()`, with its every refusal.
     *
     * @param list<string> $argv
     * @return array{command: string, source: string|null, updateTarget: array{type: string, source?: string}, showExtensionsSkippedNote: bool, local: bool, force: bool, projectTrustOverride: bool|null, help: bool, invalidOption: string|null, invalidArgument: string|null, missingOptionValue: string|null, conflictingOptions: string|null}|null
     */
    public static function parse(array $argv): ?array
    {
        $raw = $argv[0] ?? '';
        $command = $raw === 'uninstall' ? 'remove' : $raw;

        if (!in_array($command, ['install', 'remove', 'update', 'list'], true)) {
            return null;
        }

        $rest = array_slice($argv, 1);
        $local = $force = $help = $selfFlag = $extensionsFlag = $modelsFlag = $allFlag = false;
        $trust = null;
        $invalidOption = $invalidArgument = $missingValue = $conflicting = $source = $extensionSource = null;

        for ($i = 0; $i < count($rest); $i++) {
            $arg = $rest[$i];

            if ($arg === '-h' || $arg === '--help') {
                $help = true;
            } elseif ($arg === '-l' || $arg === '--local') {
                if ($command === 'install' || $command === 'remove') {
                    $local = true;
                } else {
                    $invalidOption ??= $arg;
                }
            } elseif (in_array($arg, ['--self', '--extensions', '--models', '--all', '--force'], true)) {
                if ($command !== 'update') {
                    $invalidOption ??= $arg;
                } else {
                    match ($arg) {
                        '--self' => $selfFlag = true,
                        '--extensions' => $extensionsFlag = true,
                        '--models' => $modelsFlag = true,
                        '--all' => $allFlag = true,
                        '--force' => $force = true,
                    };
                }
            } elseif ($arg === '--approve' || $arg === '-a') {
                $trust = true;
            } elseif ($arg === '--no-approve' || $arg === '-na') {
                $trust = false;
            } elseif ($arg === '--extension') {
                if ($command !== 'update') {
                    $invalidOption ??= $arg;

                    continue;
                }

                $value = $rest[$i + 1] ?? null;

                if ($value === null || str_starts_with($value, '-')) {
                    $missingValue ??= $arg;
                } elseif ($extensionSource !== null) {
                    $conflicting ??= '--extension can only be provided once';
                    $i++;
                } else {
                    $extensionSource = $value;
                    $i++;
                }
            } elseif (str_starts_with($arg, '-')) {
                $invalidOption ??= $arg;
            } elseif ($source === null) {
                $source = $arg;
            } else {
                $invalidArgument ??= $arg;
            }
        }

        $target = ['type' => 'self'];
        $skippedNote = false;

        if ($command === 'update') {
            if ($allFlag && ($selfFlag || $extensionsFlag || $modelsFlag || $extensionSource !== null)) {
                $conflicting ??= '--all cannot be combined with --self, --extensions, --models, or --extension';
            }

            if ($allFlag && $source !== null) {
                $conflicting ??= '--all cannot be combined with a positional source';
            }

            if ($modelsFlag) {
                if ($selfFlag || $extensionsFlag || $allFlag || $extensionSource !== null) {
                    $conflicting ??= '--models cannot be combined with --self, --extensions, --all, or --extension';
                }

                if ($source !== null) {
                    $conflicting ??= '--models cannot be combined with a positional source';
                }

                $target = ['type' => 'models'];
            } elseif ($extensionSource !== null) {
                if ($selfFlag || $extensionsFlag || $allFlag) {
                    $conflicting ??= '--extension cannot be combined with --self, --extensions, or --all';
                }

                if ($source !== null) {
                    $conflicting ??= '--extension cannot be combined with a positional source';
                }

                $target = ['type' => 'extensions', 'source' => $extensionSource];
            } elseif ($source !== null) {
                if ($source === 'self' || $source === 'pig') {
                    $target = ['type' => $extensionsFlag ? 'all' : 'self'];
                } else {
                    if ($extensionsFlag || $selfFlag || $allFlag) {
                        $conflicting ??= 'positional update targets cannot be combined with --self, --extensions, or --all';
                    }

                    $target = ['type' => 'extensions', 'source' => $source];
                }
            } elseif ($allFlag || ($selfFlag && $extensionsFlag)) {
                $target = ['type' => 'all'];
            } elseif ($selfFlag) {
                $target = ['type' => 'self'];
            } elseif ($extensionsFlag) {
                $target = ['type' => 'extensions'];
            } else {
                $target = ['type' => 'self'];
                $skippedNote = true;
            }
        }

        return [
            'command' => $command,
            'source' => $source,
            'updateTarget' => $target,
            'showExtensionsSkippedNote' => $skippedNote,
            'local' => $local,
            'force' => $force,
            'projectTrustOverride' => $trust,
            'help' => $help,
            'invalidOption' => $invalidOption,
            'invalidArgument' => $invalidArgument,
            'missingOptionValue' => $missingValue,
            'conflictingOptions' => $conflicting,
        ];
    }

    /** Upstream's `printPackageCommandHelp()`, with pig's sources. */
    public static function help(string $command): void
    {
        $usage = self::USAGE[$command];
        $bold = Style::bold('Usage:');

        echo match ($command) {
            'install' => <<<TEXT
            {$bold}
              {$usage}

            Install a package and add it to settings.

            Options:
              -l, --local       Install project-locally (.pig/settings.json)
              -a, --approve     Trust project-local files for this command
              -na, --no-approve Ignore project-local files for this command

            Examples:
              pig install git:github.com/user/repo
              pig install git:github.com/user/repo@v1
              pig install git:git@github.com:user/repo
              pig install https://github.com/user/repo
              pig install ssh://git@github.com/user/repo
              pig install ./local/path

            TEXT,
            'remove' => <<<TEXT
            {$bold}
              {$usage}

            Remove a package and its source from settings.
            Alias: pig uninstall <source> [-l]

            Options:
              -l, --local       Remove from project settings (.pig/settings.json)
              -a, --approve     Trust project-local files for this command
              -na, --no-approve Ignore project-local files for this command

            Examples:
              pig remove git:github.com/user/repo
              pig uninstall git:github.com/user/repo

            TEXT,
            'update' => <<<TEXT
            {$bold}
              {$usage}

            Update pig, installed packages, or model catalogs.

            Options:
              --self                  Update pig only (default when no target is given)
              --extensions            Update installed packages, and the bundled extensions' copies
              --models                Refresh model catalogs only
              --all                   Update pig and installed packages
              --extension <source>    Update one package only
              -a, --approve           Trust project-local files for this command
              -na, --no-approve       Ignore project-local files for this command
              --force                 Reinstall pig even if the current version is latest

            Short forms:
              pig update                Update pig only
              pig update --all          Update pig and all packages
              pig update --models       Refresh model catalogs only
              pig update <source>       Update one package
              pig update pig            Update pig only (self works as alias to pig)

            Changelog: https://pigagent.dev/changelog

            TEXT,
            'config' => <<<TEXT
            {$bold}
              {$usage}

            Open the resource configuration screen to enable or disable package resources.
            Without -l, starts in global settings (~/.pig/agent/settings.json).
            Press Tab in the screen to switch between global and project-local modes.

            Options:
              -l, --local       Edit project overrides (.pig/settings.json)
              -a, --approve     Trust project-local files for this command with -l
              -na, --no-approve Ignore project-local files for this command with -l

            TEXT,
            default => <<<TEXT
            {$bold}
              {$usage}

            List installed packages from user and project settings.

            Options:
              -a, --approve      Trust project-local files for this command
              -na, --no-approve  Ignore project-local files for this command

            TEXT,
        };
    }
}
