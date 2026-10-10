<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

/**
 * Upstream's `AuthResult`: "Result of resolving auth for a model." — the request auth, the
 * provider-scoped environment/config values resolved from credentials and ambient context, and a
 * human-readable label for status UI ("ANTHROPIC_API_KEY", "OAuth", "~/.aws/credentials").
 */
final readonly class AuthResult
{
    /** @param array<string, string> $env */
    public function __construct(
        public ModelAuth $auth,
        public array $env = [],
        public ?string $source = null,
    ) {
    }
}
