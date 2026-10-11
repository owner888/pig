<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Tui\KeybindingDefinition;
use Pig\Tui\Keybindings as TuiKeybindings;
use Pig\Tui\KeybindingsManager;
use Pig\Tui\Keys;

/**
 * Which key does what in the terminal — upstream's `core/keybindings.ts`: its `KEYBINDINGS` (the
 * TUI's definitions with the platform overrides, plus `APP_KEYBINDINGS`) and its
 * `KeybindingsManager`, which extends the TUI's manager exactly as this extends
 * `Pig\Tui\KeybindingsManager`.
 *
 * `~/.pig/agent/keybindings.json`, optional, upstream's action names and upstream's key spelling:
 *
 * ```json
 * { "app.model.select": "ctrl+m", "tui.editor.undo": ["ctrl+-", "ctrl+z"], "app.session.tree": "ctrl+shift+t" }
 * ```
 *
 * A binding here is **the** binding — it replaces the default rather than adding to it, so
 * `"app.tools.expand": "ctrl+e"` frees `ctrl+o` for whatever was fighting over it; `[]` unbinds.
 * Every id upstream defines can be bound: the editing keys (`tui.editor.*`, `tui.input.*`,
 * `tui.select.*`) are read by `Editor`, `Input`, `SelectList` and the selectors through
 * `Pig\Tui\Keybindings::getKeybindings()`, which `InteractiveMode` points at this manager, the
 * same `setKeybindings()` upstream's constructor makes.
 *
 * **Where this is stricter than upstream**: upstream drops an id it does not know and a value its
 * schema rejects without a word. Here both are a line in `problems()`, which `bin/pig` prints at
 * startup and `/reload` says as a warning — a binding that silently never fires is the thing a
 * keybindings file exists to prevent.
 */
final class Keybindings extends KeybindingsManager
{
    public const string FILE = 'keybindings.json';

