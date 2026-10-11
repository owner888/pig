<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * The `powershell` tool — upstream's `createPowerShellTool(cwd, options)` in
 * `core/tools/powershell.ts`.
 *
 * `bash` with another shell: `pwsh.exe` when it is on `PATH`, else `powershell.exe`, started with
 * `-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command` (`Shell::powerShellConfig()`), and
 * every command led by a line that makes the console write UTF-8. Same output handling, same
 * structured result, same `PIG_*` session variables.
 *
 * Off by default and on nowhere by itself, as upstream has it: it is one of the built-ins `--tools`
 * and `defaultTools` can name (`["-bash", "+powershell"]`), and on anything but native Windows a
 * call to it fails with upstream's "only available on Windows". `!` and `!!` keep using bash.
 *
 * $shell replaces the resolution — upstream's `operations` option is how its tests do the same —
 * so the tool can be exercised on a machine without PowerShell.
 */
final readonly class PowerShellTool implements AgentTool
{
    /** Upstream's `UTF8_OUTPUT_PREFIX`. */
    public const string UTF8_OUTPUT_PREFIX = "try { [Console]::OutputEncoding=[System.Text.Encoding]::UTF8 } catch {}\n";

    private ShellTool $tool;

    /**
     * @param bool $exposeSessionEnvironment as `BashTool`'s
     * @param (Closure(): ?SessionEnvironment)|null $sessionEnvironment as `BashTool`'s
     * @param (Closure(SpawnContext): SpawnContext)|null $spawnHook as `BashTool`'s
     * @param ShellConfig|null $shell the PowerShell to run; null resolves it as upstream does when a
     *        command starts
     */
    public function __construct(
        string $cwd,
        bool $exposeSessionEnvironment = true,
        ?Closure $sessionEnvironment = null,
        ?Closure $spawnHook = null,
        ?ShellConfig $shell = null,
    ) {
        $this->tool = new ShellTool(
            $cwd,
            'powershell',
            'PowerShell',
            'PowerShell',
            'pig-powershell',
            static fn (): ShellConfig => $shell ?? Shell::powerShellConfig(),
            static fn (string $command): string => self::UTF8_OUTPUT_PREFIX . $command,
            null,
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
