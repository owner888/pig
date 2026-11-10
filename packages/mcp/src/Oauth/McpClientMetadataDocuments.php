<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use Closure;

/**
 * The Client ID Metadata Documents an application publishes — upstream's `McpClientMetadataDocuments`:
 * `<baseUrl>/client.json`, and for each MCP server `<baseUrl>/<callback ID>/client.json`, whose
 * redirect URIs carry the path `<path>/<callback ID>`.
 *
 * With one, a sign-in can identify the application by the document's https URL as its `client_id`
 * instead of registering a client with every authorization server (draft-ietf-oauth-client-id-metadata-document).
 */
final readonly class McpClientMetadataDocuments
{
    /**
     * @param string $baseUrl for example `https://pigagent.dev/oauth`
     * @param Closure(string): bool $listsRedirectUrl whether the documents list a redirect URI;
     *        loopback ones match on any port (RFC 8252)
     */
    public function __construct(
        public string $baseUrl,
        private Closure $listsRedirectUrl,
    ) {
    }

    public function listsRedirectUrl(string $redirectUrl): bool
    {
        return ($this->listsRedirectUrl)($redirectUrl);
    }

    /**
     * Whether the client may identify with a document instead of registering — upstream's
     * `offersClientMetadataDocument()`. `auto` registers when the person named a client or a client
     * name, or the documents do not list the redirect URI; `cimd` always offers one, `dcr` never.
     *
     * @param array<string, mixed> $settings the server's `oauth` block
     */
    public static function offered(array $settings, ?self $documents, string $redirectUrl): bool
    {
        $registration = $settings['clientRegistration'] ?? 'auto';

        if ($registration !== 'auto') {
            return $registration === 'cimd';
        }

        return !isset($settings['clientId']) && !isset($settings['clientName']) && ($documents?->listsRedirectUrl($redirectUrl) ?? false);
    }

    /**
     * What the flow asks for when nothing is stored: the document to identify with, or null to
     * register — upstream's closure in `createOAuthClientProvider()`. Null when the settings do not
     * offer a document at all.
     *
     * @param array<string, mixed> $settings
     * @return (Closure(array<string, mixed>|null): (array{url: string, redirectUrl: string}|null))|null
     */
    public static function chooser(array $settings, ?self $documents, string $serverUrl, string $redirectUrl): ?Closure
    {
        if (!self::offered($settings, $documents, $redirectUrl)) {
            return null;
        }

        $cimd = ($settings['clientRegistration'] ?? 'auto') === 'cimd';

        return static function (?array $metadata) use ($documents, $serverUrl, $redirectUrl, $cimd): ?array {
            if ($documents === null || !$documents->listsRedirectUrl($redirectUrl)) {
                throw new \RuntimeException("oauth.clientRegistration \"cimd\" needs Client ID Metadata Documents that list the redirect URI {$redirectUrl}");
            }

            $document = self::document($serverUrl, $redirectUrl, $metadata, $documents->baseUrl);

            if ($document === null && $cimd) {
                throw new \RuntimeException('The authorization server does not support Client ID Metadata Documents for public clients; remove oauth.clientRegistration "cimd"');
            }

            return $document;
        };
    }

    /**
     * The document to use, or null when the authorization server does not support documents for
     * public clients. Without the `iss` parameter in authorization responses (RFC 9207), the redirect
     * URI and the document are specific to the MCP server, so a response cannot be mixed up with one
     * from another authorization server (RFC 9700 section 4.4.2.2).
     *
     * @param array<string, mixed>|null $metadata
     * @return array{url: string, redirectUrl: string}|null
     */
    public static function document(string $serverUrl, string $redirectUrl, ?array $metadata, string $baseUrl): ?array
    {
        if (($metadata['client_id_metadata_document_supported'] ?? false) !== true
            || !in_array('none', $metadata['token_endpoint_auth_methods_supported'] ?? [], true)
        ) {
            return null;
        }

        if (($metadata['authorization_response_iss_parameter_supported'] ?? false) === true) {
            return ['url' => "{$baseUrl}/client.json", 'redirectUrl' => $redirectUrl];
        }

        $parts = parse_url($redirectUrl) ?: [];
        $path = self::serverSpecificPath((string) ($parts['path'] ?? '/'), $serverUrl);
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return [
            'url' => "{$baseUrl}/" . self::callbackId($serverUrl) . '/client.json',
            'redirectUrl' => ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . $port . $path . $query,
        ];
    }

    /** The path of a server-specific document's redirect URI: `<path>/<callback ID>`. */
    public static function serverSpecificPath(string $path, string $serverUrl): string
    {
        return rtrim($path, '/') . '/' . self::callbackId($serverUrl);
    }

    /**
     * 12 characters naming an MCP server URL in callback paths, as upstream computes them: the first
     * nine bytes of the SHA-256 of the URL without its fragment, in base64url.
     */
    public static function callbackId(string $serverUrl): string
    {
        $url = explode('#', $serverUrl, 2)[0];

        return rtrim(strtr(base64_encode(substr(hash('sha256', self::normalized($url), true), 0, 9)), '+/', '-_'), '=');
    }

    /** Redirect URIs a stored client was registered with. @param array<string, mixed>|null $client @return list<string> */
    public static function registeredRedirectUrls(?array $client): array
    {
        return array_values(array_filter($client['redirect_uris'] ?? [], is_string(...)));
    }

    /** The URL as a browser's `URL.href` writes it, for the parts that matter here: case and an empty path. */
    private static function normalized(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;
        $port = isset($parts['port']) && $parts['port'] !== $default ? ":{$parts['port']}" : '';
        $user = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ":{$parts['pass']}" : '') . '@' : '';

        return "{$scheme}://{$user}" . strtolower($parts['host']) . $port . ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
    }
}