    /**
     * Upstream's `APP_KEYBINDINGS`, the platform-independent defaults. The keys that differ on
     * Windows, WSL or macOS are filled in by `appDefinitions()`; the value here is the one for
     * every other platform.
     *
     * @var array<string, array{defaultKeys: string|list<string>, description: string}>
     */
    public const array APP_KEYBINDINGS = [
        'app.interrupt' => ['defaultKeys' => 'escape', 'description' => 'Cancel or abort'],
        'app.clear' => ['defaultKeys' => 'ctrl+c', 'description' => 'Clear editor'],
        'app.exit' => ['defaultKeys' => 'ctrl+d', 'description' => 'Exit when editor is empty'],
        'app.suspend' => ['defaultKeys' => 'ctrl+z', 'description' => 'Suspend to background'],
        'app.thinking.cycle' => ['defaultKeys' => 'shift+tab', 'description' => 'Cycle thinking level'],
        'app.thinking.save' => ['defaultKeys' => 'ctrl+s', 'description' => 'Save thinking level'],
        'app.model.cycleForward' => ['defaultKeys' => 'ctrl+p', 'description' => 'Cycle to next model'],
        'app.model.cycleBackward' => ['defaultKeys' => 'shift+ctrl+p', 'description' => 'Cycle to previous model'],
        'app.model.select' => ['defaultKeys' => 'ctrl+l', 'description' => 'Open model selector'],
        'app.tools.expand' => ['defaultKeys' => 'ctrl+o', 'description' => 'Toggle tool output'],
        'app.thinking.toggle' => ['defaultKeys' => 'ctrl+t', 'description' => 'Toggle thinking blocks'],
        'app.session.toggleNamedFilter' => ['defaultKeys' => 'ctrl+n', 'description' => 'Toggle named session filter'],
        'app.editor.external' => ['defaultKeys' => 'ctrl+g', 'description' => 'Open external editor'],
        'app.message.copy' => ['defaultKeys' => 'ctrl+x', 'description' => 'Copy selection or last assistant message'],
        // Upstream's is `alt+enter` alone. `command+enter` is pig's, kept on purpose: the macOS
        // modifier fallback in `ProcessTerminal` turns ⌘+Return into `\e[13;9u`, and Apple
        // Terminal's `\e\r` is Shift+Enter rather than Alt+Enter (see `Keys::isAltEnter()`), so on
        // that terminal this is the follow-up key there is. Idle, the action sends.
        'app.message.followUp' => ['defaultKeys' => ['command+enter', 'alt+enter'], 'description' => 'Queue follow-up message'],
        'app.message.dequeue' => ['defaultKeys' => 'alt+up', 'description' => 'Restore queued messages'],
        'app.clipboard.pasteImage' => ['defaultKeys' => 'ctrl+v', 'description' => 'Paste files on macOS, images, or text from clipboard'],
        'app.session.new' => ['defaultKeys' => [], 'description' => 'Start a new session'],
        'app.session.tree' => ['defaultKeys' => [], 'description' => 'Open session tree'],
        'app.session.fork' => ['defaultKeys' => [], 'description' => 'Fork current session'],
        'app.session.resume' => ['defaultKeys' => [], 'description' => 'Resume a session'],
        'app.tree.foldOrUp' => ['defaultKeys' => ['ctrl+left', 'alt+left'], 'description' => 'Fold tree branch or move up'],
        'app.tree.unfoldOrDown' => ['defaultKeys' => ['ctrl+right', 'alt+right'], 'description' => 'Unfold tree branch or move down'],
        'app.tree.editLabel' => ['defaultKeys' => 'shift+l', 'description' => 'Edit tree label'],
        'app.tree.toggleLabelTimestamp' => ['defaultKeys' => 'shift+t', 'description' => 'Toggle tree label timestamps'],
        'app.session.togglePath' => ['defaultKeys' => 'ctrl+p', 'description' => 'Toggle session path display'],
        'app.session.toggleSort' => ['defaultKeys' => 'ctrl+s', 'description' => 'Toggle session sort mode'],
        'app.session.rename' => ['defaultKeys' => 'ctrl+r', 'description' => 'Rename session'],
        'app.session.delete' => ['defaultKeys' => 'ctrl+d', 'description' => 'Delete session'],
        'app.session.deleteNoninvasive' => ['defaultKeys' => 'ctrl+backspace', 'description' => 'Delete session when query is empty'],
        'app.models.save' => ['defaultKeys' => 'ctrl+s', 'description' => 'Save model selection'],
        'app.models.enableAll' => ['defaultKeys' => 'ctrl+a', 'description' => 'Enable all models'],
        'app.models.clearAll' => ['defaultKeys' => 'ctrl+x', 'description' => 'Clear all models'],
        'app.models.toggleProvider' => ['defaultKeys' => 'ctrl+p', 'description' => 'Toggle all models for provider'],
        'app.models.reorderUp' => ['defaultKeys' => 'alt+up', 'description' => 'Move model up in order'],
        'app.models.reorderDown' => ['defaultKeys' => 'alt+down', 'description' => 'Move model down in order'],
        'app.tree.filter.default' => ['defaultKeys' => 'ctrl+d', 'description' => 'Tree filter: default view'],
        'app.tree.filter.noTools' => ['defaultKeys' => 'ctrl+t', 'description' => 'Tree filter: hide tool results'],
        'app.tree.filter.userOnly' => ['defaultKeys' => 'ctrl+u', 'description' => 'Tree filter: user messages only'],
        'app.tree.filter.labeledOnly' => ['defaultKeys' => 'ctrl+l', 'description' => 'Tree filter: labeled entries only'],
        'app.tree.filter.all' => ['defaultKeys' => 'ctrl+a', 'description' => 'Tree filter: show all entries'],
        'app.tree.filter.cycleForward' => ['defaultKeys' => 'ctrl+o', 'description' => 'Tree filter: cycle forward'],
        'app.tree.filter.cycleBackward' => ['defaultKeys' => 'shift+ctrl+o', 'description' => 'Tree filter: cycle backward'],
    ];

