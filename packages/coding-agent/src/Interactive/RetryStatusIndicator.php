<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Async\Loop;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\TUI;

/**
 * `Retrying (1/3) in 30s... (escape to cancel)` — upstream's `RetryStatusIndicator`, with its
 * `CountdownTimer` inside: the seconds rounded up (`Math.ceil(delayMs / 1000)`) and counted
 * down once a second until the retry starts. `dispose()` stops the countdown with the spinner,
 * so a retry taken down early (its end, a turn starting) leaves no tick behind to redraw it.
 *
 * Not the error with the countdown after it, which this used to say: the reason is already a
 * red line in the transcript by the time the retry starts, and in the prompt's border an
 * 80-column terminal cut the error off before the one word that matters — the key that stops it.
 */
final class RetryStatusIndicator extends StatusIndicator
{
    private ?string $tick = null;

    public function __construct(
        private readonly TUI $tui,
        Keybindings $keybindings,
        int $attempt,
        int $maxAttempts,
        float $delaySeconds,
    ) {
        $key = $keybindings->keyText('app.interrupt');
        $message = static fn (int $seconds): string => "Retrying ({$attempt}/{$maxAttempts}) in {$seconds}s... ({$key} to cancel)";
        $remaining = (int) ceil($delaySeconds);

        parent::__construct(
            'retry',
            $tui,
            static fn (string $text): string => Themes::theme()->fg('warning', $text),
            self::muted(),
            $message($remaining),
        );

        $tick = function () use (&$tick, &$remaining, $message): void {
            $this->tick = null;
            $remaining--;
            $this->setMessage($message(max(0, $remaining)));
            $this->tui->requestRender();

            if ($remaining > 0) {
                $this->tick = Loop::get()->delay(1.0, $tick);
            }
        };

        if ($remaining > 0) {
            $this->tick = Loop::get()->delay(1.0, $tick);
        }
    }

    #[\Override]
    public function dispose(): void
    {
        if ($this->tick !== null) {
            Loop::get()->cancel($this->tick);
            $this->tick = null;
        }

        parent::dispose();
    }
}
