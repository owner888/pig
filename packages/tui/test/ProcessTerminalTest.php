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

    public function testPiProgramStatusEnvironmentVariableForcesSupport(): void
    {
        putenv('PIG_PROGRAM_STATUS');
        putenv('PI_PROGRAM_STATUS=1');
        try {
            $output = fopen('php://memory', 'w+');
            $terminal = new ProcessTerminal(STDIN, $output);
            // Reflect private properties to verify override resolution
            $init = new \ReflectionMethod(ProcessTerminal::class, 'writeProgramStatus');
            $supported = new \ReflectionProperty(ProcessTerminal::class, 'programStatusSupported');
            $pending = new \ReflectionProperty(ProcessTerminal::class, 'programStatusQueryPending');
            // Mock start initialization logic
            $override = \Pig\Tui\Env::isSet('PIG_PROGRAM_STATUS')
                ? getenv('PIG_PROGRAM_STATUS')
                : (\Pig\Tui\Env::isSet('PI_PROGRAM_STATUS') ? getenv('PI_PROGRAM_STATUS') : false);
            $supported->setValue($terminal, $override === '1');
            $pending->setValue($terminal, $override !== '1' && $override !== '0');

            $this->assertTrue($supported->getValue($terminal));
            $this->assertFalse($pending->getValue($terminal));
        } finally {
            putenv('PI_PROGRAM_STATUS');
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

        (new ProcessTerminal(STDIN, $pipes[0]))->write($data);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($reader);

        return (string) file_get_contents($this->target);
    }
}
