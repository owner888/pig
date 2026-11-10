<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Accounts\User;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Sandbox\Shells;
use Pig\CodingAgent\Sandbox\SshSandbox;
use Pig\CodingAgent\Sandbox\SshTarget;
use Pig\CodingAgent\Tools\CommandStopped;
use Pig\CodingAgent\Tools\RemoteOperations;
use Pig\CodingAgent\Tools\ToolSet;

/**
 * An SSH account's tools (docs/dev/design/storage.md, phase 4 step 6), against a stand-in for
 * `ssh` (`fakeSsh()`): a script that drops `ssh`'s options and the host and runs the remote line
 * with bash on this machine, in an environment of its own as the remote login shell would, and
 * writes down every command it was given. A host named `unreachable` is refused, as a machine that is down is.
 */
final class SshOperationsTest extends TestCase
{
    private string $dir;

    private string $ssh;

    #[\Override]
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('the stand-in for ssh is a bash script');
        }

        $this->dir = sys_get_temp_dir() . '/pig-ssh-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/home', 0o777, true);
        mkdir($this->dir . '/there/app', 0o777, true);
        $this->ssh = self::fakeSsh($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ToolSet::useOperations(null);
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** The stand-in for `ssh`, written into `$dir`, logging to `$dir/ssh.log`. */
    public static function fakeSsh(string $dir): string
    {
        $ssh = $dir . '/ssh';
        file_put_contents($ssh, <<<'SH'
            #!/bin/bash
            printf '%s\n' "$*" >> "$(dirname "$0")/ssh.log"
            nostdin=
            while [ $# -gt 0 ]; do
              case "$1" in
                -o|-p|-i|-l) shift 2;;
                -n) nostdin=1; shift;;
                -t|-T) shift;;
                *) break;;
              esac
            done
            host=$1; shift
            if [ "$host" = unreachable ]; then echo "ssh: connect to host unreachable port 22: Connection refused" >&2; exit 255; fi
            [ -n "$nostdin" ] && exec < /dev/null
            # A login there has that machine's environment, not this process's.
            exec env -i HOME="$HOME" PATH="$PATH" bash -c "$1"
            SH);
        chmod($ssh, 0o755);

        return $ssh;
    }

    private function target(string $host = 'box'): SshTarget
    {
        return new SshTarget($host, 2222, 'bob', '', "{$this->dir}/home", $this->ssh);
    }

    /** @return list<string> what ssh was asked, one line each */
    private function asked(): array
    {
        return is_file("{$this->dir}/ssh.log") ? explode("\n", trim((string) file_get_contents("{$this->dir}/ssh.log"))) : [];
    }

    private static function bob(array $ssh = []): User
    {
        return new User('0123456789abcdef', 'bob', false, User::SSH, [], $ssh === [] ? null : User::ssh($ssh));
    }

    public function testTheCommandIsOneQuotedLineAfterSshsOwnOptions(): void
    {
        $argv = $this->target()->exec(['printf', '%s', "it's"], '/srv/my app', ['PIG_X' => 'a b']);

        $this->assertSame($this->ssh, $argv[0]);
        $this->assertSame(["cd -- '/srv/my app' && exec env 'PIG_X=a b' 'printf' '%s' 'it'\\''s'", 'box', '-n'], [end($argv), $argv[count($argv) - 2], $argv[count($argv) - 3]]);
        foreach (['BatchMode=yes', 'StrictHostKeyChecking=accept-new', "UserKnownHostsFile={$this->dir}/home/known_hosts", 'ControlMaster=auto', "ControlPath={$this->dir}/home/ssh/%C"] as $option) {
            $this->assertContains($option, $argv);
        }
        $this->assertSame(['-p', '2222', '-l', 'bob'], array_slice($argv, (int) array_search('-p', $argv, true), 4));
        $this->assertNotContains('-i', $argv, 'the server user\'s own key when none is given');
        $this->assertContains('-t', $this->target()->exec(['bash', '-l'], tty: true));
        $this->assertNotContains('-n', $this->target()->exec(['cat'], stdin: true));

        $this->assertSame([0, "it's", ''], $this->target()->run(['printf', '%s', "it's"]));
        $this->assertSame("hello\n", $this->target()->run(['cat'], "hello\n")[1], 'standard input goes through');
    }

    public function testAHostOrUserSshWouldReadAsAnOptionIsRefused(): void
    {
        foreach ([['-oProxyCommand=x', 22, 'bob'], ['box', 0, 'bob'], ['box', 22, '-l'], ['box', 22, 'a b']] as [$host, $port, $user]) {
            try {
                new SshTarget($host, $port, $user, '', $this->dir);
                $this->fail("{$host} {$port} {$user}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAChildFindsItsTargetInItsEnvironment(): void
    {
        $target = new SshTarget('box', 22, 'bob', '/keys/bob', "{$this->dir}/home", $this->ssh);
        $back = Shells::fromEnvironment($target->environment());

        $this->assertEquals($target, $back);
        $this->assertNull(Shells::fromEnvironment([]));
    }

    public function testTheToolsWorkThere(): void
    {
        $ops = new RemoteOperations($this->target());
        $file = "{$this->dir}/there/app/it's here.txt";

        $ops->writeFile($file, "one\n");
        $this->assertSame("one\n", $ops->readFile($file));
        $this->assertTrue($ops->isDirectory("{$this->dir}/there/app"));
        $this->assertSame(["it's here.txt"], $ops->readdir("{$this->dir}/there/app"));

        foreach ($this->asked() as $line) {
            $this->assertStringContainsString(' box ', $line, 'everything went through ssh');
        }
    }

    public function testBashRunsThereAndStoppingItStopsWhatItStarted(): void
    {
        if (trim((string) shell_exec('command -v setsid')) === '') {
            self::markTestSkipped('needs util-linux setsid');
        }

        $ops = new RemoteOperations($this->target());
        $out = '';
        $exit = Async::run(function () use ($ops, &$out): ?int {
            return $ops->exec('echo "[$PIG_X][$PIG_RUN_FILE]"; pwd', "{$this->dir}/there/app", static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            }, env: ['PIG_X' => 'pig']);
        });

        $this->assertSame(0, $exit);
        $this->assertSame("[pig][]\n{$this->dir}/there/app\n", $out, 'where the group is written is not the command\'s');

        $marker = 'pig-ssh-' . bin2hex(random_bytes(4));
        $abort = new AbortController();
        try {
            Async::run(static function () use ($ops, $abort, $marker): void {
                Loop::get()->delay(0.5, static fn () => $abort->abort());
                $ops->exec("sleep 30 & exec -a {$marker} sleep 30", '/', static function (): void {
                }, $abort->signal);
            });
            $this->fail('stopped');
        } catch (CommandStopped) {
            usleep(300_000);
            $this->assertSame('', trim((string) shell_exec('pgrep -f ' . escapeshellarg('[p]' . substr($marker, 1)))));
        }
    }

    public function testTheContextFilesAreReadThere(): void
    {
        file_put_contents("{$this->dir}/there/AGENTS.md", 'outer');
        file_put_contents("{$this->dir}/there/app/CLAUDE.md", 'inner');
        ToolSet::useOperations(new RemoteOperations($this->target()));

        $files = ContextFiles::load("{$this->dir}/there/app", "{$this->dir}/home");

        $this->assertSame([["{$this->dir}/there/AGENTS.md", 'outer'], ["{$this->dir}/there/app/CLAUDE.md", 'inner']], array_map(static fn ($f): array => [$f->path, $f->content], $files));
        $this->assertNotEmpty(array_filter($this->asked(), static fn (string $line): bool => str_contains($line, 'AGENTS.override.md')));
    }

    public function testTheGitOfAFolderThereRunsThere(): void
    {
        ToolSet::useOperations(new RemoteOperations($this->target()));

        [$exit, $out] = ToolSet::run(['pwd'], "{$this->dir}/there/app");

        $this->assertSame([0, "{$this->dir}/there/app\n"], [$exit, $out]);
        $this->assertCount(1, $this->asked());
    }

    public function testTheSandboxChecksAConnectionMakesTheWorkspaceAndListsThere(): void
    {
        $sandbox = new SshSandbox("{$this->dir}/home", $this->ssh);
        $bob = self::bob(['host' => 'box', 'port' => '2222', 'user' => 'bob', 'workspace' => "{$this->dir}/there/work/"]);

        $this->assertSame(['host' => 'box', 'port' => 2222, 'user' => 'bob', 'key' => '', 'workspace' => "{$this->dir}/there/work"], $bob->ssh);
        Async::run(static fn () => $sandbox->ensure($bob));
        $this->assertDirectoryExists("{$this->dir}/there/work", 'made there');
        $this->assertTrue($sandbox->contains($bob, '/etc'), 'that user\'s permissions are the boundary');
        $this->assertFalse($sandbox->contains($bob, 'etc'));
        $here = $sandbox->here($bob, '/anywhere');
        $this->assertSame("{$this->dir}/home/ssh/here/bob-01234567", $here);
        $this->assertDirectoryExists($here);

        mkdir("{$this->dir}/there/work/b");
        mkdir("{$this->dir}/there/work/A");
        mkdir("{$this->dir}/there/work/.hidden");
        touch("{$this->dir}/there/work/file");
        $listing = Async::run(static fn (): array => $sandbox->dirs($bob, '', false));
        $this->assertSame(['ok' => true, 'path' => (string) realpath("{$this->dir}/there/work"), 'parent' => dirname((string) realpath("{$this->dir}/there/work")), 'home' => "{$this->dir}/there/work", 'dirs' => ['A', 'b'], 'truncated' => false], $listing);
        $this->assertSame(['.hidden', 'A', 'b'], Async::run(static fn (): array => $sandbox->dirs($bob, '', true))['dirs'] ?? null);
        $this->assertFalse(Async::run(static fn (): array => $sandbox->dirs($bob, 'nothing', false))['ok']);

        $down = self::bob(['host' => 'unreachable', 'user' => 'bob', 'workspace' => '/w']);
        try {
            Async::run(static fn () => $sandbox->ensure($down));
            $this->fail('unreachable');
        } catch (AgentError $error) {
            $this->assertStringContainsString('Connection refused', $error->getMessage());
            $this->assertStringContainsString('bob@unreachable:22', $error->getMessage());
        }

        $this->expectException(AgentError::class);
        $sandbox->shell(new User('1', 'carol', false, User::SSH));
    }

    public function testWhatAnAccountIsGivenIsChecked(): void
    {
        $sandbox = new SshSandbox("{$this->dir}/home", $this->ssh);
        file_put_contents("{$this->dir}/key", 'k');
        $sandbox->checkConfig(['host' => '10.0.0.5', 'port' => 22, 'user' => 'bob', 'key' => "{$this->dir}/key", 'workspace' => '/home/bob']);

        foreach ([
            ['key' => "{$this->dir}/nokey"],
            ['key' => 'key'],
            ['workspace' => 'work'],
            ['host' => '-x'],
            ['port' => 70000],
        ] as $wrong) {
            try {
                $sandbox->checkConfig([...['host' => 'box', 'port' => 22, 'user' => 'bob', 'key' => '', 'workspace' => '/w'], ...$wrong]);
                $this->fail(json_encode($wrong));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
