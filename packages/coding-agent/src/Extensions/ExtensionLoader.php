<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Extensions\DeclaredSymbols;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Timings;
use Pig\CodingAgent\Tools\Paths;
use Throwable;

/**
 * Finding and loading extension files.
 *
 * An extension is a PHP file that returns a callable. The callable receives an `ExtensionApi`
 * and can register slash commands, agent tools, lifecycle event handlers, message renderers,
 * and interact with the UI.
 *
 * Locations searched in order — the person's first, so they are loaded before the project is
 * trusted and can be asked `project_trust` about it:
 * 1. Global extensions: ~/.pig/agent/extensions/*.php and subdirectories with index.php
 * 2. Configured extra paths from the person's settings
 * 3. Explicit CLI paths passed via --extension
 * 4. Project extensions: <cwd>/.pig/extensions/*.php and subdirectories with index.php
 * 5. Project root extensions: <cwd>/extensions/*.php and subdirectories with index.php
 * 6. Configured extra paths from the project's settings
 */
final class ExtensionLoader
{
    /**
     * @param list<string> $configured extra paths from settings — the person's, since a project's
     *                                 settings wait for trust like its extensions do
     * @param list<string> $cliPaths    explicit paths passed on the command line
     * @param bool|Closure(list<LoadedExtension>): bool $projectTrusted whether the project roots
     *        may be loaded; a closure is asked once everything else has loaded and is handed it —
     *        upstream's `resolveProjectTrust({extensionsResult})`, which is how `project_trust`
     *        reaches the extensions that can answer it. Two phases of one load rather than
     *        upstream's two loads, because a factory run twice is an MCP server connected twice.
     * @param list<string> $disabled    extension names (their directory or file name) not to load
     * @param (Closure(): list<string>)|null $projectConfigured the project's own `extensions`
     *        setting, asked only once it is trusted
     * @param (Closure(bool): list<string>)|null $packageExtensions the extensions the configured
     *        packages provide (`PackageManager::resolve()`), asked once trust is decided and
     *        loaded last — a package's resource ranks after every local one, as upstream ranks it
     * @param bool $discover false is upstream's `--no-extensions`: only `$cliPaths` load — nothing
     *        discovered, configured, bundled, from the project or from a package
     * @param list<string> $builtins upstream's built-in extensions this run loads
     *        (`Settings::builtinExtensions()`), after everything else, as upstream ranks them
     * @param list<string>|null $only when given, the names (as `$disabled`'s) that may load at
     *        all, from wherever they come: a Docker or SSH account's child loads only what the admin
     *        let such accounts have (`Settings::sandboxExtensions()`), since an extension runs in
     *        this process, on the server, as its user
     * @return array{0: list<LoadedExtension>, 1: list<ExtensionError>}
     */
    public static function load(
        string $cwd,
        array $configured = [],
        array $cliPaths = [],
        ?string $home = null,
        ?Auth $auth = null,
        bool|Closure $projectTrusted = true,
        array $disabled = [],
        ?Closure $projectConfigured = null,
        ?Closure $packageExtensions = null,
        bool $discover = true,
        array $builtins = [],
        ?array $only = null,
    ): array {
        // Upstream's resource loader resets the `extensions` timings at every load, so a `/reload`
        // under `PIG_TIMING=1` is measured on its own.
        Timings::resetTimings('extensions');
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = $discover ? self::discover($home . '/extensions') : [];
        $homeFolder = $paths;

        foreach ([...($discover ? $configured : []), ...$cliPaths] as $path) {
            $paths[] = Paths::resolve($path, $cwd);
        }

        $extensions = [];
        $errors = [];
        $seen = [];
        $events = new EventBus();

        $loadAll = static function (array $paths) use (&$extensions, &$errors, &$seen, $events, $cwd, $auth, $disabled, $only): void {
            foreach ($paths as $path) {
                $real = realpath($path) ?: $path;

                if (isset($seen[$real])) {
                    continue;
                }

                // A folder named as a built-in's that is not the built-in: the copy goes stale, and
                // the built-in loads anyway (`BuiltinExtensions::isStaleCopy()`).
                if (BuiltinExtensions::isStaleCopy($path)) {
                    $seen[$real] = true;
                    $folder = self::nameOf($path);
                    $errors[] = new ExtensionError($path, 'load', "not loaded: `{$folder}` is built into pig now, so this copy would be an older one; remove it");

                    continue;
                }

                // `--no-mcp`: upstream's `disabledBuiltinExtensions: ["mcp"]`. By name, which is
                // the directory's, so the same switch reaches the copy under
                // `~/.pig/agent/extensions` and one in the project. Not loaded at all, rather than
                // loaded and told to do nothing — an extension that connects servers in a fiber
                // has no "do nothing" to be told.
                if (in_array(self::nameOf($path), $disabled, true) || ($only !== null && !in_array(self::nameOf($path), $only, true))) {
                    continue;
                }

                $seen[$real] = true;

                [$loaded, $error] = self::one($path, $real, $cwd, $auth, $events);

                if ($error !== null) {
                    $errors[] = $error;
                }

                if ($loaded !== null) {
                    $extensions[$loaded->name] = $loaded;
                }
            }
        };

        // A project extension with the name of one in `~/.pig/agent/extensions` replaces it, which
        // used to mean loading the person's, running its factory, and throwing it away when the
        // project's came. Held back instead until the project's trust is known, and loaded then
        // only if no project copy took its name. Most visible with pig's own repository as the
        // project, whose `extensions/` holds every extension the person's folder has a copy of.
        // Found from the files alone, so nothing of an untrusted project runs to find it.
        $projectFolders = $discover && $projectTrusted !== false
            ? [...self::discover($cwd . '/.pig/extensions'), ...self::inProjectFolder($cwd)]
            : [];
        // Only the person's folder is held back: a path the settings or the command line name is
        // asked for by name, and loads first as it always did.
        $projectNames = array_flip(array_map(self::nameOf(...), $projectFolders));
        $heldBack = array_values(array_filter($homeFolder, static fn (string $path): bool => isset($projectNames[self::nameOf($path)])));

        $loadAll(array_values(array_filter($paths, static fn (string $path): bool => !in_array($path, $heldBack, true))));

        if ($projectTrusted instanceof Closure) {
            $projectTrusted = $projectTrusted(array_values($extensions));
        }

        if (!$discover) {
            return [self::omitReplaced(array_values($extensions), $errors), $errors];
        }

        // Both project roots only for a project somebody said yes to — see `ProjectTrust`. An
        // extension is `require`d into this process, which is the whole reason the question exists.
        if ($projectTrusted) {
            $project = $projectFolders;

            foreach ($projectConfigured === null ? [] : $projectConfigured() as $path) {
                $project[] = Paths::resolve($path, $cwd);
            }

            $loadAll($project);
        }

        // What was held back for a project copy that did not come — the project untrusted, or its
        // copy failing to load.
        $loadAll(array_values(array_filter($heldBack, static fn (string $path): bool => !isset($extensions[self::nameOf($path)]))));

        if ($packageExtensions !== null) {
            $loadAll(self::entryFiles($packageExtensions($projectTrusted)));
        }

        $loadAll($builtins);

        return [self::omitReplaced(array_values($extensions), $errors), $errors];
    }

