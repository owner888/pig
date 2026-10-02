<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

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
        'app.message.followUp' => ['alt+enter'],
        'app.message.dequeue' => ['alt+up'],
    ];

    /** @var array<string, list<string>> action => keys */
    private array $bindings;

    /** @var list<string> */
    private array $problems = [];

    /**
     * @param array<string, list<string>> $overrides actions whose keys replace the defaults
     * @param list<string>                $problems
     */
    public function __construct(array $overrides = [], array $problems = [])
    {
        $this->bindings = [...self::DEFAULTS, ...$overrides];
        $this->problems = $problems;
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
            if (!isset(self::DEFAULTS[$action])) {
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
        $order = ['shift' => 0, 'ctrl' => 1, 'control' => 1, 'alt' => 2, 'option' => 2, 'meta' => 2];
        usort($parts, static fn (string $a, string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $parts = array_map(static fn (string $p): string => match ($p) { 'control' => 'ctrl', 'option', 'meta' => 'alt', default => $p }, $parts);

        return implode('+', [...$parts, $last === 'esc' ? 'escape' : $last]);
    }
}
