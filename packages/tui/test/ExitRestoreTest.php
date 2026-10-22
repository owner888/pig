<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;

/**
 * Upstream's `exit-restore.test.ts`: exits that skip stop() left mouse tracking, focus reporting,
 * the Kitty keyboard protocol, bracketed paste and the alternate screen on (upstream #11170). In
 * PHP the common one is a fatal error — memory exhausted left the shell printing mouse reports.
 */
final class ExitRestoreTest extends TestCase
{
    public function testRestoresTerminalModesWhenTheProcessExitsWithoutStop(): void
    {
        [$output] = self::runFixture('exit');
        foreach (["\x1b[?1006l", "\x1b[?1004l", "\x1b[?1003l", "\x1b[?1000l", "\x1b[<u", "\x1b[?2004l"] as $sequence) {
            $this->assertSame(1, substr_count($output, $sequence), json_encode($sequence, JSON_THROW_ON_ERROR));
        }
        $this->assertLessThan(strpos($output, "\x1b[?1049l"), strpos($output, "\x1b[<u"), 'Kitty pop must precede leaving alt screen');
        $this->assertStringEndsWith("\x1b[?1049l\x1b[?25h", $output);
    }

    public function testDoesNotRestoreTwiceAfterANormalStop(): void
    {
        [$output] = self::runFixture('stop');
        foreach (["\x1b[?1006l", "\x1b[?1004l", "\x1b[<u", "\x1b[?2004l", "\x1b[?1049l"] as $sequence) {
            $this->assertSame(1, substr_count($output, $sequence), json_encode($sequence, JSON_THROW_ON_ERROR));
        }
    }

    public function testAFatalErrorRestoresTheTerminalAndPrintsTheErrorAgainAfterLeavingTheAltScreen(): void
    {
        [$output, $errors] = self::runFixture('fatal');
        $this->assertSame(1, substr_count($output, "\x1b[?1000l"));
        $this->assertSame(1, substr_count($output, "\x1b[?1049l"));
        $this->assertStringContainsString('Allowed memory size', $errors);
    }

    /** @return array{string, string} stdout and stderr */
    private static function runFixture(string $mode): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stdout', __DIR__ . '/fixtures/exit-without-stop.php', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['TERM' => 'xterm-256color', 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$output, $errors];
    }
}
