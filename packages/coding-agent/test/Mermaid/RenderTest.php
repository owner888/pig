<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Mermaid;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Mermaid\Ansi;
use Pig\CodingAgent\Mermaid\Canvas;
use Pig\CodingAgent\Mermaid\ClassStyle;
use Pig\CodingAgent\Mermaid\Diagrams\ClassDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\Er;
use Pig\CodingAgent\Mermaid\Diagrams\Flowchart;
use Pig\CodingAgent\Mermaid\Diagrams\SequenceDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\State;
use Pig\CodingAgent\Mermaid\Layout;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Mermaid;
use Pig\CodingAgent\Mermaid\MermaidArt;
use Pig\CodingAgent\Mermaid\Node;
use Pig\CodingAgent\Mermaid\SourceBox;
use Pig\CodingAgent\Mermaid\Span;

/**
 * Upstream's unit tests (`lovely-mermaid`'s `render`, `spans`, `ansi`, `parse` and `layout` tests,
 * v0.3.3): what the golden files cannot show — roles, author classes, links, classDefs, the ANSI
 * merge of theme and class style, caps, streaming stability, control characters and the model
 * under the art.
 */
final class RenderTest extends TestCase
{
    private const string ESC = "\x1b";

    private static function art(string $src): MermaidArt
    {
        $art = Mermaid::render($src);
        self::assertNotNull($art, $src);

        return $art;
    }

    /** @return list<Span> every span of every row */
    private static function spans(MermaidArt $art): array
    {
        return array_merge(...$art->styled);
    }

