<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\SkillInvocationMessageComponent;
use Pig\CodingAgent\Prompt\SkillBlock;
use Pig\Tui\Ansi;

final class SkillInvocationMessageTest extends TestCase
{
    use GlobalThemeFixture;

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
    }

    public function testParseSkillBlockExtractsAttributesAndContent(): void
    {
        $text = "<skill name=\"review\" location=\"/path/to/SKILL.md\">\nReview the pull request carefully.\n</skill>";
        $block = SkillBlock::parse($text);

        $this->assertNotNull($block);
        $this->assertSame('review', $block->name);
        $this->assertSame('/path/to/SKILL.md', $block->location);
        $this->assertSame('Review the pull request carefully.', $block->content);
        $this->assertNull($block->userMessage);

        $textWithUser = "<skill name=\"deploy\" location=\"/path/to/deploy.md\">\nRun deployment.\n</skill>\n\nPlease deploy to staging.";
        $blockWithUser = SkillBlock::parse($textWithUser);

        $this->assertNotNull($blockWithUser);
        $this->assertSame('deploy', $blockWithUser->name);
        $this->assertSame('Please deploy to staging.', $blockWithUser->userMessage);

        $this->assertNull(SkillBlock::parse('Just a regular user question'));
    }

    public function testComponentRendersCollapsedAndExpandedStates(): void
    {
        $block = new SkillBlock('test-skill', '/path', 'Instructions here.');
        $component = new SkillInvocationMessageComponent($block, expanded: false);

        // Collapsed mode: shows single line [skill] name (ctrl+o to expand)
        $collapsed = Ansi::strip(implode("\n", $component->render(60)));
        $this->assertStringContainsString('[skill] test-skill (ctrl+o to expand)', $collapsed);
        $this->assertStringNotContainsString('Instructions here.', $collapsed);

        // Expanded mode
        $component->setExpanded(true);
        $expanded = Ansi::strip(implode("\n", $component->render(60)));
        $this->assertStringContainsString('[skill]', $expanded);
        $this->assertStringContainsString('test-skill', $expanded);
        $this->assertStringContainsString('Instructions here.', $expanded);
    }
}
