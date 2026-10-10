<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\CodingAgent\ProviderAttribution;
use Pig\CodingAgent\Settings;

/**
 * Upstream's `provider-attribution.ts` under pig's name: who is told what, the setting and
 * `PIG_TELEMETRY` that switch it, and the request's own headers winning.
 */
final class ProviderAttributionTest extends TestCase
{
    public function testEachProviderThatAsksIsToldPigIsCalling(): void
    {
        $on = Settings::inMemory();

        $this->assertSame(
            ['HTTP-Referer' => 'https://pigagent.dev', 'X-OpenRouter-Title' => 'pig', 'X-OpenRouter-Categories' => 'cli-agent'],
            ProviderAttribution::merge(self::model('openrouter', 'https://openrouter.ai/api/v1'), $on, null, null, false),
        );
        $this->assertSame(
            ['X-BILLING-INVOKE-ORIGIN' => 'Pig'],
            ProviderAttribution::merge(self::model('custom', 'https://integrate.api.nvidia.com/v1'), $on, null, null, false),
        );
        $this->assertSame(
            ['User-Agent' => 'pig-coding-agent'],
            ProviderAttribution::merge(self::model('cloudflare-workers-ai', 'https://example.com'), $on, null, null, false),
        );
        $this->assertNull(ProviderAttribution::merge(self::model('anthropic', 'https://api.anthropic.com'), $on, 's1', null, false));
    }

    public function testTheSettingAndTheEnvironmentSwitchItAndOpenCodeIsToldEitherWay(): void
    {
        $off = Settings::inMemory(['enableInstallTelemetry' => false]);
        $openRouter = self::model('openrouter', 'https://openrouter.ai/api/v1');

        $this->assertNull(ProviderAttribution::merge($openRouter, $off, null, null, false));
        $this->assertNotNull(ProviderAttribution::merge($openRouter, $off, null, null, 'yes'), 'PIG_TELEMETRY=yes beats the setting');
        $this->assertNull(ProviderAttribution::merge($openRouter, Settings::inMemory(), null, null, '0'));

        $this->assertSame(
            ['x-opencode-session' => 's1', 'x-opencode-client' => 'pig'],
            ProviderAttribution::merge(self::model('opencode', 'https://opencode.ai/zen/v1'), $off, 's1', null, false),
        );
    }

    public function testTheRequestsOwnHeadersWin(): void
    {
        $this->assertSame(
            ['HTTP-Referer' => 'https://pigagent.dev', 'X-OpenRouter-Title' => 'mine', 'X-OpenRouter-Categories' => null, 'X-Trace' => '1'],
            ProviderAttribution::merge(
                self::model('openrouter', 'https://openrouter.ai/api/v1'),
                Settings::inMemory(),
                null,
                ['X-OpenRouter-Title' => 'mine', 'X-OpenRouter-Categories' => null, 'X-Trace' => '1'],
                false,
            ),
        );
    }

    private static function model(string $provider, string $baseUrl): Model
    {
        return new Model('m', 'M', Api::OpenAiCompletions, $provider, $baseUrl, 100_000, 4_000);
    }
}
