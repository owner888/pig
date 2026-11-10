<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Providers\AntigravityCli;

final class AntigravityCliTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        putenv('ANTIGRAVITY_USER_AGENT');
        putenv('NOAGY_USER_AGENT');
    }

    public function testTheUserAgentIsTheClisOwnWithTheVersionTheCatalogueIsGatedOn(): void
    {
        // pi-antigravity's `DEFAULT_USER_AGENT`, character for character.
        $this->assertSame('antigravity/cli/1.4.0 (aidev_client; os_type=linux; arch=amd64; cl=982146307; auth_method=consumer)', AntigravityCli::userAgent());
        $this->assertSame(AntigravityCli::defaultUserAgent(), AntigravityCli::userAgent());
        $this->assertStringContainsString('/' . AntigravityCli::VERSION . ' ', AntigravityCli::userAgent());
    }

    public function testTheEnvironmentOverridesItAndAnEmptyVariableIsNotSet(): void
    {
        putenv('NOAGY_USER_AGENT=antigravity/cli/9.9.9 (old name)');
        $this->assertSame('antigravity/cli/9.9.9 (old name)', AntigravityCli::userAgent());

        putenv('ANTIGRAVITY_USER_AGENT=antigravity/cli/2.0.0 (new name)');
        $this->assertSame('antigravity/cli/2.0.0 (new name)', AntigravityCli::userAgent(), 'the current name wins');

        putenv('ANTIGRAVITY_USER_AGENT=');
        putenv('NOAGY_USER_AGENT=');
        $this->assertSame(AntigravityCli::defaultUserAgent(), AntigravityCli::userAgent());
    }
}
