<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Prompt\SkillBlock;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\Style;

/**
 * Component that renders a skill invocation message with collapsed/expanded state.
 *
 * Uses the same background color (`customMessageBg`) as custom hook messages for consistency.
 * - Collapsed: single line — `[skill] name (ctrl+o to expand)`
 * - Expanded: `[skill]` label, bold skill name header, and full markdown content.
 *
 * Ported from upstream's `modes/interactive/components/skill-invocation-message.ts`.
 */
final class SkillInvocationMessageComponent extends Container
{
    private readonly Box $box;

    public function __construct(
        private readonly SkillBlock $skillBlock,
        private readonly Palette $palette,
        private bool $expanded = false,
        private readonly string $expandKey = 'ctrl+o',
    ) {
        $this->box = new Box(1, 1, $this->palette->of('customMessageBg'));
        $this->addChild(new Spacer(1));
        $this->addChild($this->box);
        $this->updateDisplay();
    }

    public function setExpanded(bool $expanded): void
    {
        if ($this->expanded === $expanded) {
            return;
        }

        $this->expanded = $expanded;
        $this->updateDisplay();
    }

    private function updateDisplay(): void
    {
        $this->box->clear();

        if ($this->expanded) {
            $label = $this->palette->fg('customMessageLabel', Style::bold('[skill]'));
            $this->box->addChild(new Text($label, 0, 0));
            $this->box->addChild(new Spacer(1));

            $header = "**{$this->skillBlock->name}**\n\n";
            $this->box->addChild(new Markdown(
                $header . $this->skillBlock->content,
                0,
                0,
                $this->palette->markdownTheme(),
                new DefaultTextStyle(colour: $this->palette->of('customMessageText')),
            ));
        } else {
            $line = $this->palette->fg('customMessageLabel', Style::bold('[skill]') . ' ')
                . $this->palette->fg('customMessageText', $this->skillBlock->name)
                . $this->palette->fg('dim', " ({$this->expandKey} to expand)");

            $this->box->addChild(new Text($line, 0, 0));
        }
    }
}
