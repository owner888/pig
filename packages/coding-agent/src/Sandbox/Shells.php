<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

/** Which `Shell` a child `pig` was started for, from what its environment says (`Shell::environment()`). */
final class Shells
{
    /** @param array<string, string>|null $env this process's when null */
    public static function fromEnvironment(?array $env = null): ?Shell
    {
        $get = static fn (string $key): string => $env !== null ? ($env[$key] ?? '') : (string) getenv($key);

        if ($get('PIG_SANDBOX_CONTAINER') !== '') {
            return new DockerContainer($get('PIG_SANDBOX_CONTAINER'), $get('PIG_SANDBOX_DOCKER') ?: 'docker');
        }

        if ($get('PIG_SANDBOX_SSH') !== '') {
            return SshTarget::fromEnvironment($get('PIG_SANDBOX_SSH')) ?? throw new \Pig\Agent\AgentError('PIG_SANDBOX_SSH does not say where to connect.');
        }

        return null;
    }
}
