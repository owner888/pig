<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

/**
 * Shared state container for a session's hooks and extensions.
 *
 * Allows handlers to share state, services, and middleware data across
 * turns and events without defining global variables or symbols.
 */
final class HookState
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
