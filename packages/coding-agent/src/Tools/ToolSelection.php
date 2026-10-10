<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * What `--tools` and `--exclude-tools` said, with `*` in it.
 *
 * Upstream's `createToolNameMatcher()` in `core/mcp-servers.ts`: each entry is an exact name or
 * a pattern where `*` matches any characters, so `--tools read,codemode,'mcp__radius__*'` is
 * three entries of two kinds. Nothing else is special — no `?`, no `[…]` — because a tool name
 * has no business containing either, and a pattern language wider than its input is a second
 * thing to document.
 *
 * **MCP tools are kept unless an entry asks about them.** `--tools read,codemode` means "these
 * two built-ins" and must not also unregister every MCP server's tools, which codemode and
 * `tool_search` reach through; so an MCP tool (`mcp__<server>__<tool>`, or one of the three
 * resource tools) is only filtered when some entry starts with `mcp__`. Upstream's rule since
 * its 1.0.3 fix for `pi --tools codemode` arriving with no servers.
 *
 * `--exclude-tools` has no such exception: a denylist means what it names.
 */
final readonly class ToolSelection
{
    private bool $namesMcp;

    /** @param list<string> $entries */
    public function __construct(private array $entries)
    {
        $namesMcp = false;

        foreach ($entries as $entry) {
            $namesMcp = $namesMcp || str_starts_with($entry, 'mcp__');
        }

        $this->namesMcp = $namesMcp;
    }

    /** Does an entry name this tool, exactly or by pattern? */
    public function matches(string $name): bool
    {
        foreach ($this->entries as $entry) {
            if (self::entryMatches($entry, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Should an allowlist built from these entries keep this tool?
     *
     * The MCP exception lives here and not in `matches()`, because `--exclude-tools` asks the
     * plain question and this is the one `--tools` asks.
     */
    public function allows(string $name): bool
    {
        // Upstream: "An empty list, like `noTools: \"all\"`, disables MCP tools too."
        if (self::isMcp($name) && !$this->namesMcp && $this->entries !== []) {
            return true;
        }

        return $this->matches($name);
    }

    /**
     * The entries that name none of the given tools — typos, to be said by name. An `mcp__`
     * entry is not one, because the servers it names connect after startup.
     *
     * @param list<string> $available
     * @return list<string>
     */
    public function unmatched(array $available): array
    {
        $out = [];

        foreach ($this->entries as $entry) {
            if (str_starts_with($entry, 'mcp__')) {
                continue;
            }

            $hit = false;

            foreach ($available as $name) {
                $hit = $hit || self::entryMatches($entry, $name);
            }

            if (!$hit) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** An MCP server's tool, or one of the resource tools that reach every server. */
    public static function isMcp(string $name): bool
    {
        return str_starts_with($name, 'mcp__')
            || in_array($name, ['list_mcp_resources', 'list_mcp_resource_templates', 'read_mcp_resource'], true);
    }

    private static function entryMatches(string $entry, string $name): bool
    {
        if (!str_contains($entry, '*')) {
            return $entry === $name;
        }

        $parts = array_map(static fn (string $part): string => preg_quote($part, '/'), explode('*', $entry));

        return preg_match('/^' . implode('.*', $parts) . '\z/', $name) === 1;
    }
}
