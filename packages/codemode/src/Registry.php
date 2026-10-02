<?php

declare(strict_types=1);

namespace Pig\Codemode;

use Closure;

/**
 * The tools a script may call that are **not** declared to the model — upstream's loadout, the
 * half of it that codemode and the MCP extension share: `getExposure()` and `getNamespace()`.
 *
 * Upstream keeps every tool in one registry with an exposure, and the agent declares only the
 * `direct` ones. pig has no such registry: a tool the agent holds is a tool the model sees. So a
 * `codemode`-exposed MCP tool is **not** given to the agent at all; the MCP extension puts it
 * here instead, and the codemode tool is what reaches it. A `direct` tool is on the agent and
 * callable from scripts too; it is found through the agent, not here.
 *
 * Static, for `Models::register()`'s reason: two extensions loaded in either order have to see
 * one list, and an extension has nothing but its own API object to hold state in.
 */
final class Registry
{
    /**
     * @var array<string, array{name: string, description: string, parameters: array<string, mixed>, outputSchema?: mixed, execute: Closure, namespace: ?array{name: string, description: ?string}, exposure: string}>
     */
    private static array $tools = [];

    /** @var list<Closure(): void> told when the list changes */
    private static array $listeners = [];

    /**
     * @param array<string, mixed> $parameters
     * @param Closure(array<string, mixed>, \Pig\Async\AbortSignal, \Pig\CodingAgent\Hooks\HookContext): mixed $execute
     * @param array{name: string, description: ?string}|null $namespace
     * @param 'codemode'|'codemode-deferred' $exposure
     */
    public static function register(string $name, string $description, array $parameters, Closure $execute, ?array $namespace, string $exposure, mixed $outputSchema = null): void
    {
        self::$tools[$name] = [
            'name' => $name,
            'description' => $description,
            'parameters' => $parameters,
            'outputSchema' => $outputSchema,
            'execute' => $execute,
            'namespace' => $namespace,
            'exposure' => $exposure,
        ];
        self::changed();
    }

    /** @param Closure(string): bool $which */
    public static function remove(Closure $which): void
    {
        $before = count(self::$tools);
        self::$tools = array_filter(self::$tools, static fn (array $tool): bool => !$which($tool['name']));

        if (count(self::$tools) !== $before) {
            self::changed();
        }
    }

    /** @return array<string, array{name: string, description: string, parameters: array<string, mixed>, outputSchema?: mixed, execute: Closure, namespace: ?array{name: string, description: ?string}, exposure: string}> */
    public static function all(): array
    {
        return self::$tools;
    }

    public static function isEmpty(): bool
    {
        return self::$tools === [];
    }

    /** @param Closure(): void $listener */
    public static function onChange(Closure $listener): Closure
    {
        self::$listeners[] = $listener;

        return static function () use ($listener): void {
            self::$listeners = array_values(array_filter(self::$listeners, static fn (Closure $l): bool => $l !== $listener));
        };
    }

    /** For tests: nothing registered, nobody listening. */
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
