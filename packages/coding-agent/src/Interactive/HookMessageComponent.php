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
 * Folded to a few lines until ctrl+o, like every other long thing in the transcript. That was
 * missing, and it is the "wired at one end only" shape: `setExpanded()` existed on the tool
 * view and on the two summary components, `InteractiveMode` named those three in its ctrl+o
 * handler, and this one had no such method to be named. What it cost is not cosmetic — what a
 * hook *sends* is a build failure or a lint report, so the long message is the common case, and
 * it sat open for ever while ctrl+o folded every tool call around it.
 *
 * Ported from upstream's `components/hook-message.ts`.
 */
final class HookMessageComponent extends Container
{
    /** Lines kept while folded. Upstream's number. */
    private const int PREVIEW_LINES = 5;

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

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $lines = explode("\n", $this->text);
        $cut = !$expanded && count($lines) > self::PREVIEW_LINES;

        $this->heading->setText(
            Themes::theme()->fg('customMessageLabel', "[{$this->message->customType}]")
            . ($cut ? Themes::theme()->fg('muted', ' (ctrl+o to expand)') : ''),
        );

        $this->box->clear();
        $this->box->addChild($this->heading);

        if (trim($this->text) === '') {
            return;
        }

        $this->box->addChild(new Spacer(1));

        // Cut the *source* before the markdown, as upstream does: a hook's lines reflow into
        // paragraphs on the way out, so cutting the drawn rows would cut whole paragraphs.
        $shown = $cut
            ? implode("\n", array_slice($lines, 0, self::PREVIEW_LINES))
                . "\n\n... (" . (count($lines) - self::PREVIEW_LINES) . ' more lines)'
            : $this->text;

        $this->box->addChild(new Markdown(
            $shown,
            0,
            0,
            Themes::getMarkdownTheme(),
            new DefaultTextStyle(colour: static fn (string $text): string => Themes::theme()->fg('muted', $text)),
        ));
    }
}
