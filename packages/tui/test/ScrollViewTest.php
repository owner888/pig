<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\Text;
use Pig\Tui\Layout;
use Pig\Tui\TuiError;

/** `ScrollView` on its own; the layout behaviour is upstream's `layout.test.ts`, in `LayoutTest`. */
final class ScrollViewTest extends TestCase
{
    public function testOutsideALayoutItIsJustItsChild(): void
    {
        $scroll = new ScrollView(new Text("one\ntwo", 0, 0));

        $this->assertSame(['one', 'two'], array_map('rtrim', $scroll->render(10)));
    }

    public function testAnAlwaysScrollbarReservesTheLastColumn(): void
    {
        $scroll = new ScrollView(new Text('abc', 0, 0), scrollbar: 'always');

        $this->assertSame(9, $scroll->getContentWidth(10));
        $this->assertSame(10, mb_strlen($scroll->render(10)[0]));
    }

    public function testItHasExactlyOneChild(): void
    {
        $scroll = new ScrollView(new Text('abc', 0, 0));

        $this->expectException(TuiError::class);
        $scroll->addChild(new Text('more', 0, 0));
    }

    public function testScrollingToTheStartAndEndFollowsOnlyAtTheEnd(): void
    {
        $scroll = new ScrollView(new Text(implode("\n", range(1, 10)), 0, 0), follow: 'end');
        Layout::renderLayoutFrame($scroll, 10, 3, static function (): void {
        });

        $scroll->scrollToStart();
        $this->assertSame(0, $scroll->scrollTop());
        $this->assertFalse($scroll->isFollowingEnd());

        $scroll->scrollToEnd();
        $this->assertSame(7, $scroll->scrollTop());
        $this->assertTrue($scroll->isFollowingEnd());
    }
}
