<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Tui;

/**
 * A spinner that Escape can call off.
 *
 * Hand `signal()` to whatever is being waited on and give this component focus; the work
 * sees the abort through the same `AbortSignal` the agent loop and the HTTP client already
 * take, so "Escape stops it" needs no special path anywhere below here.
 */
final class CancellableLoader extends Loader implements InputHandler
{
    private readonly AbortController $controller;

    /** @var Closure(): void|null */
    private ?Closure $onAbort = null;

    /**
     * @param Closure(string): string $spinnerStyle
     * @param Closure(string): string $messageStyle
     */
    public function __construct(
        Tui $tui,
        Closure $spinnerStyle,
        Closure $messageStyle,
        string $message = 'Working...',
    ) {
        // Before the parent constructor, which starts the animation and can already draw.
        $this->controller = new AbortController();

        parent::__construct($tui, $spinnerStyle, $messageStyle, $message);
    }

    public function signal(): AbortSignal
    {
        return $this->controller->signal;
    }

    public function aborted(): bool
    {
        return $this->controller->signal->aborted();
    }

    /** @param Closure(): void|null $handler called when the user presses Escape */
    public function setAbortHandler(?Closure $handler): void
    {
        $this->onAbort = $handler;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if (!Keys::isEscape($data) || $this->aborted()) {
            return;
        }

        $this->controller->abort('Cancelled');

        if ($this->onAbort !== null) {
            ($this->onAbort)();
        }
    }

    /** Stop the animation. A loader still ticking keeps the event loop from going idle. */
    public function dispose(): void
    {
        $this->stop();
    }
}
