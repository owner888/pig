<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\Shell;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * Something a hook said, drawn the ordinary way.
 *
 * The default when a hook has not registered a renderer of its own. It is deliberately not
 * `UserMessageComponent`: a hook's message reaches the model as a user message, and showing
 * it as one would put words in the person's mouth — they would scroll back and find
 * themselves saying something they never typed. So it is labelled with the hook's own type,
 * in the muted colour, and reads as a note rather than as a turn.
 *
 * Upstream's `CustomMessageComponent` default: the type as a bold label, then the whole text as
 * markdown — not folded, as upstream does not fold it.
 *
 * Ported from upstream's `components/custom-message.ts`.
 */
final class HookMessageComponent extends Container
{
    private readonly Box $box;

    private readonly Text $heading;

    private readonly string $text;

    private bool $expanded = false;

    public function __construct(
        private readonly HookMessage $message,
        bool $expanded = false,
        int $outputPad = 1,
    ) {
        // A hook's message is usually output it just captured, so it is no more pig's own
        // words than a tool's result is — and this was the one of the three that had no
        // guard at all: one stray byte from a build log threw out of `render()`.
        $this->text = Shell::sanitize($message->toText());
        $this->box = new Box($outputPad, 1, static fn (string $text): string => Themes::theme()->bg('customMessageBg', $text));
        $this->heading = new Text('', 0, 0);

        $this->addChild(new Spacer(1));
        $this->addChild($this->box);
        $this->setExpanded($expanded);
    }

    /** Rebuilt so a theme change reaches the heading, as upstream's components rebuild on `invalidate()`. */
    #[\Override]
    public function invalidate(): void
    {
        parent::invalidate();
        $this->setExpanded($this->expanded);
    }

    /** Upstream's `setOutputPad()`: the box's own padding, which is where the pad lives here. */
    public function setOutputPad(int $outputPad): void
    {
        $this->box->setPaddingX($outputPad);
    }

    /** Kept for ctrl+o, which names every transcript component; the default draw is the same either way. */
    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->heading->setText(Themes::theme()->fg('customMessageLabel', "\x1b[1m[{$this->message->customType}]\x1b[22m"));

        $this->box->clear();
        $this->box->addChild($this->heading);
        $this->box->addChild(new Spacer(1));
        $this->box->addChild(new Markdown(
            $this->text,
            0,
            0,
            Themes::getMarkdownTheme(),
            new DefaultTextStyle(colour: static fn (string $text): string => Themes::theme()->fg('customMessageText', $text)),
        ));
    }
}
