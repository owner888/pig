<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Hooks\HookContext;

/**
 * MCP resources, through the tools Codex and opencode use — upstream's `extensions/mcp/resources.js`:
 * `list_mcp_resources`, `list_mcp_resource_templates` and `read_mcp_resource`. They take a `server`
 * argument and cover every connected server with resources, so models trained on those tools use
 * them unchanged.
 *
 * Listings are JSON, as in Codex: `{ server?, resources: [{ server, ...resource }], nextCursor? }`.
 * With a `server`, one page is listed and `cursor` continues it; without, every page of every
 * server. MCP App resources (`ui://` URIs and `profile=mcp-app` HTML) are left out, since they are
 * user interfaces for hosts that render them, and so are icons. Read resources become text and
 * images for the model; binary resources are saved to temp files.
 */
final class McpResources
{
    public const string LIST = 'list_mcp_resources';

    public const string LIST_TEMPLATES = 'list_mcp_resource_templates';

    public const string READ = 'read_mcp_resource';

    public const array NAMES = [self::LIST, self::LIST_TEMPLATES, self::READ];

    /** MCP App user interfaces, which only hosts that render them can use. */
    public static function isMcpAppResource(array $item): bool
    {
        $uri = (string) ($item['uri'] ?? $item['uriTemplate'] ?? '');

        return str_starts_with($uri, 'ui://') || preg_match('/;\s*profile\s*=\s*"?mcp-app"?/i', (string) ($item['mimeType'] ?? '')) === 1;
    }

