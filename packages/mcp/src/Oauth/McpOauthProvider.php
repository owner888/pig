<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use Closure;
use RuntimeException;

/**
 * The OAuth state of one MCP server — upstream's `McpOAuthProvider`: what is registered, what
 * tokens there are, the PKCE verifier and `state` of a sign-in in progress, and what discovery
 * found. All of it in one record in an `OauthStateStore`, keyed by the server's URL so state for
 * another server is never read as this one's.
 *
 * `onRedirect` is handed the authorization URL when the flow needs the browser.
 */
final class McpOauthProvider
{
    public readonly string $serverUrl;

    public readonly string $redirectUrl;

    /** @var array<string, mixed> */
    public readonly array $clientMetadata;

    /** @var array<string, mixed>|null a pre-registered client, which the store never overrides */
    private readonly ?array $configuredClient;

    private readonly OauthStateStore $store;

    /**
     * @param array<string, mixed> $clientMetadata
     * @param Closure(string): void $onRedirect
     */
    public function __construct(
        string $serverUrl,
        string $redirectUrl,
        array $clientMetadata,
        private readonly Closure $onRedirect,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?OauthStateStore $store = null,
    ) {
        $this->serverUrl = $serverUrl;
        $this->redirectUrl = $redirectUrl;
        $this->clientMetadata = [
            ...$clientMetadata,
            'redirect_uris' => $clientMetadata['redirect_uris'] ?? [$redirectUrl],
            'grant_types' => $clientMetadata['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'response_types' => $clientMetadata['response_types'] ?? ['code'],
            'token_endpoint_auth_method' => $clientMetadata['token_endpoint_auth_method'] ?? ($clientSecret !== null ? 'client_secret_post' : 'none'),
        ];
        $this->configuredClient = $clientId !== null
            ? ['client_id' => $clientId, ...($clientSecret !== null ? ['client_secret' => $clientSecret] : [])]
            : null;
        $this->store = $store ?? new MemoryOauthStateStore();
    }

    /** The `state` of the sign-in in progress, minted on first ask. */
    public function state(): string
    {
        $existing = $this->load()['oauthState'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $state = bin2hex(random_bytes(32));
        $this->update(static fn (array $value): array => [...$value, 'oauthState' => $state]);

        return $state;
    }

    /** @return array<string, mixed>|null */
    public function clientInformation(): ?array
    {
        return $this->configuredClient ?? ($this->load()['clientInformation'] ?? null);
    }

    /** @param array<string, mixed> $information */
    public function saveClientInformation(array $information): void
    {
        if ($this->configuredClient !== null) {
            return;
        }

        $this->update(static fn (array $value): array => [...$value, 'clientInformation' => $information]);
    }

    /** @return array<string, mixed>|null */
    public function tokens(): ?array
    {
        return $this->load()['tokens'] ?? null;
    }

    /** @param array<string, mixed> $tokens */
    public function saveTokens(array $tokens): void
    {
        $expiresAt = isset($tokens['expires_in']) ? (int) (microtime(true) * 1000) + (int) ($tokens['expires_in'] * 1000) : null;

        $this->update(static function (array $value) use ($tokens, $expiresAt): array {
            $next = [...$value, 'tokens' => $tokens];

            if ($expiresAt === null) {
                unset($next['tokensExpireAt']);
            } else {
                $next['tokensExpireAt'] = $expiresAt;
            }

            return $next;
        });
    }

    public function redirectToAuthorization(string $url): void
    {
        ($this->onRedirect)($url);
    }

    public function saveCodeVerifier(string $verifier): void
    {
        $this->update(static fn (array $value): array => [...$value, 'codeVerifier' => $verifier]);
    }

    public function codeVerifier(): string
    {
        $verifier = $this->load()['codeVerifier'] ?? null;

        if (!is_string($verifier) || $verifier === '') {
            throw new RuntimeException('No OAuth PKCE code verifier is stored');
        }

        return $verifier;
    }

    /** @param 'all'|'client'|'tokens'|'verifier'|'discovery' $kind */
    public function invalidateCredentials(string $kind): void
    {
        $this->update(static function (array $value) use ($kind): array {
            if ($kind === 'all' || $kind === 'client') {
                unset($value['clientInformation']);
            }

            if ($kind === 'all' || $kind === 'tokens') {
                unset($value['tokens'], $value['tokensExpireAt']);
            }

            if ($kind === 'all' || $kind === 'verifier') {
                unset($value['codeVerifier']);
            }

            if ($kind === 'all' || $kind === 'discovery') {
                unset($value['discovery']);
            }

            if ($kind === 'all') {
                unset($value['oauthState']);
            }

            return $value;
        });
    }

    /** @param array<string, mixed> $discovery */
    public function saveDiscoveryState(array $discovery): void
    {
        $this->update(static fn (array $value): array => [...$value, 'discovery' => $discovery]);
    }

    /** @return array<string, mixed>|null */
    public function discoveryState(): ?array
    {
        return $this->load()['discovery'] ?? null;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        return $this->own($this->store->load());
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $update */
    private function update(Closure $update): void
    {
        $this->store->save($update($this->own($this->store->load())));
    }

    /**
     * Stored state for another server URL is ignored so credentials never leak across servers.
     *
     * @param array<string, mixed>|null $state
     * @return array<string, mixed>
     */
    private function own(?array $state): array
    {
        return ($state['serverUrl'] ?? null) === $this->serverUrl ? $state : ['serverUrl' => $this->serverUrl];
    }
}