    /**
     * Upstream's `KEYBINDING_NAME_MIGRATIONS`: the flat names `keybindings.json` used before the
     * ids were namespaced. A file still written that way is read as the new names.
     *
     * @var array<string, string>
     */
    public const array NAME_MIGRATIONS = [
        'cursorUp' => 'tui.editor.cursorUp',
        'cursorDown' => 'tui.editor.cursorDown',
        'cursorLeft' => 'tui.editor.cursorLeft',
        'cursorRight' => 'tui.editor.cursorRight',
        'cursorWordLeft' => 'tui.editor.cursorWordLeft',
        'cursorWordRight' => 'tui.editor.cursorWordRight',
        'cursorLineStart' => 'tui.editor.cursorLineStart',
        'cursorLineEnd' => 'tui.editor.cursorLineEnd',
        'jumpForward' => 'tui.editor.jumpForward',
        'jumpBackward' => 'tui.editor.jumpBackward',
        'pageUp' => 'tui.editor.pageUp',
        'pageDown' => 'tui.editor.pageDown',
        'deleteCharBackward' => 'tui.editor.deleteCharBackward',
        'deleteCharForward' => 'tui.editor.deleteCharForward',
        'deleteWordBackward' => 'tui.editor.deleteWordBackward',
        'deleteWordForward' => 'tui.editor.deleteWordForward',
        'deleteToLineStart' => 'tui.editor.deleteToLineStart',
        'deleteToLineEnd' => 'tui.editor.deleteToLineEnd',
        'yank' => 'tui.editor.yank',
        'yankPop' => 'tui.editor.yankPop',
        'undo' => 'tui.editor.undo',
        'newLine' => 'tui.input.newLine',
        'submit' => 'tui.input.submit',
        'tab' => 'tui.input.tab',
        'copy' => 'tui.input.copy',
        'selectUp' => 'tui.select.up',
        'selectDown' => 'tui.select.down',
        'selectPageUp' => 'tui.select.pageUp',
        'selectPageDown' => 'tui.select.pageDown',
        'selectConfirm' => 'tui.select.confirm',
        'selectCancel' => 'tui.select.cancel',
        'interrupt' => 'app.interrupt',
        'clear' => 'app.clear',
        'exit' => 'app.exit',
        'suspend' => 'app.suspend',
        'cycleThinkingLevel' => 'app.thinking.cycle',
        'cycleModelForward' => 'app.model.cycleForward',
        'cycleModelBackward' => 'app.model.cycleBackward',
        'selectModel' => 'app.model.select',
        'expandTools' => 'app.tools.expand',
        'toggleThinking' => 'app.thinking.toggle',
        'toggleSessionNamedFilter' => 'app.session.toggleNamedFilter',
        'externalEditor' => 'app.editor.external',
        'followUp' => 'app.message.followUp',
        'dequeue' => 'app.message.dequeue',
        'pasteImage' => 'app.clipboard.pasteImage',
        'newSession' => 'app.session.new',
        'tree' => 'app.session.tree',
        'fork' => 'app.session.fork',
        'resume' => 'app.session.resume',
        'treeFoldOrUp' => 'app.tree.foldOrUp',
        'treeUnfoldOrDown' => 'app.tree.unfoldOrDown',
        'treeEditLabel' => 'app.tree.editLabel',
        'treeToggleLabelTimestamp' => 'app.tree.toggleLabelTimestamp',
        'toggleSessionPath' => 'app.session.togglePath',
        'toggleSessionSort' => 'app.session.toggleSort',
        'renameSession' => 'app.session.rename',
        'deleteSession' => 'app.session.delete',
        'deleteSessionNoninvasive' => 'app.session.deleteNoninvasive',
    ];

