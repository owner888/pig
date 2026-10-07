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
    ) {
        $this->box = new Box(1, 1, static fn (string $text): string => Themes::theme()->bg('customMessageBg', $text));
        $this->label = new Text('', 0, 0);
        $this->detail = new Text('', 0, 0);
        $this->body = new Markdown(
            $summary->summary,
            0,
            0,
            Themes::getMarkdownTheme(),
            new DefaultTextStyle(colour: static fn (string $text): string => Themes::theme()->fg('muted', $text)),
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

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->label->setText(Themes::theme()->fg('customMessageLabel', '[compaction]'));
        $this->detail->setText($this->line());

        $this->box->clear();
        $this->box->addChild($this->label);
        $this->box->addChild(new Spacer(1));
        $this->box->addChild($this->detail);

        if ($expanded) {
            $this->box->addChild(new Spacer(1));
            $this->box->addChild($this->body);
        }
    }

    private function line(): string
    {
        $line = $this->summary->tokensBefore > 0
            ? sprintf('Compacted from %s tokens', number_format($this->summary->tokensBefore))
            : sprintf('Compacted %s earlier messages', number_format($this->summary->replaced));

        if (!$this->expanded) {
            $line .= ' (ctrl+o to expand)';
        }

        return Themes::theme()->fg('muted', $line);
    }
}
