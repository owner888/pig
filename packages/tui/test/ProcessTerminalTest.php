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