    /**
     * Upstream's `omitReplacedExtensions()`: a replaceable built-in — `codemode`, `tool-search`,
     * `mcp` — gives way to another extension that registers one of its tools or commands, and says
     * so. (Upstream compares flags too; pig's flags are process-wide, not an extension's.)
     *
     * @param list<LoadedExtension> $extensions
     * @param list<ExtensionError> $errors
     * @return list<LoadedExtension>
     */
    private static function omitReplaced(array $extensions, array &$errors): array
    {
        $replaceable = static function (LoadedExtension $extension): ?string {
            $name = BuiltinExtensions::nameOf($extension->resolved);

            return $name !== null && in_array($name, BuiltinExtensions::REPLACEABLE, true) ? $name : null;
        };
        $names = static fn (LoadedExtension $extension): array => [
            ...array_map(static fn (object $tool): string => "tool:{$tool->name}", $extension->api->tools()),
            ...array_map(static fn (string|int $command): string => "command:{$command}", array_keys($extension->api->commands())),
        ];
        $taken = [];

        foreach ($extensions as $extension) {
            if ($replaceable($extension) === null) {
                foreach ($names($extension) as $name) {
                    $taken[$name] ??= $extension;
                }
            }
        }

        return array_values(array_filter($extensions, static function (LoadedExtension $extension) use ($replaceable, $names, $taken, &$errors): bool {
            $builtin = $replaceable($extension);

            if ($builtin === null) {
                return true;
            }

            foreach ($names($extension) as $name) {
                if (isset($taken[$name])) {
                    [$kind, $raw] = explode(':', $name, 2);
                    $registered = $kind === 'command' ? "/{$raw}" : $raw;
                    $errors[] = new ExtensionError(
                        BuiltinExtensions::PREFIX . $builtin,
                        'load',
                        "Extension {$taken[$name]->path} registers {$kind} `{$registered}`, so built-in extension `{$builtin}` was not loaded. To use `{$builtin}`, run `pig config` and make sure it is enabled under Built-in extensions, then disable or remove the existing extension. We recommend only having one or the other loaded at a time.",
                    );

                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * A package may name a directory as one extension (a local path with none of the package
     * shapes, as `-e ./dir` does); its entry is the `index.php` inside, as upstream's is `index.ts`.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function entryFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                if (is_file("{$path}/index.php")) {
                    $files[] = "{$path}/index.php";
                }
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Scan a directory for *.php files and subdirectories with index.php.
     *
     * Not recursive, and sorted so that two machines with the same files load them in the
     * same order — extensions run in load order, and readdir order is not an order.
     *
     * A `glob()` rather than a scan, and here that is the point rather than an oversight:
     * a glob does not match a leading dot, and an extension directory is a directory somebody
     * *edits*. Emacs writes a `.#name.php` symlink beside the file it has open, which ends
     * in `.php` — upstream's `readdirSync` picks it up (it accepts symlinks) and reports a
     * load error for as long as the editor is open. `Migrations` has the opposite rule, and
     * for the opposite reason: missing a session file there loses somebody's conversation,
     * where missing a dotfile here is how an editor's droppings stay out of the way.
     *
     * @return list<string>
     */
    public static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.php', GLOB_NOSORT) ?: [];
        $dirs = glob($directory . '/*/index.php', GLOB_NOSORT) ?: [];

        $all = [...$files, ...$dirs];
        sort($all);

        return $all;
    }

    /**
     * The extensions `<cwd>/extensions` contributes: every file `discover()` finds there — unless the
     * working directory is a pig package whose manifest names its extensions, and then those, as the
     * package loads when it is installed. A package keeps its helper classes beside its entry file
     * (`extensions/FastGrep.php` beside `extensions/fff.php`), and loading each of those as an
     * extension of its own `require`d files the entry had already `require_once`d.
     *
     * @return list<string>
     */
    public static function inProjectFolder(string $cwd): array
    {
        return PackageManager::declaredFiles($cwd, 'extensions') ?? self::discover($cwd . '/extensions');
    }

    /**
     * What is still in a hooks or tools folder, or named under a `hooks` or `customTools`
     * setting, now that both are extensions — one line per find, each saying where it goes.
     * Empty when there is nothing.
     *
     * `~/.pig/agent/hooks` and `<cwd>/.pig/hooks` were the hook folders, `~/.pig/agent/tools/<name>/index.php`
     * and the same under `<cwd>/.pig/tools` the tool folders; `hooks` and `customTools` in the
     * settings named more. A hook file is an extension file already: it returns a factory, and
     * what the factory is handed has every method `HookApi` had. A tool file returned a
     * `CustomTool` (or a list) from a factory handed `CustomToolApi`; as an extension it
     * `$pig->registerTool()`s each one instead, and `$pig->exec()` is the `exec()` it had.
     *
     * @param mixed $hooksSetting the raw `hooks` setting, whatever it is
     * @param mixed $customTools the raw `customTools` setting, whatever it is
     * @return list<string>
     */
    public static function legacyFiles(string $cwd, mixed $hooksSetting = null, mixed $customTools = null, ?string $home = null): array
    {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');
        $found = [];

        foreach ([$home . '/hooks' => $home . '/extensions', $cwd . '/.pig/hooks' => $cwd . '/.pig/extensions'] as $folder => $instead) {
            foreach (glob($folder . '/*.php', GLOB_NOSORT) ?: [] as $file) {
                $found[] = "{$file}: hooks are extensions now — move it to {$instead}/ and it loads as it is";
            }
        }

        if (is_array($hooksSetting) && $hooksSetting !== []) {
            $found[] = 'the `hooks` setting: hooks are extensions now — name these files under `extensions` instead';
        }

        foreach ([$home . '/tools' => $home . '/extensions', $cwd . '/.pig/tools' => $cwd . '/.pig/extensions'] as $folder => $instead) {
            foreach (glob($folder . '/*/index.php', GLOB_NOSORT) ?: [] as $file) {
                $found[] = "{$file}: tool files are extensions now — move the folder to {$instead}/ and have its factory `\$pig->registerTool()` each tool it returned";
            }
        }

        if (is_array($customTools) && $customTools !== []) {
            $found[] = 'the `customTools` setting: tool files are extensions now — name these files under `extensions` instead, once they register their tools';
        }

        if ($found !== []) {
            $found[] = '(`Pig\\CodingAgent\\Hooks\\HookApi` and `Pig\\CodingAgent\\CustomTools\\CustomToolApi` are `Pig\\CodingAgent\\Extensions\\ExtensionApi` now, which has everything they had)';
        }

        return $found;
    }

    /**
     * Derive a human-friendly name for an extension.
     */
    public static function nameOf(string $path): string
    {
        $base = basename($path);
        if ($base === 'index.php') {
            return basename(dirname($path));
        }

        return preg_replace('/\.php$/', '', $base) ?? $base;
    }

    /**
     * Load a single extension file.
     *
     * @return array{0: LoadedExtension|null, 1: ExtensionError|null}
     */
    private static function one(string $path, string $resolved, string $cwd, ?Auth $auth, EventBus $events): array
    {
        if (!is_readable($path)) {
            return [null, new ExtensionError($path, 'load', 'not a readable file')];
        }

        $name = self::nameOf($path);
        $api = new ExtensionApi($cwd, $path, $name, $auth, events: $events);
        $note = null;

        // A file that declares a class or a function cannot be `require`d twice, so on a reload it
        // is the factory from its first load that runs — and a class another file already declared
        // is refused by name rather than let the second `require` fatal. `DeclaredSymbols` says why.
        $declared = DeclaredSymbols::in($resolved);
        $hash = $declared === [] ? '' : DeclaredSymbols::hashOf($resolved);
        $kept = $declared === [] ? null : DeclaredSymbols::recall($resolved, $hash);

        // Upstream labels a line by the path the extension was loaded from; a built-in is
        // `builtin:<name>` there, and here too.
        $label = ($builtin = BuiltinExtensions::nameOf($path)) !== null ? BuiltinExtensions::PREFIX . $builtin : $path;

        if ($kept !== null) {
            $factory = $kept['factory'];
            Timings::time("{$label} module import", 'extensions');

            if ($kept['changed']) {
                $note = new ExtensionError($path, 'reload', 'declares ' . self::listOf($declared) . ', which PHP cannot unload — running the version loaded at startup; restart pig to pick up the change');
            }
        } else {
            // Already in this process through another file's `require_once` — a helper beside an
            // entry file, say. A second `require` would redeclare its classes, which is a fatal
            // error no `catch` reaches; and it is not a factory either way. Upstream's sentence.
            // (A file of closures alone can be `require`d again, which is what `/reload` does.)
            if ($declared !== [] && in_array($resolved, array_map(static fn (string $file): string => realpath($file) ?: $file, get_included_files()), true)) {
                return [null, new ExtensionError($path, 'load', 'Extension does not export a valid factory function: it was already included by another file, so it is a library and not an extension')];
            }

            $conflict = DeclaredSymbols::conflict($resolved, $declared);

            if ($conflict !== null) {
                return [null, new ExtensionError($path, 'load', "declares {$conflict}, which is already in memory; two different copies of one extension cannot both be loaded")];
            }

            // A git or local package that ships its own libraries ships its own `vendor/` — pig runs
            // no composer there (see `PackageManager`). Beside the file, or one level up for a file
            // under `extensions/`. A `composer:` package sits inside a `vendor/` instead, and its
            // libraries are that `vendor/`, so the autoloader is the one above the last `/vendor/`.
            $vendor = strrpos($resolved, '/vendor/');

            foreach ([dirname($resolved) . '/vendor', dirname($resolved, 2) . '/vendor', $vendor === false ? null : substr($resolved, 0, $vendor) . '/vendor'] as $dir) {
                if ($dir !== null && is_file("{$dir}/autoload.php")) {
                    require_once "{$dir}/autoload.php";

                    break;
                }
            }

            ob_start();

            try {
                $factory = self::evaluate($resolved);
            } catch (Throwable $error) {
                ob_end_clean();

                return [null, new ExtensionError($path, 'load', self::describe($error))];
            }

            Timings::time("{$label} module import", 'extensions');

            $printed = ob_get_clean();

            if (is_string($printed) && trim($printed) !== '') {
                return [null, new ExtensionError($path, 'load', 'printed to standard output while loading: ' . trim($printed))];
            }

            if (!is_callable($factory)) {
                return [null, new ExtensionError($path, 'load', 'must return a callable, got ' . get_debug_type($factory))];
            }

            if ($declared !== []) {
                DeclaredSymbols::remember($resolved, Closure::fromCallable($factory), $hash);
            }
        }

        try {
            $factory($api);
        } catch (Throwable $error) {
            return [null, new ExtensionError($path, 'load', self::describe($error))];
        }

        Timings::time("{$label} factory", 'extensions');

        return [new LoadedExtension($path, $resolved, $name, $api), $note];
    }

    /**
     * The declared names for a message: the first few and a count, because an extension with
     * twelve classes is twelve names nobody needs to read.
     *
     * @param list<string> $names
     */
    public static function listOf(array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $rest = count($names) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? " and {$rest} more" : '');
    }

    /**
     * `require`, in a scope of its own.
     *
     * Static so the file cannot reach `$this`, and with one oddly-named parameter so that an
     * extension writing `$path` at the top level is writing its own variable, not ours.
     */
    private static function evaluate(string $pigExtensionPath): mixed
    {
        return (static fn (): mixed => require $pigExtensionPath)();
    }

    /** A throwable as one line, with where it came from when that is not obvious. */
    private static function describe(Throwable $error): string
    {
        $type = $error::class;
        $where = basename($error->getFile()) . ':' . $error->getLine();
        $message = $error->getMessage();

        // An extension written against a name that moved: PHP says "not found" or "must be of
        // type" with the old name in it, which does not say where the class went. Say it.
        foreach (self::MOVED_NAMESPACES as $old => $hint) {
            // A class key must end where it ends: `ModelReference` is not `ModelReferenceTarget`.
            $named = str_ends_with($old, '\\')
                ? str_contains($message, $old)
                : preg_match('/' . preg_quote($old, '/') . '(?!\\w)/', $message) === 1;

            if ($named) {
                $message .= " — {$hint}";

                break;
            }
        }

        return "{$type}: {$message} ({$where})";
    }

    /** Namespaces and classes an older extension may still name, and what to write instead. Longer keys first: a class before its namespace. */
    private const array MOVED_NAMESPACES = [
        'Pig\\Ai\\PricingTier' => '`Pig\\Ai\\PricingTier` is `Pig\\Ai\\ModelCostTier` now (1.9.0)',
        'Pig\\Ai\\AnthropicCompat' => '`Pig\\Ai\\AnthropicCompat` is `Pig\\Ai\\AnthropicMessagesCompat` now (1.9.0)',
        'Pig\\Ai\\Utils\\Headers' => '`Pig\\Ai\\Utils\\Headers` is `Pig\\Ai\\Utils\\ProviderHeaders` now (1.9.0)',
        'Pig\\Ai\\Utils\\StreamingJson' => '`Pig\\Ai\\Utils\\StreamingJson` is `Pig\\Ai\\Utils\\StreamingJsonParser` now (1.9.0)',
        'Pig\\Ai\\Utils\\Oauth\\Credentials' => '`Pig\\Ai\\Utils\\Oauth\\Credentials` is `Pig\\Ai\\Utils\\Oauth\\OauthCredentials` now (1.9.0)',
        'Pig\\Ai\\Utils\\Oauth\\Provider' => '`Pig\\Ai\\Utils\\Oauth\\Provider` is `Pig\\Ai\\Utils\\Oauth\\OauthProvider` now (1.9.0)',
        'Pig\\Ai\\Extension\\ClassificationApi' => '`Pig\\Ai\\Extension\\ClassificationApi` is `Pig\\Ai\\Extension\\ProviderClassifier` now (1.9.0)',
        'Pig\\Ai\\Extension\\ImageGenerationApi' => '`Pig\\Ai\\Extension\\ImageGenerationApi` is `Pig\\Ai\\Extension\\ProviderImages` now (1.9.0)',
        'Pig\\Ai\\Providers\\AzureOpenAiConfig' => '`Pig\\Ai\\Providers\\AzureOpenAiConfig` is `Pig\\Ai\\Providers\\AzureEndpointOptions` now (1.9.0)',
        'Pig\\CodingAgent\\ModelRefreshResult' => '`Pig\\CodingAgent\\ModelRefreshResult` is `Pig\\CodingAgent\\ModelsRefreshResult` now (1.9.0)',
        'Pig\\CodingAgent\\ModelReference' => '`Pig\\CodingAgent\\ModelReference` is `Pig\\CodingAgent\\ModelReferenceResult` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\BashExecution' => '`Pig\\CodingAgent\\Session\\BashExecution` is `Pig\\CodingAgent\\Session\\BashExecutionMessage` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\BranchSummary' => '`Pig\\CodingAgent\\Session\\BranchSummary` is `Pig\\CodingAgent\\Session\\BranchSummaryEntry` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\CompactionSummary' => '`Pig\\CodingAgent\\Session\\CompactionSummary` is `Pig\\CodingAgent\\Session\\CompactionEntry` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\Label' => '`Pig\\CodingAgent\\Session\\Label` is `Pig\\CodingAgent\\Session\\LabelEntry` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\ModelChange' => '`Pig\\CodingAgent\\Session\\ModelChange` is `Pig\\CodingAgent\\Session\\ModelChangeEntry` now (1.9.0)',
        'Pig\\CodingAgent\\Session\\ThinkingLevelChange' => '`Pig\\CodingAgent\\Session\\ThinkingLevelChange` is `Pig\\CodingAgent\\Session\\ThinkingLevelChangeEntry` now (1.9.0)',
        'Pig\\CodingAgent\\Prompt\\FileCommand' => '`Pig\\CodingAgent\\Prompt\\FileCommand` is `Pig\\CodingAgent\\Prompt\\PromptTemplate` now (1.9.0)',
        'Pig\\CodingAgent\\Interactive\\SettingsSubmenu' => '`Pig\\CodingAgent\\Interactive\\SettingsSubmenu` is `Pig\\CodingAgent\\Interactive\\SelectSubmenu` now (1.9.0)',
        'Pig\\Mcp\\Transports\\Transport' => '`Pig\\Mcp\\Transports\\Transport` is `Pig\\Mcp\\Transports\\McpTransport` now (1.9.0)',
        'Pig\\Codemode\\Sandbox' => '`Pig\\Codemode\\Sandbox` is `Pig\\Codemode\\CodemodeSandbox` now (1.9.0)',

        'Pig\\CodingAgent\\CustomTools\\' => '`Pig\\CodingAgent\\CustomTools\\` is `Pig\\CodingAgent\\Extensions\\` now: `CustomTool` is `ToolDefinition`, `LoadedCustomTool` is `RegisteredTool`, `RenderOptions` is `ToolRenderResultOptions`; the rest kept their names',
        'Pig\\CodingAgent\\Hooks\\' => '`Pig\\CodingAgent\\Hooks\\` is `Pig\\CodingAgent\\Extensions\\` now: `HookApi` is `ExtensionApi`, `HookContext` is `ExtensionContext`, the events kept their names',
    ];
}
