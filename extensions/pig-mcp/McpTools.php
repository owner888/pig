<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\Mcp\Protocol\Content;
use RuntimeException;

/**
 * Adapts MCP tools to pig tools — upstream's `extensions/mcp/tools.ts`.
 *
 * Results map onto pig's model-facing content (text and images). Text over 20KB keeps its start
 * and end with the middle cut out, like Codex does, and the full text is saved to a temp file
 * the model can read. Binary resources other than images are saved to temp files too. An MCP
 * error (`isError`) is an error result for the model.
 */
final class McpTools
{
    /** Provider tool names are limited to 64 characters of `[A-Za-z0-9_-]`. */
    private const int MAX_TOOL_NAME_LENGTH = 64;

    /** Model-facing text of an MCP result beyond this is cut in the middle. */
    public const int OUTPUT_MAX_BYTES = 20 * 1024;

    /**
     * `mcp__<server>__<tool>`, sanitized and shortened with a hash suffix when too long.
     *
     * `$isTaken` reports names already used by a different MCP tool: sanitizing can map two tools
     * to one name (`a.b` and `a_b`), and the second then gets the hash suffix too.
     *
     * @param Closure(string): bool|null $isTaken
     */
    public static function name(string $server, string $tool, ?Closure $isTaken = null): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', "mcp__{$server}__{$tool}");

        if (strlen($name) <= self::MAX_TOOL_NAME_LENGTH && ($isTaken === null || !$isTaken($name))) {
            return $name;
        }

        $hash = substr(hash('sha256', "{$server}\0{$tool}"), 0, 8);

