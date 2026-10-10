<?php

declare(strict_types=1);

namespace Pig\Codemode;

use Closure;

/**
 * The tools `tool_search` can load — upstream's loadout, the `deferred` half: what
 * `tool-search/tool.ts` reads with `getAllTools()` (exposure `deferred`, not active) and
 * activates with `setActiveTools()`.
 *
 * pig has no exposure on a registered tool: a tool the agent holds is a tool the model sees
 * (see `Registry`). So an extension with a deferred tool does not register it; it lists it
 * here, with a `load` closure that registers it through the extension's own API when
 * `tool_search` names it — which keeps the tool the extension's to take away again. A listed
 * tool that is loaded stays listed, `loaded`, as upstream's stays registered and active, so the
 * extension that owns `tool_search` can tell a loaded tool from one nobody offered.
 *
 * Static, for `Registry`'s reason: the extension that lists and the one that searches are two
 * extensions loaded in either order, and they have to see one list.
 */
final class DeferredTools
{
    /**
     * @var array<string, array{name: string, description: string, inputSchema: mixed, namespace: array{name: string, description: ?string}, loaded: bool, load: Closure}>
     *      in the order upstream's registry would hold them: listed, then loaded
     */
    private static array $tools = [];

    /** @var list<Closure(): void> told when the list changes */
    private static array $listeners = [];

    /**
     * List a tool, or update one listed under that name in place. `$description` and
     * `$inputSchema` are what the search reads — the tool as its source describes it.
     *
     * @param array{name: string, description: ?string} $namespace where it comes from — an MCP server
     * @param Closure(): \Pig\CodingAgent\CustomTools\CustomTool $load registers the tool and answers it
     * @param bool $loaded already registered — a tool a resumed session declared
     */
    public static function register(string $name, string $description, mixed $inputSchema, array $namespace, Closure $load, bool $loaded = false): void
    {
        self::$tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'namespace' => $namespace,
            'loaded' => $loaded,
            'load' => $load,
        ];
        self::changed();
    }

    /**
     * Load a listed tool that is not loaded yet — upstream's `setActiveTools([...active, name])`.
     * Answers the registered tool, or null when there is nothing to load under that name.
     */
    public static function load(string $name): ?\Pig\CodingAgent\CustomTools\CustomTool
    {
        $entry = self::$tools[$name] ?? null;

        if ($entry === null || $entry['loaded']) {
            return null;
        }

        // To the end, as a newly activated tool goes after the ones active before it.
        unset(self::$tools[$name]);
        self::$tools[$name] = [...$entry, 'loaded' => true];
        $tool = ($entry['load'])();
        self::changed();

        return $tool;
    }

    public static function isLoaded(string $name): bool
    {
        return self::$tools[$name]['loaded'] ?? false;
    }

    /** @param Closure(array{name: string, description: string, inputSchema: mixed, namespace: array{name: string, description: ?string}, loaded: bool, load: Closure}): bool $which */
    public static function remove(Closure $which): void
    {
        $before = count(self::$tools);
        self::$tools = array_filter(self::$tools, static fn (array $tool): bool => !$which($tool));

        if (count(self::$tools) !== $before) {
            self::changed();
        }
    }

    /** @return array<string, array{name: string, description: string, inputSchema: mixed, namespace: array{name: string, description: ?string}, loaded: bool, load: Closure}> */
    public static function all(): array
    {
        return self::$tools;
    }

    /** @param Closure(): void $listener */
    public static function onChange(Closure $listener): Closure
    {
        self::$listeners[] = $listener;

        return static function () use ($listener): void {
            self::$listeners = array_values(array_filter(self::$listeners, static fn (Closure $l): bool => $l !== $listener));
        };
    }

    /** For tests: nothing listed, nobody listening. */
    public static function reset(): void
    {
        self::$tools = [];
        self::$listeners = [];
    }

    private static function changed(): void
    {
        foreach (self::$listeners as $listener) {
            $listener();
        }
    }
}
