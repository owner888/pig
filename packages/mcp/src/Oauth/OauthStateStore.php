<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

/**
 * Where one server's OAuth state lives — client registration, tokens, the pending PKCE verifier
 * and `state`, the discovery it did. One record, read and written whole.
 */
interface OauthStateStore
{
    /** @return array<string, mixed>|null */
    public function load(): ?array;

    /** @param array<string, mixed> $state */
    public function save(array $state): void;
}
