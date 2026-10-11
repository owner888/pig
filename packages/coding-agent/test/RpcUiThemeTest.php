<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Rpc\RpcUi;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Images\TerminalImage;

/**
 * An extension's themes over RPC: upstream's answers — none, and `setTheme()` refused — until the
 * host says it draws in a theme, as the web shell does; then the theme files, the host's theme as
 * the global one, and `setTheme()` as a request the host applies.
 */
final class RpcUiThemeTest extends TestCase
{
    use ThemeTestEnvironment;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private RpcUi $ui;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
        Themes::initTheme('dark');
        $this->sent = [];
        $this->ui = new RpcUi(function (array $line): void {
            $this->sent[] = $line;
        });
    }

    #[\Override]
    protected function tearDown(): void
    {
        TerminalImage::setCapabilityOverrides([]);
        $this->tearDownThemeEnvironment();
    }

    public function testAHostThatDrawsNoThemeGetsUpstreamsAnswers(): void
    {
        $this->assertSame([], $this->ui->getAllThemes());
        $this->assertNull($this->ui->getTheme('light'));
        $this->assertSame(['success' => false, 'error' => 'Theme switching not supported in RPC mode'], $this->ui->setTheme('light'));
        $this->assertSame([], $this->sent);
        $this->assertNull($this->ui->hostTheme());
    }

    public function testTheHostsThemeIsTheOneExtensionsDrawWith(): void
    {
        TerminalImage::setCapabilityOverrides(['trueColor' => false]);

        $this->ui->useHostTheme('light');

        $this->assertSame('light', $this->ui->hostTheme());
        $this->assertSame(Themes::getThemeByName('light')?->getFgAnsi('accent'), $this->ui->theme()->getFgAnsi('accent'));
        // A page draws every colour as it is, not the nearest of 256.
        $this->assertSame('truecolor', $this->ui->theme()->getColorMode());
        $this->assertSame([], $this->sent, 'the host said it; nothing to tell it back');
    }

    public function testOnceTheHostDrawsThemesTheyAreTheThemeFilesButTheTerminalsOwn(): void
    {
        $this->ui->useHostTheme('dark');

        $names = array_map(static fn ($info): string => $info->name, $this->ui->getAllThemes());
        $this->assertContains('dark', $names);
        $this->assertContains('light', $names);
        $this->assertNotContains(Themes::SYSTEM_THEME_NAME, $names, 'the terminal\'s colours; a page has none');
        $this->assertNotNull($this->ui->getTheme('light'));
        $this->assertNull($this->ui->getTheme('no-such-theme'));
    }

    public function testAnExtensionsSetThemeIsToldToTheHostAndTakenAtOnce(): void
    {
        $this->ui->useHostTheme('dark');

        $this->assertSame(['success' => true], $this->ui->setTheme('light'));

        $this->assertCount(1, $this->sent);
        $this->assertSame('extension_ui_request', $this->sent[0]['type']);
        $this->assertSame('setTheme', $this->sent[0]['method']);
        $this->assertSame('light', $this->sent[0]['theme']);
        $this->assertIsString($this->sent[0]['id']);
        $this->assertSame('light', $this->ui->hostTheme());
        $this->assertSame(Themes::getThemeByName('light')?->getFgAnsi('accent'), $this->ui->theme()->getFgAnsi('accent'));
    }

    public function testAThemeTheHostCannotShowIsRefusedAndNothingChanges(): void
    {
        $this->ui->useHostTheme('dark');
        $before = $this->ui->theme()->getFgAnsi('accent');

        foreach (['no-such-theme', Themes::SYSTEM_THEME_NAME] as $name) {
            $result = $this->ui->setTheme($name);
            $this->assertFalse($result['success'], $name);
            $this->assertStringContainsString("No theme called '{$name}'", $result['error'] ?? '');
        }

        $object = $this->ui->setTheme((Themes::getThemeByName('light') ?? $this->fail('no light theme')));
        $this->assertFalse($object['success'], 'a Theme object has no name the host could show');

        $this->assertSame([], $this->sent);
        $this->assertSame('dark', $this->ui->hostTheme());
        $this->assertSame($before, $this->ui->theme()->getFgAnsi('accent'));
    }

    public function testTheHostNamingAThemeThereIsNoneOfFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No theme called 'no-such-theme'");

        try {
            $this->ui->useHostTheme('no-such-theme');
        } finally {
            $this->assertNull($this->ui->hostTheme());
        }
    }
}
