<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use Closure;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Utils\Oauth\Pkce;
use RuntimeException;

/**
 * The authorization code flow with PKCE and dynamic client registration — upstream's
 * `oauth/flow.ts`, which is the MCP TypeScript SDK's `auth()` with Zod and the SDK taken out.
 *
 * `authorize()` answers `AUTHORIZED` (there are tokens now: refreshed, or exchanged from a code)
 * or `REDIRECT` (the provider was handed an authorization URL and the caller has to get a code
 * back from the browser).
 *
 * Every request goes through a `Closure(Request): Response`; see `Discovery::fetcher()`.
 */
final class Flow
{
    public const string AUTHORIZED = 'AUTHORIZED';

    public const string REDIRECT = 'REDIRECT';

    /**
     * @param array{serverUrl: string, resourceMetadataUrl?: ?string, authorizationServerMetadataUrl?: ?string, scope?: ?string, authorizationCode?: ?string, iss?: ?string, skipRefresh?: bool, skipIssuerValidation?: bool} $options
     * @param Closure(Request): Response|null $fetch
     */
    public static function authorize(McpOauthProvider $provider, array $options, ?Closure $fetch = null): string
    {
        $fetch = Discovery::fetcher($fetch);

        try {
            return self::run($provider, $options, $fetch);
        } catch (OauthError $error) {
            if (in_array($error->oauthCode, ['invalid_client', 'unauthorized_client'], true)) {
                $provider->invalidateCredentials('all');

                return self::run($provider, $options, $fetch);
            }

            if ($error->oauthCode === 'invalid_grant') {
                $provider->invalidateCredentials('tokens');

                return self::run($provider, $options, $fetch);
            }

            throw $error;
        }
    }

    /** @param array<string, mixed> $options */
    private static function run(McpOauthProvider $provider, array $options, Closure $fetch): string
    {
        $serverUrl = (string) $options['serverUrl'];
        $resourceMetadataUrl = $options['resourceMetadataUrl'] ?? null;
        $skipIssuerValidation = (bool) ($options['skipIssuerValidation'] ?? false);
        $metadataUrl = isset($options['authorizationServerMetadataUrl']) ? self::secureEndpoint((string) $options['authorizationServerMetadataUrl']) : null;

        // With a configured metadata URL, discovery is neither read from the cache nor written to
        // it, so changing the URL applies at once.
        $cached = $metadataUrl === null ? $provider->discoveryState() : [];

        if (is_string($cached['authorizationServerUrl'] ?? null)) {
            $discovered = [
                'authorizationServerUrl' => $cached['authorizationServerUrl'],
                'authorizationServerMetadata' => $cached['authorizationServerMetadata']
                    ?? Discovery::authorizationServerMetadata($cached['authorizationServerUrl'], $fetch, $skipIssuerValidation),
                'resourceMetadata' => $cached['resourceMetadata'] ?? null,
            ];
        } else {
            $discovered = Discovery::serverInfo($serverUrl, $resourceMetadataUrl, $fetch, $skipIssuerValidation, $metadataUrl);
        }

        if ($metadataUrl === null) {
            $provider->saveDiscoveryState([...$discovered, ...($resourceMetadataUrl !== null ? ['resourceMetadataUrl' => $resourceMetadataUrl] : [])]);
        }

        $metadata = $discovered['authorizationServerMetadata'];
        $resource = Discovery::selectResource($serverUrl, $discovered['resourceMetadata']);
        // `?:`, not `??`: an empty scope (`scopes_supported: []`) falls through to the next source.
        $scope = ($options['scope'] ?? null)
            ?: (isset($discovered['resourceMetadata']['scopes_supported']) ? implode(' ', $discovered['resourceMetadata']['scopes_supported']) : null)
            ?: ($provider->clientMetadata['scope'] ?? null)
            ?: null;

        $client = $provider->clientInformation();

        if ($client === null) {
            if (isset($options['authorizationCode'])) {
                throw new RuntimeException('OAuth client information is missing during code exchange');
            }

            $client = self::registerClient($discovered['authorizationServerUrl'], $metadata, $provider->clientMetadata, $scope, $fetch);
            $provider->saveClientInformation($client);
        }

        $tokenOptions = ['metadata' => $metadata, 'clientInformation' => $client, 'resource' => $resource];

        if (isset($options['authorizationCode'])) {
            // RFC 9207: never send a code from another authorization server to this one. A
            // response carrying `iss` has to name this server; a server that promised to send
            // `iss` and did not is not this server either.
            $iss = $options['iss'] ?? null;

            if ($metadata !== null && ($iss !== null || ($metadata['authorization_response_iss_parameter_supported'] ?? false) === true)) {
                if ($iss !== $metadata['issuer']) {
                    throw new OauthIssuerMismatchError((string) $metadata['issuer'], $iss);
                }
            }

            $tokens = self::tokenRequest($discovered['authorizationServerUrl'], $tokenOptions, [
                'grant_type' => 'authorization_code',
                'code' => (string) $options['authorizationCode'],
                'code_verifier' => $provider->codeVerifier(),
                'redirect_uri' => $provider->redirectUrl,
            ], $fetch);
            // A response without `scope` grants the requested scope (RFC 6749 §5.1). Recorded so a
            // step-up can keep it: `$scope` is what the authorization request asked for, because
            // the caller passes the same options to both halves of the flow.
            $provider->saveTokens(self::withScope($tokens, $scope));

            return self::AUTHORIZED;
        }

        $existing = ($options['skipRefresh'] ?? false) ? null : $provider->tokens();

        if (is_string($existing['refresh_token'] ?? null)) {
            try {
                $tokens = self::tokenRequest($discovered['authorizationServerUrl'], $tokenOptions, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $existing['refresh_token'],
                ], $fetch);
                // A server that rotates refresh tokens sends a new one; one that does not sends none,
                // and the old one is still good.
                $provider->saveTokens(['refresh_token' => $existing['refresh_token'], ...$tokens]);

                return self::AUTHORIZED;
            } catch (OauthInsecureEndpointError $error) {
                throw $error;
            } catch (OauthError $error) {
                if ($error->oauthCode !== 'server_error') {
                    throw $error;
                }
            }
        }

