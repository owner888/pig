<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;

/**
 * Upstream's `FocusableOverlay` fixture in `overlay-non-capturing.test.ts`: fixed lines, records
 * every input. Upstream tests replace `handleInput` per instance; here `$onInput` runs after the
 * input is recorded, which is what each of those replacements does.
 */
final class FocusableOverlay implements Component, Focusable, InputHandler
{
    public bool $focused = false;

    /** @var list<string> */
    public array $inputs = [];

    /** @var (Closure(string): void)|null */
    public ?Closure $onInput = null;

    /** @param list<string> $lines */
    public function __construct(private array $lines)
    {
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->inputs[] = $data;
        if ($this->onInput !== null) {
            ($this->onInput)($data);
        }
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->lines;
    }

    #[\Override]
    public function invalidate(): void
    {
    }
}
