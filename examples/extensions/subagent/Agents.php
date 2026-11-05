<?php

declare(strict_types=1);

namespace PigSubagent;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Prompt\Frontmatter;
use Pig\CodingAgent\Utils\YamlError;

/**
 * Agent discovery and configuration — upstream's `agents.ts`.
 *
 * An agent is a markdown file with frontmatter (`name`, `description`, optional `tools` and
 * `model`) and the system prompt below it. User agents live in `~/.pig/agent/agents/`, project
 * agents in the nearest `.pig/agents/` at or above the working directory.
 *
 * Every entry below is an array rather than a class on purpose: an agent is part of the tool's
 * `details`, which the session file keeps as JSON, so the shape that is written is the shape
 * that is read back.
 *
 * @phpstan-type Agent array{name: string, description: string, tools: ?list<string>, model: ?string, systemPrompt: string, source: 'user'|'project', filePath: string}
 */
final class Agents
{
    /** Upstream's `CONFIG_DIR_NAME`. */
    public const string CONFIG_DIR_NAME = '.pig';

    /**
     * Upstream's `discoverAgents()`.
     *
     * @param 'user'|'project'|'both' $scope
     * @return array{agents: list<Agent>, projectAgentsDir: ?string}
     */
    public static function discover(string $cwd, string $scope): array
    {
        $userDir = Config::home() . '/agents';
        $projectAgentsDir = self::findNearestProjectAgentsDir($cwd);

        $userAgents = $scope === 'project' ? [] : self::loadFromDir($userDir, 'user');
        $projectAgents = $scope === 'user' || $projectAgentsDir === null ? [] : self::loadFromDir($projectAgentsDir, 'project');

        // Keyed by name so a project agent replaces a user agent of the same name under `both`,
        // upstream's `Map` insertion order kept: user agents first, then the project's.
        $byName = [];

        foreach ([...$userAgents, ...$projectAgents] as $agent) {
            $byName[$agent['name']] = $agent;
        }

        return ['agents' => array_values($byName), 'projectAgentsDir' => $projectAgentsDir];
    }

    /**
     * Upstream's `formatAgentList()`.
     *
     * @param list<Agent> $agents
     * @return array{text: string, remaining: int}
     */
    public static function formatList(array $agents, int $maxItems): array
    {
        if ($agents === []) {
            return ['text' => 'none', 'remaining' => 0];
        }

        $listed = array_slice($agents, 0, $maxItems);

        return [
            'text' => implode('; ', array_map(static fn (array $a): string => "{$a['name']} ({$a['source']}): {$a['description']}", $listed)),
            'remaining' => count($agents) - count($listed),
        ];
    }

    /**
     * Upstream's `parseToolList()`: `tools: read, bash` and `tools: [read, bash]` are both in use.
     * Anything else yields no tools rather than throwing — this runs inside discovery, where one
     * bad file must not take down every other agent in the directory.
     *
     * @return list<string>|null
     */
    private static function parseToolList(mixed $value): ?array
    {
        $raw = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $tools = [];

        foreach ($raw as $tool) {
            if (is_string($tool) && trim($tool) !== '') {
                $tools[] = trim($tool);
            }
        }

        return $tools === [] ? null : $tools;
    }

    /**
     * @param 'user'|'project' $source
     * @return list<Agent>
     */
    private static function loadFromDir(string $dir, string $source): array
    {
        $agents = [];

        if (!is_dir($dir)) {
            return $agents;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return $agents;
        }

        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            $filePath = "{$dir}/{$entry}";

            // `is_file()` follows a symlink, which is how upstream's `isFile() || isSymbolicLink()`
            // reads: a link to a file counts, a directory does not.
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $content = file_get_contents($filePath);

            if ($content === false) {
                continue;
            }

            // A file whose frontmatter is not YAML is skipped like one with no `name`: upstream's
            // `parseFrontmatter()` returns an empty object for it, and the check below drops it.
            try {
                [$frontmatter, $body] = Frontmatter::parse($content);
            } catch (YamlError) {
                continue;
            }

            if (!is_string($frontmatter['name'] ?? null) || !is_string($frontmatter['description'] ?? null)) {
                continue;
            }

            $agents[] = [
                'name' => $frontmatter['name'],
                'description' => $frontmatter['description'],
                'tools' => self::parseToolList($frontmatter['tools'] ?? null),
                'model' => is_string($frontmatter['model'] ?? null) ? $frontmatter['model'] : null,
                'systemPrompt' => $body,
                'source' => $source,
                'filePath' => $filePath,
            ];
        }

        return $agents;
    }

    /** Upstream's `findNearestProjectAgentsDir()`: `.pig/agents` here or in the closest parent. */
    private static function findNearestProjectAgentsDir(string $cwd): ?string
    {
        $current = $cwd;

        while (true) {
            $candidate = $current . '/' . self::CONFIG_DIR_NAME . '/agents';

            if (is_dir($candidate)) {
                return $candidate;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }
}
