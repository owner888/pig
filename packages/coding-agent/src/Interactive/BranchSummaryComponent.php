<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\Box;
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
    private readonly Box $box;

    private readonly Text $label;

    private readonly Text $detail;

    private readonly Markdown $body;

    private bool $expanded = false;

    public function __construct(
        private readonly BranchSummary $summary,
        private readonly Palette $palette,
        bool $expanded = false,
    ) {
        $this->box = new Box(1, 1, $palette->of('customMessageBg'));
        $this->label = new Text($palette->fg('customMessageLabel', '[branch summary]'), 0, 0);
        $this->detail = new Text('', 0, 0);
        $this->body = new Markdown(
            $summary->summary,
            0,
            0,
            $palette->markdownTheme(),
            new DefaultTextStyle(colour: $palette->of('muted')),
        );

        $this->addChild(new Spacer(1));
        $this->addChild($this->box);
        $this->setExpanded($expanded);
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
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
        $line = $this->summary->fromHook
            ? 'Branch summary provided by a hook'
            : 'Branch summarised';

        if (!$this->expanded) {
            $line .= ' (ctrl+o to expand)';
        }

        return $this->palette->fg('muted', $line);
    }
}
