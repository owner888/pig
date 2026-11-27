<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Usage;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Usage\UsageGroup;
use Pig\CodingAgent\Usage\UsageMeter;
use Pig\CodingAgent\Usage\UsageReport;
use Pig\CodingAgent\Usage\UsageView;

/** One look for every extension's usage: the transcript, the footer, the web panel. */
final class UsageViewTest extends TestCase
{
    use GlobalThemeFixture;

    private const int NOW = 1_795_000_000;

    public function testTheTranscriptHasAntigravitysLookAndPigsNamesForTheUsualWindows(): void
    {
        $text = UsageView::text($this->antigravity(), now: self::NOW);

        $this->assertSame(<<<'TEXT'
            Google AI Ultra (g1-ultra-lite-tier)

            Gemini Models
              Models within this group: Gemini 4 Argon, Gemini 3.8 Flash
              [████████████████████] Weekly limit: 99.2% left · resets 5d 21h
              [████████████████████] 5-hour limit: 100% left · resets 5h 0m

            Claude and GPT models
              [██████--------------] Weekly limit: 30% left · resets n/a · lasts ~3d at this pace
            TEXT, $text);
    }

    public function testTwoProvidersNameTheSameWindowTheSame(): void
    {
        // Antigravity says "Five Hour Limit Remaining", OpenCode Go "Rolling 5h": one window, one name.
        $antigravity = new UsageMeter('Five Hour Limit Remaining', 1.0, short: '5h');
        $opencode = new UsageMeter('Rolling 5h', 0.99, short: '5h');

        $this->assertSame(UsageView::label($antigravity, 'zh-CN'), UsageView::label($opencode, 'zh-CN'));
        $this->assertSame('5 小时额度', UsageView::label($opencode, 'zh-CN'));
        $this->assertSame('Monthly limit', UsageView::label(new UsageMeter('Monthly', 0.5, short: 'mo')));
        // A window pig has no name for keeps the provider's.
        $this->assertSame('Balance ($80.00 of $100.00)', UsageView::label(new UsageMeter('Balance ($80.00 of $100.00)', 0.8, short: 'balance'), 'zh-CN'));
    }

    public function testAGroupWithoutANameHasNoHeadingAndATitleHeadsTheReport(): void
    {
        $report = new UsageReport([new UsageGroup([new UsageMeter('Rolling 5h', 0.05, null, '5h', daysLeft: 0.4)])]);

        $this->assertSame(
            "OpenCode Go\n\n  [█-------------------] 5-hour limit: 5% left · resets n/a · lasts under a day at this pace",
            UsageView::text($report, title: 'OpenCode Go'),
        );
    }

    public function testBarsAreColouredByWhatIsLeft(): void
    {
        $this->setUpGlobalTheme();

        try {
            $this->assertColouredBars();
        } finally {
            $this->tearDownGlobalTheme();
        }
    }

    private function assertColouredBars(): void
    {
        $theme = Themes::theme();
        $text = UsageView::text($this->antigravity(), $theme, now: self::NOW);

        $this->assertStringContainsString($theme->fg('success', UsageView::bar(1.0)), $text);
        $this->assertStringContainsString($theme->fg('warning', UsageView::bar(0.3)), $text);
        $this->assertSame('nearlyOut', UsageView::barRole(0.19));
    }

    public function testTheFooterLineIsShort(): void
    {
        $one = new UsageReport([new UsageGroup([
            new UsageMeter('Rolling 5h', 1.0, short: '5h'),
            new UsageMeter('Weekly', 0.992, short: 'wk'),
        ])]);
        $this->assertSame('OpenCode Go: 5h 100% · wk 99.2%', UsageView::statusLine('OpenCode Go', $one));
        $this->assertSame(
            'Antigravity: Gemini wk 99.2% 5h 100% · Claude wk 30%',
            UsageView::statusLine('Antigravity', $this->antigravity()),
        );
        $this->assertSame('OpenCode Go: Not connected.', UsageView::statusLine('OpenCode Go', UsageReport::note("Not connected.\nRun /login.")));
        $this->assertSame('X: HTTP 500', UsageView::statusError('X', "HTTP 500\nbody"));
    }

    public function testTheWebPanelGetsAMeterPerLimitUnderACardPerGroup(): void
    {
        $blocks = UsageView::blocks($this->antigravity(), 'zh-CN', self::NOW);

        $this->assertSame(['type' => 'text', 'text' => 'Google AI Ultra (g1-ultra-lite-tier)', 'tone' => 'muted'], $blocks[0]);
        $this->assertSame('card', $blocks[1]['type']);
        $this->assertSame('Gemini Models', $blocks[1]['title']);
        // The bar carries what is left; the words under it are when, and how long at this pace.
        $this->assertSame(
            ['type' => 'meter', 'label' => '每周额度', 'value' => 0.992, 'detail' => '5d 21h 后重置'],
            $blocks[1]['blocks'][1],
        );
        $this->assertSame('重置时间未知 · 按当前速度约 3 天用完', $blocks[2]['blocks'][0]['detail']);
        // A group with no name is a card too, so every provider's pools look alike.
        $one = UsageView::blocks(new UsageReport([new UsageGroup([new UsageMeter('Rolling 5h', 1.0, short: '5h')])]), 'en');
        $this->assertSame(['type' => 'card', 'blocks' => [['type' => 'meter', 'label' => '5-hour limit', 'value' => 1.0, 'detail' => 'resets n/a']]], $one[0]);
    }

    public function testResets(): void
    {
        $at = static fn (int $seconds): DateTimeImmutable => new DateTimeImmutable('@' . (self::NOW + $seconds));

        $this->assertSame('12m', UsageView::formatReset($at(720), self::NOW));
        $this->assertSame('1h 12m', UsageView::formatReset($at(4320), self::NOW));
        $this->assertSame('3d 4h', UsageView::formatReset($at(273_600), self::NOW));
        $this->assertSame('now', UsageView::formatReset($at(-1), self::NOW));
        $this->assertSame('n/a', UsageView::formatReset(null, self::NOW));
    }

    public function testAMeterIsAFractionLeft(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UsageMeter('Weekly', 62.0);
    }

    private function antigravity(): UsageReport
    {
        $at = static fn (int $seconds): DateTimeImmutable => new DateTimeImmutable('@' . (self::NOW + $seconds));

        return new UsageReport([
            new UsageGroup([
                new UsageMeter('Weekly Limit Remaining', 0.992, $at(5 * 86_400 + 21 * 3600), 'wk'),
                new UsageMeter('Five Hour Limit Remaining', 1.0, $at(5 * 3600), '5h'),
            ], 'Gemini Models', 'Models within this group: Gemini 4 Argon, Gemini 3.8 Flash', 'Gemini'),
            new UsageGroup([new UsageMeter('Weekly Limit Remaining', 0.3, null, 'wk', daysLeft: 3.2)], 'Claude and GPT models', short: 'Claude'),
        ], 'Google AI Ultra (g1-ultra-lite-tier)');
    }
}
