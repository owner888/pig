<?php

declare(strict_types=1);

namespace PigMcp;

use Pig\CodingAgent\McpServers;
use RuntimeException;

/**
 * MCP server configuration — upstream's `extensions/mcp/config.ts`. The shape of one entry is
 * `Pig\CodingAgent\McpServers::validate()`, upstream's `core/mcp-servers.ts`, which the core
 * also runs on a server an extension registers.
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
 * **Exposure.** `codemode` (the default, as upstream's), `codemode-deferred`, `deferred`, `direct`
 * and `hidden`, read as written (`here()`).
 */
final class McpConfig
{
    public const string FILE = 'mcp.json';

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
            $validated = McpServers::validate((string) $name, $value);

            if (is_string($validated)) {
                $errors[] = "{$path}: {$validated}";
                continue;
            }

            $servers[(string) $name] = new ServerEntry((string) $name, $validated, $path, $scope);
        }
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


}
