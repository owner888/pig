<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\TuiMainScreen;
use PigMcp\McpManagerView;

/** The `/mcp` manager view's colours follow a theme change, as upstream's proxy `theme` makes them. */
final class McpManagerViewTest extends TestCase
{
    use GlobalThemeFixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();

        if (!class_exists(McpManagerView::class, false)) {
            require dirname(__DIR__, 4) . '/extensions/pig-mcp/McpManagerView.php';
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        Loop::reset();
    }

    public function testAStatusScreenRecoloursOnAThemeChange(): void
    {
        $view = new McpManagerView(new TuiMainScreen(new FakeTerminal(80, 24)));
        $view->status('MCP servers', 'Connecting…');
        $tokens = ['accent', 'muted'];
        $dark = array_map(static fn (string $token): string => Themes::theme()->getFgAnsi($token), $tokens);
        $before = implode("\n", $view->render(80));
        foreach ($dark as $escape) {
            $this->assertStringContainsString($escape, $before);
        }

        Themes::initTheme('light');
        $view->invalidate();
        $after = implode("\n", $view->render(80));

        foreach ($tokens as $index => $token) {
            $this->assertStringContainsString(Themes::theme()->getFgAnsi($token), $after, "{$token} in the new theme");
            $this->assertStringNotContainsString($dark[$index], $after, "{$token} left in the old theme");
        }
    }
}
