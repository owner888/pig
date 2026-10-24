<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\ClientAttribution;
use Pig\Ai\ClientIdentity;
use Pig\Ai\Model;
use Pig\CodingAgent\ProviderAttribution;
use Pig\CodingAgent\Settings;

/**
 * Upstream's `client-identity.ts` under pig's name: a detailed `User-Agent` with pig's version, the
 * attribution switched by the setting and `PIG_TELEMETRY`, and OpenCode's session header.
 */
final class ProviderAttributionTest extends TestCase
{
    public function testPigIsAttributedWhileTelemetryIsOnAndTheEnvironmentWins(): void
    {
        $on = ProviderAttribution::identity(Settings::inMemory(), false);

        $this->assertSame('detailed', $on->userAgent);
        $this->assertEquals(ClientAttribution::pig(), $on->attribution);
        $this->assertFalse(ProviderAttribution::identity(Settings::inMemory(['enableInstallTelemetry' => false]), false)->attribution);
        $this->assertEquals(ClientAttribution::pig(), ProviderAttribution::identity(Settings::inMemory(['enableInstallTelemetry' => false]), 'yes')->attribution, 'PIG_TELEMETRY=yes beats the setting');
        $this->assertFalse(ProviderAttribution::identity(Settings::inMemory(), '0')->attribution);
    }

    public function testEachProviderThatAsksIsToldPigIsCalling(): void
    {
        $identity = ProviderAttribution::identity(Settings::inMemory(), false);

        $this->assertSame(
            ['HTTP-Referer' => 'https://pigagent.dev', 'X-OpenRouter-Title' => 'pig', 'X-OpenRouter-Categories' => 'cli-agent'],
            ClientIdentity::attributionHeaders(self::model('openrouter', 'https://openrouter.ai/api/v1'), $identity),
        );
        $this->assertSame(['X-BILLING-INVOKE-ORIGIN' => 'Pig'], ClientIdentity::attributionHeaders(self::model('custom', 'https://integrate.api.nvidia.com/v1'), $identity));
        // OpenCode's client header whenever attribution is on, not only with a session.
        $this->assertSame(['x-opencode-client' => 'pig'], ClientIdentity::attributionHeaders(self::model('opencode', 'https://opencode.ai/zen/v1'), $identity));
        // Cloudflare gets the detailed `User-Agent` like everyone else.
        $this->assertSame(['User-Agent' => ClientIdentity::formatUserAgent($identity)], ClientIdentity::headers(self::model('cloudflare-workers-ai', 'https://example.com'), $identity));
    }

    public function testOnlyOpenCodesSessionHeaderIsMergedUnderTheRequestsOwn(): void
    {
        $this->assertNull(ProviderAttribution::merge(self::model('anthropic', 'https://api.anthropic.com'), 's1', null));
        $this->assertSame(['x-opencode-session' => 's1'], ProviderAttribution::merge(self::model('custom', 'https://opencode.ai/zen/v1'), 's1', null));
        $this->assertSame(['x-opencode-session' => null, 'X-Trace' => '1'], ProviderAttribution::merge(self::model('opencode', 'https://opencode.ai/zen/v1'), 's1', ['x-opencode-session' => null, 'X-Trace' => '1']));
    }

    private static function model(string $provider, string $baseUrl): Model
    {
        return new Model('m', 'M', Api::OpenAiCompletions, $provider, $baseUrl, 100_000, 4_000);
    }
}