    /**
     * A listed resource or template without `_meta` and icons, tagged with its server.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function listed(string $server, array $item): array
    {
        unset($item['_meta'], $item['icons']);

        return ['server' => $server, ...$item];
    }

    /**
     * The three tools. `$servers` answers the connections whose resources they reach, at call time.
     *
     * @param Closure(): list<ServerConnection> $servers
     * @return list<CustomTool>
     */
    public static function define(Closure $servers): array
    {
        $string = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $listParameters = [
            'type' => 'object',
            'properties' => [
                'server' => $string('MCP server name. Omit to list every server with resources.'),
                'cursor' => $string('Opaque cursor from a previous call with the same server; omit for the first page.'),
            ],
            'additionalProperties' => false,
        ];
        $readParameters = [
            'type' => 'object',
            'properties' => [
                'server' => $string("MCP server name exactly as configured. Must match the 'server' field returned by list_mcp_resources."),
                'uri' => $string('Resource URI to read. Must be one of the URIs returned by list_mcp_resources.'),
            ],
            'required' => ['server', 'uri'],
            'additionalProperties' => false,
        ];

        $findServer = static function (string $name) use ($servers): ServerConnection {
            $all = $servers();

            foreach ($all as $candidate) {
                if ($candidate->name() === $name) {
                    return $candidate;
                }
            }

            $available = implode(', ', array_map(static fn (ServerConnection $c): string => $c->name(), $all));

            throw new AgentError("MCP server \"{$name}\" has no resources" . ($available !== '' ? ". Servers with resources: {$available}" : ''));
        };

        /**
         * One page of one server, or every page of every server.
         *
         * @param Closure(ServerConnection, ?string, array): array{items: list<array>, nextCursor?: string} $page
         * @param Closure(ServerConnection, array): list<array> $all
         */
        $list = static function (array $params, ?AbortSignal $signal, string $key, Closure $page, Closure $all) use ($servers, $findServer): array {
            $serverName = self::stringArgument($params, 'server');
            $cursor = self::stringArgument($params, 'cursor');
            $visible = static fn (array $item): bool => !self::isMcpAppResource($item);

            if ($serverName !== null) {
                $server = $findServer($serverName);
                $result = $page($server, $cursor, ['signal' => $signal, 'timeout' => $server->timeout()]);
                $items = array_map(static fn (array $item): array => self::listed($server->name(), $item), array_values(array_filter($result['items'], $visible)));

                return ['server' => $server->name(), $key => $items, ...(isset($result['nextCursor']) ? ['nextCursor' => $result['nextCursor']] : [])];
            }

            if ($cursor !== null) {
                throw new AgentError('cursor can only be used when a server is specified');
            }

            $sorted = $servers();
            usort($sorted, static fn (ServerConnection $a, ServerConnection $b): int => strcmp($a->name(), $b->name()));
            $items = [];
            $errors = [];

            // One server at a time rather than all at once: a failure on one must not stop the rest,
            // and a listing is not something that has to be fast.
            foreach ($sorted as $server) {
                try {
                    foreach ($all($server, ['signal' => $signal, 'timeout' => $server->timeout()]) as $item) {
                        if ($visible($item)) {
                            $items[] = self::listed($server->name(), $item);
                        }
                    }
                } catch (\Throwable $error) {
                    $errors[] = ['server' => $server->name(), 'error' => $error->getMessage()];
                }
            }

            return [$key => $items, ...($errors !== [] ? ['errors' => $errors] : [])];
        };

        $jsonResult = static function (string $tool, ?string $server, array $payload): AgentToolResult {
            [$content, $fullOutputPath] = McpTools::limit([new TextContent((string) json_encode($payload, JSON_UNESCAPED_SLASHES))]);
            $details = ['server' => $server ?? '', 'tool' => $tool];

            if ($fullOutputPath !== null) {
                $details['fullOutputPath'] = $fullOutputPath;
            }

            return new AgentToolResult($content, $details);
        };

        return [
            new CustomTool(
                name: self::LIST,
                label: self::LIST,
                description: 'Lists resources provided by MCP servers. Resources allow servers to share data that provides context to language models, such as files, database schemas, or application-specific information. Prefer resources over web search when possible.',
                parameters: $listParameters,
                execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($list, $jsonResult): AgentToolResult {
                    $payload = $list(
                        $params,
                        $signal,
                        'resources',
                        static function (ServerConnection $server, ?string $cursor, array $options): array {
                            $result = $server->resourcesPage($cursor, $options);

                            return ['items' => $result['resources'], ...(isset($result['nextCursor']) ? ['nextCursor' => $result['nextCursor']] : [])];
                        },
                        static fn (ServerConnection $server, array $options): array => $server->allResources($options),
                    );

                    return $jsonResult(self::LIST, self::stringArgument($params, 'server'), $payload);
                },
            ),
            new CustomTool(
                name: self::LIST_TEMPLATES,
                label: self::LIST_TEMPLATES,
                description: 'Lists resource templates provided by MCP servers. Parameterized resource templates allow servers to share data that takes parameters and provides context to language models, such as files, database schemas, or application-specific information. Prefer resource templates over web search when possible.',
                parameters: $listParameters,
                execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($list, $jsonResult): AgentToolResult {
                    $payload = $list(
                        $params,
                        $signal,
                        'resourceTemplates',
                        static function (ServerConnection $server, ?string $cursor, array $options): array {
                            $result = $server->resourceTemplatesPage($cursor, $options);

                            return ['items' => $result['resourceTemplates'], ...(isset($result['nextCursor']) ? ['nextCursor' => $result['nextCursor']] : [])];
                        },
                        static fn (ServerConnection $server, array $options): array => $server->allResourceTemplates($options),
                    );

                    return $jsonResult(self::LIST_TEMPLATES, self::stringArgument($params, 'server'), $payload);
                },
            ),
            new CustomTool(
                name: self::READ,
                label: self::READ,
                description: 'Read a specific resource from an MCP server given the server name and resource URI.',
                parameters: $readParameters,
                execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($findServer): AgentToolResult {
                    $serverName = self::stringArgument($params, 'server');
                    $uri = self::stringArgument($params, 'uri');

                    if ($serverName === null) {
                        throw new AgentError('server must be provided');
                    }

                    if ($uri === null) {
                        throw new AgentError('uri must be provided');
                    }

                    $server = $findServer($serverName);
                    $result = $server->readResource($uri, ['signal' => $signal, 'timeout' => $server->timeout()]);
                    $blocks = [];

                    // Several contents (for example a directory) are labeled with their URIs.
                    foreach ($result['contents'] as $contents) {
                        if (count($result['contents']) > 1) {
                            $blocks[] = ['type' => 'text', 'text' => (string) ($contents['uri'] ?? '') . ':'];
                        }

                        $blocks[] = ['type' => 'resource', 'resource' => $contents];
                    }

                    $converted = McpTools::toModelContent($server->name(), $blocks);
                    [$content, $fullOutputPath] = McpTools::limit($converted !== [] ? $converted : [new TextContent("Resource {$uri} is empty.")]);
                    $details = ['server' => $server->name(), 'tool' => self::READ];

                    if ($fullOutputPath !== null) {
                        $details['fullOutputPath'] = $fullOutputPath;
                    }

                    return new AgentToolResult($content, $details);
                },
            ),
        ];
    }

    /** @param array<string, mixed> $params */
    private static function stringArgument(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new AgentError("{$key} must be a string");
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
