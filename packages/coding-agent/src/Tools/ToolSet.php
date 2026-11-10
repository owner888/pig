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
    /**
     * The operations every built-in tool is made with, when not this machine's: a Docker account's
     * container or an SSH account's machine (`RemoteOperations`, docs/dev/design/storage.md phase
     * 4), set once by `bin/pig` for the whole run. Null for this machine, as upstream's tools
     * default to.
     */
    private static ?RemoteOperations $operations = null;

    public static function useOperations(?RemoteOperations $operations): void
    {
        self::$operations = $operations;
    }

    public static function operations(): ?RemoteOperations
    {
        return self::$operations;
    }

    /**
     * Run `$command` in `$cwd` where the tools run (`operations()`), or here: what pig runs in a
     * project besides the tools — `git` for `/diff`, the doctor, a PR description. Run here for an
     * account whose files are elsewhere, it would read the wrong ones; for a Docker account's
     * directory, a `.git/config` of the account's (`core.fsmonitor`) would run on the host.
     *
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    public static function run(array $command, string $cwd, float $timeout = 30.0): array
    {
        if (self::$operations !== null) {
            return self::$operations->run($command, cwd: $cwd);
        }

        [$exit, $out, $errors] = \Pig\Tui\Process::run($command, $timeout, $cwd);

        return [$exit, $out, $errors];
    }

    /**
     * Everything there is, in the order a prompt lists them — upstream's `allToolNames`, `powershell`
     * among them though no default names it and it runs only on native Windows.
     */
    public const array ALL = ['read', 'bash', 'powershell', 'edit', 'write', 'grep', 'find', 'ls'];

    /** The default: enough to do work. */
    public const array CODING = ['read', 'bash', 'edit', 'write'];

    /** Enough to answer questions about a codebase and change none of it. */
    public const array READ_ONLY = ['read', 'grep', 'find', 'ls'];

    /** One line each, for the system prompt. */
    private const array DESCRIPTIONS = [
        'read' => 'Read a file, or look at an image',
        'bash' => 'Run a bash command',
        'powershell' => 'Run a PowerShell command',
        'edit' => 'Change parts of a file by replacing exact text, several separate places in one call',
        'write' => 'Create a file, or replace one outright',
        'grep' => 'Search file contents for a pattern (respects .gitignore)',
        'find' => 'Find files by glob pattern (respects .gitignore)',
        'ls' => 'List a directory',
    ];

    /**
     * Build the named tools, bound to $cwd.
     *
     * $options is upstream's `createAllToolDefinitions()` options, by tool: `read` takes
     * `autoResizeImages` and `resizeOptions` (a closure asked per read, since the model can change),
     * `bash` takes `commandPrefix`, and both shells take `sessionEnvironment` — the closure that
     * answers the session their `PIG_*` variables describe, asked as each command starts.
     *
     * @param list<string> $names
     * @param array{read?: array{autoResizeImages?: bool, resizeOptions?: \Closure(): ?array<string, int>}, bash?: array{commandPrefix?: ?string, sessionEnvironment?: \Closure(): ?SessionEnvironment}, powershell?: array{sessionEnvironment?: \Closure(): ?SessionEnvironment}} $options
     * @return list<AgentTool>
     */
    public static function create(string $cwd, array $names = self::CODING, array $options = []): array
    {
        return array_map(static fn (string $name): AgentTool => self::one($cwd, $name, $options), array_values($names));
    }

    /** @param array{read?: array{autoResizeImages?: bool, resizeOptions?: \Closure(): ?array<string, int>}, bash?: array{commandPrefix?: ?string, sessionEnvironment?: \Closure(): ?SessionEnvironment}, powershell?: array{sessionEnvironment?: \Closure(): ?SessionEnvironment}} $options */
    public static function one(string $cwd, string $name, array $options = []): AgentTool
    {
        $operations = self::$operations;

        return match ($name) {
            'read' => new ReadTool($cwd, $options['read']['autoResizeImages'] ?? true, $options['read']['resizeOptions'] ?? null, operations: $operations),
            'bash' => new BashTool(
                $cwd,
                $options['bash']['commandPrefix'] ?? null,
                sessionEnvironment: $options['bash']['sessionEnvironment'] ?? null,
                operations: $operations,
            ),
            // PowerShell is Windows's, and a container is Linux: there is none there.
            'powershell' => $operations === null
                ? new PowerShellTool($cwd, sessionEnvironment: $options['powershell']['sessionEnvironment'] ?? null)
                : throw new AgentError('There is no PowerShell in the container this account works in.'),
            'edit' => new EditTool($cwd, $operations),
            'write' => new WriteTool($cwd, $operations),
            'grep' => new GrepTool($cwd, $operations),
            'find' => new FindTool($cwd, $operations),
            'ls' => new LsTool($cwd, $operations),
            default => throw new AgentError("Unknown tool '{$name}'. Available: " . implode(', ', self::ALL)),
        };
    }

    public static function describe(string $name): string
    {
        return self::DESCRIPTIONS[$name] ?? $name;
    }
}
