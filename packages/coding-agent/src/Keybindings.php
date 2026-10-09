<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Tui\Keybindings as TuiKeybindings;
use Pig\Tui\KeybindingsManager;
use Pig\Tui\Keys;

/**
 * Which key does what in the terminal — upstream's `core/keybindings.ts`, the `app.*` half.
 *
 * `~/.pig/agent/keybindings.json`, optional, upstream's action names and upstream's key spelling:
 *
 * ```json
 * { "app.model.select": "ctrl+m", "app.tools.expand": ["ctrl+o", "ctrl+e"] }
 * ```
 *
 * The keys were hardcoded before this, and the reason that stopped being acceptable is the
 * one upstream had: `ctrl+l`, `ctrl+o` and `ctrl+p` are also tmux's, screen's and a dozen
 * editors' keys, and a terminal that eats one of them leaves pig with a feature nobody can
 * reach. A binding here is **the** binding — it replaces the default rather than adding to
 * it, so `"app.tools.expand": "ctrl+e"` frees `ctrl+o` for whatever was fighting over it.
 *
 * Only the `app.*` actions, because those are the keys `CustomEditor` *claims* before the text
 * field sees them; the editing keys inside the field (`tui.editor.*` upstream) stay as they are.
 * An action a file names that pig does not have, or a key `Keys::matchesName()` cannot read, is
 * a complaint at startup rather than a binding that silently never fires.
 */
final class Keybindings
{
    public const string FILE = 'keybindings.json';

    /**
     * Upstream's defaults, under upstream's names, for the actions pig has.
     *
     * @var array<string, list<string>>
     */
    public const array DEFAULTS = [
        'app.interrupt' => ['escape'],
        'app.clear' => ['ctrl+c'],
        'app.exit' => ['ctrl+d'],
        'app.suspend' => ['ctrl+z'],
        'app.thinking.cycle' => ['shift+tab'],
        'app.model.cycleForward' => ['ctrl+p'],
        'app.model.cycleBackward' => ['shift+ctrl+p'],
        'app.model.select' => ['ctrl+l'],
        'app.tools.expand' => ['ctrl+o'],
        'app.thinking.toggle' => ['ctrl+t'],
        'app.editor.external' => ['ctrl+g'],
        'app.message.copy' => ['ctrl+x'],
        'app.message.followUp' => ['command+enter', 'alt+enter'],
        'app.message.dequeue' => ['alt+up'],
    ];

    /**
     * The `tui.*` actions a file may rebind: the alternate-screen ones, which `TuiAltScreen` reads
     * from the TUI's registry. The editing keys (`tui.editor.*`, `tui.input.*`, `tui.select.*`) are
     * not read from the registry by pig's components yet, so binding one would never fire.
     */
    private const string TUI_PREFIX = 'tui.altScreen.';

    /** @var array<string, list<string>> action => keys */
    private array $bindings;

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, list<string>> the `tui.altScreen.*` keys a file replaced */
    private array $tuiOverrides = [];

    /**
     * @param array<string, list<string>> $overrides actions whose keys replace the defaults;
     *                                               `tui.altScreen.*` ones go to the TUI's registry
     * @param list<string>                $problems
     */
    public function __construct(array $overrides = [], array $problems = [])
    {
        foreach ($overrides as $action => $keys) {
            if (str_starts_with($action, self::TUI_PREFIX)) {
                $this->tuiOverrides[$action] = $keys;
                unset($overrides[$action]);
            }
        }
        $this->bindings = [...self::DEFAULTS, ...$overrides];
        $this->problems = $problems;
    }

    /** Upstream's coding-agent `KeybindingsManager`, for `Pig\Tui\Keybindings::setKeybindings()`. */
    public function tuiKeybindings(): KeybindingsManager
    {
        return new KeybindingsManager(TuiKeybindings::definitions(), $this->tuiOverrides);
    }

    private static function isAction(string $action): bool
    {
        return isset(self::DEFAULTS[$action])
            || (str_starts_with($action, self::TUI_PREFIX) && isset(TuiKeybindings::TUI_KEYBINDINGS[$action]));
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** Read the file if there is one; a missing file is the defaults, a broken one is named. */
    public static function load(?string $home = null): self
    {
        $path = ($home ?? Config::home()) . '/' . self::FILE;

        if (!is_file($path)) {
            return self::defaults();
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;
        $parsed = $raw === false ? null : json_decode($raw, true);

        if (!is_array($parsed)) {
            return new self([], ["keybindings: {$path} is not a JSON object; using the defaults"]);
        }

        $overrides = [];
        $problems = [];

        foreach ($parsed as $action => $keys) {
            if (!is_string($action) || !self::isAction($action)) {
                $problems[] = "keybindings: {$path} binds '{$action}', which pig has no action for";
                continue;
            }

            $list = is_string($keys) ? [$keys] : (is_array($keys) ? $keys : null);

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

        return new self($overrides, $problems);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> the keys bound to $action, as written */
    public function keysFor(string $action): array
    {
        if (str_starts_with($action, self::TUI_PREFIX)) {
            return $this->tuiKeybindings()->getKeys($action);
        }

        return $this->bindings[$action] ?? [];
    }

    /** The action $data is bound to, or null when it is just typing. */
    public function actionFor(string $data): ?string
    {
        foreach ($this->bindings as $action => $keys) {
            foreach ($keys as $key) {
                if (Keys::matchesName($data, $key)) {
                    return $action;
                }
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
        $last = array_pop($parts);
        sort($parts);
        $order = ['shift' => 0, 'ctrl' => 1, 'control' => 1, 'alt' => 2, 'option' => 2, 'meta' => 3, 'super' => 3, 'cmd' => 3, 'command' => 3];
        usort($parts, static fn (string $a, string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $parts = array_map(static fn (string $p): string => match ($p) { 'control' => 'ctrl', 'option' => 'alt', 'cmd', 'super' => 'command', default => $p }, $parts);

        return implode('+', [...$parts, $last === 'esc' ? 'escape' : $last]);
    }
}
