<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

final class MemoryOauthStateStore implements OauthStateStore
{
    /** @var array<string, mixed>|null */
    private ?array $value = null;

    #[\Override]
    public function load(): ?array
    {
        return $this->value;
    }

    #[\Override]
    public function save(array $state): void
    {
        $this->value = $state;
    }
}
