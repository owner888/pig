<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\PowerShellTool;
use Pig\CodingAgent\Tools\SessionEnvironment;
use Pig\CodingAgent\Tools\Shell;
use Pig\CodingAgent\Tools\ShellConfig;
use Pig\CodingAgent\Tools\ShellTool;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Test\AssertsThrows;

/**
 * The `powershell` tool and the shell resolution under both shell tools — upstream's
 * `core/tools/powershell.ts` and `utils/shell.ts`. No PowerShell is needed: the executable is a
 * stand-in script handed in as a `ShellConfig`, and the resolution takes the platform, the
 * environment and the file check as arguments.
 */
final class PowerShellToolTest extends ToolTestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Loop::reset();
    }

    /** A "PowerShell" that prints the arguments it was given, one per line, and the session id. */
    private function standIn(): ShellConfig
    {
        $script = $this->cwd . '/pwsh';
        file_put_contents($script, "#!/bin/sh\nfor a; do printf '[%s]\\n' \"\$a\"; done\nprintf 'id=%s' \"\$PIG_SESSION_ID\"\n");
        chmod($script, 0o755);

        return new ShellConfig($script, Shell::POWERSHELL_ARGS);
    }

    public function testACommandGoesToPowerShellWithUpstreamsArgumentsAndTheUtf8Prefix(): void
    {
        $tool = new PowerShellTool(
            $this->cwd,
            sessionEnvironment: static fn (): SessionEnvironment => new SessionEnvironment(sessionId: 'ps-1'),
            shell: $this->standIn(),
        );

        $result = Async::run(static fn (): AgentToolResult => $tool->execute('call-1', ['command' => 'Get-ChildItem']));

        $this->assertSame(
            "[-NoProfile]\n[-NonInteractive]\n[-ExecutionPolicy]\n[Bypass]\n[-Command]\n["
                . PowerShellTool::UTF8_OUTPUT_PREFIX . "Get-ChildItem]\nid=ps-1",
            $this->textOf($result),
        );
        $this->assertSame(0, $result->structuredContent['exit_code']);
    }

    public function testItIsNamedAndShapedLikeBash(): void
    {
        $definition = (new PowerShellTool($this->cwd, shell: $this->standIn()))->definition();

        $this->assertSame('powershell', $definition->name);
        $this->assertStringContainsString('Run a PowerShell command', $definition->description);
        $this->assertSame(ShellTool::OUTPUT_SCHEMA, $definition->outputSchema);
        $this->assertInstanceOf(PowerShellTool::class, ToolSet::one($this->cwd, 'powershell'));
        $this->assertContains('powershell', ToolSet::ALL);
        $this->assertNotContains('powershell', ToolSet::CODING);
    }

    public function testOffWindowsTheToolSaysItIsWindowsOnly(): void
    {
        $this->assertThrows(
            AgentError::class,
            static fn () => Shell::powerShellConfig('Linux', []),
            'The powershell tool is only available on Windows.',
        );

        if (PHP_OS_FAMILY !== 'Windows') {
            $tool = new PowerShellTool($this->cwd);
            $this->assertThrows(
                AgentError::class,
                static fn () => Async::run(static fn (): AgentToolResult => $tool->execute('call-1', ['command' => 'dir'])),
                'only available on Windows',
            );
        }
    }

    public function testPowerShellSevenIsPreferredThenWindowsPowerShell(): void
    {
        $env = ['Path' => 'C:\\pwsh;C:\\Windows\\System32\\WindowsPowerShell\\v1.0'];

        $both = Shell::powerShellConfig('Windows', $env, static fn (string $p): bool => in_array($p, ['C:\\pwsh\\pwsh.exe', 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'], true));
        $this->assertSame('C:\\pwsh\\pwsh.exe', $both->shell);
        $this->assertSame(Shell::POWERSHELL_ARGS, $both->args);

        $legacy = Shell::powerShellConfig('Windows', $env, static fn (string $p): bool => $p === 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe');
        $this->assertSame('C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', $legacy->shell);

        $this->assertThrows(
            AgentError::class,
            static fn () => Shell::powerShellConfig('Windows', $env, static fn (string $p): bool => false),
            'No PowerShell executable found',
        );
    }

    // ---- bash resolution ------------------------------------------------------------------------

    public function testOnUnixBinBashThenBashOnPathThenSh(): void
    {
        $env = ['PATH' => '/opt/a:/opt/b'];

        $this->assertSame('/bin/bash', Shell::resolve(null, 'Darwin', $env, static fn (string $p): bool => true)->shell);
        $this->assertSame('/opt/b/bash', Shell::resolve(null, 'Linux', $env, static fn (string $p): bool => $p === '/opt/b/bash')->shell);

        $sh = Shell::resolve(null, 'Linux', $env, static fn (string $p): bool => false);
        $this->assertSame('sh', $sh->shell);
        $this->assertSame(['-c'], $sh->args);
    }

    public function testShellPathWinsAndIsRefusedByNameWhenItIsNotThere(): void
    {
        $this->assertSame('/my/bash', Shell::resolve('/my/bash', 'Linux', [], static fn (string $p): bool => $p === '/my/bash')->shell);

        $this->assertThrows(
            AgentError::class,
            static fn () => Shell::resolve('/my/bash', 'Linux', [], static fn (string $p): bool => $p === '/bin/bash'),
            'Custom shell path not found: /my/bash',
        );
    }

    public function testOnWindowsGitBashThenBashExeOnPath(): void
    {
        $env = ['ProgramFiles' => 'C:\\Program Files', 'ProgramFiles(x86)' => 'C:\\Program Files (x86)', 'PATH' => 'C:\\msys64\\usr\\bin'];

        $x86 = Shell::resolve(null, 'Windows', $env, static fn (string $p): bool => $p === 'C:\\Program Files (x86)\\Git\\bin\\bash.exe');
        $this->assertSame('C:\\Program Files (x86)\\Git\\bin\\bash.exe', $x86->shell);

        $msys = Shell::resolve(null, 'Windows', $env, static fn (string $p): bool => $p === 'C:\\msys64\\usr\\bin\\bash.exe');
        $this->assertSame('C:\\msys64\\usr\\bin\\bash.exe', $msys->shell);

        $error = $this->assertThrows(AgentError::class, static fn () => Shell::resolve(null, 'Windows', $env, static fn (string $p): bool => false), 'No bash shell found');
        $this->assertStringContainsString('  C:\\Program Files\\Git\\bin\\bash.exe', $error->getMessage());
    }

    public function testTheLegacyWslBashTakesItsCommandOnStdin(): void
    {
        $config = Shell::resolve(null, 'Windows', ['PATH' => 'C:\\Windows\\System32'], static fn (string $p): bool => $p === 'C:\\Windows\\System32\\bash.exe');

        $this->assertSame(['-s'], $config->args);
        $this->assertSame('stdin', $config->commandTransport);
        $this->assertSame([$config->shell, '-s'], $config->argv('echo hi'));
    }

    public function testTheLegacyWslLauncherIsFoundWhereverWindowsIsInstalled(): void
    {
        // Upstream #9501: `%SystemRoot%` names the Windows directory; `C:\Windows` is the guess only
        // when it is unknown.
        $env = ['PATH' => 'D:\WinNT\System32', 'SystemRoot' => 'D:\WinNT'];
        $elsewhere = Shell::resolve(null, 'Windows', $env, static fn (string $p): bool => $p === 'D:\WinNT\System32\bash.exe');
        $this->assertSame('stdin', $elsewhere->commandTransport);

        // With `SystemRoot` known, `C:\Windows\System32\bash.exe` is some other bash.
        $other = Shell::resolve(null, 'Windows', ['PATH' => 'C:\Windows\System32', 'SystemRoot' => 'D:\WinNT'], static fn (string $p): bool => $p === 'C:\Windows\System32\bash.exe');
        $this->assertSame(['-c'], $other->args);
    }

    public function testPowerShellOutsidePathIsFoundInItsInstallDirectories(): void
    {
        // Upstream #9501: "Installation directories may be on any drive and need not be on PATH."
        $env = ['Path' => 'C:\nothing', 'ProgramFiles' => 'E:\Apps', 'SystemRoot' => 'D:\WinNT'];

        $seven = Shell::powerShellConfig('Windows', $env, static fn (string $p): bool => in_array($p, ['E:\Apps\PowerShell\7\pwsh.exe', 'D:\WinNT\System32\WindowsPowerShell\v1.0\powershell.exe'], true));
        $this->assertSame('E:\Apps\PowerShell\7\pwsh.exe', $seven->shell);

        $windows = Shell::powerShellConfig('Windows', $env, static fn (string $p): bool => $p === 'D:\WinNT\System32\WindowsPowerShell\v1.0\powershell.exe');
        $this->assertSame('D:\WinNT\System32\WindowsPowerShell\v1.0\powershell.exe', $windows->shell);
    }

    public function testAStdinShellIsHandedTheCommandOnItsInput(): void
    {
        $tool = new PowerShellTool($this->cwd, shell: new ShellConfig('/bin/sh', ['-s'], 'stdin'));

        // The UTF-8 prefix is PowerShell, which sh reports and carries on past.
        $result = $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn (): AgentToolResult => $tool->execute('call-1', ['command' => 'echo from-stdin; exit 4'])),
            'from-stdin',
        );

        $this->assertSame(4, $result->structuredContent['exit_code']);
    }
}
