<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\TerminalQuestion;

/**
 * Upstream's `terminal-question.test.ts`: one line, read off the loop so a sign-in's loopback server
 * is still answered while the question waits; null on abort or the end of input.
 */
final class TerminalQuestionTest extends TestCase
{
    /** @var resource */
    private mixed $in;

    /** @var resource */
    private mixed $peer;

    /** @var resource */
    private mixed $out;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        [$this->in, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->out = fopen('php://temp', 'w+') ?: throw new \RuntimeException('no temp stream');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->in, $this->peer, $this->out] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testALineComesBackWithoutItsEndingAndTheLoopRanMeanwhile(): void
    {
        $ticks = 0;
        $peer = $this->peer;
        $tick = static function () use (&$tick, &$ticks): void {
            $ticks++;
            Loop::get()->delay(0.01, $tick);
        };
        Loop::get()->delay(0.01, $tick);
        Loop::get()->delay(0.08, static fn () => fwrite($peer, "code#state\r\n"));

        $answer = Async::run(fn (): ?string => TerminalQuestion::ask('Paste the code: ', (new AbortController())->signal, $this->out, input: $this->in));

        $this->assertSame('code#state', $answer);
        $this->assertGreaterThan(2, $ticks, 'the loop was not stopped while the question waited');
        rewind($this->out);
        $this->assertSame('Paste the code: ', stream_get_contents($this->out));
    }

    public function testAnAbortAnswersNull(): void
    {
        // The browser came back first: the paste box closes.
        $controller = new AbortController();
        Loop::get()->delay(0.02, static fn () => $controller->abort());

        $this->assertNull(Async::run(fn (): ?string => TerminalQuestion::ask('Paste: ', $controller->signal, $this->out, input: $this->in)));
    }

    public function testTheEndOfInputAnswersNull(): void
    {
        $peer = $this->peer;
        Loop::get()->delay(0.02, static fn () => fclose($peer));

        $this->assertNull(Async::run(fn (): ?string => TerminalQuestion::ask('Paste: ', (new AbortController())->signal, $this->out, input: $this->in)));
    }

    public function testAnAlreadyAbortedSignalAsksNothing(): void
    {
        $controller = new AbortController();
        $controller->abort();

        $this->assertNull(TerminalQuestion::ask('Paste: ', $controller->signal, $this->out, input: $this->in));
        rewind($this->out);
        $this->assertSame('', stream_get_contents($this->out));
    }
}
