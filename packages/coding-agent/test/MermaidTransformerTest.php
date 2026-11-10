<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\MermaidTransformer;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Ansi;
use Pig\Tui\Images\TerminalCapabilities;
use Pig\Tui\Images\TerminalImage;

/**
 * Upstream's Mermaid transformer: a top-level ```mermaid block drawn as code-span rows, and left
 * as written when it is off, still streaming under `final`, too wide, or not drawable; a source
 * that did not all parse keeps its block and gets one warning line.
 */
final class MermaidTransformerTest extends TestCase
{
    use GlobalThemeFixture;

    private const string FLOW = "Here:\n\n```mermaid\ngraph TD\n  A[Start] --> B[End]\n```\n\nDone.";

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TerminalImage::resetCapabilitiesCache();
        $this->tearDownGlobalTheme();
    }

    public function testATopLevelBlockBecomesItsDiagramOneCodeSpanARow(): void
    {
        $out = Ansi::strip(MermaidTransformer::transform(self::FLOW, false, 80));

        $this->assertSame(
            "Here:\n\n` ┌───────┐`  \n` │ Start │`  \n` └───┬───┘`  \n`     │`  \n`     ▼`  \n`  ┌─────┐`  \n`  │ End │`  \n`  └─────┘`\n\nDone.",
            $out,
        );
    }

    public function testTheModeDecidesWhetherAndWhen(): void
    {
        $mode = 'final';
        $transform = MermaidTransformer::create(static function () use (&$mode): string {
            return $mode;
        }, static fn (): int => 80);

        $this->assertSame(self::FLOW, $transform(self::FLOW, ['role' => 'assistant', 'isStreaming' => true]), 'final waits for the end');
        $this->assertNotSame(self::FLOW, $transform(self::FLOW, ['role' => 'assistant', 'isStreaming' => false]));

        $mode = 'streaming';
        $this->assertNotSame(self::FLOW, $transform(self::FLOW, ['role' => 'assistant', 'isStreaming' => true]));

        $mode = 'off';
        $this->assertSame(self::FLOW, $transform(self::FLOW, ['role' => 'user', 'isStreaming' => false]));
    }

    public function testWhatCannotBeDrawnOrDoesNotFitIsLeftAsWritten(): void
    {
        $this->assertSame(self::FLOW, MermaidTransformer::transform(self::FLOW, false, 8), 'wider than the room');

        $gantt = "```mermaid\ngantt\n  task :a1, 2024-01-01, 30d\n```";
        $this->assertSame($gantt, MermaidTransformer::transform($gantt, false, 80), 'a kind it does not draw');

        $other = "```js\nconst mermaid = 1\n```";
        $this->assertSame($other, MermaidTransformer::transform($other, false, 80));

        $nested = "- a list\n\n  ```mermaid\n  graph TD\n  A --> B\n  ```";
        $this->assertSame($nested, MermaidTransformer::transform($nested, false, 80), 'inside a list item');
    }

    public function testAFinishedSourceThatDidNotAllParseKeepsItsBlockAndSaysWhy(): void
    {
        $broken = "```mermaid\ngraph TD\n A[Start --> B\n```";

        $this->assertSame(
            // Fenced with two backticks for the one inside; the warning colour sits around the text,
            // so no padding is needed against the closing backtick.
            "{$broken}\n\n``Mermaid diagram not rendered: node \"A\": label is missing its closing `]```  ",
            Ansi::strip(MermaidTransformer::transform($broken, false, 80)),
        );
        $this->assertStringStartsWith('` ┌', Ansi::strip(MermaidTransformer::transform($broken, true, 80)), 'drawn while it streams');
    }

    public function testLaterParseWarningsAreSummarised(): void
    {
        $markdown = "```mermaid\nflowchart LR\n  A --> B\n  broken !\n  other ?\n```";
        $out = Ansi::strip(MermaidTransformer::transform($markdown, false, 80));

        $this->assertStringContainsString($markdown, $out);
        $this->assertStringContainsString('dropped, expected a link: "!"', $out);
        $this->assertStringContainsString('(+1 more)', $out);
        $this->assertStringNotContainsString('dropped, expected a link: "?"', $out);
        $this->assertStringContainsString('│ broken │', Ansi::strip(MermaidTransformer::transform($markdown, true, 80)));
    }

    public function testTheKindsLovelyMermaidAddedAreDrawn(): void
    {
        $out = Ansi::strip(MermaidTransformer::transform("```mermaid\npie\n  title Pets\n  \"Dogs\" : 4\n```", false, 80));

        $this->assertStringContainsString('Pets', $out);
        $this->assertStringContainsString('Dogs', $out);
        $this->assertStringContainsString('████████████████████', $out);
        $this->assertStringNotContainsString('```mermaid', $out);
    }

    public function testRolesTakeTheThemesColours(): void
    {
        $theme = Themes::theme();
        $out = MermaidTransformer::transform("```mermaid\nflowchart LR\n  A --> B\n```", false, 80);

        $this->assertStringContainsString("\x1b[" . substr($theme->getFgAnsi('borderMuted'), 2, -1) . 'm┌', $out);
        $this->assertStringContainsString("\x1b[" . substr($theme->getFgAnsi('accent'), 2, -1) . 'm', $out);
    }

    public function testAuthorClassStylesWinOverTheTheme(): void
    {
        $out = MermaidTransformer::transform(
            "```mermaid\nflowchart LR\n  A[Hot]:::highlight --> B[Plain]\n  classDef highlight fill:#f96,stroke:#333,color:#000,font-weight:bold\n```",
            false,
            80,
        );

        $this->assertStringContainsString("\x1b[1;38;2;51;51;51;48;2;255;153;102m", $out);
        $this->assertStringContainsString("\x1b[1;38;2;0;0;0;48;2;255;153;102m", $out);
        $this->assertStringNotContainsString('```mermaid', $out);
    }

    public function testOnlyLabelsAreLinkedAndOnlyWhereTheTerminalShowsLinks(): void
    {
        $markdown = "```mermaid\nflowchart TD\n  A[Docs] --> B[Done]\n  click A \"https://example.com/docs\"\n```";
        $open = "\x1b]8;;https://example.com/docs\x1b\\";

        TerminalImage::setCapabilities(new TerminalCapabilities(null, true, true));
        $linked = MermaidTransformer::transform($markdown, false, 80);
        $this->assertSame(1, substr_count($linked, $open));
        $this->assertStringNotContainsString('```mermaid', $linked);

        TerminalImage::setCapabilities(new TerminalCapabilities(null, true, false));
        $unlinked = MermaidTransformer::transform($markdown, false, 80);
        $this->assertStringNotContainsString("\x1b]8;;", $unlinked);
        $this->assertStringNotContainsString('https://example.com/docs', $unlinked);
        $this->assertStringNotContainsString('```mermaid', $unlinked);
    }

    public function testAnUnclosedFenceRunsToTheEndAsItStreams(): void
    {
        $out = Ansi::strip(MermaidTransformer::transform("```mermaid\ngraph LR\n  A --> B", true, 80));

        $this->assertStringContainsString('│ A ├', $out);
    }

    public function testNodeWithSpacedClassAnnotationRendersWithoutWarning(): void
    {
        $src = "```mermaid\ngraph TD\n  A[\"Start\"] ::: myClass\n  B[\"End\"]:::other\n  A --> B\n```";
        $out = Ansi::strip(MermaidTransformer::transform($src, false, 80));

        $this->assertStringNotContainsString('Mermaid diagram not rendered', $out);
        $this->assertStringContainsString('Start', $out);
        $this->assertStringContainsString('End', $out);
    }
}
