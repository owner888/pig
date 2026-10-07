<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\ThemedText;
use Pig\CodingAgent\Theme\Themes;

/** Upstream's `themed-text.test.ts`. */
final class ThemedTextTest extends TestCase
{
    use GlobalThemeFixture;

    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
    }

    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
    }

    public function testBuildsLazilyAndRebuildsWithTheCurrentThemeAfterInvalidation(): void
    {
        Themes::initTheme('dark');
        $builds = 0;
        $text = new ThemedText(static function () use (&$builds): string {
            $builds++;

            return Themes::theme()->fg('accent', 'hello');
        });
        $this->assertSame(0, $builds);
        $dark = implode('', $text->render(20));

        Themes::initTheme('light');
        $this->assertSame($dark, implode('', $text->render(20)));
        $text->invalidate();
        $this->assertStringContainsString(Themes::theme()->getFgAnsi('accent'), implode('', $text->render(20)));
        $this->assertSame(2, $builds);
    }
}
