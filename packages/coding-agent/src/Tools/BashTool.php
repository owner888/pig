<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * The `bash` tool — upstream's `createBashTool(cwd, options)`. What it does is `ShellTool`'s, which
 * `powershell` shares; this is the bash half of upstream's `ShellToolConfig` and the options an
 * extension or an SDK caller builds one with:
 *
 * ```php
 * $bash = new BashTool($cwd,
 *     spawnHook: fn (SpawnContext $c): SpawnContext => $c->withEnv([...$c->env, 'CI' => '1']),
 *     sessionEnvironment: fn (): SessionEnvironment => SessionEnvironment::of($ctx->session),
 * );
 * ```
 *
 * Upstream's `operations` (a replacement for local execution, for running over SSH) is not here:
 * a pig command is a process the event loop watches, and a `spawnHook` that rewrites the command
 * into `ssh host …` does what most of those wanted.
 */
final readonly class BashTool implements AgentTool
{
    /**
     * Upstream's `bashToolSystemPromptContribution.guidelines` (and `powershell`'s), which the system
     * prompt carries for a built-in shell tool — the built-ins always expose the session variables.
     */
    public const string SESSION_ENVIRONMENT_GUIDELINE = 'You can inspect PIG_* environment variables for current model and session details.';

    private ShellTool $tool;

    /**
     * @param ?string $commandPrefix upstream's `commandPrefix` (`shellCommandPrefix`): a line run in
     *        front of the command — `shopt -s expand_aliases`, say — joined to it with a newline
     * @param ?string $shellPath upstream's `shellPath` option: this tool's own bash, in place of the
     *        `shellPath` setting `Shell::useShellPath()` was given
     * @param bool $exposeSessionEnvironment upstream's option, default true: `PIG_SESSION_ID`,
     *        `PIG_SESSION_FILE`, `PIG_PROVIDER`, `PIG_MODEL` and `PIG_REASONING_LEVEL` in the
     *        command's environment
     * @param (Closure(): ?SessionEnvironment)|null $sessionEnvironment the session those describe,
     *        asked as each command starts; the session wires its own, and a tool without one sets none
     * @param (Closure(SpawnContext): SpawnContext)|null $spawnHook upstream's `spawnHook`
     */
    public function __construct(
        string $cwd,
        ?string $commandPrefix = null,
        ?string $shellPath = null,
        bool $exposeSessionEnvironment = true,
        ?Closure $sessionEnvironment = null,
        ?Closure $spawnHook = null,
    ) {
        $this->tool = new ShellTool(
            $cwd,
            'bash',
            'Bash',
            'bash',
            'pig-bash',
            static fn (): ShellConfig => Shell::config($shellPath),
            static fn (string $command): string => $command,
            $commandPrefix,
            $exposeSessionEnvironment,
            $sessionEnvironment,
            $spawnHook,
        );
    }

    #[\Override]
    public function definition(): Tool
    {
        return $this->tool->definition();
    }

    #[\Override]
    public function label(): string
    {
        return $this->tool->label();
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        return $this->tool->execute($toolCallId, $arguments, $signal, $onUpdate);
    }
}
