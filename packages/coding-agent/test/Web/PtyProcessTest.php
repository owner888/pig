<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Web\Pty\PtyProcess;

/**
 * The web terminal's pty, end to end on a real shell: what vim needs from it. Each case is a
 * way `vim` in the web terminal stopped working — a first screen that never arrived, a frame cut
 * inside a 你, Ctrl+C that did nothing, a terminal that never said it had exited.
 */
final class PtyProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        Loop::reset();
        parent::tearDown();
    }

    public function testCtrlCReachesTheForegroundJobBecauseThePtyIsTheShellsControllingTerminal(): void
    {
        // Without a session of its own the shell has no controlling terminal: ^C is a plain byte,
        // `sleep` never hears of it and the terminal sits there for the full 30 seconds.
        [$output, $exit, $elapsed] = $this->runInPty('sleep 30', ["\x03"], timeout: 8.0);

        $this->assertLessThan(5.0, $elapsed, "^C did not stop the job. Output:\n{$output}");
        $this->assertContains($exit, [2, 130]);
    }

    public function testTheShellsTerminalHasAForegroundGroup(): void
    {
        $flag = PHP_OS_FAMILY === 'Darwin' ? 'pgid=' : 'sid=';
        [$output] = $this->runInPty("ps -o {$flag} -o tpgid= -p $$", [], timeout: 5.0);

        [$id, $tpgid] = array_map(intval(...), preg_split('/\s+/', trim($output)) ?: []);
        $this->assertGreaterThan(0, $id);
        $this->assertGreaterThan(0, $tpgid, "The shell's terminal has no foreground group: {$output}");
    }

    public function testTheSizeIsOnThePtyBeforeTheShellStarts(): void
    {
        [$output] = $this->runInPty('stty size', [], cols: 117, rows: 33, timeout: 5.0);

        $this->assertStringContainsString('33 117', $output);
    }

    public function testACharacterSplitBetweenTwoReadsArrivesWhole(): void
    {
        $chunks = [];
        [$output] = $this->runInPty("printf '\\344\\275'; sleep 0.3; printf '\\240\\n'", [], timeout: 5.0, chunks: $chunks);

        $this->assertStringContainsString('你', $output);
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'A chunk that is not UTF-8 cannot go out as JSON: ' . bin2hex($chunk));
        }
    }

    public function testBytesThatAreNoCharacterBecomeReplacementCharacters(): void
    {
        // What vim writes while it probes the terminal: one lone byte would make json_encode()
        // refuse the whole frame, and vim's first screen with it.
        [$output] = $this->runInPty("printf 'a\\275b\\n'", [], timeout: 5.0);

        $this->assertStringContainsString("a\u{FFFD}b", $output);
        $this->assertNotFalse(json_encode(['data' => $output]));
    }

    public function testTheExitIsReportedWhenTheShellIsGone(): void
    {
        // Linux answers a read of the master with EIO once the slave side is gone; a reader that
        // only knew EOF spun on it forever and never said the terminal had exited.
        [, $exit, $elapsed] = $this->runInPty('exit 3', [], timeout: 5.0);

        $this->assertSame(3, $exit);
        $this->assertLessThan(4.0, $elapsed);
    }

    /**
     * @param list<string> $keys typed half a second apart, starting half a second in
     * @param list<string> $chunks every `$onOutput` call, in order
     * @return array{0: string, 1: ?int, 2: float}
     */
    private function runInPty(string $command, array $keys, float $timeout, int $cols = 80, int $rows = 24, array &$chunks = []): array
    {
        $output = '';
        $exit = null;
        $exited = false;
        $started = microtime(true);
        $elapsed = 0.0;

        $process = new PtyProcess(
            'test',
            sys_get_temp_dir(),
            $cols,
            $rows,
            $command,
            onOutput: function (string $id, string $data) use (&$output, &$chunks): void {
                $output .= $data;
                $chunks[] = $data;
            },
            onExit: function (string $id, ?int $code) use (&$exit, &$exited, &$elapsed, $started): void {
                $exit = $code;
                $exited = true;
                $elapsed = microtime(true) - $started;
                Loop::get()->stop();
            },
        );

        foreach ($keys as $i => $key) {
            Loop::get()->delay(0.5 * ($i + 1), static fn () => $process->input($key));
        }
        $deadline = Loop::get()->delay($timeout, static fn () => Loop::get()->stop());
        Loop::get()->run();
        Loop::get()->cancel($deadline);

        if (!$exited) {
            $process->kill();
            $elapsed = microtime(true) - $started;
        }

        return [$output, $exit, $elapsed];
    }
}
