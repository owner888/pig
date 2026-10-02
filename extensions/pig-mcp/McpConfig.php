<?php

declare(strict_types=1);

namespace PigMcp;

use RuntimeException;

/**
 * MCP server configuration — upstream's `extensions/mcp/config.ts` and the validation half of
 * `core/mcp-servers.ts`.
 *
 * Servers are read from `mcp.json` in the agent directory and, for trusted projects, from
 * `<project>/.pig/mcp.json`. Both use the `mcpServers` shape shared by other MCP clients, so an
 * entry written for Claude Desktop, Claude Code or Cursor can be copied over as it is. Project
 * entries replace global entries with the same name.
 *
 * ```json
 * { "mcpServers": {
 *     "filesystem": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "."] },
 *     "docs": { "url": "https://example.com/mcp", "headers": { "Authorization": "Bearer ${DOCS_TOKEN}" } } } }
 * ```
 *
 * **Exposure.** Upstream's five are read, and the two that name `codemode` are mapped to
 * `deferred`: codemode is a JavaScript sandbox in which the model writes scripts that call MCP
 * tools, and pig has no JavaScript engine and will not bundle one. `deferred` is the nearest
 * behaviour — the tools are not declared to the model until `tool_search` loads them — and the
 * default, where upstream's default is `codemode`. So a file copied from pi works unchanged and
 * reaches the same tools by the other door; `/mcp` says so once.
 */
final class McpConfig
{
    public const string FILE = 'mcp.json';

    /** Upstream's five, accepted as written. */
    public const array EXPOSURES = ['codemode', 'codemode-deferred', 'deferred', 'direct', 'hidden'];

    /**
     * Upstream's five exposures are pig's five now that codemode is ported. The table stays
     * because `here()` is what every reader asks, and a sixth value from a newer pi lands on
     * the default rather than on a crash.
     */
    public const array EXPOSURE_HERE = [
        'codemode' => 'codemode',
        'codemode-deferred' => 'codemode-deferred',
        'deferred' => 'deferred',
        'direct' => 'direct',
        'hidden' => 'hidden',
    ];

    public const string DEFAULT_EXPOSURE = 'codemode';

    private const string SERVER_NAME = '/^[A-Za-z0-9_-]+$/';

    /**
     * @param list<ServerEntry> $servers
     * @param list<string>      $errors
     */
    public function __construct(
        public readonly array $servers,
        public readonly array $errors,
    ) {
    }

    /**
     * Load global and (when trusted) project MCP configuration. Disabled servers are included
     * with `enabled: false`, so they can be enabled again.
     */
    public static function load(string $agentDir, string $cwd, bool $projectTrusted): self
    {
        $servers = [];
        $errors = [];

        self::readFile($agentDir . '/' . self::FILE, 'global', $servers, $errors);

        if ($projectTrusted) {
            self::readFile(rtrim($cwd, '/') . '/.pig/' . self::FILE, 'project', $servers, $errors);
        }

        return new self(array_values($servers), $errors);
    }

    /**
     * @param array<string, ServerEntry> $servers
     * @param list<string>               $errors
     */
    private static function readFile(string $path, string $scope, array &$servers, array &$errors): void
    {
        if (!is_file($path)) {
            return;
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            $errors[] = "{$path}: could not be read";

            return;
        }

        $parsed = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "{$path}: " . json_last_error_msg();

            return;
        }

        if (!self::isRecord($parsed) || (array_key_exists('mcpServers', $parsed) && !self::isRecord($parsed['mcpServers']))) {
            $errors[] = "{$path}: expected an object with an \"mcpServers\" object";

            return;
        }

        foreach ($parsed['mcpServers'] ?? [] as $name => $value) {
            $validated = self::validate((string) $name, $value);

            if (is_string($validated)) {
                $errors[] = "{$path}: {$validated}";
                continue;
            }

            $servers[(string) $name] = new ServerEntry((string) $name, $validated, $path, $scope);
        }
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

