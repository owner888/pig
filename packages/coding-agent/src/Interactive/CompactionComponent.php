<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Theme\Palette;
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
    private readonly Text $heading;

    private readonly Markdown $body;

    private bool $expanded = false;

    public function __construct(
        private readonly CompactionSummary $summary,
        private readonly Palette $palette,
        bool $expanded = false,
    ) {
        $this->heading = new Text('', 1, 0);
        $this->body = new Markdown(
            $summary->summary,
            2,
            0,
            $palette->markdownTheme(),
            new DefaultTextStyle(colour: $palette->of('muted')),
        );

        $this->addChild(new Spacer(1));
        $this->addChild($this->heading);
        $this->setExpanded($expanded);
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->heading->setText($this->line());

        $this->clear();
        $this->addChild(new Spacer(1));
        $this->addChild($this->heading);

        if ($expanded) {
            $this->addChild($this->body);
        }
    }

    private function line(): string
    {
        $files = count($this->summary->readFiles) + count($this->summary->modifiedFiles);

        $line = $this->palette->fg('accent', '⊙ Compacted')
            . $this->palette->fg('muted', sprintf(
                ' · %s earlier messages summarised',
                number_format($this->summary->replaced),
            ));

        if ($files > 0) {
            $line .= $this->palette->fg('muted', sprintf(' · %d files remembered', $files));
        }

        return $line . $this->palette->fg('dim', $this->expanded ? '' : '  ctrl+o to read it');
    }
}
