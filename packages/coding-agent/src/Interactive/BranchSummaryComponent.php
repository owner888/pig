<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * The line in the transcript where a branch was left behind, and what came of it.
 *
 * `CompactionComponent`'s sibling, and deliberately not the same class: what they mean is
 * different enough that one line saying the wrong one would be worse than two components.
 * A compaction says "the last hour was rewritten"; this says "an hour that happened
 * somewhere else is now in front of you". Collapsed, one line; ctrl+o opens the handover.
 *
 * Ported from upstream's `components/branch-summary-message.ts`.
 */
final class BranchSummaryComponent extends Container
{
    private readonly Text $heading;

    private readonly Markdown $body;

    private bool $expanded = false;

    public function __construct(
        private readonly BranchSummary $summary,
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

        $line = $this->palette->fg('accent', '⑂ Branch summarised')
            . $this->palette->fg('muted', $this->summary->fromHook ? ' · by a hook' : '');

        if ($files > 0) {
            $line .= $this->palette->fg('muted', sprintf(' · %d files remembered', $files));
        }

        return $line . $this->palette->fg('dim', $this->expanded ? '' : '  ctrl+o to read it');
    }
}