    private static function stripSgr(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    // ------------------------------------------------------- classes and classDefs

    public function testAuthorClassesReachTheSpansAndClassDefsTheArt(): void
    {
        $art = self::art("flowchart TD\n A[Hot node]:::hot --> B\n class B cold\n classDef hot fill:#f96,stroke:#333\n classDef cold fill:#69f");

        $this->assertSame(['hot' => ['fill' => '#f96', 'stroke' => '#333'], 'cold' => ['fill' => '#69f']], $art->classDefs);
        $spans = self::spans($art);
        $this->assertNotEmpty(array_filter($spans, static fn (Span $s): bool => str_contains($s->text, 'Hot node') && ($s->classes[0] ?? null) === 'hot'));
        $this->assertNotEmpty(array_filter($spans, static fn (Span $s): bool => str_contains($s->text, 'B') && ($s->classes[0] ?? null) === 'cold'));
        // Cells the node did not paint carry no classes.
        $this->assertEmpty(array_filter($spans, static fn (Span $s): bool => $s->role === 'edge' && $s->classes !== null));
    }

    public function testStateAndClassDiagramsCarryAuthorClasses(): void
    {
        $art = self::art("stateDiagram-v2\n A --> B\n class A warning\n classDef warning fill:#f00");
        $this->assertSame(['warning' => ['fill' => '#f00']], $art->classDefs);
        $this->assertNotEmpty(array_filter(self::spans($art), static fn (Span $s): bool => str_contains($s->text, 'A') && in_array('warning', $s->classes ?? [], true)));

        $state = self::art("stateDiagram-v2\n [*] --> Still:::quiet\n Still --> Moving\n classDef quiet fill:#eee");
        $this->assertSame(['quiet' => ['fill' => '#eee']], $state->classDefs);
        $this->assertNotEmpty(array_filter(self::spans($state), static fn (Span $s): bool => str_contains($s->text, 'Still') && in_array('quiet', $s->classes ?? [], true)));

        $class = self::art("classDiagram\n class Animal:::hot\n Animal <|-- Dog");
        $this->assertNotEmpty(array_filter(self::spans($class), static fn (Span $s): bool => str_contains($s->text, 'Animal') && in_array('hot', $s->classes ?? [], true)));
    }

    public function testTheSourceBoxCarriesNoClassDefsOrWarnings(): void
    {
        $box = SourceBox::render("gantt\n title Plan", 80);

        $this->assertSame([], $box->classDefs);
        $this->assertSame([], $box->warnings);
    }

    // ----------------------------------------------------------- kind and refusal

    public function testBlankSourceDrawsNothing(): void
    {
        $this->assertNull(Mermaid::render("   \n  "));
    }

    public function testTheKindSeparatesAnUnsupportedTypeFromAMalformedOne(): void
    {
        $malformed = "stateDiagram-v2\n bad one here\n bad two here";
        $this->assertNull(Mermaid::render($malformed));
        $this->assertSame('state', Mermaid::kind($malformed));

        $unsupported = "gantt\n title Plan";
        $this->assertNull(Mermaid::render($unsupported));
        $this->assertNull(Mermaid::kind($unsupported));
    }

    public function testTheKindReadsTheHeaderWithoutParsingTheBody(): void
    {
        $this->assertSame('flowchart', Mermaid::kind("graph TD\n A --> B"));
        $this->assertSame('flowchart', Mermaid::kind('flowchart LR'));
        $this->assertSame('class', Mermaid::kind('classDiagram'));
        $this->assertSame('er', Mermaid::kind('erDiagram'));
        $this->assertSame('sequence', Mermaid::kind('sequenceDiagram'));
        $this->assertSame('pie', Mermaid::kind('pie title x'));
        $this->assertSame('mindmap', Mermaid::kind('mindmap'));
        $this->assertSame('timeline', Mermaid::kind('timeline'));
        $this->assertSame('gitgraph', Mermaid::kind('gitGraph LR:'));
        $this->assertSame('journey', Mermaid::kind('journey'));
        $this->assertSame('kanban', Mermaid::kind('kanban'));
        $this->assertNull(Mermaid::kind('gantt'));
        $this->assertNull(Mermaid::kind("stateDiagramFoo\n A --> B"));
        $this->assertSame('class', Mermaid::kind("classDiagram-v2\n A --> B"));
        $this->assertNull(Mermaid::kind(''));
    }

    // --------------------------------------------------------------------- streaming

    /** How many times the display would change between art and source box. */
    private static function flips(string $src, callable $frame): int
    {
        $seen = [];

        for ($n = 4; $n < strlen($src); $n += 4) {
            $seen[] = $frame(substr($src, 0, $n));
        }

        $seen[] = $frame($src);
        $flips = 0;

        for ($i = 1; $i < count($seen); $i++) {
            if ($seen[$i] !== $seen[$i - 1]) {
                $flips++;
            }
        }

        return $flips;
    }

    private const string STREAMED = "flowchart TD\n"
        . "  A[User submits login form] --> B{Credentials valid?}\n"
        . "  B -->|yes| C[Issue JWT access token]\n"
        . "  B -->|no| D[Return 401 Unauthorized]\n"
        . '  C --> E[Set refresh cookie]';

    public function testAStreamingFlowchartNeverStopsBeingDrawable(): void
    {
        // One transition, box to art, and never back.
        $this->assertSame(1, self::flips(self::STREAMED, static fn (string $s): bool => Mermaid::render($s) !== null));
    }

    public function testGatingOnWarningsIsWhatMakesAStreamingDiagramFlicker(): void
    {
        $gated = self::flips(self::STREAMED, static function (string $s): bool {
            $art = Mermaid::render($s);

            return $art !== null && $art->warnings === [];
        });

        $this->assertGreaterThan(5, $gated);
    }

    public function testAStreamingSequenceDiagramNeverStopsBeingDrawable(): void
    {
        $src = "sequenceDiagram\n  participant U as User\n  participant W as Web App\n  U->>W: Submit login\n  W-->>U: Set session cookie";

        $this->assertSame(1, self::flips($src, static fn (string $s): bool => Mermaid::render($s) !== null));
    }

    // -------------------------------------------------------------------------- caps

    public function testAnAdversarialChainDrawsTruncatedAtTheNodeCap(): void
    {
        $src = "graph TD\n";

        for ($i = 0; $i < 10_000; $i++) {
            $src .= ' N' . $i . ' --> N' . ($i + 1) . "\n";
        }

        $art = self::art($src);
        $this->assertStringContainsString('N100', implode("\n", $art->plain));
        // The one truncation warning covers the cap; a parse failure the cap caused is not the
        // statement's fault.
        $this->assertCount(1, $art->warnings);
        $this->assertStringStartsWith('diagram truncated:', $art->warnings[0]);
    }

    public function testASingleStatementChainDrawsTruncatedAtTheNodeCap(): void
    {
        $src = "graph LR\n ";

        for ($i = 0; $i < 10_000; $i++) {
            $src .= "N{$i}-->";
        }

        $art = self::art("{$src}N10000");
        $this->assertNotEmpty(array_filter($art->warnings, static fn (string $w): bool => str_starts_with($w, 'diagram truncated:')));
    }

    public function testStateAndSequenceDiagramsDrawTruncatedAtTheirCaps(): void
    {
        $state = "stateDiagram-v2\n";
        $sequence = "sequenceDiagram\n";

        for ($i = 0; $i < 600; $i++) {
            $state .= " S{$i} --> S" . ($i + 1) . "\n";
            $sequence .= " A->>B: msg {$i}\n";
        }

        foreach ([$state, $sequence] as $src) {
            $this->assertNotEmpty(array_filter(self::art($src)->warnings, static fn (string $w): bool => str_starts_with($w, 'diagram truncated:')));
        }
    }

    // ------------------------------------------------------------------------- width

    public function testAnOverWideDiagramStillDrawsAndReportsItsWidth(): void
    {
        $src = "flowchart LR\n A[aaaaaaaaaaaaaaaaaaaa] --> B[bbbbbbbbbbbbbbbbbbbb] --> C[cccccccccccccccccccc]";
        $art = self::art($src);

        $this->assertGreaterThan(40, $art->width);
        $this->assertStringContainsString('▶', implode("\n", $art->plain));
        $box = SourceBox::render($src, 40);
        $this->assertStringContainsString('mermaid: flowchart', implode("\n", $box->plain));
        $this->assertLessThanOrEqual(strlen($src), $box->width);
    }

    // -------------------------------------------------------------------- source box

    public function testTheSourceBoxWrapsLongLinesToTheWidth(): void
    {
        $out = SourceBox::render("gantt\n title a very long line that should wrap inside the source box nicely", 40)->plain;

        foreach ($out as $line) {
            $this->assertLessThanOrEqual(40, Measure::width($line));
        }

        foreach (array_slice($out, 1, -1) as $line) {
            $this->assertTrue(str_starts_with($line, '│') && str_ends_with($line, '│'));
        }

        $this->assertStringContainsString('nicely', implode("\n", $out));
    }

    public function testTabsInTheSourceBoxExpandToSpaces(): void
    {
        $box = SourceBox::render("flowchart TD\n\tA --> B");

        foreach ($box->plain as $row) {
            $this->assertStringNotContainsString("\t", $row);
        }

        $this->assertCount(1, array_unique(array_map(Measure::width(...), array_slice($box->plain, 1, -1))));
    }

    // ------------------------------------------------------------ control characters

    public function testControlCharactersNeverReachTheOutput(): void
    {
        foreach (["graph TD\n A[x" . Canvas::CONT . 'y] --> B', "graph TD\n A[" . self::ESC . '[31mRED] --> B'] as $src) {
            $out = implode("\n", self::art($src)->plain);
            $this->assertStringNotContainsString(Canvas::CONT, $out);
            $this->assertStringNotContainsString(self::ESC, $out);
        }

        $box = implode("\n", SourceBox::render("gantt\n title " . self::ESC . "]8;;http://example\x07link", 80)->plain);
        $this->assertStringNotContainsString(self::ESC, $box);
        $this->assertStringNotContainsString("\x07", $box);
        $this->assertStringContainsString('link', $box);
    }

    public function testAControlCharacterInALabelDoesNotShrinkItsBox(): void
    {
        $lines = self::art("graph TD\n A[x" . Canvas::CONT . 'y] --> B')->plain;

        $this->assertSame('┌────┐', $lines[0]);
        $this->assertSame(Measure::width($lines[0]), Measure::width($lines[1]));
    }

    public function testALabelTabPaintsAsSpaces(): void
    {
        foreach (["pie\n\"a\tb\" : 1", "timeline\n2020 : ev\tent", "mindmap\nroot\n  a\tb", "gitGraph\ncommit id: \"a\tb\"", "flowchart LR\n A[\"a\tb\"] --> B"] as $src) {
            $this->assertStringNotContainsString("\t", implode("\n", self::art($src)->plain), $src);
        }
    }

    public function testASourceOfOnlyControlCharactersIsBlank(): void
    {
        $this->assertNull(Mermaid::render(Canvas::CONT . self::ESC));
    }

    public function testTheKindStripsControlCharactersAsRenderDoes(): void
    {
        $src = "\x01flowchart TD\n A --> B";

        $this->assertNotNull(Mermaid::render($src));
        $this->assertSame('flowchart', Mermaid::kind($src));
    }

    public function testAFrontmatterTitleCarriesTheTitleRole(): void
    {
        $art = self::art("---\ntitle: Order flow\n---\nflowchart TD\n A --> B");
        $first = $art->styled[0];

        $this->assertEquals(new Span('Order flow', 'title'), $first[count($first) - 1]);
    }

    // ----------------------------------------------------------------------- links

    public function testClickAndLinkStatementsLandOnTheSpansAsLinks(): void
    {
        $flowchart = self::spans(self::art("flowchart TD\n A[Docs] --> B\n click A \"https://example.com/docs\" \"open\""));
        $this->assertNotEmpty(array_filter($flowchart, static fn (Span $s): bool => str_contains($s->text, 'Docs') && $s->href === 'https://example.com/docs'));
        // The whole box is the link, blank interior included; B carries none.
        $this->assertNotEmpty(array_filter($flowchart, static fn (Span $s): bool => $s->href !== null && $s->role === 'border'));
        $this->assertEmpty(array_filter($flowchart, static fn (Span $s): bool => str_contains($s->text, 'B') && $s->href !== null));

        $class = self::spans(self::art("classDiagram\n class Agent {\n +run()\n }\n link Agent \"https://example.com\""));
        $this->assertNotEmpty(array_filter($class, static fn (Span $s): bool => str_contains($s->text, 'Agent') && $s->href === 'https://example.com'));

        // Callback forms carry nothing a terminal can open.
        $callback = self::spans(self::art("flowchart TD\n A --> B\n click A call doIt() \"tip\""));
        $this->assertEmpty(array_filter($callback, static fn (Span $s): bool => $s->href !== null));
    }

    public function testLinkedSpansAreWrappedInOsc8AndStripBackToPlain(): void
    {
        $art = self::art("flowchart TD\n A[Docs] --> B\n click A \"https://example.com\"");
        $out = implode("\n", Ansi::toAnsi($art));
        $open = self::ESC . ']8;;https://example.com' . self::ESC . '\\';

        $this->assertStringContainsString($open, $out);
        $this->assertSame(implode("\n", $art->plain), self::stripSgr(str_replace([$open, self::ESC . ']8;;' . self::ESC . '\\'], '', $out)));
    }

    // ------------------------------------------------------------------------ spans

    public function testARowEndingInNonAsciiWhiteSpaceStillRebuilds(): void
    {
        $canvas = new Canvas(6, 1);
        Canvas::drawText($canvas, "ab\u{a0}", 0, 0, 'text');
        ['plain' => $plain, 'styled' => $styled] = $canvas->toLines();

        $this->assertSame("ab\u{a0}", $plain[0]);
        $this->assertSame($plain[0], implode('', array_map(static fn (Span $s): string => $s->text, $styled[0])));
    }

    public function testAdjacentSpansNeverRepeatARoleAndTheArtHasNoBlankEdges(): void
    {
        $sources = [
            "graph TD\n A[Start] -->|go| B[End]\n B --> A",
            "flowchart LR\n A --> B --> C",
            "flowchart BT\n A[first] --> B[second]",
            "flowchart RL\n A[first] --> B[second]",
            "graph TD\n A[日本語ab] --> B[x]",
            "graph TD\n A -->|again| A\n A --> B",
            "graph TD\n S --> one\n subgraph one [Group]\n A --> B\n end",
            "stateDiagram-v2\n [*] --> Idle\n Idle --> Run: start\n Run --> [*]",
            "classDiagram\n class Animal {\n +int age\n +run()\n }\n Animal <|-- Duck",
            "erDiagram\n CUSTOMER ||--o{ ORDER : places\n CUSTOMER {\n string name\n }",
            "sequenceDiagram\n A->>B: hi\n loop x\n B-->>A: ok\n end\n Note over A,B: n",
            "pie title Pets\n \"Dogs\" : 3\n \"Cats\" : 1",
            "mindmap\n  root((App))\n    UI\n      Theme\n    DB",
            "timeline\n title T\n section S\n 2023 : one : two",
            "gitGraph\n commit id: \"a\"\n branch dev\n commit id: \"b\"\n checkout main\n merge dev",
        ];

        foreach ($sources as $src) {
            $art = self::art($src);
            $this->assertNotSame('', $art->plain[0], $src);
            $this->assertNotSame('', $art->plain[count($art->plain) - 1], $src);

            foreach ($art->styled as $row) {
                for ($i = 1; $i < count($row); $i++) {
                    $this->assertNotSame($row[$i - 1]->role, $row[$i]->role, $src);
                }
            }
        }
    }

    public function testABoxOutlineIsASingleBorderSpan(): void
    {
        $art = self::art("graph TD\n A[Start] --> B[End]");
        $top = null;

        foreach ($art->plain as $i => $line) {
            if (str_contains($line, '┌')) {
                $top = $art->styled[$i];

                break;
            }
        }

        $this->assertEquals([new Span('┌───────┐', 'border')], array_values(array_filter($top ?? [], static fn (Span $s): bool => $s->role !== 'none')));
    }

    // ------------------------------------------------------------------------- ANSI

    public function testAnEmptyThemeLeavesTheTextAsItIs(): void
    {
        $art = self::art("graph TD\n A --> B");

        $this->assertSame($art->plain, Ansi::toAnsi($art, []));
    }

    public function testStyledRunsAreWrappedInSgrSequences(): void
    {
        $art = self::art("graph TD\n A --> B");
        $out = implode("\n", Ansi::toAnsi($art, ['border' => '2']));

        $this->assertStringContainsString(self::ESC . '[2m', $out);
        $this->assertStringContainsString(self::ESC . '[0m', $out);
        $this->assertSame(implode("\n", $art->plain), self::stripSgr($out));
    }

    public function testClassStylesResolveNormalisedAndMergedInOrder(): void
    {
        $defs = [
            'hot' => ['fill' => '#f96', 'stroke' => 'rgb(51, 51, 51)', 'font-weight' => 'bold'],
            'cold' => ['fill' => 'lightblue', 'stroke-width' => '4px', 'wobble' => 'yes'],
        ];

        $this->assertEquals(['fill' => '#ff9966', 'stroke' => '#333333', 'bold' => true], ClassStyle::resolve(['hot'], $defs));
        // Later classes win; unknown properties and unknown classes are ignored.
        $this->assertEquals(['fill' => '#add8e6', 'stroke' => '#333333', 'bold' => true], ClassStyle::resolve(['hot', 'cold', 'ghost'], $defs));
        $this->assertNull(ClassStyle::resolve(['ghost'], $defs));
        $this->assertNull(ClassStyle::resolve(null, $defs));
        // A class whose only properties a terminal cannot show resolves to nothing.
        $this->assertNull(ClassStyle::resolve(['thin'], ['thin' => ['stroke-width' => '1px']]));
    }

    public function testTheContrastingForegroundIsTheReadableOne(): void
    {
        $this->assertSame('#000000', ClassStyle::contrastOn('#ffffe0'));
        $this->assertSame('#ffffff', ClassStyle::contrastOn('#4b0082'));
    }

    public function testClassStylesApplyOverTheRoleTheme(): void
    {
        $art = self::art("flowchart TD\n A[Hot]:::hot --> B\n classDef hot fill:#ff9966,color:#000000");
        $out = implode("\n", Ansi::toAnsi($art, ['border' => '2']));

        $this->assertStringContainsString('48;2;255;153;102', $out);
        $this->assertStringContainsString(self::ESC . '[2m', $out);
        $this->assertSame(implode("\n", $art->plain), self::stripSgr($out));

        // A fill without a colour gets a contrasting foreground.
        $pale = self::art("flowchart TD\n A[Pale]:::pale --> B\n classDef pale fill:#ffffe0");
        $this->assertStringContainsString('38;2;0;0;0', implode("\n", Ansi::toAnsi($pale)));

        // A bold-only class bolds the themed look instead of replacing it.
        $em = implode("\n", Ansi::toAnsi(self::art("flowchart TD\n A[Em]:::em --> B\n classDef em font-weight:bold"), ['border' => '2;36']));
        $this->assertStringContainsString(self::ESC . '[1;2;36m', $em);
        $this->assertStringContainsString(self::ESC . '[2;36m', $em);
    }

    public function testTheAnsiOutputIsUpstreamsAcrossTheMergeMatrix(): void
    {
        // Upstream's snapshot (`ansi.test.ts`): theme fallback, fill with a contrasting
        // foreground, stroke on borders, bold-only merging with the theme, OSC 8 round links.
        $src = "flowchart TD\n  A[Styled]:::hot --> B[Plain]\n  C[Linked] --> B\n  click C \"https://example.com\"\n"
            . "  classDef hot fill:#ff0000,color:#ffffff\n  classDef boldonly font-weight:bold\n  B:::boldonly\n";
        $expected = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/mermaid/ansi.json'), true);

        $this->assertSame($expected, Ansi::toAnsi(self::art($src)));
    }

    // ------------------------------------------------------------------------ model

    public function testEntitiesDecodeAtEverySink(): void
    {
        // Entities contain `;`, which the statement splitter takes as a separator, so they only
        // reach a sink intact inside quotes.
        $state = (new State())->parseState("stateDiagram-v2\n  state \"work &lt;job&gt;\" as J\n  Idle --> Run: \"on &lt;go&gt;\"\n  Run: \"d &lt;e&gt;\"");
        $this->assertNotNull($state);
        $labels = implode("\n", array_map(static fn (Node $n): string => $n->label, $state->nodes));
        $edges = implode("\n", array_map(static fn ($e): string => $e->label ?? '', $state->edges));
        $this->assertStringContainsString('work <job>', $labels);
        $this->assertStringContainsString('d <e>', $labels);
        $this->assertStringContainsString('on <go>', $edges);
        $this->assertStringNotContainsString('&lt;', $labels . $edges);

        $class = (new ClassDiagram())->parseClass("classDiagram\n  A --> B : \"uses &lt;X&gt;\"");
        $this->assertStringContainsString('uses <X>', $class?->edges[0]->label ?? '');
        $this->assertStringNotContainsString('&lt;', $class?->edges[0]->label ?? '');

        $seq = (new SequenceDiagram())->parseSequence("sequenceDiagram\n  A->>B: \"call &lt;svc&gt;\"\n  Note over A,B: \"memo &lt;o&gt;\"\n  alt \"c &lt;x&gt;\"\n    A->>B: ok\n  end");
        $this->assertNotNull($seq);
        $texts = array_map(static fn (array $item): string => "{$item['kind']}: " . ($item['text'] ?? ''), $seq->items);
        $this->assertContains('message: "call <svc>"', $texts);
        $this->assertContains('note: "memo <o>"', $texts);
        $this->assertContains('divider: alt "c <x>"', $texts);

        // Class members and ER attributes have no clean quoted form, so their decoding is
        // exercised directly.
        $member = new Node('M', 'rect', [['M'], [], []]);
        ClassDiagram::pushMember($member, '+run &lt;R&gt;');
        $this->assertSame(['+run <R>'], $member->sections[1] ?? null);
        $attr = new Node('A', 'rect', [['A'], []]);
        Er::pushErAttribute($attr, 'string &lt;pk&gt;');
        $this->assertSame(['string <pk>'], $attr->sections[1] ?? null);
    }

    public function testClassDefFunctionValuesKeepTheirCommasAndClassIdsSurviveASpace(): void
    {
        $graph = (new Flowchart())->parseGraph("graph TD\n A\n classDef c fill:rgb(255, 0, 0),stroke:#333");
        $this->assertSame(['fill' => 'rgb(255, 0, 0)', 'stroke' => '#333'], $graph?->classDefs['c']);

        $graph = (new Flowchart())->parseGraph("graph TD\n A --> B\n class A, B warn");
        $this->assertSame([['warn'], ['warn']], array_map(static fn (Node $n): ?array => $n->classes, $graph->nodes ?? []));
    }

    public function testATagBeforeADescriptionColonStaysATag(): void
    {
        $graph = (new State())->parseState("stateDiagram-v2\n S1:::hot : waiting\n A --> B:::cold : go");
        $this->assertNotNull($graph);
        $s1 = $graph->nodes[$graph->find('S1') ?? -1];

        $this->assertSame('waiting', $s1->label);
        $this->assertSame(['hot'], $s1->classes);
        $this->assertSame(['cold'], $graph->nodes[$graph->find('B') ?? -1]->classes);
        $this->assertSame('go', $graph->edges[0]->label);
    }

    public function testAnActivationAtTheItemCapIsDroppedWithTheItem(): void
    {
        $seq = (new SequenceDiagram())->parseSequence("sequenceDiagram\n" . implode("\n", array_fill(0, 512, 'A->>B: m')) . "\nA->>+B: over");
        $this->assertNotNull($seq);

        $this->assertSame([], $seq->activations);
        $this->assertStringContainsString('truncated', $seq->warnings[count($seq->warnings) - 1]);
    }

    public function testClassDiagramClassAssignments(): void
    {
        $graph = (new ClassDiagram())->parseClass("classDiagram\n cssClass \"Agent,Tool\" hot\n class Agent {\n +run()\n }\n class Agent focus\n Tool <|-- Agent");
        $this->assertNotNull($graph);

        $this->assertSame(['hot', 'focus'], $graph->nodes[$graph->find('Agent') ?? -1]->classes);
        $this->assertSame(['hot'], $graph->nodes[$graph->find('Tool') ?? -1]->classes);
        $this->assertSame([], $graph->warnings);
    }

    public function testATagGluedToALinkKeepsTheLink(): void
    {
        $graph = (new Flowchart())->parseGraph("graph TD\n A:::x-->B");
        $this->assertNotNull($graph);

        $this->assertCount(1, $graph->edges);
        $this->assertSame(['x'], $graph->nodes[0]->classes);
        $this->assertSame([], $graph->warnings);
    }

    // ----------------------------------------------------------------------- layout

    /** @return array{ranks: list<int>, byRank: list<list<int>>, pos: array<int, int>, at: \Closure(string): int, crossings: int} */
    private static function orderedRanks(string $src): array
    {
        $graph = (new Flowchart())->parseGraph($src);
        self::assertNotNull($graph);
        $ranks = Layout::computeRanks($graph);
        $byRank = array_fill(0, max($ranks) + 1, []);

        foreach ($ranks as $i => $rank) {
            $byRank[$rank][] = $i;
        }

        Layout::orderRanks($byRank, $graph->edges, $ranks);
        $pos = array_fill(0, count($graph->nodes), 0);

        foreach ($byRank as $row) {
            foreach ($row as $i => $v) {
                $pos[$v] = $i;
            }
        }

        return [
            'ranks' => $ranks,
            'byRank' => $byRank,
            'pos' => $pos,
            'at' => static fn (string $id): int => $graph->find($id) ?? -1,
            'crossings' => Layout::countCrossings($graph->edges, $ranks, $pos),
        ];
    }

    public function testRanksIgnoreBackEdges(): void
    {
        ['ranks' => $ranks, 'at' => $at] = self::orderedRanks("graph TD\n A-->B\n B-->C\n C-->A");

        $this->assertSame([0, 1, 2], [$ranks[$at('A')], $ranks[$at('B')], $ranks[$at('C')]]);
    }

    public function testOrderingRemovesAnAvoidableCrossingAndKeepsACrossingFreeOrder(): void
    {
        ['pos' => $pos, 'at' => $at, 'crossings' => $crossings] = self::orderedRanks("graph TD\n C[ccc]\n D[ddd]\n A --> D\n B --> C");
        $this->assertSame(0, $crossings);
        $this->assertLessThan($pos[$at('C')], $pos[$at('D')]);

        ['byRank' => $byRank, 'at' => $at, 'crossings' => $crossings] = self::orderedRanks("graph TD\n A --> C\n B --> D");
        $this->assertSame([[$at('A'), $at('B')], [$at('C'), $at('D')]], $byRank);
        $this->assertSame(0, $crossings);

        $this->assertSame(0, self::orderedRanks("graph TD\n X[x]\n Y[y]\n A --> Y\n B --> X\n X --> Q\n Y --> P\n P[p]\n Q[q]")['crossings']);
    }
}
