<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Resolves actions to keys — upstream's `KeybindingsManager` in `keybindings.ts`. A user binding
 * replaces the action's default keys rather than adding to them; `[]` unbinds it.
 *
 * `matchesKey()` upstream is `Keys::matchesName()` here.
 */
class KeybindingsManager
{
    /** @var array<string, list<string>> */
    private array $keysById = [];

    /** @var list<KeybindingConflict> */
    private array $conflicts = [];

    /**
     * @param array<string, KeybindingDefinition> $definitions
     * @param array<string, string|list<string>|null> $userBindings
     */
    public function __construct(
        private readonly array $definitions,
        private array $userBindings = [],
    ) {
        $this->rebuild();
    }

    /**
     * @param string|list<string>|null $keys
     *
     * @return list<string>
     */
    private static function normalizeKeys(string|array|null $keys): array
    {
        if ($keys === null) {
            return [];
        }

        return array_values(array_unique(is_array($keys) ? $keys : [$keys]));
    }

    private function rebuild(): void
    {
        $this->keysById = [];
        $this->conflicts = [];

        /** @var array<string, list<string>> $userClaims */
        $userClaims = [];
        foreach ($this->userBindings as $keybinding => $keys) {
            if (!isset($this->definitions[$keybinding])) {
                continue;
            }
            foreach (self::normalizeKeys($keys) as $key) {
                $userClaims[$key] ??= [];
                if (!in_array($keybinding, $userClaims[$key], true)) {
                    $userClaims[$key][] = $keybinding;
                }
            }
        }

        foreach ($userClaims as $key => $keybindings) {
            if (count($keybindings) > 1) {
                $this->conflicts[] = new KeybindingConflict((string) $key, $keybindings);
            }
        }

        foreach ($this->definitions as $id => $definition) {
            $this->keysById[$id] = array_key_exists($id, $this->userBindings) && $this->userBindings[$id] !== null
                ? self::normalizeKeys($this->userBindings[$id])
                : self::normalizeKeys($definition->defaultKeys);
        }
    }

    public function matches(string $data, string $keybinding): bool
    {
        foreach ($this->keysById[$keybinding] ?? [] as $key) {
            if (Keys::matchesName($data, $key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function getKeys(string $keybinding): array
    {
        return $this->keysById[$keybinding] ?? [];
    }

    public function getDefinition(string $keybinding): KeybindingDefinition
    {
        return $this->definitions[$keybinding] ?? throw new TuiError("Unknown keybinding '{$keybinding}'");
    }

    /** @return list<KeybindingConflict> */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /** @param array<string, string|list<string>|null> $userBindings */
    public function setUserBindings(array $userBindings): void
    {
        $this->userBindings = $userBindings;
        $this->rebuild();
    }

    /** @return array<string, string|list<string>|null> */
    public function getUserBindings(): array
    {
        return $this->userBindings;
    }

    /** @return array<string, string|list<string>> */
    public function getResolvedBindings(): array
    {
        $resolved = [];
        foreach (array_keys($this->definitions) as $id) {
            $keys = $this->keysById[$id] ?? [];
            $resolved[$id] = count($keys) === 1 ? $keys[0] : $keys;
        }

        return $resolved;
    }
}
