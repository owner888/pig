<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * The line in the transcript where the conversation was boiled down.
 *
 * Shown rather than silently done, because the model's memory of the last hour just
 * changed shape and the person is entitled to know which hour it was. Collapsed it is
 * one line; ctrl+o opens the summary the model will actually be working from, which is
 * the only way to tell a good summary from a bad one before it costs you something.
 */
final class CompactionComponent extends Container
{
    private readonly Box $box;

    private readonly Text $label;

    private readonly Text $detail;

    private readonly Markdown $body;

    private bool $expanded = false;

    public function __construct(
        private readonly CompactionSummary $summary,
        bool $expanded = false,
        int $outputPad = 1,
        /** `keyText("app.tools.expand")`, for the collapsed line. */
        private readonly string $expandKey = 'ctrl+o',
    ) {
        $this->box = new Box($outputPad, 1, static fn (string $text): string => Themes::theme()->bg('customMessageBg', $text));
        $this->label = new Text('', 0, 0);
        $this->detail = new Text('', 0, 0);
        $this->body = new Markdown(
            '',
            0,
            0,
            Themes::getMarkdownTheme(),
            new DefaultTextStyle(colour: static fn (string $text): string => Themes::theme()->fg('customMessageText', $text)),
        );

        $this->addChild(new Spacer(1));
        $this->addChild($this->box);
        $this->setExpanded($expanded);
    }

    /** Rebuilt so a theme change reaches the label and the summary line, as upstream's components rebuild on `invalidate()`. */
    #[\Override]
    public function invalidate(): void
    {
        parent::invalidate();
        $this->body->invalidate();
        $this->setExpanded($this->expanded);
    }

    /** Upstream's `setOutputPad()`: the box's own padding, which is where the pad lives here. */
    public function setOutputPad(int $outputPad): void
    {
        $this->box->setPaddingX($outputPad);
    }

    /**
     * Upstream's: the label in bold, then collapsed `Compacted from N tokens (ctrl+o to expand)`, or
     * expanded the summary under a bold header of its own.
     */
    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->label->setText(Themes::theme()->fg('customMessageLabel', "\x1b[1m[compaction]\x1b[22m"));

        $this->box->clear();
        $this->box->addChild($this->label);
        $this->box->addChild(new Spacer(1));

        if ($expanded) {
            $this->body->setText('**' . $this->line() . "**\n\n" . $this->summary->summary);
            $this->box->addChild($this->body);

            return;
        }

        $this->detail->setText(
            Themes::theme()->fg('customMessageText', $this->line() . ' (')
            . Themes::theme()->fg('dim', $this->expandKey)
            . Themes::theme()->fg('customMessageText', ' to expand)'),
        );
        $this->box->addChild($this->detail);
    }

    /** Upstream's line; a session from before pig kept the token count says how many messages instead. */
    private function line(): string
    {
        return $this->summary->tokensBefore > 0
            ? sprintf('Compacted from %s tokens', number_format($this->summary->tokensBefore))
            : sprintf('Compacted %s earlier messages', number_format($this->summary->replaced));
    }
}
