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
        foreach (glob($this->home . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->home);
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

        return new WebDaemon('/usr/local/bin/pig', $this->home, function (array $argv) use (&$seen): never {
            $seen = $argv;

            throw new \RuntimeException('exec');
        });
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
}