    /** @var list<string> */
    private array $problems;

    /**
     * Upstream's `new KeybindingsManager(userBindings, configPath)`.
     *
     * @param array<string, list<string>> $overrides actions whose keys replace the defaults
     * @param list<string>                $problems  what `load()` could not read
     * @param string|null                 $configPath the file `reload()` reads again; none, nothing to reload
     * @param string|null                 $platform  upstream's `process.platform` (`win32`, `linux`, `darwin`); null is this machine
     * @param array<string, string>|null  $env       the environment the WSL test reads; null is this process's
     */
    public function __construct(
        array $overrides = [],
        array $problems = [],
        private readonly ?string $configPath = null,
        ?string $platform = null,
        ?array $env = null,
    ) {
        parent::__construct(self::keybindingDefinitions($platform, $env), $overrides);
        $this->problems = $problems;
    }

    /** Upstream's `process.platform`, from PHP's family name. */
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'win32',
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    /**
     * Upstream's `useWindowsKeybindings()`: native Windows, or Linux under WSL — where the
     * terminal in front of pig is a Windows one, and the keys it can send are Windows' keys.
     *
     * @param array<string, string>|null $env
     */
    public static function useWindowsKeybindings(?string $platform = null, ?array $env = null): bool
    {
        $platform ??= self::platform();

        if ($platform === 'win32') {
            return true;
        }

        if ($platform !== 'linux') {
            return false;
        }

        $read = static fn (string $name): string => (string) ($env === null ? getenv($name) : ($env[$name] ?? ''));

        return $read('WSL_DISTRO_NAME') !== '' || $read('WSL_INTEROP') !== '';
    }

    /**
     * Upstream's `APP_KEYBINDINGS` for one platform: `app.suspend` has no key on native Windows,
     * whose terminals have no job control; Windows and WSL move the keys their terminals keep for
     * themselves (`shift+ctrl+p`, `alt+enter`, `alt+up`, `ctrl+v`); macOS lists `alt+left` first
     * for the tree, because that is the key Option+Left is.
     *
     * @param array<string, string>|null $env
     *
     * @return array<string, array{defaultKeys: string|list<string>, description: string}>
     */
    public static function appDefinitions(?string $platform = null, ?array $env = null): array
    {
        $platform ??= self::platform();
        $windows = self::useWindowsKeybindings($platform, $env);
        $app = self::APP_KEYBINDINGS;

        if ($platform === 'win32') {
            $app['app.suspend']['defaultKeys'] = [];
        }

        if ($windows) {
            $app['app.model.cycleBackward']['defaultKeys'] = 'alt+p';
            $app['app.message.followUp']['defaultKeys'] = 'ctrl+q';
            $app['app.message.dequeue']['defaultKeys'] = 'alt+q';
            $app['app.clipboard.pasteImage']['defaultKeys'] = 'alt+v';
        }

        if ($platform === 'darwin') {
            $app['app.tree.foldOrUp']['defaultKeys'] = ['alt+left', 'ctrl+left'];
            $app['app.tree.unfoldOrDown']['defaultKeys'] = ['alt+right', 'ctrl+right'];
        }

        return $app;
    }

    /**
     * Upstream's `KEYBINDINGS`: the TUI's definitions, the four platform overrides it makes to
     * them (undo, the prompt jumps and transcript search), then the app's.
     *
     * @param array<string, string>|null $env
     *
     * @return array<string, KeybindingDefinition>
     */
    public static function keybindingDefinitions(?string $platform = null, ?array $env = null): array
    {
        $platform ??= self::platform();
        $windows = self::useWindowsKeybindings($platform, $env);
        $tui = TuiKeybindings::TUI_KEYBINDINGS;

        $tui['tui.editor.undo']['defaultKeys'] = $platform === 'win32' ? 'ctrl+z' : ($windows ? 'alt+z' : 'ctrl+-');
        $tui['tui.altScreen.previousPrompt']['defaultKeys'] = $windows ? 'ctrl+up' : ['ctrl+shift+up', 'ctrl+up'];
        $tui['tui.altScreen.nextPrompt']['defaultKeys'] = $windows ? 'ctrl+down' : ['ctrl+shift+down', 'ctrl+down'];
        $tui['tui.altScreen.search']['defaultKeys'] = $windows ? 'ctrl+f' : 'ctrl+shift+f';

        return TuiKeybindings::definitions([...$tui, ...self::appDefinitions($platform, $env)]);
    }

