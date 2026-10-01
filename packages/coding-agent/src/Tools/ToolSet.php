<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;

/**
 * Which tools the agent has, by name.
 *
 * Not every run wants all of them. Reviewing a pull request needs to read and search and
 * nothing else, and a model that cannot write is a stronger guarantee than a model that
 * has been asked not to.
 */
final class ToolSet
{
    /** Everything there is, in the order a prompt lists them. */
    public const array ALL = ['read', 'bash', 'edit', 'write', 'grep', 'find', 'ls'];

    /** The default: enough to do work. */
    public const array CODING = ['read', 'bash', 'edit', 'write'];

    /** Enough to answer questions about a codebase and change none of it. */
    public const array READ_ONLY = ['read', 'grep', 'find', 'ls'];

    /** One line each, for the system prompt. */
    private const array DESCRIPTIONS = [
        'read' => 'Read a file, or look at an image',
        'bash' => 'Run a bash command',
        'edit' => 'Change part of a file by replacing exact text',
        'write' => 'Create a file, or replace one outright',
        'grep' => 'Search file contents for a pattern (respects .gitignore)',
        'find' => 'Find files by glob pattern (respects .gitignore)',
        'ls' => 'List a directory',
    ];

    /**
     * Build the named tools, bound to $cwd.
     *
     * @param list<string> $names
     * @return list<AgentTool>
     */
    public static function create(string $cwd, array $names = self::CODING): array
    {
        return array_map(static fn (string $name): AgentTool => self::one($cwd, $name), array_values($names));
    }

    public static function one(string $cwd, string $name): AgentTool
    {
        return match ($name) {
            'read' => new ReadTool($cwd),
            'bash' => new BashTool($cwd),
            'edit' => new EditTool($cwd),
            'write' => new WriteTool($cwd),
            'grep' => new GrepTool($cwd),
            'find' => new FindTool($cwd),
            'ls' => new LsTool($cwd),
            default => throw new AgentError("Unknown tool '{$name}'. Available: " . implode(', ', self::ALL)),
        };
    }

    public static function describe(string $name): string
    {
        return self::DESCRIPTIONS[$name] ?? $name;
    }
}