        return substr($name, 0, self::MAX_TOOL_NAME_LENGTH - strlen($hash) - 1) . '_' . $hash;
    }

    /**
     * The pig tool for one MCP tool of one server.
     *
     * @param array<string, mixed> $tool as the server listed it
     */
    public static function define(string $server, array $tool, string $name, ServerConnection $connection, ?Closure $readableResources = null): CustomTool
    {
        $toolName = (string) $tool['name'];
        $title = $tool['title'] ?? ($tool['annotations']['title'] ?? null);
        $description = trim((string) ($tool['description'] ?? ''));

        return new CustomTool(
            name: $name,
            label: "{$server}/{$toolName}",
            description: $description !== '' ? $description : (is_string($title) ? $title : "MCP tool {$toolName} from server {$server}"),
            parameters: self::parameters(is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : []),
            execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($server, $toolName, $connection, $readableResources): AgentToolResult {
                $result = $connection->callTool($toolName, $params, [
                    'signal' => $signal,
                    'timeout' => $connection->timeout(),
                    'onProgress' => static function (array $progress) use ($onUpdate, $server, $toolName): void {
                        if ($onUpdate === null) {
                            return;
                        }

                        $total = isset($progress['total']) ? '/' . $progress['total'] : '';
                        $text = (string) ($progress['message'] ?? "Progress {$progress['progress']}{$total}");
                        $onUpdate(new AgentToolResult([new TextContent($text)], ['server' => $server, 'tool' => $toolName]));
                    },
                ]);

                return self::convert($server, $toolName, $result, $readableResources !== null && $readableResources());
            },
        );
    }

    /**
     * Tool input schemas must be objects. MCP servers may omit `type`, and some providers reject
     * object schemas without `properties`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function parameters(array $schema): array
    {
        $schema['type'] ??= 'object';

        if (!array_key_exists('properties', $schema)) {
            $schema['properties'] = new \stdClass();
        } elseif ($schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * Convert an MCP result. An `isError` result is an error for the model — raised, because that
     * is how pig's loop learns a tool failed.
     *
     * @param array<string, mixed> $result
     */
    public static function convert(string $server, string $tool, array $result, bool $readableResources = false): AgentToolResult
    {
        $blocks = is_array($result['content'] ?? null) ? $result['content'] : [];
        $converted = $blocks !== [] ? self::toModelContent($server, $blocks, $readableResources) : Content::toLlm($result);

        if (($result['isError'] ?? false) === true && self::textOf($converted) === '') {
            $converted[] = new TextContent("MCP tool {$server}/{$tool} returned an error");
        }

        [$content, $fullOutputPath] = self::limit($converted);
        $details = ['server' => $server, 'tool' => $tool];

        if ($fullOutputPath !== null) {
            $details['fullOutputPath'] = $fullOutputPath;
        }

        if (($result['isError'] ?? false) === true) {
            throw new AgentError(self::textOf($content));
        }

        return new AgentToolResult($content, $details);
    }

    /**
     * Model-facing content of a server's blocks, before the output limit.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<TextContent|ImageContent>
     */
    public static function toModelContent(string $server, array $blocks, bool $readableResources = false): array
    {
        $out = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            foreach (self::blockToContent($block, $server, $readableResources) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<TextContent|ImageContent>
     */
    private static function blockToContent(array $block, string $server = '', bool $readableResources = false): array
    {
        if (($block['type'] ?? null) === 'resource_link') {
            $details = array_values(array_filter([
                $block['mimeType'] ?? null,
                isset($block['size']) ? self::size((int) $block['size']) : null,
            ]));
            $description = isset($block['description']) ? ': ' . $block['description'] : '';
            $label = $block['title'] ?? $block['name'] ?? '';
            // A link is only worth naming the reader for when the resource tools are on the model.
            $read = $readableResources ? ". Read it with read_mcp_resource (server \"{$server}\")" : '';

            return [new TextContent(sprintf(
                '[Resource %s "%s"%s%s%s]',
                (string) ($block['uri'] ?? ''),
                (string) $label,
                $details !== [] ? ' (' . implode(', ', $details) . ')' : '',
                $description,
                $read,
            ))];
        }

        $resource = is_array($block['resource'] ?? null) ? $block['resource'] : null;

        if (($block['type'] ?? null) === 'resource' && $resource !== null && isset($resource['blob']) && !str_starts_with((string) ($resource['mimeType'] ?? ''), 'image/')) {
            $uri = (string) ($resource['uri'] ?? '');
            $mime = (string) ($resource['mimeType'] ?? '');
            $data = (string) base64_decode((string) $resource['blob'], true);

            if (self::isTextMime($mime)) {
                return [new TextContent($data)];
            }

            $kind = ($mime !== '' ? $mime : 'unknown type') . ', ' . self::size(strlen($data));

            try {
                $path = self::saveToTempFile($data, self::extensionOf($uri));

                return [new TextContent("[Binary resource {$uri} ({$kind}) saved to {$path}]")];
            } catch (RuntimeException $error) {
                return [new TextContent("[Binary resource {$uri} ({$kind}) could not be saved: {$error->getMessage()}]")];
            }
        }

        return Content::toLlm(['content' => [$block]]);
    }

    /**
     * Keep model-facing text within `OUTPUT_MAX_BYTES`. Longer text becomes one text block in
     * Codex's truncation format, followed by the path of the file with the full text; images
     * follow it.
     *
     * @param list<TextContent|ImageContent> $content
     * @return array{0: list<TextContent|ImageContent>, 1: ?string}
     */
    public static function limit(array $content): array
    {
        $combined = self::textOf($content);
        $bytes = strlen($combined);

        if ($bytes <= self::OUTPUT_MAX_BYTES) {
            return [$content, null];
        }

        [$cut, $removed] = self::truncateMiddle($combined, self::OUTPUT_MAX_BYTES);
        $totalLines = substr_count($combined, "\n") + 1;

        try {
            $path = self::saveToTempFile($combined, '.txt');
            $where = "[Full output: {$path} (read it with offset/limit)]";
        } catch (RuntimeException $error) {
            $path = null;
            $where = "[Could not save the full output: {$error->getMessage()}]";
        }

        $tokens = (int) ceil($bytes / 4);
        $text = "Warning: truncated output (original token count: {$tokens})\nTotal output lines: {$totalLines}\n\n{$cut}\n\n{$where}";
        $images = array_values(array_filter($content, static fn ($block): bool => $block instanceof ImageContent));

        return [[new TextContent($text), ...$images], $path];
    }

    /**
     * Codex's middle cut: the first half of the budget from the start, the second from the end,
     * each snapped to a character boundary, and the number of characters removed between them.
     *
     * @return array{0: string, 1: int}
     */
    public static function truncateMiddle(string $text, int $maxBytes): array
    {
        $length = strlen($text);

        if ($length <= $maxBytes) {
            return [$text, 0];
        }

        $boundary = static fn (int $i): bool => $i >= $length || (ord($text[$i]) & 0xC0) !== 0x80;
        $headEnd = intdiv($maxBytes, 2);

        while ($headEnd > 0 && !$boundary($headEnd)) {
            $headEnd--;
        }

        $tailStart = $length - ($maxBytes - intdiv($maxBytes, 2));

        while ($tailStart < $length && !$boundary($tailStart)) {
            $tailStart++;
        }

        $removed = mb_strlen(substr($text, $headEnd, $tailStart - $headEnd), 'UTF-8');

        return [substr($text, 0, $headEnd) . "…{$removed} chars truncated…" . substr($text, $tailStart), $removed];
    }

    /** Results can carry private data, so only the user may read the file. */
    public static function saveToTempFile(string $data, string $extension): string
    {
        $path = sys_get_temp_dir() . '/pig-mcp-' . bin2hex(random_bytes(8)) . $extension;

        if (file_put_contents($path, $data) === false) {
            throw new RuntimeException("could not write {$path}");
        }

        chmod($path, 0600);

        return $path;
    }

    /** @param list<TextContent|ImageContent> $content */
    private static function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = $block->text;
            }
        }

        return implode("\n", $parts);
    }

    /** File extension for a saved binary resource: the one its URI ends in, else `.bin`. */
    private static function extensionOf(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : $uri;

        return preg_match('/\.[A-Za-z0-9]{1,8}$/', $path, $m) === 1 ? $m[0] : '.bin';
    }

    /** Blobs of these types are shown as text. */
    private static function isTextMime(string $mime): bool
    {
        if ($mime === '') {
            return false;
        }

        $type = strtolower(trim(explode(';', $mime, 2)[0]));

        return str_starts_with($type, 'text/') || $type === 'application/json' || str_ends_with($type, '+json') || str_ends_with($type, '+xml');
    }

    private static function size(int $bytes): string
    {
        return $bytes < 1024 ? "{$bytes} B" : ($bytes < 1024 * 1024 ? round($bytes / 1024, 1) . ' KB' : round($bytes / (1024 * 1024), 1) . ' MB');
    }
}
