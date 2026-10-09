<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/**
 * MCP server configuration as the core sees it — upstream's `core/mcp-servers.ts`: the shape of
 * one `mcpServers` entry, validated once for a file (`PigMcp\McpConfig::load()`) and for an
 * extension's `$pi->registerMcpServer()`, and the namespace a server's tools are registered under.
 * Connecting is the `pig-mcp` extension's business; what a server *is* has to be known before it.
 */
final class McpServers
{
    /** Upstream's five, accepted as written. */
    public const array EXPOSURES = ['codemode', 'codemode-deferred', 'deferred', 'direct', 'hidden'];

    private const string SERVER_NAME = '/^[A-Za-z0-9_-]+$/';

    /** Upstream's `mcpNamespace()`: `mcp__<server>` with `-` as `_`, which is why two names can clash. */
    public static function namespace(string $server): string
    {
        return 'mcp__' . str_replace('-', '_', $server);
    }

    /**
     * Validate one server entry of the `mcpServers` shape. The config as given, or an error.
     *
     * @return array<string, mixed>|string
     */
    public static function validate(string $name, mixed $value): array|string
    {
        if (preg_match(self::SERVER_NAME, $name) !== 1) {
            return "invalid server name \"{$name}\" (use letters, digits, \"_\" and \"-\")";
        }

        if (!self::isRecord($value)) {
            return "server \"{$name}\" must be an object";
        }

        $exposures = implode(', ', array_map(static fn (string $e): string => "\"{$e}\"", self::EXPOSURES));

        if (array_key_exists('exposure', $value) && !in_array($value['exposure'], self::EXPOSURES, true)) {
            return "server \"{$name}\": exposure must be one of {$exposures}";
        }

        if (array_key_exists('toolExposure', $value)) {
            if (!self::isRecord($value['toolExposure'])) {
                return "server \"{$name}\": toolExposure must map tool names to exposures";
            }

            foreach ($value['toolExposure'] as $tool => $exposure) {
                if (!in_array($exposure, self::EXPOSURES, true)) {
                    return "server \"{$name}\": toolExposure \"{$tool}\" must be one of {$exposures}";
                }
            }
        }

        if (array_key_exists('enabled', $value) && !is_bool($value['enabled'])) {
            return "server \"{$name}\": enabled must be a boolean";
        }

        if (array_key_exists('timeout', $value) && (!is_numeric($value['timeout']) || !($value['timeout'] > 0))) {
            return "server \"{$name}\": timeout must be a positive number of seconds";
        }

        $type = $value['type'] ?? null;

        if ($type === 'sse') {
            return "server \"{$name}\": legacy SSE transport is not supported; use the streamable HTTP URL";
        }

        if (is_string($value['url'] ?? null) && ($type === null || $type === 'http' || $type === 'streamable-http')) {
            $scheme = parse_url($value['url'], PHP_URL_SCHEME);

            if (!in_array($scheme, ['http', 'https'], true) || parse_url($value['url'], PHP_URL_HOST) === null) {
                return "server \"{$name}\": url must be an http or https URL";
            }

            if (array_key_exists('headers', $value) && !self::isStringRecord($value['headers'])) {
                return "server \"{$name}\": headers must map names to strings";
            }

            $oauth = self::validateOauth($value['oauth'] ?? null);

            if ($oauth !== null) {
                return "server \"{$name}\": {$oauth}";
            }

            return $value;
        }

        if (is_string($value['command'] ?? null) && ($type === null || $type === 'stdio')) {
            if (array_key_exists('args', $value) && !self::isStringList($value['args'])) {
                return "server \"{$name}\": args must be an array of strings";
            }

            if (array_key_exists('env', $value) && !self::isStringRecord($value['env'])) {
                return "server \"{$name}\": env must map names to strings";
            }

            if (array_key_exists('cwd', $value) && !is_string($value['cwd'])) {
                return "server \"{$name}\": cwd must be a string";
            }

            return $value;
        }

        return "server \"{$name}\" needs either \"command\" (stdio) or \"url\" (streamable HTTP)";
    }

    private static function validateOauth(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!self::isRecord($value)) {
            return 'oauth must be an object';
        }

        if (array_key_exists('clientId', $value) && !is_string($value['clientId'])) {
            return 'oauth.clientId must be a string';
        }

        if (array_key_exists('clientSecret', $value) && !is_string($value['clientSecret'])) {
            return 'oauth.clientSecret must be a string';
        }

        $port = $value['callbackPort'] ?? null;

        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            return 'oauth.callbackPort must be a port number';
        }

        if (array_key_exists('callbackUrl', $value)) {
            if (!is_string($value['callbackUrl']) || !self::isLoopbackRedirectUri($value['callbackUrl'])) {
                return 'oauth.callbackUrl must be an http URI on localhost, 127.0.0.1, or [::1] without query or fragment';
            }

            $urlPort = parse_url($value['callbackUrl'], PHP_URL_PORT);

            if ($urlPort !== null && $port !== null && $urlPort !== $port) {
                return 'oauth.callbackUrl and oauth.callbackPort name different ports';
            }
        }

        if (array_key_exists('scope', $value) && !is_string($value['scope'])) {
            return 'oauth.scope must be a string';
        }

        // The authorization server's metadata document, for a server that advertises the wrong
        // one or none. https only, as the flow would refuse it anyway: a document fetched in the
        // clear is a token endpoint somebody else chose.
        if (array_key_exists('authServerMetadataUrl', $value)) {
            $url = $value['authServerMetadataUrl'];

            if (!is_string($url) || !str_starts_with($url, 'https://') || parse_url($url, PHP_URL_HOST) === null) {
                return 'oauth.authServerMetadataUrl must be an https URL';
            }
        }

        return null;
    }

    /** Whether a redirect URI can be served by pig's loopback callback server. */
    public static function isLoopbackRedirectUri(string $value): bool
    {
        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'http') {
            return false;
        }

        return in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function isStringRecord(mixed $value): bool
    {
        if (!self::isRecord($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    private static function isStringList(mixed $value): bool
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }
}
