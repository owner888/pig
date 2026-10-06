<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Async\AbortSignal;

/**
 * `AntigravityOauth`, built the moment it is used and not before.
 *
 * The flow's constructor refuses to exist without the client id and secret, which pig does not
 * ship — and this extension has to load, register its provider and list its sign-in whether or
 * not the pair is set, so that `/login antigravity` can say *what is missing* rather than the
 * provider being silently absent. So the row in the list is this, and the refusal happens on
 * `login()` or `refresh()`, where there is somebody to tell.
 *
 * A renewal may use the public desktop client's pair as a fallback (`allowDefault`), because a
 * token already minted under it has to be renewed under it; a fresh sign-in never does.
 */
final class LazyAntigravityOauth implements OauthFlow
{
    /** @param Closure(bool): array{0: string, 1: string} $client */
    public function __construct(private readonly Closure $client)
    {
    }

    #[\Override]
    public function id(): string
    {
        return Models::PROVIDER;
    }

    #[\Override]
    public function label(): string
    {
        return 'Antigravity (Gemini 3, Claude, GPT-OSS)';
    }

    #[\Override]
    public function isSubscription(): bool
    {
        return true;
    }

    #[\Override]
    public function login(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?Closure $onSelect = null,
    ): ?Credentials {
        return $this->flow(false)->login($onAuth, $onPrompt, $onProgress, $signal, $onSelect);
    }

    #[\Override]
    public function refresh(Credentials $credentials): Credentials
    {
        return $this->flow(true)->refresh($credentials);
    }

    #[\Override]
    public function apiKey(Credentials $credentials): string
    {
        // Needs no client: it is arithmetic on the credential, and it runs on every turn.
        return AntigravityOauth::keyFor($credentials);
    }

    private function flow(bool $allowDefault): AntigravityOauth
    {
        [$id, $secret] = ($this->client)($allowDefault);

        return new AntigravityOauth($id, $secret);
    }
}
