<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\AuthSelectorProvider;
use Pig\CodingAgent\Interactive\OAuthSelectorComponent;
use Pig\CodingAgent\PreferredProviders;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Ansi;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\TuiMainScreen;

/** Upstream's `preferred-providers.test.ts` and the brand rows of `oauth-selector.test.ts` (683a7a86). */
final class PreferredProvidersTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Themes::initTheme('dark');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Loop::reset();
    }

    public function testPreferredPartnersComeFirstInTheirOrderThenTheRestByName(): void
    {
        $providers = array_map(static fn (array $p): object => (object) ['id' => $p[0], 'name' => $p[1]], [
            ['zai', 'Z.AI'], ['openrouter', 'OpenRouter'], ['anthropic', 'Anthropic'], ['google', 'Google'],
        ]);

        usort($providers, PreferredProviders::compare(...));

        $this->assertSame(['openrouter'], PreferredProviders::IDS);
        $this->assertSame(['openrouter', 'anthropic', 'google', 'zai'], array_column($providers, 'id'));
        $this->assertTrue(PreferredProviders::isPreferred('openrouter'));
        $this->assertFalse(PreferredProviders::isPreferred('anthropic'));
    }

    public function testThePartnersAreMarkedAndOnlyTheSelectedOneHasItsBrandStyle(): void
    {
        $theme = Themes::theme();
        $selector = new OAuthSelectorComponent(
            'login',
            [new AuthSelectorProvider('openrouter', 'OpenRouter', 'api_key', null), new AuthSelectorProvider('zai', 'Z.AI', 'api_key', null)],
            static fn () => null,
            static fn () => null,
            tui: new TuiMainScreen(new FakeTerminal()),
        );

        try {
            $output = implode("\n", $selector->render(120));
            $this->assertStringContainsString('→ OpenRouter ✦', Ansi::strip($output));
            $this->assertStringContainsString('Z.AI', Ansi::strip($output));
            $this->assertStringNotContainsString('Z.AI ✦', Ansi::strip($output));
            $this->assertStringNotContainsString($theme->fg('accent', 'OpenRouter'), $output, 'the glow, not the accent');

            $selector->handleInput("\x1b[B");
            $moved = implode("\n", $selector->render(120));
            $this->assertStringContainsString($theme->fg('text', 'OpenRouter'), $moved);
            $this->assertStringContainsString('→ Z.AI', Ansi::strip($moved));
            $this->assertStringContainsString($theme->fg('accent', 'Z.AI'), $moved);
        } finally {
            $selector->dispose();
        }

        $plain = new OAuthSelectorComponent('login', [new AuthSelectorProvider('openrouter', 'OpenRouter', 'api_key', null)], static fn () => null, static fn () => null);
        $this->assertStringContainsString($theme->fg('accent', 'OpenRouter'), implode("\n", $plain->render(120)), 'without a TUI to animate in, the accent');
    }
}
