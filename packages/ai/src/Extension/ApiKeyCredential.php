<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

/**
 * Upstream's `ApiKeyCredential`: "Stored api-key credential. `env` holds provider-scoped
 * environment/config values such as Cloudflare account/gateway ids."
 *
 * The `api_key` entry of `auth.json` — `{"type": "api_key", "key": "…", "env": {…}}` — which pi
 * writes and reads as well, so the key is optional here as it is there: a keyless local server
 * stores only its `env`.
 */
final readonly class ApiKeyCredential
{
    /** @param array<string, string> $env */
    public function __construct(
        public ?string $key = null,
        public array $env = [],
    ) {
    }
}
