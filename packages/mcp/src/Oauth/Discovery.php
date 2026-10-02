<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Mcp\Protocol\Protocol;
use RuntimeException;

/**
 * Finding the authorization server — upstream's `oauth/discovery.ts` (RFC 9728 protected
 * resource metadata, then RFC 8414 / OpenID discovery for the server it names).
 *
 * `$fetch` is `Closure(Request): Response`; the default sends through `HttpClient`. A test hands
 * in one that answers from a table, which is how discovery is checked without a network.
 */
final class Discovery
{
    /** @param Closure(Request): Response|null $fetch */
    public static function fetcher(?Closure $fetch = null): Closure
    {
        return $fetch ?? static fn (Request $request): Response => (new HttpClient(timeout: 15.0))->follow($request);
    }

    /** 4xx and 502 mean "not here", so discovery tries the next candidate URL. */
    private static function isMiss(int $status): bool
    {
        return ($status >= 400 && $status < 500) || $status === 502;
    }

    /** Path suffix for `/.well-known/<kind><path>`; empty for the root path. */
    private static function pathSuffix(string $pathname): string
    {
        return rtrim($pathname, '/');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Not a URL: {$url}");
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : '');
    }

    private static function fetchMetadata(string $url, Closure $fetch): Response
    {
        return $fetch(new Request('GET', $url, ['Accept' => 'application/json', 'MCP-Protocol-Version' => Protocol::LATEST_VERSION]));
    }

    /** @return array<string, mixed> */
    public static function protectedResourceMetadata(string $serverUrl, ?string $resourceMetadataUrl, Closure $fetch): array
    {
        $origin = self::origin($serverUrl);
        $path = (string) (parse_url($serverUrl, PHP_URL_PATH) ?: '/');

        $response = self::fetchMetadata(
            $resourceMetadataUrl ?? $origin . '/.well-known/oauth-protected-resource' . self::pathSuffix($path),
            $fetch,
        );

        if ($resourceMetadataUrl === null && $path !== '/' && self::isMiss($response->status)) {
            $response->body->close();
            $response = self::fetchMetadata($origin . '/.well-known/oauth-protected-resource', $fetch);
        }

        if (!$response->isSuccessful()) {
            $response->body->close();

            throw new RuntimeException("HTTP {$response->status} loading OAuth protected resource metadata");
        }

        return Metadata::protectedResource(json_decode($response->body->all(), true));
    }

    /** @return list<string> */
    public static function authorizationServerDiscoveryUrls(string $authorizationServerUrl): array
    {
        $origin = self::origin($authorizationServerUrl);
        $path = self::pathSuffix((string) (parse_url($authorizationServerUrl, PHP_URL_PATH) ?: '/'));
        $urls = [
            "{$origin}/.well-known/oauth-authorization-server{$path}",
            "{$origin}/.well-known/openid-configuration{$path}",
        ];

        if ($path !== '') {
            $urls[] = "{$origin}{$path}/.well-known/openid-configuration";
        }

        return $urls;
    }

    /** @return array<string, mixed>|null null when no candidate URL had a document */
    public static function authorizationServerMetadata(string $authorizationServerUrl, Closure $fetch, bool $skipIssuerValidation = false): ?array
    {
        foreach (self::authorizationServerDiscoveryUrls($authorizationServerUrl) as $url) {
            $response = self::fetchMetadata($url, $fetch);

            if (!$response->isSuccessful()) {
                $response->body->close();

                if (self::isMiss($response->status)) {
                    continue;
                }

                throw new RuntimeException("HTTP {$response->status} loading authorization server metadata from {$url}");
            }

            $metadata = Metadata::authorizationServer(json_decode($response->body->all(), true));

            if (!$skipIssuerValidation && rtrim((string) $metadata['issuer'], '/') !== rtrim($authorizationServerUrl, '/')) {
                throw new OauthIssuerMismatchError($authorizationServerUrl, (string) $metadata['issuer']);
            }

            return $metadata;
        }

        return null;
    }

    /**
     * Everything the flow needs to know about where to sign in.
     *
     * @return array{authorizationServerUrl: string, authorizationServerMetadata: ?array<string, mixed>, resourceMetadata: ?array<string, mixed>}
     */
    public static function serverInfo(string $serverUrl, ?string $resourceMetadataUrl, Closure $fetch, bool $skipIssuerValidation = false): array
    {
        $resourceMetadata = null;

        try {
            $resourceMetadata = self::protectedResourceMetadata($serverUrl, $resourceMetadataUrl, $fetch);
        } catch (\Pig\Ai\Http\HttpError|\Pig\Async\SocketError $error) {
            // A network failure is a failure; a server with no resource metadata is not.
            throw $error;
        } catch (\Throwable) {
            // No resource metadata: the server's own origin is the authorization server.
        }

        $authorizationServerUrl = $resourceMetadata['authorization_servers'][0] ?? self::origin($serverUrl) . '/';

        return [
            'authorizationServerUrl' => $authorizationServerUrl,
            'authorizationServerMetadata' => self::authorizationServerMetadata($authorizationServerUrl, $fetch, $skipIssuerValidation),
            'resourceMetadata' => $resourceMetadata,
        ];
    }

    /**
     * The `resource` to ask tokens for, when the resource metadata names one that covers the server.
     *
     * @param array<string, mixed>|null $metadata
     */
    public static function selectResource(string $serverUrl, ?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $requested = strtok($serverUrl, '#');
        $configured = (string) $metadata['resource'];

        if (self::origin($requested) !== self::origin($configured)) {
            throw new RuntimeException("Protected resource {$configured} does not match MCP server {$requested}");
        }

        $requestedPath = rtrim((string) (parse_url($requested, PHP_URL_PATH) ?: '/'), '/') . '/';
        $configuredPath = rtrim((string) (parse_url($configured, PHP_URL_PATH) ?: '/'), '/') . '/';

        if (!str_starts_with($requestedPath, $configuredPath)) {
            throw new RuntimeException("Protected resource {$configured} does not match MCP server {$requested}");
        }

        return $configured;
    }
}
