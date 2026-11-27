<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Usage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Interactive\UsageStatus;
use Pig\CodingAgent\Usage\UsageGroup;
use Pig\CodingAgent\Usage\UsageMeter;
use Pig\CodingAgent\Usage\UsagePanel;
use Pig\CodingAgent\Usage\UsageReport;
use Pig\CodingAgent\Usage\UsageSource;
use RuntimeException;

/** `registerUsage()`: the registry, the web panel core adds for it, and the footer's lines. */
final class UsageRegistryTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        ExtensionApi::forgetUsageSources();
        ExtensionApi::forgetWebPanels();
    }

    public function testRegisteringASourceAddsTheUsagePanelOnce(): void
    {
        $this->assertNull(ExtensionApi::webPanel(UsagePanel::ID));
        $api = new ExtensionApi('/tmp', '/tmp/one.php');
        $api->registerUsage('one', ['en' => 'One', 'zh-CN' => '一'], static fn (): UsageReport => UsageReport::note('fine'));
        $api->registerUsage('two', 'Two', static fn (): UsageReport => throw new RuntimeException('HTTP 503'));

        $this->assertSame(['one', 'two'], array_map(static fn (UsageSource $s): string => $s->id, ExtensionApi::usageSources()));
        $this->assertSame(['usage'], ExtensionApi::webPanel(UsagePanel::ID)->commands);
        $this->assertCount(1, array_filter(ExtensionApi::webPanels(), static fn ($panel): bool => $panel->id === UsagePanel::ID));

        $this->assertSame([
            ['type' => 'heading', 'text' => '一'],
            ['type' => 'lazy', 'section' => 'one', 'placeholder' => '正在获取用量…'],
            ['type' => 'heading', 'text' => 'Two'],
            ['type' => 'lazy', 'section' => 'two', 'placeholder' => '正在获取用量…'],
        ], UsagePanel::render(null, 'zh-CN'));
        $this->assertSame([['type' => 'text', 'text' => 'fine', 'tone' => 'muted']], UsagePanel::render('one', 'en'));
        // A source that fails says so where its usage would be, and the others are drawn regardless.
        $this->assertSame([['type' => 'text', 'text' => 'HTTP 503', 'tone' => 'error']], UsagePanel::render('two', 'en'));
    }

    public function testASourceIsCheckedWhenItIsRegistered(): void
    {
        $api = new ExtensionApi('/tmp', '/tmp/one.php');

        foreach ([['Bad Id', 300], ['ok', 10]] as [$id, $refresh]) {
            try {
                $api->registerUsage($id, 'X', static fn (): UsageReport => new UsageReport(), refreshSeconds: $refresh);
                $this->fail("{$id} / {$refresh} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $api->registerUsage('wrong', 'X', static fn (): string => 'not a report');
        $this->expectExceptionMessage('not a UsageReport');
        ExtensionApi::usageSource('wrong')->fetch();
    }

    public function testTheFooterHasALinePerStatusSourceAndLosesItWhenStopped(): void
    {
        $api = new ExtensionApi('/tmp', '/tmp/one.php');
        $api->registerUsage('go', 'OpenCode Go', static fn (): UsageReport => new UsageReport([
            new UsageGroup([new UsageMeter('Rolling 5h', 0.5, short: '5h')]),
        ]), status: true);
        $api->registerUsage('down', 'Down', static fn (): UsageReport => throw new RuntimeException("refused\ntrace"), status: true);
        $api->registerUsage('quiet', 'Quiet', static fn (): UsageReport => throw new RuntimeException('never asked'));
        $lines = [];
        $timers = static fn (): int => (fn (): int => count($this->timers))->call(Loop::get());
        $timersBefore = $timers();
        $status = new UsageStatus(static function (string $key, ?string $text) use (&$lines): void {
            $lines[$key] = $text;
        });

        Async::run(static function () use ($status): void {
            $status->start();
            Async::delay(0.01);
        });

        $this->assertSame(['usage:go' => 'OpenCode Go: 5h 50%', 'usage:down' => 'Down: refused'], $lines);
        $this->assertSame($timersBefore + 2, $timers(), 'One refresh timer per status source.');

        $status->stop();
        $this->assertSame(['usage:go' => null, 'usage:down' => null], $lines);
        $this->assertSame($timersBefore, $timers(), 'A stopped status leaves no timer behind.');
    }
}
