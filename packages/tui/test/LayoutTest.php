<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Components\HStack;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\StackEntry;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\VStack;
use Pig\Tui\Layout;
use Pig\Tui\LayoutFrame;

/** Upstream's `layout.test.ts` ("viewport layout"), less the Kitty-image and billion-line cases. */
final class LayoutTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function visibleLines(array $lines): array
    {
        return array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $lines);
    }

    private static function frame(Component $root, int $width, int $height): LayoutFrame
    {
        return Layout::renderLayoutFrame($root, $width, $height, static function (): void {
        });
    }

    /** @param Closure(int): list<string> $render */
    private static function component(\Closure $render): Component
    {
        return new class ($render) implements Component {
            public function __construct(private readonly \Closure $render)
            {
            }

            #[\Override]
            public function render(int $width): array
            {
                return ($this->render)($width);
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
    }

    public function testAllocatesVerticalGrowSpaceDeterministically(): void
    {
        $frame = self::frame(new VStack([
            new StackEntry(new Text('top', 0, 0), basis: 1, shrink: 0),
            new StackEntry(new Text('body', 0, 0), basis: 0, grow: 1),
        ]), 10, 4);

        $this->assertSame([1, 3], array_map(static fn ($child): int => $child->rect->height, $frame->root->children));
        $this->assertSame(['top', 'body', '', ''], self::visibleLines($frame->lines));
    }

    public function testDoesNotRenderFixedBasisScrollContentDuringStackMeasurement(): void
    {
        $renderCount = 0;
        $transcript = new ScrollView(self::component(static function () use (&$renderCount): array {
            $renderCount++;

            return ['one', 'two', 'three'];
        }));
        self::frame(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1),
            new StackEntry(new Text('dock', 0, 0), basis: 'auto'),
        ]), 10, 3);

        $this->assertSame(1, $renderCount);
    }

    public function testPaintsOnlyClippedRowsFromLargeScrollContent(): void
    {
        $lines = array_fill(0, 100000, '');
        $lines[99996] = 'before';
        $lines[99997] = 'visible 1';
        $lines[99998] = 'visible 2';
        $lines[99999] = 'visible 3';
        $transcript = new ScrollView(self::component(static fn (): array => $lines), follow: 'end');

        $this->assertSame(['visible 1', 'visible 2', 'visible 3'], self::visibleLines(self::frame($transcript, 10, 3)->lines));
    }

    public function testShrinksEntriesToTheirMinimumSizes(): void
    {
        $frame = self::frame(new VStack([
            new StackEntry(new Text("a1\na2\na3", 0, 0), shrink: 1, minSize: 1),
            new StackEntry(new Text("b1\nb2\nb3", 0, 0), shrink: 0),
        ]), 10, 4);

        $this->assertSame([1, 3], array_map(static fn ($child): int => $child->rect->height, $frame->root->children));
        $this->assertSame(['a1', 'b1', 'b2', 'b3'], self::visibleLines($frame->lines));
    }

    public function testIncludesNestedMinimumSizesInIntrinsicStackMeasurement(): void
    {
        $dock = new VStack([
            new Text("top1\ntop2\ntop3", 0, 0),
            new StackEntry(new Text('selector', 0, 0), minSize: 3),
            new Text('below', 0, 0),
            new StackEntry(new Text('footer', 0, 0), minSize: 1),
        ]);
        $frame = self::frame(new VStack([
            new StackEntry(new Text('body', 0, 0), basis: 0, grow: 1, minSize: 1),
            new StackEntry($dock, basis: 'auto', minSize: 1),
        ]), 10, 9);

        $this->assertSame(['body', 'top1', 'top2', 'top3', 'selector', '', '', 'below', 'footer'], self::visibleLines($frame->lines));
    }

    public function testOmitsGapsAroundInvisibleEntries(): void
    {
        $stack = new VStack([
            new Text('one', 0, 0),
            new StackEntry(new Text('hidden', 0, 0), visible: static fn (): bool => false),
            new Text('two', 0, 0),
        ], gap: 1);

        $this->assertSame(['one', '', 'two'], array_map('rtrim', $stack->render(10)));
    }

    public function testComposesHorizontalChildrenAtAllocatedWidths(): void
    {
        $frame = self::frame(new HStack([
            new StackEntry(new Text('left', 0, 0), basis: 6, shrink: 0),
            new StackEntry(new Text('right', 0, 0), basis: 6, shrink: 0),
        ]), 12, 1);

        $this->assertSame(['left  right'], self::visibleLines($frame->lines));
    }

    public function testDoesNotPaintZeroWidthHorizontalChildren(): void
    {
        $frame = self::frame(new HStack([
            new StackEntry(new Text('hidden', 0, 0), basis: 0, shrink: 0),
            new StackEntry(new Text('shown', 0, 0), basis: 0, grow: 1),
        ]), 5, 1);

        $this->assertSame(['shown'], self::visibleLines($frame->lines));
    }

    public function testTracksFollowEndStateAndReturnsUnusedScrollDelta(): void
    {
        $scrollView = new ScrollView(new Text("1\n2\n3\n4\n5\n6", 0, 0), follow: 'end', primary: true);
        self::frame($scrollView, 10, 3);
        $this->assertSame(3, $scrollView->scrollTop());
        $this->assertTrue($scrollView->isFollowingEnd());

        $this->assertSame(0, $scrollView->scrollBy(-2));
        $this->assertSame(1, $scrollView->scrollTop());
        $this->assertFalse($scrollView->isFollowingEnd());
        $this->assertSame(-2, $scrollView->scrollBy(-3));
        $this->assertSame(0, $scrollView->scrollTop());
        $this->assertSame(7, $scrollView->scrollBy(10));
        $this->assertSame(3, $scrollView->scrollTop());
        $this->assertTrue($scrollView->isFollowingEnd());
    }

    public function testRendersAProportionalGlyphScrollbarWithAnExpandedActiveThumb(): void
    {
        $sourceLines = ['abcd界', 'abcde2', 'abcde3', 'abcde4', 'abcde5', 'abcde6', 'abcde7', 'abcde8'];
        $contentBackground = "\x1b[42m";
        $trackColor = "\x1b[38;5;2m";
        $thumbColor = "\x1b[38;5;1m";
        $track = static fn (string $text): string => "{$trackColor}{$text}\x1b[39m";
        $thumb = static fn (string $text): string => "{$thumbColor}{$text}\x1b[39m";
        $content = new Text(implode("\n", $sourceLines), 0, 0, static fn (string $text): string => "{$contentBackground}{$text}\x1b[49m");
        $scrollView = new ScrollView($content, scrollbar: 'auto', scrollbarTrackStyle: $track, scrollbarThumbStyle: $thumb, scrollbarHideDelayMs: 10);
        $render = static fn (): array => self::frame($scrollView, 6, 4)->lines;
        $visible = static fn (array $lines): array => array_map(Ansi::strip(...), $lines);

        $this->assertSame(array_slice($sourceLines, 0, 4), $visible($render()));

        $scrollView->scrollBy(2);
        $lines = $render();
        $this->assertSame(['abcde│', 'abcde┃', 'abcde┃', 'abcde│'], $visible($lines));
        $this->assertSame([true, false, false, true], array_map(static fn (string $line): bool => str_contains($line, $trackColor), $lines));
        $this->assertSame([false, true, true, false], array_map(static fn (string $line): bool => str_contains($line, $thumbColor), $lines));

        $scrollView->setScrollbarActive(true);
        $lines = $render();
        $this->assertSame(['abcde│', 'abcde█', 'abcde█', 'abcde│'], $visible($lines));
        $this->assertLessThan(strrpos($lines[1], $thumbColor), strrpos($lines[1], $contentBackground));

        $scrollView->setScrollbarActive(false);
        $deadline = microtime(true) + 0.05;
        while (microtime(true) < $deadline) {
            // The queue is drained before the poll, so what keeps the poll from waiting is
            // something the drained callback queues for the next tick.
            Loop::get()->defer(static function (): void {
                Loop::get()->defer(static function (): void {
                });
            });
            Loop::get()->tick();
            usleep(2000);
        }
        $this->assertSame(array_slice($sourceLines, 2, 4), $visible($render()));

        $scrollView->scrollToEnd();
        $this->assertSame(['abcde│', 'abcde│', 'abcde┃', 'abcde┃'], $visible($render()));

        $scrollView->scrollToStart();
        $this->assertSame('abcd ┃', $visible($render())[0]);

        $followedContent = new Text(implode("\n", $sourceLines), 0, 0);
        $followed = new ScrollView($followedContent, follow: 'end', scrollbar: 'auto', scrollbarTrackStyle: $track, scrollbarThumbStyle: $thumb);
        self::frame($followed, 6, 4);
        $this->assertSame(4, $followed->scrollTop());
        $followedContent->setText(implode("\n", $sourceLines) . "\nabcde9");
        $growthFrame = self::frame($followed, 6, 4);
        $this->assertSame(5, $followed->scrollTop());
        foreach ($growthFrame->lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/[│┃]/u', Ansi::strip($line));
        }

        $fittingContent = new Text("1\n2", 0, 0);
        $automatic = new ScrollView($fittingContent, scrollbar: 'auto', scrollbarThumbStyle: $thumb);
        self::frame($automatic, 6, 4);
        $automatic->scrollBy(1);
        foreach (self::frame($automatic, 6, 4)->lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/[│┃]/u', Ansi::strip($line));
        }

        $alwaysFitting = new ScrollView($fittingContent, scrollbar: 'always', scrollbarThumbStyle: $thumb);
        $alwaysFittingFrame = self::frame($alwaysFitting, 6, 4);
        $this->assertSame(5, $alwaysFittingFrame->root->children[0]->rect->width);
        foreach ($visible($alwaysFittingFrame->lines) as $line) {
            $this->assertStringEndsWith('┃', $line);
        }

        $thumbHeightFor = static function (int $contentHeight) use ($thumb): int {
            $sized = new ScrollView(new Text(implode("\n", array_fill(0, $contentHeight, 'x')), 0, 0), scrollbar: 'auto', scrollbarThumbStyle: $thumb);
            self::frame($sized, 6, 20);
            $sized->scrollBy(1);

            return count(array_filter(self::frame($sized, 6, 20)->lines, static fn (string $line): bool => str_ends_with(Ansi::strip($line), '┃')));
        };
        $this->assertSame(19, $thumbHeightFor(21));
        $this->assertSame(10, $thumbHeightFor(40));
        $this->assertSame(4, $thumbHeightFor(100));
        $this->assertSame(2, $thumbHeightFor(400));
    }

    public function testUpdatesReservedScrollbarLayoutAtRuntime(): void
    {
        $scrollView = new ScrollView(new Text('123456', 0, 0), scrollbar: 'always');
        $render = static fn (): LayoutFrame => self::frame(new HStack([$scrollView], align: 'start'), 6, 2);
        $always = $render();
        $this->assertSame(['12345┃', '6    ┃'], self::visibleLines($always->lines));
        $this->assertSame(6, $always->root->children[0]->rect->width);
        $this->assertSame(5, $always->root->children[0]->children[0]->rect->width);

        $scrollView->setScrollbar('hidden');
        $this->assertSame(6, $render()->root->children[0]->children[0]->rect->width);
        $this->assertFalse($scrollView->isScrollbarVisible());
    }

    public function testMeasuresNestedScrollContentFromConstrainedChildGeometry(): void
    {
        $inner = new ScrollView(new Text("1\n2\n3\n4\n5\n6", 0, 0));
        $outer = new ScrollView(new VStack([new StackEntry($inner, basis: 2), new Text('tail', 0, 0)]));
        self::frame($outer, 10, 2);

        $this->assertSame(2, $inner->viewportHeight());
        $this->assertSame(9, $outer->scrollBy(10));
        $this->assertSame(1, $outer->scrollTop());
    }

    public function testRebuildsGeometryAfterContentChanges(): void
    {
        $text = new Text('one', 0, 0);
        $root = new VStack([$text]);
        $first = self::frame($root, 10, 4);
        $text->setText("one\ntwo\nthree");
        $second = self::frame($root, 10, 4);

        $this->assertCount(1, $first->root->children[0]->lines);
        $this->assertCount(3, $second->root->children[0]->lines);
    }
}
