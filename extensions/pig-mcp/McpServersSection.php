<?php

declare(strict_types=1);

namespace PigMcp;

use Pig\CodingAgent\McpServers;

/**
 * The `mcp_servers` system prompt section — upstream's `renderServersSection()` in
 * `extensions/mcp/index.ts`: every enabled server with codemode or deferred tools, with how its
 * tools are reached and a one-line summary. "The model learns of the servers from it, since
 * neither codemode nor tool_search lists them."
 *
 * Lengths are JavaScript's `.length`, UTF-16 code units, because the limits are upstream's numbers.
 */
final class McpServersSection
{
    /** "Name of the system prompt section that lists the servers whose tools are not declared." */
    public const string NAME = 'mcp_servers';

    /** "Characters of a server description in the section, as Codex allows for deferred namespaces." */
    public const int MAX_SERVER_DESCRIPTION_CHARS = 250;

    /**
     * "Characters of the whole section. Descriptions shrink to fit; when the server lines alone do
     * not fit, the last servers are left out and counted in a closing line."
     */
    public const int MAX_SERVERS_SECTION_CHARS = 4096;

    /**
     * The section, or null when no server has codemode or deferred tools.
     *
     * @param list<array{entry: ServerEntry, instructions?: string|null}> $servers every configured
     *        server, with the instructions of the ones that are connected
     */
    public static function render(array $servers): ?string
    {
        $listed = array_values(array_filter(
            $servers,
            static fn (array $server): bool => $server['entry']->isEnabled() && self::hasIndirectTools($server['entry']),
        ));

        if ($listed === []) {
            return null;
        }

        usort($listed, static fn (array $a, array $b): int => self::localeCompare($a['entry']->name, $b['entry']->name));

        $reaches = array_map(
            static fn (array $server): string => isset(self::configuredExposures($server['entry'])['codemode']) ? 'codemode' : 'tool_search',
            $listed,
        );
        $intro = self::intro(array_fill_keys($reaches, true));
        $heads = array_map(
            static fn (array $server, string $reach): string => '- ' . McpServers::namespace($server['entry']->name) . " ({$reach})",
            $listed,
            $reaches,
        );
        $omitted = static fn (int $count): array => $count > 0
            ? ["- … {$count} more server" . ($count === 1 ? '' : 's') . '; find their tools with searchTools()']
            : [];
        // "Characters of the intro, the first `kept` server lines without descriptions, and the omission line."
        $size = static fn (int $kept): int => self::length(implode("\n", [$intro, ...array_slice($heads, 0, $kept), ...$omitted(count($listed) - $kept)]));

        $kept = count($listed);

        while ($kept > 0 && $size($kept) > self::MAX_SERVERS_SECTION_CHARS) {
            $kept--;
        }

        // "Each description also takes a ": " separator."
        $perServer = $kept === 0
            ? 0
            : min(self::MAX_SERVER_DESCRIPTION_CHARS, intdiv(self::MAX_SERVERS_SECTION_CHARS - $size($kept), $kept) - 2);

        $lines = [];

        foreach (array_slice($listed, 0, $kept) as $index => $server) {
            $summary = $perServer > 0 ? self::truncate(self::serverSummary($server), $perServer) : '';
            $lines[] = $summary !== '' ? "{$heads[$index]}: {$summary}" : $heads[$index];
        }

        return implode("\n", [$intro, ...$lines, ...$omitted(count($listed) - $kept)]);
    }

    /**
     * "Exposures the server's tools can have, known from its config before it connects": the
     * server's and every per-tool override's, as `here()` reads them. pig's `codemode-deferred` is
     * reached from codemode scripts too, so it counts as `codemode` here.
     *
     * @return array<string, true>
     */
    private static function configuredExposures(ServerEntry $entry): array
    {
        $overrides = is_array($entry->config['toolExposure'] ?? null) ? array_values($entry->config['toolExposure']) : [];
        $exposures = [];

        foreach ([$entry->exposure(), ...$overrides] as $exposure) {
            $here = McpConfig::here((string) $exposure);
            $exposures[$here === 'codemode-deferred' ? 'codemode' : $here] = true;
        }

        return $exposures;
    }

    /** "Whether some of the server's tools are reached through codemode or tool_search." */
    private static function hasIndirectTools(ServerEntry $entry): bool
    {
        $exposures = self::configuredExposures($entry);

        return isset($exposures['codemode']) || isset($exposures['deferred']);
    }

    /**
     * "The section's first line. It explains only the ways of reaching tools that the listed servers use."
     *
     * @param array<string, true> $reaches
     */
    private static function intro(array $reaches): string
    {
        $intro = 'MCP servers whose tools are not declared to you.';

        if (isset($reaches['codemode'])) {
            $intro .= ' Call the tools of `codemode` servers from codemode scripts.';
        }

        if (isset($reaches['tool_search'])) {
            $intro .= ' Load the tools of `tool_search` servers with `tool_search`.';
        }

        return $intro;
    }

    /**
     * "First line of the configured description, or of the server instructions once connected."
     *
     * @param array{entry: ServerEntry, instructions?: string|null} $server
     */
    private static function serverSummary(array $server): string
    {
        $description = is_string($server['entry']->config['description'] ?? null) ? self::jsTrim($server['entry']->config['description']) : '';
        $text = $description !== '' ? $description : (string) ($server['instructions'] ?? '');

        return self::jsTrim(explode("\n", $text, 2)[0]);
    }

    /** Upstream's `truncate()`: `text.slice(0, max - 1).trimEnd()` and an ellipsis, in UTF-16 units. */
    private static function truncate(string $text, int $max): string
    {
        if (self::length($text) <= $max) {
            return $text;
        }

        if ($max <= 1) {
            return '';
        }

        $units = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $head = (string) mb_convert_encoding(substr($units, 0, 2 * ($max - 1)), 'UTF-8', 'UTF-16LE');

        return (string) preg_replace('/[\s\x{FEFF}]+$/u', '', $head) . '…';
    }

    /** JavaScript's `String.prototype.trim()` over the whitespace a description has in practice. */
    private static function jsTrim(string $text): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $text);
    }

    /** JavaScript's `.length`: UTF-16 code units. */
    private static function length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * `a.localeCompare(b)` for a server name, which `McpConfig` restricts to `[A-Za-z0-9_-]`: the
     * root collation's order over those characters — `_`, then `-`, then the digits, then the
     * letters without regard to case — and, between names equal that way, lower case first.
     * Written out rather than through `ext-intl`, which pig does not require (see CLAUDE.md).
     */
    private static function localeCompare(string $a, string $b): int
    {
        $rank = static fn (string $char): int => match (true) {
            $char === '_' => 0,
            $char === '-' => 1,
            ctype_digit($char) => 2 + (int) $char,
            default => 12 + ord(strtolower($char)),
        };
        $shorter = min(strlen($a), strlen($b));

        for ($i = 0; $i < $shorter; $i++) {
            $order = $rank($a[$i]) <=> $rank($b[$i]);

            if ($order !== 0) {
                return $order;
            }
        }

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        for ($i = 0; $i < $shorter; $i++) {
            if ($a[$i] !== $b[$i]) {
                return ctype_lower($a[$i]) ? -1 : 1;
            }
        }

        return 0;
    }
}
