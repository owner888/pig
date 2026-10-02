<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/**
 * The shapes OAuth hands back, checked structurally — upstream's `oauth/types.ts`, which is the
 * MCP TypeScript SDK's with Zod taken out. Each parser answers the input with its known fields
 * checked and keeps the rest, so a field this does not know about is not lost.
 */
final class Metadata
{
    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function protectedResource(mixed $value): array
    {
        $input = self::object($value, 'OAuth protected resource metadata');
        $servers = self::optionalStrings($input['authorization_servers'] ?? null, 'authorization_servers');

        return self::compact([
            ...$input,
            'resource' => self::safeUrl($input['resource'] ?? null, 'OAuth protected resource metadata resource'),
            'authorization_servers' => $servers === null ? null : array_map(static fn (string $url): string => self::safeUrl($url, 'authorization server URL'), $servers),
            'scopes_supported' => self::optionalStrings($input['scopes_supported'] ?? null, 'scopes_supported'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function authorizationServer(mixed $value): array
    {
        $input = self::object($value, 'authorization server metadata');
        $responseTypes = self::optionalStrings($input['response_types_supported'] ?? null, 'response_types_supported');

        if ($responseTypes === null) {
            throw new RuntimeException('Invalid response_types_supported');
        }

        return self::compact([
            ...$input,
            'issuer' => self::safeUrl($input['issuer'] ?? null, 'authorization server issuer'),
            'authorization_endpoint' => self::safeUrl($input['authorization_endpoint'] ?? null, 'authorization endpoint'),
            'token_endpoint' => self::safeUrl($input['token_endpoint'] ?? null, 'token endpoint'),
            'registration_endpoint' => isset($input['registration_endpoint']) ? self::safeUrl($input['registration_endpoint'], 'registration endpoint') : null,
            'scopes_supported' => self::optionalStrings($input['scopes_supported'] ?? null, 'scopes_supported'),
            'response_types_supported' => $responseTypes,
            'grant_types_supported' => self::optionalStrings($input['grant_types_supported'] ?? null, 'grant_types_supported'),
            'token_endpoint_auth_methods_supported' => self::optionalStrings($input['token_endpoint_auth_methods_supported'] ?? null, 'token_endpoint_auth_methods_supported'),
            'code_challenge_methods_supported' => self::optionalStrings($input['code_challenge_methods_supported'] ?? null, 'code_challenge_methods_supported'),
            'client_id_metadata_document_supported' => is_bool($input['client_id_metadata_document_supported'] ?? null) ? $input['client_id_metadata_document_supported'] : null,
            // RFC 9207: a server that says so puts `iss` on every authorization response, and a
            // response without one is then not from it.
            'authorization_response_iss_parameter_supported' => is_bool($input['authorization_response_iss_parameter_supported'] ?? null) ? $input['authorization_response_iss_parameter_supported'] : null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function tokens(mixed $value): array
    {
        $input = self::object($value, 'OAuth token response');
        $expires = null;

        if (array_key_exists('expires_in', $input)) {
            if (!is_numeric($input['expires_in'])) {
                throw new RuntimeException('Invalid expires_in');
            }

            $expires = (float) $input['expires_in'];
        }

        return self::compact([
            'access_token' => self::requiredString($input['access_token'] ?? null, 'access_token'),
            'token_type' => self::requiredString($input['token_type'] ?? null, 'token_type'),
            'expires_in' => $expires,
            'scope' => self::optionalString($input['scope'] ?? null, 'scope'),
            'refresh_token' => self::optionalString($input['refresh_token'] ?? null, 'refresh_token'),
            'id_token' => self::optionalString($input['id_token'] ?? null, 'id_token'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function clientInformation(mixed $value): array
    {
        $input = self::object($value, 'OAuth client registration response');

        return self::compact([
            ...$input,
            'client_id' => self::requiredString($input['client_id'] ?? null, 'client_id'),
            'client_secret' => self::optionalString($input['client_secret'] ?? null, 'client_secret'),
            'client_id_issued_at' => is_int($input['client_id_issued_at'] ?? null) ? $input['client_id_issued_at'] : null,
            'client_secret_expires_at' => is_int($input['client_secret_expires_at'] ?? null) ? $input['client_secret_expires_at'] : null,
            'redirect_uris' => self::optionalStrings($input['redirect_uris'] ?? null, 'redirect_uris') ?? [],
        ]);
    }

    /**
     * Parse `WWW-Authenticate`: the resource metadata URL, scope and error of a Bearer challenge.
     *
     * @return array{resourceMetadataUrl: ?string, scope: ?string, error: ?string, errorDescription: ?string}
     */
    public static function wwwAuthenticate(?string $header): array
    {
        $none = ['resourceMetadataUrl' => null, 'scope' => null, 'error' => null, 'errorDescription' => null];

        if ($header === null || trim($header) === '') {
            return $none;
        }

        $scheme = strtolower((string) strtok(ltrim($header), " \t"));

        if ($scheme !== 'bearer' && $scheme !== 'dpop') {
            return $none;
        }

        $resourceMetadata = self::field($header, 'resource_metadata');

        return [
            'resourceMetadataUrl' => $resourceMetadata !== null && filter_var($resourceMetadata, FILTER_VALIDATE_URL) !== false ? $resourceMetadata : null,
            'scope' => self::field($header, 'scope'),
            'error' => self::field($header, 'error'),
            'errorDescription' => self::field($header, 'error_description'),
        ];
    }

    private static function field(string $header, string $name): ?string
    {
        if (preg_match('/(?:^|[,\s])' . preg_quote($name, '/') . '=(?:"([^"]*)"|([^\s,]+))/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1] !== '' ? $match[1] : ($match[2] ?? null);
    }

    // ---- the checks -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $name): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException("Invalid {$name}");
        }

        return $value;
    }

    /**
     * Drop nulls, so an optional field is absent rather than present-and-null.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function compact(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null);
    }

    private static function requiredString(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Invalid {$name}");
        }

        return $value;
    }

    /**
     * An optional field may be absent, null **or empty** — `"scope": ""` is what some token
     * endpoints send for "what you asked for", and refusing it as `Invalid scope` refused a
     * sign-in that had worked (upstream's #10266). Only a value of the wrong type is a complaint.
     */
    private static function optionalString(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException("Invalid {$name}");
        }

        return $value;
    }

    /** @return list<string>|null */
    private static function optionalStrings(mixed $value, string $name): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn (mixed $item): bool => !is_string($item)) !== []) {
            throw new RuntimeException("Invalid {$name}");
        }

        return $value;
    }

    private static function safeUrl(mixed $value, string $name): string
    {
        $text = self::requiredString($value, $name);
        $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));

        if ($scheme === '' || in_array($scheme, ['javascript', 'data', 'vbscript'], true)) {
            throw new RuntimeException("Invalid {$name}");
        }

        return $text;
    }
}
