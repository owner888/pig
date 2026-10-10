<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Closure;
use InvalidArgumentException;
use JsonException;
use Pig\Async\Loop;
use Pig\CodingAgent\Config;
use Pig\Tui\Colors;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\MarkdownTheme;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\SettingsListTheme;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\TerminalColors;
use RuntimeException;
use Throwable;

/**
 * Upstream's `theme.ts` module: everything in it except the `Theme` class, as static methods.
 *
 * Upstream's global `theme` proxy is `Themes::theme()`. The proxy reads the current theme on every
 * property access, so upstream code may keep a reference to `theme`; here a caller must call
 * `Themes::theme()` each time it draws rather than keep the instance, or it keeps drawing in the
 * theme that was current when it asked.
 *
 * Upstream's string-literal types stay strings: `TerminalTheme`/`ThemeAppearance` are
 * `'dark'|'light'`, `TerminalColorMode` is `'truecolor'|'256color'`, and theme tokens are their names.
 * Upstream's `{ success, error? }` results are `array{success: bool, error?: string}`.
 *
 * Where pig differs from upstream, on purpose:
 *
 * - **Builtin themes** are `dark.json` and `light.json` (upstream's, verbatim) and pig's own
 *   `labra.json`, all beside this file, as upstream keeps its two beside `theme.ts`.
 * - **Custom themes** come from four directories rather than upstream's one
 *   (`getCustomThemesDir()`, which is `~/.pi/agent/themes`): pig's home, pi's home, and the
 *   project's `.pig/themes` and `.pi/themes` — see `getCustomThemesDirs()`. The project directory is
 *   set with `setCustomThemesCwd()`, which upstream does not have: its project themes arrive through
 *   the resource loader and `setRegisteredThemes()`, which pig has no counterpart for yet.
 * - For the same reason a custom theme is also found by the `name` inside it, not only by its file
 *   name: upstream's picker lists content names and relies on the resource loader to register them
 *   under that name, and without it `foo.json` named `bar` would be listed and then not found.
 * - **Invalid custom themes** are skipped by the listing, as upstream's are, but not in silence:
 *   upstream leaves reporting them to the resource loader, and here `getCustomThemeErrors()` holds
 *   what the last listing skipped and why.
 * - **No silent catches.** `initTheme()` still falls back to the system theme, as upstream's does,
 *   but returns the error it fell back from instead of discarding it. The watcher's reload and
 *   `highlightCode()` let a failure throw instead of ignoring it, and `getThemeExportColors()`
 *   answers `[]` only for the cases upstream's catch exists for (the system theme, an in-memory or
 *   registered theme without a file) and lets anything else throw.
 * - **The file watcher polls** on pig's event loop (`Loop::get()->delay()`) instead of `fs.watch()`;
 *   while it runs it keeps a timer armed, so `Loop::run()` does not return until it is stopped.
 * - **Syntax highlighting** is pig's own `Highlight`/`Grammar` rather than `cli-highlight`, so
 *   `highlightCode()` colors pig's seven token kinds and leaves operators and punctuation unstyled.
 */
final class Themes
{
    public const string SYSTEM_THEME_NAME = SystemTheme::SYSTEM_THEME_NAME;

    /** The name `setThemeInstance()` records, as upstream's. */
    private const string IN_MEMORY = '<in-memory>';

    /** How often the watcher looks at the theme file, in seconds. */
    private const float WATCH_INTERVAL = 0.5;

    private const array BACKGROUND_TOKENS = [
        'selectedBg',
        'searchMatchBg',
        'userMessageBg',
        'customMessageBg',
        'toolPendingBg',
        'toolSuccessBg',
        'toolErrorBg',
    ];

    /** Upstream's `withThemeColorFallbacks()` table: optional token to the token it falls back to. */
    private const array COLOR_FALLBACKS = [
        'scrollbarTrack' => 'muted',
        'scrollbarThumb' => 'text',
        'thinkingMax' => 'thinkingXhigh',
        'searchMatchBg' => 'selectedBg',
        'searchMatchText' => 'text',
    ];

    private const array EXT_TO_LANG = [
        'ts' => 'typescript',
        'tsx' => 'typescript',
        'js' => 'javascript',
        'jsx' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'py' => 'python',
        'rb' => 'ruby',
        'rs' => 'rust',
        'go' => 'go',
        'java' => 'java',
        'kt' => 'kotlin',
        'swift' => 'swift',
        'c' => 'c',
        'h' => 'c',
        'cpp' => 'cpp',
        'cc' => 'cpp',
        'cxx' => 'cpp',
        'hpp' => 'cpp',
        'cs' => 'csharp',
        'php' => 'php',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'fish' => 'fish',
        'ps1' => 'powershell',
        'sql' => 'sql',
        'html' => 'html',
        'htm' => 'html',
        'css' => 'css',
        'scss' => 'scss',
        'sass' => 'sass',
        'less' => 'less',
        'json' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'toml' => 'toml',
        'xml' => 'xml',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'dockerfile' => 'dockerfile',
        'makefile' => 'makefile',
        'cmake' => 'cmake',
        'lua' => 'lua',
        'perl' => 'perl',
        'r' => 'r',
        'scala' => 'scala',
        'clj' => 'clojure',
        'ex' => 'elixir',
        'exs' => 'elixir',
        'erl' => 'erlang',
        'hs' => 'haskell',
        'ml' => 'ocaml',
        'vim' => 'vim',
        'graphql' => 'graphql',
        'proto' => 'protobuf',
        'tf' => 'hcl',
        'hcl' => 'hcl',
    ];

