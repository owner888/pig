<?php

declare(strict_types=1);

namespace PigMcp;

/** One configured server: its name, its config as validated, and which file said so. */
final class ServerEntry
{
    /**
     * @param array<string, mixed> $config
     * @param 'global'|'project'|'extension' $scope where it came from: a file, or an extension that registered it
     */
    public function __construct(
        public readonly string $name,
        public array $config,
        public readonly string $source,
        public readonly string $scope,
    ) {
    }

    public function isHttp(): bool
    {
        return isset($this->config['url']);
    }

    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? true) !== false;
    }

    /** Upstream's word for it, before pig's mapping. */
    public function exposure(): string
    {
        return (string) ($this->config['exposure'] ?? 'codemode');
    }

    public function timeout(): float
    {
        return (float) ($this->config['timeout'] ?? 60);
    }

    /** The command line or the URL, for a status line. */
    public function describeTransport(): string
    {
        if ($this->isHttp()) {
            return (string) $this->config['url'];
        }

        return implode(' ', [(string) $this->config['command'], ...($this->config['args'] ?? [])]);
    }
}
