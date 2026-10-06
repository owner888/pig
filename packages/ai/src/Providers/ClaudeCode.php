<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Tool;

/**
 * The identity a Claude Pro/Max subscription token is issued to.
 *
 * An `sk-ant-oat` token belongs to Claude Code, and Anthropic's API checks that the request it
 * arrives on looks like one Claude Code would make: the two betas, the `claude-cli` user agent,
 * `x-app: cli`, the "You are Claude Code" system block, and **tool names spelled the way Claude
 * Code spells them** — `Read` and not `read`. Upstream's `anthropic-messages.ts` calls this
 * "stealth mode" and has carried it since January 2026 (commit `b4351040a`, a week after pig's
 * anchor); the version string is the one that file names.
 *
 * It is one fact read from three places — the headers, the tools going out, the tool calls
 * coming back — which is why it is a class and not three constants in `Anthropic`. A name that
 * is not in the table goes out as it is, which is what upstream does too.
 */
final class ClaudeCode
{
    public const string VERSION = '2.1.280';

    public const string IDENTITY = "You are Claude Code, Anthropic's official CLI for Claude.";

    /** Both betas, in upstream's order. */
    public const array BETAS = ['claude-code-20250219', 'oauth-2025-04-20'];

    /**
     * Claude Code 2.x tool names, canonical casing. Source: upstream's list, from
     * https://cchistory.mariozechner.at/data/prompts-2.1.11.md
     */
    private const array TOOLS = [
        'Read', 'Write', 'Edit', 'Bash', 'Grep', 'Glob', 'AskUserQuestion', 'EnterPlanMode',
        'ExitPlanMode', 'KillShell', 'NotebookEdit', 'Skill', 'Task', 'TaskOutput', 'TodoWrite',
        'WebFetch', 'WebSearch',
    ];

    /** Is this key a subscription token rather than an API key? */
    public static function isToken(string $apiKey): bool
    {
        return str_contains($apiKey, 'sk-ant-oat');
    }

    /** @return array<string, string> the headers that make a request read as Claude Code's */
    public static function headers(string $token): array
    {
        return [
            'authorization' => "Bearer {$token}",
            'user-agent' => 'claude-cli/' . self::VERSION,
            'x-app' => 'cli',
        ];
    }

    /**
     * `read` → `Read`, by a case-insensitive match against the table; anything else unchanged.
     */
    public static function nameOut(string $name): string
    {
        foreach (self::TOOLS as $canonical) {
            if (strcasecmp($canonical, $name) === 0) {
                return $canonical;
            }
        }

        return $name;
    }

    /**
     * `Read` → whichever of this request's tools is spelled that way ignoring case, so the
     * loop finds the tool it declared. With no tools to match against, the name stays as it came.
     *
     * @param list<Tool> $tools
     */
    public static function nameIn(string $name, array $tools): string
    {
        foreach ($tools as $tool) {
            if (strcasecmp($tool->name, $name) === 0) {
                return $tool->name;
            }
        }

        return $name;
    }
}
