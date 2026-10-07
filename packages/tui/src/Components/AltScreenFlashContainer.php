<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\Width;

/**
 * Transient messages composited by the alternate-screen renderer.
 *
 * Ported from upstream's `components/alt-screen-flash.ts`.
 */
final class AltScreenFlashContainer implements Component
{
    private const float DEFAULT_DURATION = 1.0;

    /** @var array<int, array{message: string, timer: string}> */
    private array $entries = [];

    private int $nextId = 0;

    /** @param Closure(): void $requestRender */
    public function __construct(private readonly Closure $requestRender)
    {
    }

    public function flash(string $message, float $duration = self::DEFAULT_DURATION): void
    {
        $id = $this->nextId++;
        $timer = Loop::get()->delay(max(0.0, $duration), function () use ($id): void {
            if (!isset($this->entries[$id])) {
                return;
            }

            unset($this->entries[$id]);
            ($this->requestRender)();
        });
        $this->entries[$id] = ['message' => $message, 'timer' => $timer];
        ($this->requestRender)();
    }

    public function dispose(): void
    {
        foreach ($this->entries as $entry) {
            Loop::get()->cancel($entry['timer']);
        }

        $this->entries = [];
    }

    #[\Override]
    public function invalidate(): void
    {
    }

    #[\Override]
    public function render(int $width): array
    {
        $lines = [];

        foreach ($this->entries as $entry) {
            $message = Width::truncate(" {$entry['message']} ", $width, '');
            $lines[] = "\x1b[7m{$message}\x1b[27m";
        }

        return $lines;
    }
}
