<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\TUI;

/**
 * A spinner with a message beside it.
 *
 * The animation is a timer that reschedules itself, because the loop has no repeating
 * timer and does not need one: a spinner that stops being drawn should stop costing
 * anything, and one that reschedules from its own callback does exactly that.
 *
 * Something has to call `stop()`. A loader left running keeps the loop alive forever,
 * since a pending timer is work as far as `Loop::isIdle()` is concerned.
 */
class Loader extends Text
{
    private const float FRAME_SECONDS = 0.08;

    /** @var list<string> */
    private const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** @var list<string> the frames in use; upstream's `setIndicator()` replaces them */
    private array $frames = self::FRAMES;

    private float $frameSeconds = self::FRAME_SECONDS;

    /** Custom frames are drawn as given, without the spinner colour, as upstream draws them. */
    private bool $verbatim = false;

    private int $frame = 0;

    private ?string $timer = null;

    private bool $running = false;

    private bool $withTimer = false;

    private ?float $startedAt = null;

    /**
     * @param Closure(string): string $spinnerStyle
     * @param Closure(string): string $messageStyle
     */
    public function __construct(
        private readonly TUI $tui,
        private readonly Closure $spinnerStyle,
        private readonly Closure $messageStyle,
        private string $message = 'Loading...',
    ) {
        parent::__construct('', paddingX: 1, paddingY: 0);
        $this->start();
    }

    #[\Override]
    public function render(int $width): array
    {
        // A blank line above, so the spinner never sits flush against the last message.
        return ['', ...parent::render($width)];
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->update();
        $this->schedule();
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->timer !== null) {
            Loop::get()->cancel($this->timer);
            $this->timer = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Upstream's `setIndicator()`: no argument restores the default spinner, `frames: ['●']`
     * is a still mark, `frames: []` hides the indicator, and custom frames are drawn verbatim.
     *
     * @param array{frames?: list<string>, intervalMs?: int}|null $indicator
     */
    public function setIndicator(?array $indicator = null): void
    {
        $this->verbatim = $indicator !== null;
        $this->frames = $indicator['frames'] ?? self::FRAMES;
        $interval = $indicator['intervalMs'] ?? 0;
        $this->frameSeconds = $interval > 0 ? $interval / 1000 : self::FRAME_SECONDS;
        $this->frame = 0;
        $this->stop();
        $this->start();
    }

    public function withTimer(bool $withTimer = true, ?float $startedAt = null): self
    {
        $this->withTimer = $withTimer;
        $this->startedAt = $withTimer ? ($startedAt ?? microtime(true)) : null;
        $this->update();

        if ($this->running && $this->timer === null) {
            $this->schedule();
        }

        return $this;
    }

    /**
     * The spinner and its message as one line, for `Editor::setBorderStatus()`.
     *
     * Upstream's `StatusIndicator.renderInBorder()`: the same text `render()` draws, without
     * the blank line above and the padding round it, because the border supplies both.
     * When timer is enabled, formats elapsed duration: e.g. "Working... · 16s".
     */
    public function inBorder(): string
    {
        $msg = $this->message;

        if ($this->withTimer && $this->startedAt !== null) {
            $elapsedSec = max(0, (int) (microtime(true) - $this->startedAt));
            if ($elapsedSec >= 1) {
                $timeStr = $elapsedSec >= 60
                    ? sprintf('%dm%02ds', intdiv($elapsedSec, 60), $elapsedSec % 60)
                    : "{$elapsedSec}s";

                if (preg_match('/^(.*?)\s*(\([^\)]+\))$/', $msg, $m)) {
                    $msg = "{$m[1]} · {$timeStr} {$m[2]}";
                } else {
                    $msg = "{$msg} · {$timeStr}";
                }
            }
        }

        $frame = $this->frames[$this->frame] ?? '';
        $indicator = $frame === '' ? '' : ($this->verbatim ? $frame : ($this->spinnerStyle)($frame)) . ' ';

        return $indicator . ($this->messageStyle)($msg);
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
        $this->update();
    }

    private function schedule(): void
    {
        // A single frame or none is a still indicator: nothing to animate, so no timer to
        // keep the loop awake — unless the elapsed time is on the line, which ticks anyway.
        if (count($this->frames) <= 1 && !$this->withTimer) {
            return;
        }

        $this->timer = Loop::get()->delay($this->frameSeconds, function (): void {
            // The id that just fired is spent, so it is dropped before anything else can
            // ask to cancel it — and `running` is what decides whether to go round again,
            // so a stop() from inside the redraw below is not undone by the reschedule.
            $this->timer = null;

            if (!$this->running) {
                return;
            }

            $this->frame = ($this->frame + 1) % count($this->frames);
            $this->update();
            $this->schedule();
        });
    }

    private function update(): void
    {
        $this->setText($this->inBorder());
        $this->tui->requestRender();
    }
}