    /** @var (Closure(string, mixed): array<string, mixed>)|null upstream's `ThemeJsonValidator` */
    private static ?Closure $themeJsonValidator = null;

    /**
     * The terminal's reported colors. Replaced (never mutated) on update, so themes can cache resolved colors
     * by identity. Null until first asked for: a static property cannot start as an object.
     */
    private static ?TerminalColors $terminalColors = null;

    /** While the terminal color query is in flight, the system theme renders in grayscale. */
    private static bool $terminalColorsPending = false;

    /** @var 'dark'|'light'|null The terminal's last light/dark report (mode 2031). Only used while it has not reported a background. */
    private static ?string $terminalColorScheme = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $builtinThemes = null;

    private static ?Theme $globalTheme = null;

    private static ?string $currentThemeName = null;

    /** Poll timer id while a theme file is watched. */
    private static ?string $themeWatcher = null;

    private static ?string $themeReloadTimer = null;

    /** @var (Closure(): void)|null */
    private static ?Closure $onThemeChangeCallback = null;

    /** @var array<string, Theme> */
    private static array $registeredThemes = [];

    /** Project directory whose `.pig/themes` and `.pi/themes` hold custom themes (pig addition). */
    private static ?string $customThemesCwd = null;

    /** @var list<string> */
    private static array $extensionThemeDirs = [];

    /** @var list<string> the theme files the packages provide, see `setPackageThemeFiles()` */
    private static array $packageThemeFiles = [];

    /** @var list<string> the files and directories `--theme` named, see `setCliThemePaths()` */
    private static array $cliThemePaths = [];

    /** False is upstream's `--no-themes`, see `useThemeDiscovery()`. */
    private static bool $themeDiscovery = true;

    /** @var list<string> what the last custom theme listing skipped, and why (pig addition) */
    private static array $customThemeErrors = [];

    private static ?Theme $cachedHighlightThemeFor = null;

    private static ?HighlightTheme $cachedHighlightTheme = null;

    /**
     * Install full theme validation. Without it, documents are accepted as-is, which is what built-in
     * themes already do. Upstream defers it to keep typebox out of a presentation that only uses built-in
     * themes; pig keeps the switch so the two load the same way (`ThemeJson::validateThemeJson(...)`).
     *
     * @param (Closure(string, mixed): array<string, mixed>)|null $validator
     */
    public static function setThemeJsonValidator(?Closure $validator): void
    {
        self::$themeJsonValidator = $validator;
    }

    // ============================================================================
    // Color Utilities
    // ============================================================================

    /**
     * @param array<string, string|int> $vars
     * @param array<string, true> $visited
     */
    private static function resolveVarRefs(mixed $value, array $vars, array $visited = []): string|int
    {
        if (is_int($value) || $value === '') {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid color value: ' . get_debug_type($value));
        }
        if (str_starts_with($value, '#') || preg_match('/^ok(lch|hsl)\(/i', $value) === 1) {
            return $value;
        }
        if (isset($visited[$value])) {
            throw new InvalidArgumentException("Circular variable reference detected: {$value}");
        }
        if (!array_key_exists($value, $vars)) {
            throw new InvalidArgumentException("Variable reference not found: {$value}");
        }
        $visited[$value] = true;

        return self::resolveVarRefs($vars[$value], $vars, $visited);
    }

