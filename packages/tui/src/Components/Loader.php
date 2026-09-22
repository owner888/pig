<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Tui;

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

    private int $frame = 0;

    private ?string $timer = null;

    private bool $running = false;

    /**
     * @param Closure(string): string $spinnerStyle
     * @param Closure(string): string $messageStyle
     */
    public function __construct(
        private readonly Tui $tui,
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

    public function setMessage(string $message): void
    {
        $this->message = $message;
        $this->update();
    }

    private function schedule(): void
    {
        $this->timer = Loop::get()->delay(self::FRAME_SECONDS, function (): void {
            // The id that just fired is spent, so it is dropped before anything else can
            // ask to cancel it — and `running` is what decides whether to go round again,
            // so a stop() from inside the redraw below is not undone by the reschedule.
            $this->timer = null;

            if (!$this->running) {
                return;
            }

            $this->frame = ($this->frame + 1) % count(self::FRAMES);
            $this->update();
            $this->schedule();
        });
    }

    private function update(): void
    {
        $this->setText(($this->spinnerStyle)(self::FRAMES[$this->frame]) . ' ' . ($this->messageStyle)($this->message));
        $this->tui->requestRender();
    }
}
