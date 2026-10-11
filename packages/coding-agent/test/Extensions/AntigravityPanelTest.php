<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use PigAntigravity\AccountsPanel;

/**
 * The Antigravity accounts panel as blocks (`AccountsPanel`), and those blocks drawn by the page's
 * own `panels.js` under Node — what moved out of the page's `accounts.js` must still draw what it
 * drew: the accounts with their buttons, the quota pools as bars, in both languages the page ships.
 */
final class AntigravityPanelTest extends TestCase
{
    use LoadsAntigravity;

    private const int NOW = 1_800_000_000_000;

    #[\Override]
    protected function setUp(): void
    {
        self::loadAntigravity();
    }

    /** @return list<array{id: string, email: string, projectId: ?string, expires: ?int, active: bool}> */
    private static function rows(): array
    {
        return [
            ['id' => 'a1', 'email' => 'one@example.com', 'projectId' => 'p1', 'expires' => self::NOW + 30 * 60_000, 'active' => true],
            ['id' => 'a2', 'email' => '<two>@example.com', 'projectId' => null, 'expires' => self::NOW - 1, 'active' => false],
        ];
    }

    /** @return array<string, mixed> */
    private static function usage(): array
    {
        return [
            'planLabel' => 'Pro',
            'quotaError' => null,
            'groups' => [[
                'displayName' => 'Claude & GPT models',
                'buckets' => [
                    ['displayName' => '5 hour limit remaining', 'remainingFraction' => 0.15, 'resetTime' => gmdate('c', intdiv(self::NOW, 1000) + 90 * 60)],
                    ['displayName' => 'Something new', 'remainingFraction' => 0.0, 'resetTime' => null],
                ],
            ]],
        ];
    }

    public function testTheAccountsAreAListWithTheirButtons(): void
    {
        $blocks = AccountsPanel::blocks(self::rows(), ['bad entry skipped'], 'en', self::NOW);

        $this->assertSame([['type' => 'actions', 'actions' => [['label' => 'Rotate', 'action' => 'rotate', 'title' => 'Switch to the next account']]]], [$blocks[0]]);
        [$active, $other] = $blocks[1]['items'];

        $this->assertTrue($active['active']);
        $this->assertSame('active', $active['detail']);
        $this->assertSame('token 30m left', $active['meta']);
        $this->assertSame(['remove'], array_column($active['actions'], 'action'), 'the active one cannot be made active');

        $this->assertSame('token expired', $other['meta']);
        $this->assertSame('error', $other['tone']);
        $this->assertSame(['activate', 'remove'], array_column($other['actions'], 'action'));
        $this->assertSame(['id' => 'a2'], $other['actions'][0]['args']);
        $this->assertSame('Forget <two>@example.com? Its tokens are removed from this machine.', $other['actions'][1]['confirm']);

        $this->assertSame(['type' => 'text', 'text' => 'bad entry skipped', 'tone' => 'error'], $blocks[2]);
        $this->assertSame(['type' => 'lazy', 'section' => 'usage', 'placeholder' => 'Fetching…'], end($blocks));
    }

    public function testOneAccountHasNothingToRotateToAndNoneHasNoQuota(): void
    {
        $one = AccountsPanel::blocks([self::rows()[0]], [], 'en', self::NOW);
        $this->assertNotContains('actions', array_column($one, 'type'));

        $none = AccountsPanel::blocks([], [], 'zh-CN', self::NOW);
        $this->assertSame([['type' => 'list', 'empty' => '暂无 Antigravity 账号。请在终端执行 /login antigravity。', 'items' => []]], $none);
    }

    public function testThePanelSpeaksThePagesLanguage(): void
    {
        $blocks = AccountsPanel::blocks(self::rows(), [], 'zh-CN', self::NOW);

        $this->assertSame('轮换', $blocks[0]['actions'][0]['label']);
        $this->assertSame('使用中', $blocks[1]['items'][0]['detail']);
        $this->assertSame('令牌剩余 30 分钟', $blocks[1]['items'][0]['meta']);
        $this->assertSame('令牌剩余 2 小时', AccountsPanel::expiryLabel(self::NOW + 120 * 60_000, 'zh', self::NOW));
        $this->assertSame('token 2h left', AccountsPanel::expiryLabel(self::NOW + 120 * 60_000, 'fr', self::NOW), 'English for a language it lacks');
        $this->assertNull(AccountsPanel::expiryLabel(null, 'en', self::NOW));
    }

    public function testTheQuotaIsACardOfBarsPerPool(): void
    {
        $blocks = AccountsPanel::usageBlocks(self::usage(), 'zh-CN', self::NOW);

        $this->assertSame(['type' => 'text', 'text' => 'Pro', 'tone' => 'muted'], $blocks[0]);
        $this->assertSame('card', $blocks[1]['type']);
        $this->assertSame('Claude 与 GPT 模型', $blocks[1]['title'], 'the service\'s other spelling of the name');
        $this->assertSame(
            [
                ['type' => 'meter', 'label' => '5 小时限额剩余', 'value' => 0.15, 'detail' => '1 小时 30 分后重置'],
                ['type' => 'meter', 'label' => 'Something new', 'value' => 0.0, 'detail' => null],
            ],
            $blocks[1]['blocks'],
        );

        $this->assertSame([['type' => 'text', 'text' => 'quota said no', 'tone' => 'muted']], AccountsPanel::usageBlocks(['groups' => [], 'quotaError' => 'quota said no'], 'en'));
        $this->assertSame('No quota groups returned.', AccountsPanel::usageBlocks([], 'en')[0]['text']);
    }

    public function testResetTimesAreSaidAsThePageSaidThem(): void
    {
        $at = static fn (int $minutes): string => gmdate('c', intdiv(self::NOW, 1000) + $minutes * 60);

        $this->assertSame('resets now', AccountsPanel::resetLabel($at(-1), 'en', self::NOW));
        $this->assertSame('resets in 45m', AccountsPanel::resetLabel($at(45), 'en', self::NOW));
        $this->assertSame('resets in 47h 0m', AccountsPanel::resetLabel($at(47 * 60), 'en', self::NOW));
        $this->assertSame('resets in 3d', AccountsPanel::resetLabel($at(72 * 60), 'en', self::NOW));
        $this->assertSame('soon-ish', AccountsPanel::resetLabel('soon-ish', 'en', self::NOW), 'not a time: as sent');
        $this->assertNull(AccountsPanel::resetLabel(null, 'en', self::NOW));
    }

    public function testThePageDrawsWhatThePanelDescribes(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $fixture = [
            'accounts' => AccountsPanel::blocks(self::rows(), ['<b>bad</b>'], 'en', self::NOW),
            'usage' => AccountsPanel::usageBlocks(self::usage(), 'en', self::NOW),
        ];

        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/web-panels.mjs')
            . ' ' . escapeshellarg((string) realpath(dirname(__DIR__, 2) . '/src/Web/assets/js'))
            . ' ' . escapeshellarg(base64_encode(json_encode($fixture, JSON_THROW_ON_ERROR))) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web panels: passed', implode("\n", $out));
    }
}
