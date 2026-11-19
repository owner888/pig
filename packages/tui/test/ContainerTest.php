<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\ChildLines;
use Pig\Tui\Component;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\WindowedContainer;
use Pig\Tui\Container;
use Pig\Tui\MouseHandler;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;

/**
 * Upstream's `container.test.ts` (#11968): copying every transcript row on every frame made long
 * fullscreen transcripts expensive. A PHP array has no identity to assert, so what is checked is
 * that `ChildLines` hands back the instance it was given — and with it the same array — and that
 * the rows are right after a change.
 */
final class ContainerTest extends TestCase
{
    public function testHandsBackThePreviousLinesWhileChildrenAreUnchanged(): void
    {
        $text = new Text('hello', 0, 0);
        $children = [$text, new Spacer(1)];

        $first = ChildLines::render($children, 20, null);
        $this->assertSame($first, ChildLines::render($children, 20, $first));

        $text->setText('changed');
        $changed = ChildLines::render($children, 20, $first);
        $this->assertNotSame($first, $changed);
        $this->assertSame(['changed', ''], array_map(rtrim(...), $changed->lines));
        $this->assertSame(['hello', ''], array_map(rtrim(...), $first->lines));
    }

    public function testRendersAgainWhenChildrenAreAddedOrTheWidthChanges(): void
    {
        $a = new Text('a', 0, 0);
        $first = ChildLines::render([$a], 20, null);
        $this->assertNotSame($first, ChildLines::render([$a], 10, $first));

        $container = new Container();
        $container->addChild($a);
        $container->render(20);
        $container->addChild(new Text('b', 0, 0));
        $this->assertSame(['a', 'b'], array_map(rtrim(...), $container->render(20)));
    }

    public function testNestedContainersShowAChangeDeepInside(): void
    {
        $text = new Text('hello', 0, 0);
        $inner = new Container();
        $inner->addChild($text);
        $inner->addChild(new Spacer(1));
        $outer = new Container();
        $outer->addChild($inner);
        $this->assertSame(['hello', ''], array_map(rtrim(...), $outer->render(20)));

        $text->setText('changed');
        $this->assertSame(['changed', ''], array_map(rtrim(...), $outer->render(20)));
    }

    public function testABoxPaintsAgainOnlyWhenItsChildrenChange(): void
    {
        $text = new Text('boxed', 0, 0);
        $painted = 0;
        $box = new Box(1, 0, static function (string $line) use (&$painted): string {
            $painted++;

            return $line;
        });
        $box->addChild($text);
        $this->assertSame(' boxed', rtrim($box->render(20)[0]));
        $paintedOnce = $painted;

        $box->render(20);
        // One call samples the background for a theme change; nothing is painted again.
        $this->assertSame($paintedOnce + 1, $painted);

        $text->setText('next');
        $this->assertSame(' next', rtrim($box->render(20)[0]));
        $this->assertGreaterThan($paintedOnce + 1, $painted);
    }

    public function testATranscriptHandsBackTheSameRowsWhenNoChildChanged(): void
    {
        $text = new Text('one', 0, 0);
        $transcript = new class () extends WindowedContainer {
            public function lastChildLines(): ?ChildLines
            {
                return $this->childLines;
            }
        };
        $transcript->addChild($text);
        $transcript->addChild(new Text('two', 0, 0));

        $transcript->renderWindow(20, null, 5);
        $first = $transcript->lastChildLines();
        $transcript->renderWindow(20, null, 5);
        $this->assertSame($first, $transcript->lastChildLines());

        $text->setText('uno');
        $this->assertSame(['uno', 'two'], array_map(rtrim(...), $transcript->renderWindow(20, null, 5)));
        $this->assertNotSame($first, $transcript->lastChildLines());
    }

    public function testDispatchesMouseEventsByTheRenderedChildHeights(): void
    {
        $hits = [];
        $target = static function (string $name, int $lines) use (&$hits): Component {
            return new class ($name, $lines, $hits) implements Component, MouseHandler {
                /** @param list<string> $hits */
                public function __construct(private readonly string $name, private readonly int $lines, private array &$hits)
                {
                }

                public function render(int $width): array
                {
                    return array_fill(0, $this->lines, $this->name);
                }

                public function invalidate(): void
                {
                }

                public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
                {
                    $this->hits[] = $this->name;

                    return new TuiMouseEventResult(handled: true);
                }
            };
        };
        $container = new Container();
        $container->addChild($target('first', 2));
        $container->addChild($target('second', 1));
        $container->render(20);

        $container->handleMouse(new TuiMouseEvent('wheel', 'none', 0, 2, 0, 2, 20, 3, wheelDelta: 1));
        $container->handleMouse(new TuiMouseEvent('wheel', 'none', 0, 1, 0, 1, 20, 3, wheelDelta: 1));

        $this->assertSame(['second', 'first'], $hits);
    }
}
