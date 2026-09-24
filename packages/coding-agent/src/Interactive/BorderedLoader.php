<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\CancellableLoader;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;
use Pig\Tui\Tui;

/**
 * A spinner between two rules, with `esc cancel` under it.
 *
 * Upstream's `BorderedLoader`, and it exists for hooks: a hook that goes away for a while —
 * fetching something, waiting on a build — hands one of these to `HookUi::custom()`, and the
 * rules are what separate it from the transcript above and the prompt below. `TerminalUi`'s own
 * dialogs draw no rules, because they are a question and a question is read as one thing; this
 * is a *wait*, and a wait with nothing around it reads as output that stopped.
 *
 * It is a `Container` **and** an `InputHandler`, which is the whole lesson of the trap on
 * `SettingsSubmenu`: a container forwards drawing and nothing else, so one given the focus eats
 * every key — and the key that matters here is the one that cancels.
 */
final class BorderedLoader extends Container implements InputHandler
{
    private readonly CancellableLoader $loader;

    public function __construct(Tui $tui, Palette $palette, string $message = 'Working...')
    {
        // `border`, not `borderMuted`: upstream's choice, and the right one — these rules are
        // the edge of something that has taken the screen, not the quiet frame around the prompt.
        $rule = $palette->of('border');

        $this->loader = new CancellableLoader($tui, $palette->of('accent'), $palette->of('muted'), $message);

        $this->addChild(new Rule($rule));
        $this->addChild($this->loader);
        $this->addChild(new Spacer(1));
        $this->addChild(new Text($palette->fg('muted', 'esc cancel'), 1, 0));
        $this->addChild(new Spacer(1));
        $this->addChild(new Rule($rule));
    }

    /** Hand this to whatever is being waited on; Escape aborts it. */
    public function signal(): AbortSignal
    {
        return $this->loader->signal();
    }

    /** @param Closure(): void|null $handler */
    public function setAbortHandler(?Closure $handler): void
    {
        $this->loader->setAbortHandler($handler);
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->loader->handleInput($data);
    }

    /**
     * Stop the animation.
     *
     * Not optional and not a nicety: the spinner is a repeating timer, and a live timer keeps
     * `Loop::isIdle()` false — so a loader nobody disposed of is a `bin/pig` that does not exit.
     */
    public function dispose(): void
    {
        $this->loader->dispose();
    }
}