    /**
     * Exposure of one tool of a server: its `toolExposure` entry, else the server's `exposure` —
     * as upstream spells it, before the mapping to what pig can do.
     *
     * An exact name wins over patterns; among patterns, the first match in the object wins.
     *
     * @param array<string, mixed> $config
     */
    public static function toolExposure(array $config, string $toolName): string
    {
        $overrides = self::isRecord($config['toolExposure'] ?? null) ? $config['toolExposure'] : [];

        if (isset($overrides[$toolName])) {
            return (string) $overrides[$toolName];
        }

        foreach ($overrides as $pattern => $exposure) {
            if (str_contains((string) $pattern, '*') && fnmatch((string) $pattern, $toolName, FNM_NOESCAPE)) {
                return (string) $exposure;
            }
        }

        return (string) ($config['exposure'] ?? 'codemode');
    }

    /** The exposure pig applies for one upstream names. */
    public static function here(string $exposure): string
    {
        return self::EXPOSURE_HERE[$exposure] ?? self::DEFAULT_EXPOSURE;
    }

    /**
     * Change one server's settings in the `mcp.json` that defines it. Other content is kept; the
     * file is rewritten with its own indentation.
     *
     * @param array{enabled?: bool, exposure?: string} $patch
     */
    public static function update(string $path, string $name, array $patch): void
    {
        self::edit($path, static function (array &$servers) use ($path, $name, $patch): bool {
            if (!self::isRecord($servers[$name] ?? null)) {
                throw new RuntimeException("{$path} does not define MCP server \"{$name}\"");
            }

            if (array_key_exists('enabled', $patch)) {
                if ($patch['enabled']) {
                    unset($servers[$name]['enabled']);
                } else {
                    $servers[$name]['enabled'] = false;
                }
            }

            if (array_key_exists('exposure', $patch)) {
                if ($patch['exposure'] === 'codemode') {
                    unset($servers[$name]['exposure']);
                } else {
                    $servers[$name]['exposure'] = $patch['exposure'];
                }
            }

            return true;
        });
    }

    /**
     * Add a server to an `mcp.json`, creating the file when missing. An existing entry with the
     * same name is replaced; true says one was.
     *
     * @param array<string, mixed> $config
     */
    public static function add(string $path, string $name, array $config): bool
    {
        $replaced = false;

        self::edit($path, static function (array &$servers) use ($name, $config, &$replaced): bool {
            $replaced = array_key_exists($name, $servers);
            $servers[$name] = $config;

            return true;
        });

        return $replaced;
    }

    /** Remove a server from an `mcp.json`. False when the file does not define it. */
    public static function remove(string $path, string $name): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $removed = false;

        self::edit($path, static function (array &$servers) use ($name, &$removed): bool {
            if (!array_key_exists($name, $servers)) {
                return false;
            }

            unset($servers[$name]);
            $removed = true;

            return true;
        });

        return $removed;
    }

    /**
     * Read an `mcp.json` (an empty config when missing), let `$edit` change `mcpServers`, and
     * write it back with its indentation when `$edit` returns true.
     *
     * @param \Closure(array<string, mixed>&): bool $edit
     */
    private static function edit(string $path, \Closure $edit): void
    {
        $text = is_file($path) ? (string) file_get_contents($path) : null;
        $parsed = $text === null || trim($text) === '' ? [] : json_decode($text, true);

        if (!self::isRecord($parsed) || (array_key_exists('mcpServers', $parsed) && !self::isRecord($parsed['mcpServers']))) {
            throw new RuntimeException("{$path}: expected an object with an \"mcpServers\" object");
        }

        $servers = self::isRecord($parsed['mcpServers'] ?? null) ? $parsed['mcpServers'] : [];

        if (!$edit($servers)) {
            return;
        }

        $parsed['mcpServers'] = $servers === [] ? new \stdClass() : $servers;

        $indent = $text !== null && preg_match('/^([ \t]+)\S/m', $text, $m) === 1 ? $m[1] : '  ';
        // `mcpServers` empty is `{}` above; a nested `[]` (`"args": []`) stays a list, which is what it was.
        $json = (string) json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($indent !== '    ') {
            // PHP indents with four spaces and offers no option; the file's own indentation wins.
            $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat($indent, intdiv(strlen($m[0]), 4)), $json);
        }

        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Could not write {$path}");
        }
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
