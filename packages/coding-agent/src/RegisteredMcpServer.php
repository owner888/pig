<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/** A server an extension registered with `$pi->registerMcpServer()`. Upstream's `RegisteredMcpServer`. */
final readonly class RegisteredMcpServer
{
    /** @param array<string, mixed> $config the validated `mcpServers` entry */
    public function __construct(
        public string $name,
        public array $config,
        /** Path of the extension that registered the server. */
        public string $extensionPath,
    ) {
    }
}
