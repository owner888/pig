<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\ProcessTerminal;

/**
 * The real terminal, in the one respect a fake one cannot stand in for.
 *
 * Everything else here is exercised through `FakeTerminal`, which accepts whatever it
 * is given. A real terminal does not: its buffer is a few kilobytes and it says so by
 * accepting part of a write and returning how much it took.
 */
final class ProcessTerminalTest extends TestCase
{
    private string $target;

    #[\Override]
    protected function setUp(): void
    {
        $this->target = sys_get_temp_dir() . '/pig-terminal-' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->target)) {
            unlink($this->target);
        }
    }

    public function testAWriteBiggerThanTheBufferStillArrivesWhole(): void
    {
        // A frame is tens of kilobytes and a pipe holds 64K, so `fwrite` returns short
        // and the rest is dropped unless someone writes it. What gets dropped is the
        // middle of an escape sequence, and from there every cursor move is against a
        // screen that holds something else — which is what it looks like on a terminal:
        // frames stacking up instead of replacing each other.
        $frame = str_repeat('x', 1_000_000);

        $this->assertSame($frame, $this->throughATerminal($frame));
    }

    public function testASmallWriteIsUnaffected(): void
    {
        $this->assertSame('hello', $this->throughATerminal('hello'));
    }

    public function testFlushDoesNotTimeoutWhileTerminalIsActivelyAcceptingOutput(): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Read in small chunks with a brief micro-delay so draining requires more than 100 write/select iterations.
        $reader = proc_open(
            ['php', '-r', '$out = fopen($argv[1], "wb"); while (!feof(STDIN)) { $c = fread(STDIN, 1024); if ($c !== "") { fwrite($out, $c); } usleep(50); } fclose($out);', $this->target],
            $descriptors,
            $pipes,
        );

        $this->assertTrue($reader !== false, 'could not start the reader');
        stream_set_blocking($pipes[0], false);

        $terminal = new ProcessTerminal(STDIN, $pipes[0]);
        $data = str_repeat('z', 200_000);
        $terminal->write($data);
        $terminal->flush();

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($reader);

        $this->assertSame($data, file_get_contents($this->target));
    }

    public function testAFrameTheTerminalCannotTakeAtOnceDoesNotHoldTheLoop(): void
    {
        \Pig\Async\Loop::reset();
        [$output, $screen] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($output, false);
        $terminal = new ProcessTerminal(STDIN, $output);
        $frame = str_repeat('x', 4_000_000);

        // Nobody is reading: a write that waited would wait here until it gave up.
        $started = microtime(true);
        $terminal->write($frame);
        $terminal->write('TAIL');
        $this->assertLessThan(0.5, microtime(true) - $started, 'the write waited for the terminal');
        $this->assertTrue($terminal->hasPendingOutput());

        $drained = false;
        $terminal->whenDrained(static function () use (&$drained): void {
            $drained = true;
        });

        // The terminal reads, on the same loop, and the loop writes the rest as it does — in order.
        $received = '';
        stream_set_blocking($screen, false);
        $loop = \Pig\Async\Loop::get();
        $reader = $loop->onReadable($screen, static function () use ($screen, &$received): void {
            $received .= (string) fread($screen, 1 << 20);
        });

        for ($turns = 0; !$drained && $turns < 100_000; $turns++) {
            $loop->tick();
        }

        $loop->cancel($reader);

        // What the terminal has been given and not yet read.
        while (($chunk = (string) fread($screen, 1 << 20)) !== '') {
            $received .= $chunk;
        }
        $this->assertTrue($drained, 'whenDrained() was not called');
        $this->assertFalse($terminal->hasPendingOutput());
        $this->assertSame(strlen($frame) + 4, strlen($received));
        $this->assertSame('TAIL', substr($received, -4));
        fclose($output);
        fclose($screen);
    }

    public function testWhenDrainedWithNothingWaitingIsCalledOnTheNextTurn(): void
    {
        \Pig\Async\Loop::reset();
        $terminal = new ProcessTerminal(STDIN, fopen('php://memory', 'w'));
        $called = false;

        $terminal->whenDrained(static function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called, 'not inside the caller');
        \Pig\Async\Loop::get()->tick();
        $this->assertTrue($called);
    }

    public function testNormalizeNativeInputPreservesNonReturnData(): void
    {
        $ref = new \ReflectionMethod(ProcessTerminal::class, 'normalizeNativeInput');
        $this->assertSame('abc', $ref->invoke(null, 'abc'));
        $this->assertSame("\n", $ref->invoke(null, "\n"));
    }

    // ---- OSC 7501, the support query's answers sifted out of the input (#10607) --------------

    public function testATerminalThatAnswersTheQueryGetsTheStatusAndTheRestIsInput(): void
    {
        [$terminal, $output] = $this->queryingTerminal();
        $terminal->setProgramStatus(new \Pig\Tui\ProgramStatus('working', 'pig'));
        $this->assertSame('', stream_get_contents($output, -1, 0), 'nothing is sent before support is confirmed');

        $this->assertSame('ab', $this->sift($terminal, "\x1b]7501;?\x1b\\\x1b[?62;22ca" . 'b'));
        $this->assertSame("\x1b]7501;state=working:app=pig\x1b\\", stream_get_contents($output, -1, 0));
        $this->assertSame("\x1b[?62;22c", $this->sift($terminal, "\x1b[?62;22c"), 'a later DA is somebody else\'s sentinel');
    }

    public function testATerminalThatAnswersOnlyDaNeverGetsAStatus(): void
    {
        [$terminal, $output] = $this->queryingTerminal();
        $this->assertSame('x', $this->sift($terminal, "\x1b[?1;2cx"));
        $terminal->setProgramStatus(new \Pig\Tui\ProgramStatus('working'));
        $this->assertSame('', stream_get_contents($output, -1, 0));
    }

    public function testAReplyCutAcrossTwoReadsIsStillOneReply(): void
    {
        [$terminal, $output] = $this->queryingTerminal();
        $terminal->setProgramStatus(new \Pig\Tui\ProgramStatus('idle'));
        $this->assertSame('', $this->sift($terminal, "\x1b]750"));
        $this->assertSame('', $this->sift($terminal, "1;?\x1b\\\x1b[?6"));
        $this->assertSame('q', $this->sift($terminal, '2cq'));
        $this->assertSame("\x1b]7501;state=idle\x1b\\", stream_get_contents($output, -1, 0));
    }

    public function testALoneEscapeIsAKeyNotTheStartOfAReply(): void
    {
        [$terminal] = $this->queryingTerminal();
        $this->assertSame("\x1b", $this->sift($terminal, "\x1b"));
        $this->assertSame("\x1b[A", $this->sift($terminal, "\x1b[A"));
    }

    public function testPigProgramStatusEnvironmentVariableForcesSupport(): void
    {
        // Upstream's `PI_PROGRAM_STATUS`, spelled pig's way and only that way.
        putenv('PIG_PROGRAM_STATUS=1');
        try {
            $output = fopen('php://memory', 'w+');
            $terminal = new ProcessTerminal(STDIN, $output);
            // Reflect private properties to verify override resolution
            $supported = new \ReflectionProperty(ProcessTerminal::class, 'programStatusSupported');
            $pending = new \ReflectionProperty(ProcessTerminal::class, 'programStatusQueryPending');
            // Mock start initialization logic
            $override = getenv('PIG_PROGRAM_STATUS');
            $supported->setValue($terminal, $override === '1');
            $pending->setValue($terminal, $override !== '1' && $override !== '0');

            $this->assertTrue($supported->getValue($terminal));
            $this->assertFalse($pending->getValue($terminal));
        } finally {
            putenv('PIG_PROGRAM_STATUS');
        }
    }

    public function testTheEscapeTimeoutIsTheSettingThenLongerOverSsh(): void
    {
        $this->assertSame(10.0, (float) ProcessTerminal::resolveEscapeTimeoutMs([]));
        $this->assertSame(100.0, (float) ProcessTerminal::resolveEscapeTimeoutMs(['SSH_TTY' => '/dev/pts/1']));
        $this->assertSame(100.0, (float) ProcessTerminal::resolveEscapeTimeoutMs(['SSH_CONNECTION' => '1 2 3 4']));
        $this->assertSame(250.0, ProcessTerminal::resolveEscapeTimeoutMs(['PIG_TUI_ESC_TIMEOUT' => '250', 'SSH_TTY' => 'x']));
        // `Number(…)` that is not finite and positive is not a setting.
        $this->assertSame(10.0, (float) ProcessTerminal::resolveEscapeTimeoutMs(['PIG_TUI_ESC_TIMEOUT' => '0']));
        $this->assertSame(10.0, (float) ProcessTerminal::resolveEscapeTimeoutMs(['PIG_TUI_ESC_TIMEOUT' => 'soon']));
    }

    public function testALoneEscapeWaitsForTheRestOfAnAltKey(): void
    {
        \Pig\Async\Loop::reset();
        putenv('PIG_TUI_ESC_TIMEOUT=40');

        try {
            $terminal = new ProcessTerminal(STDIN, fopen('php://memory', 'w+'));
            $hold = new \ReflectionMethod(ProcessTerminal::class, 'holdLoneEscape');
            $keys = [];
            $onInput = static function (string $data) use (&$keys): void {
                $keys[] = $data;
            };

            // ESC, then `x` inside the window: one key, Alt+x.
            $this->assertNull($hold->invoke($terminal, "\x1b", $onInput));
            $this->assertSame("\x1bx", $hold->invoke($terminal, 'x', $onInput));

            // ESC and nothing after it: the Escape key, once the window has passed.
            $this->assertNull($hold->invoke($terminal, "\x1b", $onInput));
            \Pig\Async\Loop::get()->run();
            $this->assertSame(["\x1b"], $keys);

            // Anything else is not held.
            $this->assertSame("\x1b[A", $hold->invoke($terminal, "\x1b[A", $onInput));
        } finally {
            putenv('PIG_TUI_ESC_TIMEOUT');
            \Pig\Async\Loop::reset();
        }
    }

    public function testTheWriteLogGetsEveryByteAndADirectoryGetsAFilePerRun(): void
    {
        putenv('PIG_TUI_WRITE_LOG=' . $this->target);

        try {
            $terminal = new ProcessTerminal(STDIN, fopen('php://memory', 'w+'));
            $terminal->write("\x1b[2Jhello");
            $terminal->write(' world');
            $this->assertSame("\x1b[2Jhello world", file_get_contents($this->target));
            unlink($this->target);

            mkdir($this->target);
            putenv('PIG_TUI_WRITE_LOG=' . $this->target);
            (new ProcessTerminal(STDIN, fopen('php://memory', 'w+')))->write('frame');
            $files = glob($this->target . '/tui-*-' . getmypid() . '.log') ?: [];
            $this->assertCount(1, $files);
            $this->assertSame('frame', file_get_contents($files[0]));
            $this->assertMatchesRegularExpression('/tui-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-\d+\.log$/', $files[0]);
            unlink($files[0]);
            rmdir($this->target);
        } finally {
            putenv('PIG_TUI_WRITE_LOG');
        }
    }

    /** @return array{0: ProcessTerminal, 1: resource} a terminal with the query sent, and what it writes */
    private function queryingTerminal(): array
    {
        $output = fopen('php://memory', 'w+');
        $this->assertIsResource($output);
        $terminal = new ProcessTerminal(STDIN, $output);
        $pending = new \ReflectionProperty(ProcessTerminal::class, 'programStatusQueryPending');
        $pending->setValue($terminal, true);

        return [$terminal, $output];
    }

    private function sift(ProcessTerminal $terminal, string $data): string
    {
        return (new \ReflectionMethod(ProcessTerminal::class, 'takeProgramStatusReplies'))->invoke($terminal, $data);
    }

    /**
     * Write $data through a ProcessTerminal into a draining reader, and read it back.
     *
     * The output is a pipe rather than a file because a file always accepts everything —
     * there would be nothing to test. It is non-blocking for the same reason it is in
     * real life: STDIN and STDOUT are dups of one open file description when both are
     * the terminal, so putting the input in non-blocking mode does it to the output too.
     */
    private function throughATerminal(string $data): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $reader = proc_open(['sh', '-c', 'cat > ' . escapeshellarg($this->target)], $descriptors, $pipes);

        $this->assertTrue($reader !== false, 'could not start the reader');
        stream_set_blocking($pipes[0], false);

        $terminal = new ProcessTerminal(STDIN, $pipes[0]);
        $terminal->write($data);
        // What the pipe did not take at once waits for the loop; nothing runs it here.
        $terminal->flush();

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($reader);

        return (string) file_get_contents($this->target);
    }
}
