<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\CodeHighlighter;
use Pig\CodingAgent\Theme\Themes;

/** Upstream's `code-highlighter.test.ts`: a row reuses its last highlighting. */
final class CodeHighlighterTest extends TestCase
{
    use GlobalThemeFixture;

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

    public function testReusesItsLastResultUntilTheCodeOrTheThemeChanges(): void
    {
        $code = '$page = "<div>ok</div>";';
        $highlighter = new CodeHighlighter();
        $first = $highlighter->highlight($code, 'php');

        $this->assertSame(Themes::highlightCode($code, 'php'), $first);
        $this->assertSame($first, $highlighter->highlight($code, 'php'));
        $this->assertSame(Themes::highlightCode('$a = 1;', 'php'), $highlighter->highlight('$a = 1;', 'php'));
        $this->assertSame(Themes::highlightCode($code, 'js'), $highlighter->highlight($code, 'js'));

        Themes::initTheme('light');
        $light = $highlighter->highlight($code, 'php');
        $this->assertSame(Themes::highlightCode($code, 'php'), $light);
        $this->assertNotSame($first, $light, 'a new theme is highlighted again');
    }
}