    /**
     * @param array<string, mixed> $colors
     * @param array<string, string|int> $vars
     * @return array<string, string|int>
     */
    private static function resolveThemeColors(array $colors, array $vars = []): array
    {
        $resolved = [];
        foreach ($colors as $key => $value) {
            $resolved[$key] = self::resolveVarRefs($value, $vars);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $colors
     * @return array<string, mixed>
     */
    private static function withThemeColorFallbacks(array $colors): array
    {
        foreach (self::COLOR_FALLBACKS as $token => $fallback) {
            if (!array_key_exists($token, $colors) && array_key_exists($fallback, $colors)) {
                $colors[$token] = $colors[$fallback];
            }
        }

        return $colors;
    }

    // ============================================================================
    // Appearance & Terminal Default Colors
    // ============================================================================

    /**
     * Record the terminal's reported colors. Themes use the default colors for tokens set to "" (terminal
     * default); the system theme is generated from all of them. Ends the pending state.
     */
    public static function setTerminalColors(TerminalColors $colors): void
    {
        self::$terminalColors = new TerminalColors($colors->foreground, $colors->background, $colors->palette);
        self::$terminalColorsPending = false;
    }

    /**
     * Record the terminal's light/dark report, the fallback for terminals that do not report their background.
     *
     * @param 'dark'|'light'|null $scheme
     */
    public static function setTerminalColorScheme(?string $scheme): void
    {
        self::$terminalColorScheme = $scheme;
    }

    /** Render the system theme in grayscale until `setTerminalColors()` reports the terminal's colors. */
    public static function markTerminalColorsPending(): void
    {
        self::$terminalColorsPending = true;
    }

    /**
     * The colors last recorded by `setTerminalColors()`. Upstream's `Theme` reads the module variable
     * directly; this is that read across the file boundary.
     *
     * @internal for `Theme::colors()`
     */
    public static function terminalColorsForThemes(): TerminalColors
    {
        return self::$terminalColors ??= new TerminalColors();
    }

    // ============================================================================
    // Theme Loading
    // ============================================================================

    /** Upstream's `getThemesDir()`: where the builtin theme files are. */
    public static function getThemesDir(): string
    {
        return __DIR__;
    }

    /**
     * Upstream's `getCustomThemesDir()`, as pig's four directories, in precedence order: a theme name
     * found in an earlier one hides the same name in a later one.
     *
     * @return list<string>
     */
    public static function getCustomThemesDirs(): array
    {
        if (!self::$themeDiscovery) {
            return self::$extensionThemeDirs;
        }

        $dirs = [Config::home() . '/themes', Config::piHome() . '/themes'];
        $cwd = self::$customThemesCwd;
        if ($cwd !== null && $cwd !== '') {
            $dirs[] = rtrim($cwd, '/') . '/.pig/themes';
            $dirs[] = rtrim($cwd, '/') . '/.pi/themes';
        }

        return [...$dirs, ...self::$extensionThemeDirs];
    }

    /**
     * The directories extensions added through `resources_discover`, searched after the four
     * above. Replaced whole on each discovery, so a `/reload` that drops one drops its themes.
     *
     * @param list<string> $dirs
     */
    public static function setExtensionThemeDirs(array $dirs): void
    {
        self::$extensionThemeDirs = $dirs;
    }

    /**
     * The theme files the packages resolved to (`PackageManager::resolve()`), listed after the
     * directories: files rather than directories, because a package's filter can leave one theme
     * out of a folder, and a directory cannot say that.
     *
     * @param list<string> $files
     */
    public static function setPackageThemeFiles(array $files): void
    {
        self::$packageThemeFiles = array_values($files);
    }

    /**
     * Upstream's `--theme <path>`: theme files, or directories of them, for this run — after every
     * other source, as upstream's `additionalThemePaths` are, and loaded under `--no-themes` too.
     *
     * @param list<string> $paths absolute
     */
    public static function setCliThemePaths(array $paths): void
    {
        self::$cliThemePaths = array_values($paths);
    }

    /**
     * Upstream's `--no-themes` when false: no theme is looked for in the four directories or the
     * packages. The built-in ones, an extension's and `--theme`'s are still there.
     */
    public static function useThemeDiscovery(bool $enabled): void
    {
        self::$themeDiscovery = $enabled;
    }

    /** The project whose `.pig/themes` and `.pi/themes` are searched; null searches only the two homes. */
    public static function setCustomThemesCwd(?string $cwd): void
    {
        self::$customThemesCwd = $cwd;
    }

    /**
     * Custom theme files the last listing skipped, each with the reason. Upstream's resource loader
     * reports these; pig has none for themes yet, so a caller that lists themes should show these.
     *
     * @return list<string>
     */
    public static function getCustomThemeErrors(): array
    {
        return self::$customThemeErrors;
    }

    /** @return array<string, array<string, mixed>> */
    private static function getBuiltinThemes(): array
    {
        if (self::$builtinThemes === null) {
            $themes = [];
            foreach (['dark', 'light', 'labra'] as $name) {
                $path = self::getThemesDir() . "/{$name}.json";
                $themes[$name] = self::decodeJson($path, self::readFile($path));
            }
            self::$builtinThemes = $themes;
        }

        return self::$builtinThemes;
    }

    /** @return list<string> */
    public static function getAvailableThemes(): array
    {
        return array_map(static fn (ThemeInfo $info): string => $info->name, self::getAvailableThemesWithPaths());
    }

    /** @return list<ThemeInfo> */
    public static function getAvailableThemesWithPaths(): array
    {
        $themesDir = self::getThemesDir();
        $result = [];
        $seen = [];
        $addTheme = static function (ThemeInfo $themeInfo) use (&$result, &$seen): void {
            if (isset($seen[$themeInfo->name])) {
                return;
            }
            $seen[$themeInfo->name] = true;
            $result[] = $themeInfo;
        };

        // Built-in themes. The system theme is generated, so it has no file.
        $addTheme(new ThemeInfo(self::SYSTEM_THEME_NAME, null));
        foreach (array_keys(self::getBuiltinThemes()) as $name) {
            $addTheme(new ThemeInfo($name, "{$themesDir}/{$name}.json"));
        }

        // Custom themes
        foreach (self::getCustomThemeInfos() as $themeInfo) {
            $addTheme($themeInfo);
        }

        foreach (self::$registeredThemes as $name => $theme) {
            $addTheme(new ThemeInfo($name, $theme->sourcePath));
        }

        // The system theme comes first: it is the default and adapts to every terminal. The rest are in
        // name order: upstream's `localeCompare()`, approximated by a case-insensitive comparison.
        usort($result, static function (ThemeInfo $a, ThemeInfo $b): int {
            if ($a->name === self::SYSTEM_THEME_NAME) {
                return -1;
            }
            if ($b->name === self::SYSTEM_THEME_NAME) {
                return 1;
            }

            return strcasecmp($a->name, $b->name) ?: strcmp($a->name, $b->name);
        });

        return $result;
    }

    /** @return list<ThemeInfo> */
    private static function getCustomThemeInfos(): array
    {
        $result = [];
        self::$customThemeErrors = [];
        foreach (self::getCustomThemesDirs() as $customThemesDir) {
            if (!is_dir($customThemesDir)) {
                continue;
            }
            $files = scandir($customThemesDir);
            if ($files === false) {
                self::$customThemeErrors[] = "Could not read the theme directory {$customThemesDir}";
                continue;
            }
            foreach ($files as $file) {
                if (!str_ends_with($file, '.json')) {
                    continue;
                }
                $themePath = "{$customThemesDir}/{$file}";
                try {
                    $customTheme = self::loadThemeFromPath($themePath);
                } catch (Throwable $error) {
                    // Upstream ignores invalid themes here because its resource loader reports them;
                    // pig keeps the reason for `getCustomThemeErrors()`.
                    self::$customThemeErrors[] = "{$themePath}: {$error->getMessage()}";
                    continue;
                }
                if ($customTheme->name !== null && $customTheme->name !== '') {
                    $result[] = new ThemeInfo($customTheme->name, $themePath);
                }
            }
        }

        // After the directories: a package's theme ranks after every local one, so a name
        // both have is the person's. `--theme`'s come last, as upstream's additional paths do.
        foreach ([...(self::$themeDiscovery ? self::$packageThemeFiles : []), ...self::cliThemeFiles()] as $themePath) {
            try {
                $packageTheme = self::loadThemeFromPath($themePath);
            } catch (Throwable $error) {
                self::$customThemeErrors[] = "{$themePath}: {$error->getMessage()}";
                continue;
            }
            if ($packageTheme->name !== null && $packageTheme->name !== '') {
                $result[] = new ThemeInfo($packageTheme->name, $themePath);
            }
        }

        return $result;
    }

    /** @return list<string> `--theme`'s files, a directory's `.json` files in name order */
    private static function cliThemeFiles(): array
    {
        $files = [];

        foreach (self::$cliThemePaths as $path) {
            if (is_dir($path)) {
                $found = glob(rtrim($path, '/') . '/*.json') ?: [];
                sort($found);
                array_push($files, ...$found);
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                // Upstream's diagnostic, under pig's list of theme problems.
                self::$customThemeErrors[] = "{$path}: Theme path does not exist";
            }
        }

        return $files;
    }

    private static function assertThemeNameIsValid(string $name): void
    {
        if (str_contains($name, '/')) {
            throw new InvalidArgumentException(
                "Invalid theme name \"{$name}\": theme names cannot contain \"/\" because it is reserved for automatic light/dark theme settings.",
            );
        }
    }

    /** @return array<string, mixed> */
    private static function parseThemeJson(string $label, mixed $json): array
    {
        if (self::$themeJsonValidator !== null) {
            return (self::$themeJsonValidator)($label, $json);
        }
        if (!is_array($json) || !array_key_exists('colors', $json)) {
            throw new InvalidArgumentException("Invalid theme \"{$label}\": expected an object with a \"colors\" map.");
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private static function parseThemeJsonContent(string $label, string $content): array
    {
        return self::parseThemeJson($label, self::decodeJson($label, $content));
    }

    private static function decodeJson(string $label, string $content): mixed
    {
        // Upstream's `stripBom()`.
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException("Failed to parse theme {$label}: {$error->getMessage()}", 0, $error);
        }
    }

    private static function readFile(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException("Could not read theme file {$path}");
        }

        return $content;
    }

    /**
     * The file of a custom theme: `<name>.json` in the first directory that has one, as upstream looks
     * it up, then a file whose `name` is `$name` (pig addition, see the class docblock).
     */
    private static function findCustomThemePath(string $name): ?string
    {
        foreach (self::getCustomThemesDirs() as $customThemesDir) {
            $themePath = "{$customThemesDir}/{$name}.json";
            if (is_file($themePath)) {
                return $themePath;
            }
        }
        foreach (self::getCustomThemeInfos() as $themeInfo) {
            if ($themeInfo->name === $name) {
                return $themeInfo->path;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function loadThemeJson(string $name): array
    {
        $builtinThemes = self::getBuiltinThemes();
        if (isset($builtinThemes[$name])) {
            return $builtinThemes[$name];
        }
        $registeredTheme = self::$registeredThemes[$name] ?? null;
        if ($registeredTheme?->sourcePath !== null) {
            return self::parseThemeJsonContent($registeredTheme->sourcePath, self::readFile($registeredTheme->sourcePath));
        }
        if ($registeredTheme !== null) {
            throw new RuntimeException("Theme \"{$name}\" does not have a source path for export");
        }
        $themePath = self::findCustomThemePath($name);
        if ($themePath === null) {
            throw new RuntimeException("Theme not found: {$name}");
        }

        return self::parseThemeJsonContent($name, self::readFile($themePath));
    }

    /**
     * @param array<string, string|int> $colors
     * @return array{0: array<string, string|int>, 1: array<string, string|int>} foreground and background colors
     */
    private static function splitThemeColors(array $colors): array
    {
        $fgColors = [];
        $bgColors = [];
        foreach ($colors as $key => $value) {
            if (in_array($key, self::BACKGROUND_TOKENS, true)) {
                $bgColors[$key] = $value;
            } else {
                $fgColors[$key] = $value;
            }
        }

        return [$fgColors, $bgColors];
    }

    /**
     * @param array<string, mixed> $themeJson
     * @param 'truecolor'|'256color'|null $mode
     */
    private static function createTheme(array $themeJson, ?string $mode = null, ?string $sourcePath = null): Theme
    {
        $colorMode = $mode ?? TerminalImage::getTerminalColorMode();
        $resolvedColors = self::resolveThemeColors(self::withThemeColorFallbacks($themeJson['colors']), $themeJson['vars'] ?? []);
        [$fgColors, $bgColors] = self::splitThemeColors($resolvedColors);

        return new Theme(
            $fgColors,
            $bgColors,
            $colorMode,
            name: is_string($themeJson['name'] ?? null) ? $themeJson['name'] : null,
            sourcePath: $sourcePath,
            appearance: $themeJson['appearance'] ?? null,
        );
    }

    /**
     * Generate the system theme from the terminal's reported colors (grayscale while they are pending).
     *
     * @param 'truecolor'|'256color'|null $mode
     */
    private static function createSystemTheme(?string $mode = null): Theme
    {
        $terminal = self::terminalColorsForThemes();
        $generated = SystemTheme::generateSystemThemeColors(new SystemThemeInput(
            foreground: $terminal->foreground,
            background: $terminal->background,
            palette: $terminal->palette,
            saturation: self::$terminalColorsPending ? 0 : 1,
            appearanceHint: self::getTerminalTheme(),
        ));
        [$fgColors, $bgColors] = self::splitThemeColors($generated->colors);

        return new Theme(
            $fgColors,
            $bgColors,
            $mode ?? TerminalImage::getTerminalColorMode(),
            name: self::SYSTEM_THEME_NAME,
            appearance: $generated->appearance,
            dim: $generated->dim,
        );
    }

    /** @param 'truecolor'|'256color'|null $mode */
    public static function loadThemeFromPath(string $themePath, ?string $mode = null): Theme
    {
        $themeJson = self::parseThemeJsonContent($themePath, self::readFile($themePath));

        return self::createTheme($themeJson, $mode, $themePath);
    }

    /** @param 'truecolor'|'256color'|null $mode */
    private static function loadTheme(string $name, ?string $mode = null): Theme
    {
        // The system theme name is reserved: it takes precedence over custom themes of the same name.
        if ($name === self::SYSTEM_THEME_NAME) {
            return self::createSystemTheme($mode);
        }
        $registeredTheme = self::$registeredThemes[$name] ?? null;
        if ($registeredTheme !== null) {
            return $registeredTheme;
        }

        return self::createTheme(self::loadThemeJson($name), $mode);
    }

    /** The theme called `$name`, or null when there is none or it does not load — upstream's contract. */
    public static function getThemeByName(string $name): ?Theme
    {
        try {
            return self::loadTheme($name);
        } catch (Throwable) {
            // Upstream's contract: "not loadable" is the answer, and the caller decides what to say.
            return null;
        }
    }

    /** @return array{lightTheme: string, darkTheme: string}|null */
    public static function parseAutoThemeSetting(?string $themeSetting): ?array
    {
        if ($themeSetting === null || $themeSetting === '') {
            return null;
        }
        $slashIndex = strpos($themeSetting, '/');
        if ($slashIndex === false || strpos($themeSetting, '/', $slashIndex + 1) !== false) {
            return null;
        }

        $lightTheme = trim(substr($themeSetting, 0, $slashIndex));
        $darkTheme = trim(substr($themeSetting, $slashIndex + 1));
        if ($lightTheme === '' || $darkTheme === '') {
            return null;
        }

        return ['lightTheme' => $lightTheme, 'darkTheme' => $darkTheme];
    }

    /** @param 'dark'|'light' $terminalTheme */
    public static function resolveThemeSetting(?string $themeSetting, string $terminalTheme): ?string
    {
        $autoTheme = self::parseAutoThemeSetting($themeSetting);
        if ($autoTheme !== null) {
            return $terminalTheme === 'light' ? $autoTheme['lightTheme'] : $autoTheme['darkTheme'];
        }
        if ($themeSetting !== null && str_contains($themeSetting, '/')) {
            return null;
        }

        return $themeSetting;
    }

    /**
     * Dark or light from the `COLORFGBG` environment variable some terminals set, or null without a
     * usable background index. The value is `fg;bg` or `fg;xpm;bg` (rxvt), where a field is an ANSI color
     * index or `default` when the color is not in the palette. The index refers to the terminal's own palette,
     * whose colors are unknown here, so it is classified by index like Vim does: 0-6 and 8 (bright black, e.g.
     * Solarized Dark's background) are dark, 7 and 9-15 are light.
     *
     * @param array<string, string>|null $env defaults to the process environment
     * @return 'dark'|'light'|null
     */
    public static function detectColorFgBgTheme(?array $env = null): ?string
    {
        $env ??= getenv();
        $value = $env['COLORFGBG'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $fields = explode(';', $value);
        $bg = trim($fields[count($fields) - 1]);
        if ($bg === '' || preg_match('/^\d{1,2}$/', $bg) !== 1) {
            return null;
        }
        $index = (int) $bg;
        if ($index > 15) {
            return null;
        }

        return $index <= 6 || $index === 8 ? 'dark' : 'light';
    }

    /**
     * Whether the terminal is dark or light. The background it renders decides, classified the same way the
     * system theme does. Without a reported background: the terminal's light/dark report, then COLORFGBG,
     * then dark.
     *
     * @param 'dark'|'light'|null $reportedScheme
     * @param array<string, string>|null $env defaults to the process environment
     * @return 'dark'|'light'
     */
    public static function detectTerminalTheme(?TerminalColors $colors = null, ?string $reportedScheme = null, ?array $env = null): string
    {
        $colors ??= new TerminalColors();
        if ($colors->background !== null) {
            return SystemTheme::terminalAppearance($colors->background, $colors->foreground);
        }

        return $reportedScheme ?? self::detectColorFgBgTheme($env) ?? 'dark';
    }

    /**
     * Whether the terminal is dark or light, from everything it reported so far. See `detectTerminalTheme()`.
     *
     * @return 'dark'|'light'
     */
    public static function getTerminalTheme(): string
    {
        return self::detectTerminalTheme(self::terminalColorsForThemes(), self::$terminalColorScheme);
    }

    // ============================================================================
    // Global Theme Instance
    // ============================================================================

    /** Upstream's global `theme`: the current theme. Ask each time; see the class docblock. */
    public static function theme(): Theme
    {
        return self::$globalTheme ?? throw new RuntimeException('Theme not initialized. Call initTheme() first.');
    }

    private static function setGlobalTheme(Theme $theme): void
    {
        self::$globalTheme = $theme;
    }

    /** @param list<Theme> $themes */
    public static function setRegisteredThemes(array $themes): void
    {
        self::$registeredThemes = [];
        foreach ($themes as $theme) {
            if ($theme->name !== null && $theme->name !== '') {
                self::assertThemeNameIsValid($theme->name);
                self::$registeredThemes[$theme->name] = $theme;
            }
        }
    }

    /**
     * Load `$themeName` (the system theme when null) as the global theme. An invalid theme falls back to
     * the system theme, as upstream's does; the error it fell back from is returned rather than dropped.
     *
     * @return string|null why `$themeName` did not load, when the system theme was used instead
     */
    public static function initTheme(?string $themeName = null, bool $enableWatcher = false): ?string
    {
        $name = $themeName ?? self::SYSTEM_THEME_NAME;
        self::$currentThemeName = $name;
        try {
            self::setGlobalTheme(self::loadTheme($name));
            if ($enableWatcher) {
                self::startThemeWatcher();
            }

            return null;
        } catch (Throwable $error) {
            // Theme is invalid - fall back to the system theme, and hand the reason back
            self::$currentThemeName = self::SYSTEM_THEME_NAME;
            self::setGlobalTheme(self::loadTheme(self::SYSTEM_THEME_NAME));

            // Don't start watcher for fallback theme
            return $error->getMessage();
        }
    }

    /** @return array{success: bool, error?: string} */
    public static function setTheme(string $name, bool $enableWatcher = false): array
    {
        self::$currentThemeName = $name;
        try {
            self::setGlobalTheme(self::loadTheme($name));
            if ($enableWatcher) {
                self::startThemeWatcher();
            }
            if (self::$onThemeChangeCallback !== null) {
                (self::$onThemeChangeCallback)();
            }

            return ['success' => true];
        } catch (Throwable $error) {
            // Theme is invalid - fall back to the system theme
            self::$currentThemeName = self::SYSTEM_THEME_NAME;
            self::setGlobalTheme(self::loadTheme(self::SYSTEM_THEME_NAME));

            // Don't start watcher for fallback theme
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }

    public static function setThemeInstance(Theme $themeInstance): void
    {
        self::setGlobalTheme($themeInstance);
        self::$currentThemeName = self::IN_MEMORY;
        self::stopThemeWatcher(); // Can't watch a direct instance
        if (self::$onThemeChangeCallback !== null) {
            (self::$onThemeChangeCallback)();
        }
    }

    /** @param (Closure(): void)|null $callback */
    public static function onThemeChange(?Closure $callback): void
    {
        self::$onThemeChangeCallback = $callback;
    }

    /**
     * Watch the current custom theme's file and reload it when it changes.
     *
     * Upstream uses `fs.watch()` on the custom themes directory; pig polls the file's mtime and size on its
     * event loop every `WATCH_INTERVAL`, and reloads 100 ms after a change as upstream does.
     */
    private static function startThemeWatcher(): void
    {
        self::stopThemeWatcher();

        // Only watch if it's a custom theme (not built-in)
        $watchedThemeName = self::$currentThemeName;
        if (
            $watchedThemeName === null
            || $watchedThemeName === self::SYSTEM_THEME_NAME
            || isset(self::getBuiltinThemes()[$watchedThemeName])
        ) {
            return;
        }

        // Only watch if the file exists
        $themeFile = self::findCustomThemePath($watchedThemeName);
        if ($themeFile === null) {
            return;
        }

        $stamp = self::themeFileStamp($themeFile);
        self::$themeWatcher = Loop::get()->delay(
            self::WATCH_INTERVAL,
            static fn () => self::pollThemeWatcher($watchedThemeName, $themeFile, $stamp),
        );
    }

    /** The file's mtime and size, which change on every save that changes it; null while it is missing. */
    private static function themeFileStamp(string $themeFile): ?string
    {
        clearstatcache(true, $themeFile);
        if (!is_file($themeFile)) {
            return null;
        }
        $mtime = filemtime($themeFile);
        $size = filesize($themeFile);

        return $mtime === false || $size === false ? null : "{$mtime}:{$size}";
    }

    /** One look at the watched file: re-arm, and schedule a reload when it changed since `$lastStamp`. */
    private static function pollThemeWatcher(string $watchedThemeName, string $themeFile, ?string $lastStamp): void
    {
        if (self::$currentThemeName !== $watchedThemeName) {
            self::$themeWatcher = null;

            return;
        }
        $current = self::themeFileStamp($themeFile);
        self::$themeWatcher = Loop::get()->delay(
            self::WATCH_INTERVAL,
            static fn () => self::pollThemeWatcher($watchedThemeName, $themeFile, $current),
        );
        if ($current !== $lastStamp) {
            self::scheduleReload($watchedThemeName, $themeFile);
        }
    }

    private static function scheduleReload(string $watchedThemeName, string $themeFile): void
    {
        if (self::$themeReloadTimer !== null) {
            Loop::get()->cancel(self::$themeReloadTimer);
        }
        self::$themeReloadTimer = Loop::get()->delay(0.1, static fn () => self::reloadWatchedTheme($watchedThemeName, $themeFile));
    }

    private static function reloadWatchedTheme(string $watchedThemeName, string $themeFile): void
    {
        self::$themeReloadTimer = null;

        // Ignore stale timers after switching themes or stopping the watcher
        if (self::$currentThemeName !== $watchedThemeName) {
            return;
        }

        // Keep the last successfully loaded theme active if the file is temporarily missing
        if (!is_file($themeFile)) {
            return;
        }

        try {
            // Reload the theme from disk and refresh the registry cache
            $reloadedTheme = self::loadThemeFromPath($themeFile);
        } catch (Throwable $error) {
            // Upstream ignores this (the file may be mid-edit). The last good theme stays active, and the
            // failure goes to the loop's error handler instead of nowhere; the next save that parses
            // reloads as usual.
            throw new RuntimeException("Reloading theme \"{$watchedThemeName}\" from {$themeFile} failed: {$error->getMessage()}", 0, $error);
        }
        self::$registeredThemes[$watchedThemeName] = $reloadedTheme;
        self::setGlobalTheme($reloadedTheme);
        // Notify callback (to invalidate UI)
        if (self::$onThemeChangeCallback !== null) {
            (self::$onThemeChangeCallback)();
        }
    }

    public static function stopThemeWatcher(): void
    {
        if (self::$themeReloadTimer !== null) {
            Loop::get()->cancel(self::$themeReloadTimer);
            self::$themeReloadTimer = null;
        }
        if (self::$themeWatcher !== null) {
            Loop::get()->cancel(self::$themeWatcher);
            self::$themeWatcher = null;
        }
    }

    // ============================================================================
    // HTML Export Helpers
    // ============================================================================

    /**
     * Get resolved theme colors as CSS-compatible hex strings.
     * Used by HTML export to generate CSS custom properties.
     *
     * @return array<string, string>
     */
    public static function getResolvedThemeColors(?string $themeName = null): array
    {
        $colors = self::loadTheme($themeName ?? self::$currentThemeName ?? self::SYSTEM_THEME_NAME)->colors();

        return array_map(Colors::colorToHex(...), $colors);
    }

    /**
     * Check if a theme is a "light" theme (for CSS that needs light/dark variants).
     */
    public static function isLightTheme(?string $themeName = null): bool
    {
        return self::loadTheme($themeName ?? self::$currentThemeName ?? self::SYSTEM_THEME_NAME)->appearance() === 'light';
    }

    /**
     * Get explicit export colors from theme JSON, if specified: `pageBg`, `cardBg` and `infoBg`, each
     * null when the theme does not set it; `[]` when the theme has no export section or no file.
     *
     * @return array{pageBg?: ?string, cardBg?: ?string, infoBg?: ?string}
     */
    public static function getThemeExportColors(?string $themeName = null): array
    {
        $name = $themeName ?? self::$currentThemeName ?? self::SYSTEM_THEME_NAME;
        // The cases upstream's catch exists for: themes without a JSON document to read.
        if ($name === self::SYSTEM_THEME_NAME || $name === self::IN_MEMORY) {
            return [];
        }
        $registeredTheme = self::$registeredThemes[$name] ?? null;
        if ($registeredTheme !== null && $registeredTheme->sourcePath === null) {
            return [];
        }
        $themeJson = self::loadThemeJson($name);
        $exportSection = $themeJson['export'] ?? null;
        if (!is_array($exportSection)) {
            return [];
        }

        $vars = $themeJson['vars'] ?? [];
        // Export colors end up in CSS, which understands hex and oklch() values directly but not okhsl().
        $resolve = static function (mixed $value) use ($vars): ?string {
            if ($value === null) {
                return null;
            }
            $resolved = self::resolveVarRefs($value, $vars);
            if (is_int($resolved)) {
                return Colors::colorToHex(Colors::indexedColor($resolved));
            }
            if ($resolved === '') {
                return null;
            }
            if (preg_match('/^okhsl\(/i', $resolved) === 1) {
                return Colors::colorToHex(Colors::parseColor($resolved));
            }

            return $resolved;
        };

        return [
            'pageBg' => $resolve($exportSection['pageBg'] ?? null),
            'cardBg' => $resolve($exportSection['cardBg'] ?? null),
            'infoBg' => $resolve($exportSection['infoBg'] ?? null),
        ];
    }

    // ============================================================================
    // TUI Helpers
    // ============================================================================

    /**
     * Upstream's `buildCliHighlightTheme()`, for pig's highlighter: its seven token kinds take the
     * theme's syntax colors, and everything else (`plain`: operators, punctuation, spaces) is left as
     * the terminal draws it.
     */
    private static function buildHighlightTheme(Theme $t): HighlightTheme
    {
        return new HighlightTheme(
            comment: static fn (string $s): string => $t->fg('syntaxComment', $s),
            string: static fn (string $s): string => $t->fg('syntaxString', $s),
            number: static fn (string $s): string => $t->fg('syntaxNumber', $s),
            keyword: static fn (string $s): string => $t->fg('syntaxKeyword', $s),
            type: static fn (string $s): string => $t->fg('syntaxType', $s),
            function: static fn (string $s): string => $t->fg('syntaxFunction', $s),
            variable: static fn (string $s): string => $t->fg('syntaxVariable', $s),
            plain: static fn (string $s): string => $s,
        );
    }

    private static function getHighlightTheme(Theme $t): HighlightTheme
    {
        if (self::$cachedHighlightThemeFor !== $t || self::$cachedHighlightTheme === null) {
            self::$cachedHighlightThemeFor = $t;
            self::$cachedHighlightTheme = self::buildHighlightTheme($t);
        }

        return self::$cachedHighlightTheme;
    }

    /**
     * Highlight code with syntax coloring based on file extension or language.
     * Returns array of highlighted lines.
     *
     * @return list<string>
     */
    public static function highlightCode(string $code, ?string $lang = null): array
    {
        $theme = self::theme();
        // Skip highlighting when no valid language is specified: guessing a language colors prose
        // as code. Upstream's guard is cli-highlight's `supportsLanguage()`; pig's is a grammar.
        if ($lang === null || $lang === '' || Grammar::for($lang) === null) {
            return array_map(static fn (string $line): string => $theme->fg('mdCodeBlock', $line), explode("\n", $code));
        }

        return Highlight::lines($code, $lang, self::getHighlightTheme($theme));
    }

    /**
     * Get language identifier from file path extension.
     */
    public static function getLanguageFromPath(string $filePath): ?string
    {
        $parts = explode('.', $filePath);
        $ext = strtolower($parts[count($parts) - 1]);
        if ($ext === '') {
            return null;
        }

        return self::EXT_TO_LANG[$ext] ?? null;
    }

    /**
     * Upstream's `getMarkdownTheme()`. pig's `MarkdownTheme` calls the horizontal rule `rule` where
     * upstream's says `hr`; the color is upstream's `mdHr`.
     */
    public static function getMarkdownTheme(): MarkdownTheme
    {
        return new MarkdownTheme(
            heading: static fn (string $text): string => self::theme()->fg('mdHeading', $text),
            link: static fn (string $text): string => self::theme()->fg('mdLink', $text),
            linkUrl: static fn (string $text): string => self::theme()->fg('mdLinkUrl', $text),
            code: static fn (string $text): string => self::theme()->fg('mdCode', $text),
            codeBlock: static fn (string $text): string => self::theme()->fg('mdCodeBlock', $text),
            codeBlockBorder: static fn (string $text): string => self::theme()->fg('mdCodeBlockBorder', $text),
            quote: static fn (string $text): string => self::theme()->fg('mdQuote', $text),
            quoteBorder: static fn (string $text): string => self::theme()->fg('mdQuoteBorder', $text),
            rule: static fn (string $text): string => self::theme()->fg('mdHr', $text),
            listBullet: static fn (string $text): string => self::theme()->fg('mdListBullet', $text),
            bold: static fn (string $text): string => self::theme()->bold($text),
            italic: static fn (string $text): string => self::theme()->italic($text),
            strikethrough: static fn (string $text): string => self::theme()->strikethrough($text),
            underline: static fn (string $text): string => self::theme()->underline($text),
            highlightCode: static fn (string $code, ?string $lang = null): array => self::highlightCode($code, $lang),
            codeBlockIndent: self::$codeBlockIndent,
        );
    }

    /**
     * `markdown.codeBlockIndent`, which upstream lays over this theme in its interactive mode
     * (`getMarkdownThemeWithSettings()`) and hands to each component. pig's components ask for the
     * theme themselves, so the mode sets the indent here once instead.
     */
    private static string $codeBlockIndent = '  ';

    public static function setCodeBlockIndent(string $indent): void
    {
        self::$codeBlockIndent = $indent;
    }

    /**
     * Upstream's `getSelectListTheme()`. pig's `SelectListTheme` has no `selectedPrefix` (upstream's
     * is the accent, as `selectedText` is).
     */
    public static function getSelectListTheme(): SelectListTheme
    {
        return new SelectListTheme(
            selectedText: static fn (string $text): string => self::theme()->fg('accent', $text),
            description: static fn (string $text): string => self::theme()->fg('muted', $text),
            scrollInfo: static fn (string $text): string => self::theme()->fg('muted', $text),
            noMatch: static fn (string $text): string => self::theme()->fg('muted', $text),
        );
    }

    public static function getEditorTheme(): EditorTheme
    {
        return new EditorTheme(
            static fn (string $text): string => self::theme()->fg('borderMuted', $text),
            self::getSelectListTheme(),
        );
    }

    public static function getSettingsListTheme(): SettingsListTheme
    {
        return new SettingsListTheme(
            label: static fn (string $text, bool $selected): string => $selected ? self::theme()->fg('accent', $text) : $text,
            value: static fn (string $text, bool $selected): string => $selected
                ? self::theme()->fg('accent', $text)
                : self::theme()->fg('muted', $text),
            description: static fn (string $text): string => self::theme()->fg('dim', $text),
            hint: static fn (string $text): string => self::theme()->fg('dim', $text),
            cursor: self::theme()->fg('accent', '→ '),
        );
    }
}
