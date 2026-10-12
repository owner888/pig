<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\WebDaemon;

final class WebDaemonTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-webdaemon-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->home . '/LaunchAgents/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->home . '/LaunchAgents')) {
            rmdir($this->home . '/LaunchAgents');
        }
        foreach (glob($this->home . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->home)) {
            rmdir($this->home);
        }
    }

    /**
     * A daemon whose `exec` records the argv instead of replacing the process, and throws so
     * the `never` return type is honoured without exiting the test runner.
     *
     * @param list<string>|null $seen
     */
    private function daemon(?array &$seen = null): WebDaemon
    {
        $seen = null;

        // The LaunchAgents directory is this test's own, so a service installed on the machine
        // running the suite is never seen — and never booted out by the `stop` test — and
        // launchctl is a stand-in that fails the test if anything reaches it.
        return new WebDaemon('/usr/local/bin/pig', $this->home, function (array $argv) use (&$seen): never {
            $seen = $argv;

            throw new \RuntimeException('exec');
        }, static fn (array $args): never => throw new \LogicException('launchctl ' . implode(' ', $args)), $this->home . '/LaunchAgents');
    }

    /**
     * A daemon on macOS whose launchctl is a stand-in keeping launchd's state: whether the job
     * is loaded, its pid, and every command it was asked.
     */
    private function service(?Launchd &$launchd = null, string $platform = 'Darwin'): WebDaemon
    {
        $launchd = new Launchd();

        return new WebDaemon('/usr/local/bin/pig', $this->home, static function (array $argv): never {
            throw new \RuntimeException('exec');
        }, $launchd, $this->home . '/LaunchAgents', $platform);
    }

    private function target(): string
    {
        return 'gui/' . posix_getuid() . '/' . WebDaemon::LAUNCHD_LABEL;
    }

    private function invoke(WebDaemon $daemon, array $argv): array
    {
        ob_start();
        try {
            $status = $daemon->run($argv);
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'exec' ? -1 : throw $e;
        }

        return [$status, (string) ob_get_clean()];
    }

    // ---- start in the foreground is an exec of --mode web ----------------------------------

    public function testStartInTheForegroundBecomesPigModeWebWithTheDefaults(): void
    {
        $daemon = $this->daemon($seen);
        [$status] = $this->invoke($daemon, ['start']);

        $this->assertSame(-1, $status, 'exec was reached');
        $this->assertSame(['/usr/local/bin/pig', '--mode', 'web', '--cwd', getcwd(), '--web-host', '127.0.0.1', '--web-port', '8088'], $seen);
        $this->assertFileDoesNotExist($daemon->pidFile(), 'a foreground server has a terminal, not a pid file');
    }

    public function testHostAndPortReachTheExecInBothSpellings(): void
    {
        $daemon = $this->daemon($seen);
        $this->invoke($daemon, ['start', '--port', '9000', '--host', '0.0.0.0']);
        $this->assertSame(['--web-host', '0.0.0.0', '--web-port', '9000'], array_slice($seen, 5));

        $daemon = $this->daemon($seen);
        $this->invoke($daemon, ['start', '--port=9001', '--host=::1']);
        $this->assertSame(['--web-host', '::1', '--web-port', '9001'], array_slice($seen, 5));
    }

    public function testAPortThatIsNotOneIsRefusedByName(): void
    {
        foreach (['abc', '0', '70000', ''] as $bad) {
            $daemon = $this->daemon($seen);
            [$status] = $this->invoke($daemon, ['start', '--port', $bad]);

            $this->assertSame(1, $status, "port '{$bad}'");
            $this->assertNull($seen, "nothing exec'd for port '{$bad}'");
        }
    }

    public function testAnUnknownCommandIsRefusedAndHelpIsOnStderr(): void
    {
        $daemon = $this->daemon($seen);
        [$status] = $this->invoke($daemon, ['dance']);

        $this->assertSame(1, $status);
        $this->assertNull($seen);
    }

    // ---- status / stop against a real process ----------------------------------------------

    /** A child that sleeps and ignores nothing, so SIGTERM ends it as a daemon's would. */
    private function sleepingChild(): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_exec('/bin/sleep', ['30']);
            exit(127);
        }
        $this->assertGreaterThan(0, $pid);

        return $pid;
    }

    public function testStatusReadsTheLivePidAndStopEndsIt(): void
    {
        $pid = $this->sleepingChild();
        $daemon = $this->daemon();
        file_put_contents($daemon->pidFile(), $pid . "\n");

        try {
            [$status, $out] = $this->invoke($daemon, ['status']);
            $this->assertSame(0, $status);
            $this->assertStringContainsString("pid {$pid}", $out);
            $this->assertSame($pid, $daemon->runningPid());

            [$status, $out] = $this->invoke($daemon, ['stop']);
            $this->assertSame(0, $status);
            $this->assertStringContainsString('stopped', $out);
            $this->assertFileDoesNotExist($daemon->pidFile());

            // Reap, then confirm it is gone.
            pcntl_waitpid($pid, $s, WNOHANG);
            usleep(50_000);
            $this->assertFalse(posix_kill($pid, 0) && posix_get_last_error() !== 3, 'the child is gone');
        } finally {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $s);
            }
        }
    }

    public function testStatusWhenNothingRunsExitsThreeLikeAnInitScript(): void
    {
        $daemon = $this->daemon();
        [$status, $out] = $this->invoke($daemon, ['status']);

        $this->assertSame(3, $status);
        $this->assertStringContainsString('not running', $out);
    }

    public function testAStalePidFileIsNotARunningServer(): void
    {
        $pid = $this->sleepingChild();
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $s);

        $daemon = $this->daemon();
        file_put_contents($daemon->pidFile(), $pid . "\n");

        $this->assertNull($daemon->runningPid(), 'a pid the kernel has freed is nobody');
        $this->assertFileDoesNotExist($daemon->pidFile(), 'and the stale file is cleared on the way past');

        // So `start` is not refused by a ghost.
        $daemon = $this->daemon($seen);
        file_put_contents($daemon->pidFile(), $pid . "\n");
        [$status] = $this->invoke($daemon, ['start']);
        $this->assertSame(-1, $status, 'start went ahead');
    }

    public function testASecondStartIsRefusedWhileTheFirstRuns(): void
    {
        $pid = $this->sleepingChild();
        $daemon = $this->daemon($seen);
        file_put_contents($daemon->pidFile(), $pid . "\n");

        try {
            [$status] = $this->invoke($daemon, ['start', '-d']);
            $this->assertSame(1, $status);
            $this->assertNull($seen, 'nothing was exec\'d — two daemons on adjacent ports is the bug this prevents');
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $s);
        }
    }

    public function testStopWhenNothingRunsIsNotAnError(): void
    {
        [$status, $out] = $this->invoke($this->daemon(), ['stop']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('not running', $out);
    }

    // ---- the real daemon, end to end -------------------------------------------------------

    /**
     * `start -d` with a real double-fork, exec'ing a stand-in that sleeps: the pid file names a
     * live process, `status` sees it, `stop` ends it. The stand-in is `/bin/sleep` rather than
     * `bin/pig` because the daemon's job here is the fork/setsid/pidfile recipe, and a web
     * server binding a port is `WebModeTest`'s.
     */
    public function testStartDaemonForksASessionLeaderThatSurvivesTheParent(): void
    {
        if (!function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix');
        }

        // The exec stand-in cannot be a closure here — it runs in the grandchild, and a closure
        // that throws there would kill the grandchild, not the test. So: a real exec of sleep.
        $daemon = new WebDaemon('/bin/sleep', $this->home, static function (array $argv): never {
            pcntl_exec('/bin/sleep', ['30']);
            exit(127);
        });

        [$status, $out] = $this->invoke($daemon, ['start', '-d']);
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('daemon started', $out);

        $pid = $daemon->runningPid();
        $this->assertNotNull($pid);
        $this->assertNotSame(getmypid(), $pid);
        $this->assertNotSame(posix_getsid(getmypid()), posix_getsid($pid), 'the daemon is in its own session, not ours');
        // The *middle* child was the session leader; the second fork is what makes the daemon
        // not one, so it can never reacquire a controlling terminal. The first draft asserted
        // the opposite and was wrong about the recipe it had just written.
        $this->assertNotSame($pid, posix_getsid($pid), 'and is not its leader');
        $this->assertSame(1, posix_getppid() === 1 ? 1 : (int) trim((string) shell_exec("ps -o ppid= -p {$pid}")), 'reparented to init, the middle child having exited');

        [$status] = $this->invoke($daemon, ['stop']);
        $this->assertSame(0, $status);
        usleep(100_000);
        $this->assertNull($daemon->runningPid());
    }

    // ---- the macOS service ----------------------------------------------------------------

    public function testInstallIsRefusedOffMacosAndTouchesNothing(): void
    {
        $daemon = $this->service($launchd, 'Linux');
        [$status] = $this->invoke($daemon, ['install']);

        $this->assertSame(1, $status);
        $this->assertFileDoesNotExist($daemon->plistFile());
        $this->assertSame([], $launchd->calls);
    }

    public function testInstallWritesALaunchAgentThatRunsTheServerAndLoadsIt(): void
    {
        $daemon = $this->service($launchd);
        [$status, $out] = $this->invoke($daemon, ['install', '--port', '9000', '--host', '0.0.0.0']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->home . '/LaunchAgents/dev.pigagent.web.plist', $daemon->plistFile());
        $this->assertSame([['print', $this->target()], ['bootstrap', 'gui/' . posix_getuid(), $daemon->plistFile()]], $launchd->calls);

        $plist = self::plist($daemon->plistFile());
        $this->assertSame(WebDaemon::LAUNCHD_LABEL, $plist['Label']);
        // php itself, then `pig --mode web`: launchd supervises a foreground server, and a
        // double-forked daemon would look to it like a job that exited at once.
        $this->assertSame(
            [PHP_BINARY, '/usr/local/bin/pig', '--mode', 'web', '--cwd', getcwd(), '--web-host', '0.0.0.0', '--web-port', '9000', '--no-browser'],
            $plist['ProgramArguments'],
            'and no browser tab at every login and every restart KeepAlive makes',
        );
        $this->assertSame(getcwd(), $plist['WorkingDirectory']);
        $this->assertTrue($plist['RunAtLoad']);
        $this->assertTrue($plist['KeepAlive']);
        $this->assertSame($daemon->logFile(), $plist['StandardOutPath']);
        $this->assertSame($daemon->logFile(), $plist['StandardErrorPath']);
        $this->assertSame($this->home, $plist['EnvironmentVariables']['PIG_CODING_AGENT_DIR'], 'the service uses the agent directory this command did');
        $this->assertSame(getenv('PATH'), $plist['EnvironmentVariables']['PATH'], "launchd's own PATH has no fd, rg or git in it");
    }

    public function testTheServiceGetsThisShellsProxyAndNotItsKeys(): void
    {
        $saved = ['HTTPS_PROXY' => getenv('HTTPS_PROXY'), 'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY')];
        putenv('HTTPS_PROXY=http://127.0.0.1:7890');
        putenv('ANTHROPIC_API_KEY=sk-ant-secret');

        try {
            $daemon = $this->service();
            $this->invoke($daemon, ['install']);
            $plist = self::plist($daemon->plistFile());
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }

        $this->assertSame('http://127.0.0.1:7890', $plist['EnvironmentVariables']['HTTPS_PROXY']);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $plist['EnvironmentVariables']);
        $this->assertStringNotContainsString('sk-ant-secret', (string) file_get_contents($daemon->plistFile()), 'a plist is world-readable');
    }

    public function testAPathThatNeedsEscapingIsStillOneArgument(): void
    {
        $daemon = new WebDaemon('/Users/dev/a & <b>/pig', $this->home, null, new Launchd(), $this->home . '/LaunchAgents', 'Darwin');
        $this->invoke($daemon, ['install']);

        $this->assertSame('/Users/dev/a & <b>/pig', self::plist($daemon->plistFile())['ProgramArguments'][1]);
    }

    public function testInstallingAgainTakesTheOldJobDownBeforeRewritingIt(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);
        $launchd->calls = [];

        [$status] = $this->invoke($daemon, ['install', '--port', '9100']);

        $this->assertSame(0, $status);
        $this->assertSame([
            ['print', $this->target()],
            ['bootout', $this->target()],
            ['bootstrap', 'gui/' . posix_getuid(), $daemon->plistFile()],
        ], $launchd->calls);
        $this->assertContains('9100', self::plist($daemon->plistFile())['ProgramArguments']);
    }

    public function testAPlistLaunchdRefusedIsTakenBack(): void
    {
        $daemon = $this->service($launchd);
        $launchd->refuseBootstrap = true;
        [$status] = $this->invoke($daemon, ['install']);

        $this->assertSame(1, $status);
        $this->assertFileDoesNotExist($daemon->plistFile(), 'it would be loaded and refused again at every login');
    }

    public function testInstallIsRefusedWhileAPidFileDaemonHoldsThePort(): void
    {
        $pid = $this->sleepingChild();
        $daemon = $this->service($launchd);
        file_put_contents($daemon->pidFile(), $pid . "\n");

        try {
            [$status] = $this->invoke($daemon, ['install']);
            $this->assertSame(1, $status);
            $this->assertFileDoesNotExist($daemon->plistFile());
            $this->assertSame([], $launchd->calls);
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $s);
        }
    }

    public function testWhileInstalledStatusAsksLaunchd(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);

        [$status, $out] = $this->invoke($daemon, ['status']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('pid 4242', $out);
        $this->assertStringContainsString('launchd', $out);

        $launchd->pid = null;
        [$status, $out] = $this->invoke($daemon, ['status']);
        $this->assertSame(3, $status, 'loaded and not running is not running');
        $this->assertStringContainsString('not running', $out);

        $launchd->loaded = false;
        [$status, $out] = $this->invoke($daemon, ['status']);
        $this->assertSame(3, $status);
        $this->assertStringContainsString('installed as a service', $out);
    }

    public function testWhileInstalledStopUnloadsTheJobAndKeepsThePlist(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);
        $launchd->calls = [];

        [$status, $out] = $this->invoke($daemon, ['stop']);

        $this->assertSame(0, $status);
        // `/web stop` reads this word to know the daemon went.
        $this->assertStringContainsString('stopped', $out);
        $this->assertSame([['print', $this->target()], ['bootout', $this->target()]], $launchd->calls);
        $this->assertFalse($launchd->loaded, 'a SIGTERM would have been a crash KeepAlive undoes');
        $this->assertFileExists($daemon->plistFile(), 'stopped is not uninstalled: it comes back at the next login');

        [$status, $out] = $this->invoke($daemon, ['stop']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('not running', $out);
    }

    public function testWhileInstalledStartLoadsOrKicksTheJobAndRefusesASecondCopy(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);

        [$status] = $this->invoke($daemon, ['start', '-d']);
        $this->assertSame(1, $status, 'already running');

        $launchd->loaded = false;
        $launchd->calls = [];
        [$status] = $this->invoke($daemon, ['start']);
        $this->assertSame(0, $status);
        $this->assertSame(['bootstrap', 'gui/' . posix_getuid(), $daemon->plistFile()], end($launchd->calls), 'not loaded: load it');

        $launchd->pid = null;
        $launchd->calls = [];
        [$status] = $this->invoke($daemon, ['start']);
        $this->assertSame(0, $status);
        $this->assertSame(['kickstart', $this->target()], end($launchd->calls), 'loaded and not running: start it');
    }

    public function testWhileInstalledRestartKillsAndStartsTheJob(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);
        $launchd->calls = [];

        [$status] = $this->invoke($daemon, ['restart']);

        $this->assertSame(0, $status);
        $this->assertSame(['kickstart', '-k', $this->target()], end($launchd->calls));
    }

    public function testWhileInstalledAPortForOneStartIsRefusedRatherThanIgnored(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);

        foreach ([['start', '--port', '9000'], ['restart', '--host=0.0.0.0'], ['start', '-p', '1']] as $argv) {
            $launchd->calls = [];
            [$status] = $this->invoke($daemon, $argv);
            $this->assertSame(1, $status, implode(' ', $argv));
            $this->assertSame([], $launchd->calls, implode(' ', $argv));
        }
    }

    public function testUninstallUnloadsAndRemovesThePlist(): void
    {
        $daemon = $this->service($launchd);
        $this->invoke($daemon, ['install']);
        $launchd->calls = [];

        [$status] = $this->invoke($daemon, ['uninstall']);

        $this->assertSame(0, $status);
        $this->assertSame([['print', $this->target()], ['bootout', $this->target()]], $launchd->calls);
        $this->assertFileDoesNotExist($daemon->plistFile());
        $this->assertFalse($daemon->isInstalled());

        [$status, $out] = $this->invoke($daemon, ['uninstall']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('not installed', $out);
    }

    public function testAPlistOffMacosDoesNotTurnStopIntoLaunchctl(): void
    {
        $daemon = $this->service($launchd, 'Linux');
        mkdir($this->home . '/LaunchAgents');
        file_put_contents($daemon->plistFile(), '<plist/>');

        [$status, $out] = $this->invoke($daemon, ['stop']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('not running', $out);
        $this->assertSame([], $launchd->calls, 'nothing but macOS reads that file');
    }

    /** @return array<string, mixed> the plist's top-level dict, read as a plist reader reads it */
    private static function plist(string $file): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load($file), 'the plist is well-formed XML');

        $read = static function (\DOMElement $node) use (&$read): mixed {
            $children = array_values(array_filter(iterator_to_array($node->childNodes), static fn ($n) => $n instanceof \DOMElement));

            return match ($node->tagName) {
                'string' => $node->textContent,
                'true' => true,
                'false' => false,
                'array' => array_map($read, $children),
                'dict' => (static function () use ($children, $read): array {
                    $dict = [];
                    for ($i = 0; $i < count($children); $i += 2) {
                        $dict[$children[$i]->textContent] = $read($children[$i + 1]);
                    }

                    return $dict;
                })(),
            };
        };

        $root = $document->documentElement?->getElementsByTagName('dict')->item(0);
        self::assertInstanceOf(\DOMElement::class, $root);

        return $read($root);
    }
}

/** launchd, as far as `pig web` asks it anything. */
final class Launchd
{
    public bool $loaded = false;

    public ?int $pid = null;

    public bool $refuseBootstrap = false;

    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    public function __invoke(array $args): array
    {
        $this->calls[] = $args;

        return match ($args[0]) {
            'print' => $this->loaded
                ? [0, "gui/501/dev.pigagent.web = {\n\tstate = " . ($this->pid === null ? 'not running' : 'running') . "\n" . ($this->pid === null ? '' : "\tpid = {$this->pid}\n") . "}\n"]
                : [113, "Could not find service \"dev.pigagent.web\" in domain for user gui: 501\n"],
            'bootstrap' => $this->refuseBootstrap
                ? [5, "Bootstrap failed: 5: Input/output error\n"]
                : [0, (function (): string { $this->loaded = true; $this->pid = 4242; return ''; })()],
            'bootout' => [0, (function (): string { $this->loaded = false; $this->pid = null; return ''; })()],
            'kickstart' => [0, (function (): string { $this->pid = 4243; return ''; })()],
        };
    }
}