        $state = $provider->state();
        [$authorizationUrl, $verifier] = self::startAuthorization($discovered['authorizationServerUrl'], $metadata, $client, $provider->redirectUrl, $scope, $state, $resource);
        $provider->saveCodeVerifier($verifier);
        $provider->redirectToAuthorization($authorizationUrl);

        return self::REDIRECT;
    }

    /** @param array<string, mixed> $tokens */
    private static function withScope(array $tokens, ?string $scope): array
    {
        return !isset($tokens['scope']) && $scope !== null ? [...$tokens, 'scope' => $scope] : $tokens;
    }

    /**
     * Scopes for a step-up authorization: the challenged scopes plus the ones granted so far.
     *
     * A server asking for more scope (`insufficient_scope`) may list only the scopes it is
     * missing, and a new token with just those would lose the access the old one had — so the
     * server would ask again on the next request, for ever (upstream's SEP-2350). Without
     * challenged scopes, null lets the flow pick its default.
     */
    public static function stepUpScope(?string $granted, ?string $challenged): ?string
    {
        if ($challenged === null || trim($challenged) === '') {
            return null;
        }

        $scopes = [];

        foreach ([$granted, $challenged] as $scope) {
            foreach (preg_split('/\s+/', (string) $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
                $scopes[$one] = true;
            }
        }

        return implode(' ', array_keys($scopes));
    }

    /**
     * The URL to send the browser to, and the PKCE verifier that goes with it.
     *
     * @param array<string, mixed>|null $metadata
     * @param array<string, mixed> $client
     * @return array{0: string, 1: string}
     */
    public static function startAuthorization(string $authorizationServerUrl, ?array $metadata, array $client, string $redirectUrl, ?string $scope, ?string $state, ?string $resource): array
    {
        if ($metadata !== null && !in_array('code', $metadata['response_types_supported'] ?? [], true)) {
            throw new RuntimeException('Authorization server does not support authorization codes');
        }

        if (isset($metadata['code_challenge_methods_supported']) && !in_array('S256', $metadata['code_challenge_methods_supported'], true)) {
            throw new RuntimeException('Authorization server does not support PKCE S256');
        }

        $pkce = Pkce::create();
        $params = [
            'response_type' => 'code',
            'client_id' => (string) $client['client_id'],
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'redirect_uri' => $redirectUrl,
        ];

        if ($state !== null) {
            $params['state'] = $state;
        }

        if ($scope !== null) {
            $params['scope'] = $scope;

            if (in_array('offline_access', preg_split('/\s+/', $scope) ?: [], true)) {
                $params['prompt'] = 'consent';
            }
        }

        if ($resource !== null) {
            $params['resource'] = $resource;
        }

        $endpoint = (string) ($metadata['authorization_endpoint'] ?? self::join($authorizationServerUrl, '/authorize'));
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return [$endpoint . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986), $pkce->verifier];
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @param array<string, mixed> $clientMetadata
     * @return array<string, mixed>
     */
    public static function registerClient(string $authorizationServerUrl, ?array $metadata, array $clientMetadata, ?string $scope, Closure $fetch): array
    {
        $endpoint = $metadata['registration_endpoint'] ?? null;

        if ($metadata !== null && $endpoint === null) {
            throw new RuntimeException('Authorization server does not support dynamic client registration');
        }

        $response = $fetch(new Request(
            'POST',
            (string) ($endpoint ?? self::join($authorizationServerUrl, '/register')),
            ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            json_encode([...$clientMetadata, ...($scope !== null ? ['scope' => $scope] : [])], JSON_UNESCAPED_SLASHES),
        ));

        if (!$response->isSuccessful()) {
            throw new OauthRegistrationError($response->status, $response->body->all());
        }

        return Metadata::clientInformation(json_decode($response->body->all(), true));
    }

    /**
     * @param array{metadata: ?array<string, mixed>, clientInformation: array<string, mixed>, resource: ?string} $options
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private static function tokenRequest(string $authorizationServerUrl, array $options, array $params, Closure $fetch): array
    {
        $url = self::secureEndpoint((string) ($options['metadata']['token_endpoint'] ?? self::join($authorizationServerUrl, '/token')));
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'];

        if ($options['resource'] !== null) {
            $params['resource'] = $options['resource'];
        }

        $information = $options['clientInformation'];
        $method = self::selectClientAuthMethod($information, $options['metadata']['token_endpoint_auth_methods_supported'] ?? []);

        if ($method === 'client_secret_basic') {
            if (!isset($information['client_secret'])) {
                throw new RuntimeException('client_secret_basic requires a client secret');
            }

            $headers['Authorization'] = 'Basic ' . base64_encode($information['client_id'] . ':' . $information['client_secret']);
        } else {
            $params['client_id'] = (string) $information['client_id'];

            if ($method === 'client_secret_post' && isset($information['client_secret'])) {
                $params['client_secret'] = (string) $information['client_secret'];
            }
        }

        $response = $fetch(new Request('POST', $url, $headers, http_build_query($params, '', '&', PHP_QUERY_RFC3986)));
        $text = $response->body->all();
        $value = json_decode($text, true);

        // Servers may report OAuth errors with any status, so check the body before the status.
        if (is_array($value) && is_string($value['error'] ?? null)) {
            throw new OauthError(
                $value['error'],
                is_string($value['error_description'] ?? null) ? $value['error_description'] : $value['error'],
                is_string($value['error_uri'] ?? null) ? $value['error_uri'] : null,
            );
        }

        if (!$response->isSuccessful()) {
            throw new OauthError('server_error', "HTTP {$response->status}: {$text}");
        }

        return Metadata::tokens($value);
    }

    /**
     * @param array<string, mixed> $information
     * @param list<string> $supported
     */
    private static function selectClientAuthMethod(array $information, array $supported): string
    {
        $hinted = $information['token_endpoint_auth_method'] ?? null;
        $hasSecret = isset($information['client_secret']);

        if (is_string($hinted) && in_array($hinted, ['client_secret_basic', 'client_secret_post', 'none'], true) && ($supported === [] || in_array($hinted, $supported, true))) {
            return $hinted;
        }

        if ($supported === []) {
            return $hasSecret ? 'client_secret_basic' : 'none';
        }

        if ($hasSecret && in_array('client_secret_basic', $supported, true)) {
            return 'client_secret_basic';
        }

        if ($hasSecret && in_array('client_secret_post', $supported, true)) {
            return 'client_secret_post';
        }

        if (in_array('none', $supported, true)) {
            return 'none';
        }

        return $hasSecret ? 'client_secret_post' : 'none';
    }

    private static function secureEndpoint(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            throw new OauthInsecureEndpointError($url);
        }

        return $url;
    }

    private static function join(string $base, string $path): string
    {
        $parts = parse_url($base);

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : '') . $path;
    }
}