    /** Upstream's coding-agent manager, for `Pig\Tui\Keybindings::setKeybindings()`: this one. */
    public function tuiKeybindings(): KeybindingsManager
    {
        return $this;
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Upstream's `KeybindingsManager.create()`: read the file if there is one; a missing file is
     * the defaults, a broken one is named.
     */
    public static function load(?string $home = null): self
    {
        $path = ($home ?? Config::home()) . '/' . self::FILE;
        [$overrides, $problems] = self::loadFromFile($path);

        return new self($overrides, $problems, $path);
    }

    /**
     * Upstream's `reload()`: read the file again into this same manager, so every component that
     * holds it — and the TUI's global registry, which is it — sees the new keys at once.
     *
     * @return list<string> what the file had that could not be read, for the caller to say
     */
    public function reload(): array
    {
        if ($this->configPath === null) {
            return [];
        }

        [$overrides, $this->problems] = self::loadFromFile($this->configPath);
        $this->setUserBindings($overrides);

        return $this->problems;
    }

    /** Upstream's `getEffectiveConfig()`. @return array<string, string|list<string>> */
    public function getEffectiveConfig(): array
    {
        return $this->getResolvedBindings();
    }

    /**
     * Upstream's `migrateKeybindingsConfig()`: old flat names to namespaced ones. When a file has
     * both spellings of one action the new one wins, as upstream's does.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{config: array<array-key, mixed>, migrated: bool}
     */
    public static function migrateKeybindingsConfig(array $raw): array
    {
        $config = [];
        $migrated = false;

        foreach ($raw as $key => $value) {
            $next = is_string($key) ? (self::NAME_MIGRATIONS[$key] ?? $key) : $key;

            if ($next !== $key) {
                $migrated = true;

                if (array_key_exists($next, $raw)) {
                    continue;
                }
            }

            $config[$next] = $value;
        }

        return ['config' => $config, 'migrated' => $migrated];
    }

    /**
     * @return array{0: array<string, list<string>>, 1: list<string>} overrides, problems
     */
    private static function loadFromFile(string $path): array
    {
        if (!is_file($path)) {
            return [[], []];
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return [[], ["keybindings: {$path} could not be read; using the defaults"]];
        }

        // Upstream's `stripBom()`: an editor on Windows writes one, and JSON has no place for it.
        if (str_starts_with($raw, "\u{FEFF}")) {
            $raw = substr($raw, 3);
        }

        $parsed = json_decode($raw, true);

        if (!is_array($parsed) || array_is_list($parsed) && $parsed !== []) {
            return [[], ["keybindings: {$path} is not a JSON object; using the defaults"]];
        }

        $definitions = self::keybindingDefinitions();
        $overrides = [];
        $problems = [];

        foreach (self::migrateKeybindingsConfig($parsed)['config'] as $action => $keys) {
            if ($action === '$schema') {
                continue;
            }

            if (!is_string($action) || !isset($definitions[$action])) {
                $problems[] = "keybindings: {$path} binds '{$action}', which pig has no action for";
                continue;
            }

            $list = is_string($keys) ? [$keys] : (is_array($keys) && array_is_list($keys) ? $keys : null);

            if ($list === null) {
                $problems[] = "keybindings: {$path}: '{$action}' must be a key or a list of keys";
                continue;
            }

            $good = [];

            foreach ($list as $key) {
                if (!is_string($key) || !Keys::isKeyName($key)) {
                    $problems[] = "keybindings: {$path}: '{$action}' names a key this cannot read: " . json_encode($key);
                    continue;
                }

                $good[] = self::canonical($key);
            }

            // An explicit empty list unbinds the action, as upstream's `defaultKeys: []` does. A
            // list that was *only* typos is not that: the default stands, and the complaint above
            // says why nothing changed.
            if ($good !== [] || $list === []) {
                $overrides[$action] = $good;
            }
        }

        return [$overrides, $problems];
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> the keys bound to $action, as written */
    public function keysFor(string $action): array
    {
        return $this->getKeys($action);
    }

    /**
     * The first `app.*` action $data is bound to, in upstream's definition order, or null when it
     * is just typing. For a caller that wants a name for a key; `CustomEditor` asks about the
     * actions it has handlers for instead, as upstream's does, because several `app.*` ids share
     * a key on purpose (`ctrl+s` saves in three different selectors).
     */
    public function actionFor(string $data): ?string
    {
        foreach (array_keys(self::APP_KEYBINDINGS) as $action) {
            if ($this->matches($data, $action)) {
                return $action;
            }
        }

        return null;
    }

    /** The first key of $action, for the help text; `(unbound)` when there is none. */
    public function label(string $action): string
    {
        $keys = $this->keysFor($action);

        return $keys === [] ? '(unbound)' : str_replace('escape', 'esc', $keys[0]);
    }

    /**
     * Every key of $action, as upstream's `keyText()` writes them into a status line: the key ids as
     * they are bound, joined by `/`, with `alt` said as `option` on a Mac and nothing capitalised —
     * `escape` for the default interrupt key, which is what upstream's retry and summary loaders
     * say (`(escape to cancel)`). Empty when nothing is bound.
     */
    public function keyText(string $action): string
    {
        return implode('/', array_map(
            static fn (string $key): string => implode('+', array_map(
                static fn (string $part): string => PHP_OS_FAMILY === 'Darwin' && strtolower($part) === 'alt' ? 'option' : $part,
                explode('+', $key),
            )),
            $this->keysFor($action),
        ));
    }

    /**
     * The first key of $action as a hint names it — upstream's `keyDisplayText()`: each part
     * capitalised, and `alt` said as `option` on a Mac, because that is what is printed on the key.
     * `label()` is the help table's spelling and this is the one for a sentence on screen.
     */
    public function display(string $action): string
    {
        $keys = $this->keysFor($action);

        if ($keys === []) {
            return '(unbound)';
        }

        $parts = explode('+', $keys[0]);

        return implode('+', array_map(static function (string $part): string {
            $part = PHP_OS_FAMILY === 'Darwin' && $part === 'alt' ? 'option' : $part;

            return ucfirst($part);
        }, $parts));
    }

    /** `Ctrl+Shift+O` and `shift+ctrl+o` are one key; this is how it is spelled here. */
    private static function canonical(string $key): string
    {
        $parts = array_map('strtolower', array_map('trim', explode('+', $key)));
        // `ctrl++` is the plus key: two empty parts at the end, put back as one `+`.
        $last = array_pop($parts);
        if ($last === '' && $parts !== [] && end($parts) === '') {
            array_pop($parts);
            $last = '+';
        }
        $order = ['shift' => 0, 'ctrl' => 1, 'control' => 1, 'alt' => 2, 'option' => 2, 'meta' => 3, 'super' => 3, 'cmd' => 3, 'command' => 3];
        usort($parts, static fn (string $a, string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $parts = array_map(static fn (string $p): string => match ($p) { 'control' => 'ctrl', 'option' => 'alt', 'cmd', 'super' => 'command', default => $p }, $parts);

        return implode('+', [...$parts, $last === 'esc' ? 'escape' : $last]);
    }
}
