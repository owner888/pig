<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\TUI;

/**
 * `Working` while a turn runs — upstream's `WorkingStatusIndicator`. The message is an extension's
 * (`setWorkingMessage()`) when one set it, the spinner an extension's frames (`setWorkingIndicator()`)
 * when it gave any; `$colorFn`, when given, colours both, as upstream's does.
 */
final class WorkingStatusIndicator extends StatusIndicator
{
    /**
     * @param array{frames?: list<string>, intervalMs?: int}|null $indicator
     * @param Closure(string): string|null $colorFn
     */
    public function __construct(TUI $tui, string $message = 'Working', ?array $indicator = null, ?Closure $colorFn = null)
    {
        parent::__construct('working', $tui, $colorFn ?? self::accent(), $colorFn ?? self::muted(), $message);

        if ($indicator !== null) {
            $this->setIndicator($indicator);
        }
    }
}
