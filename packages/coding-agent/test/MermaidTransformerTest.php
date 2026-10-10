<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\MermaidTransformer;
use Pig\Tui\Ansi;

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

        $pie = "```mermaid\npie\n  \"a\" : 1\n```";
        $this->assertSame($pie, MermaidTransformer::transform($pie, false, 80), 'a kind it does not draw');

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

    public function testAnUnclosedFenceRunsToTheEndAsItStreams(): void
    {
        $out = Ansi::strip(MermaidTransformer::transform("```mermaid\ngraph LR\n  A --> B", true, 80));

        $this->assertStringContainsString('│ A ├', $out);
    }
}
