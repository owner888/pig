<?php

declare(strict_types=1);

namespace Pig\Evals;

use Closure;
use Pig\Async\Async;
use Pig\Tui\Ansi;
use Pig\Tui\ProgramStatus;
use Pig\Tui\Terminal;
use RuntimeException;

/**
 * Upstream's `RecordingTerminal` (`packages/evals/evals/tui.docs.eval.ts`): captures synchronized
 * TUI frames (`\e[?2026h ... \e[?2026l`) stripped of ANSI escapes for the footer oracle.
 */
final class RecordingTerminal implements Terminal
{
    /** @var list<string> */
    private array $writes = [];

    private ?Closure $resizeHandler = null;

    #[\Override]
    public function start(Closure $onInput, Closure $onResize): void
    {
        unset($onInput);
        $this->resizeHandler = $onResize;
    }

    #[\Override]
    public function stop(): void
    {
        $this->resizeHandler = null;
    }

    #[\Override]
    public function restoreOnExit(string $before, string $after): void
    {
        unset($before, $after);
    }

    public function drainInput(int $maxMs = 1000, int $idleMs = 50): void
    {
        unset($maxMs, $idleMs);
    }

    #[\Override]
    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    #[\Override]
    public function columns(): int
    {
        return 100;
    }

    #[\Override]
    public function rows(): int
    {
        return 30;
    }

    public function kittyProtocolActive(): bool
    {
        return true;
    }

    #[\Override]
    public function moveBy(int $lines): void
    {
        unset($lines);
    }

    #[\Override]
    public function hideCursor(): void
    {
    }

    #[\Override]
    public function showCursor(): void
    {
    }

    #[\Override]
    public function clearLine(): void
    {
    }

    #[\Override]
    public function clearFromCursor(): void
    {
    }

    #[\Override]
    public function clearScreen(): void
    {
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        unset($title);
    }

    #[\Override]
    public function setProgramStatus(ProgramStatus $status): void
    {
        unset($status);
    }

    #[\Override]
    public function setProgress(bool $active): void
    {
        unset($active);
    }

    public function requestResize(): void
    {
        if ($this->resizeHandler !== null) {
            ($this->resizeHandler)();
        }
    }

    /**
     * @return list<string>
     */
    public function takeRenders(): array
    {
        Async::delay(0.03);
        $output = implode('', $this->writes);
        $this->writes = [];
        $startMarker = "\x1b[?2026h";
        $endMarker = "\x1b[?2026l";
        $renders = [];
        $offset = 0;

        while (true) {
            $start = strpos($output, $startMarker, $offset);

            if ($start === false) {
                return $renders;
            }

            $contentStart = $start + strlen($startMarker);
            $end = strpos($output, $endMarker, $contentStart);

            if ($end === false) {
                throw new RuntimeException('TUI emitted an incomplete synchronized render.');
            }

            $renders[] = self::stripVtControlCharacters(substr($output, $contentStart, $end - $contentStart));
            $offset = $end + strlen($endMarker);
        }
    }

    /**
     * Upstream's `stripVTControlCharacters()`: strips CSI (`\e[...`), OSC (`\e]...`) and APC (`\e_...`) sequences.
     */
    public static function stripVtControlCharacters(string $text): string
    {
        return (string) preg_replace(
            ['/\x1b\[[0-9;?]*[A-Za-z]/', '/\x1b[\]_].*?(?:\x07|\x1b\\\\)/s'],
            '',
            $text,
        );
    }
}
