<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Cli\AuthCommand;
use Pig\CodingAgent\Cli\AuthCommandError;
use Pig\Test\WithoutProviderKeys;

/**
 * `pig auth <check|print-api-key|print-bearer-token>` — upstream's `auth-command.ts`,
 * `auth-check.ts` and `credential-print.ts`, run against an `auth.json` of the test's own.
 */
final class AuthCommandTest extends TestCase
{
    use WithoutProviderKeys;

    private string $home;

    private string $out = '';

    private string $err = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-auth-command-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        $this->forgetProviderKeys(['PIG_CODING_AGENT_DIR', 'PI_HOME']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreProviderKeys();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    /** @param array<string, mixed> $stored what `auth.json` holds */
    private function auth(array $stored, string ...$args): int
    {
        file_put_contents($this->home . '/auth.json', json_encode($stored));
        $this->out = '';
        $this->err = '';

        return (new AuthCommand(
            Auth::discover($this->home . '/auth.json'),
            function (string $text): void { $this->out .= $text; },
            function (string $text): void { $this->err .= $text; },
        ))->run(['auth', ...$args]);
    }

    public function testHelpIsUpstreamsWithNoCommandOrWithHelp(): void
    {
        $this->assertSame(0, $this->auth([]));
        $this->assertStringContainsString('pig auth check [--provider <provider>]', $this->out);
        $this->assertSame(0, $this->auth([], 'check', '--help'));
    }

    public function testAnUnknownCommandIsUpstreamsError(): void
    {
        $this->assertSame(1, $this->auth([], 'login'));
        $this->assertStringContainsString('Unknown auth command "login"', $this->err);
    }

    public function testParseCommandKeepsTheCommandsOwnOptions(): void
    {
        $parsed = AuthCommand::parseCommand(['auth', 'print-bearer-token', '--provider', 'x', '--min-expiry', '2h']);

        $this->assertSame('bearer_token', $parsed['kind']);
        $this->assertSame(['--provider', 'x'], $parsed['args']);
        $this->assertSame(7_200_000, $parsed['minExpiryMs']);

        $this->expectException(AuthCommandError::class);
        $this->expectExceptionMessage('--json is only supported by auth check');
        AuthCommand::parseCommand(['auth', 'print-api-key', '--json']);
    }

    public function testCheckNeedsAProviderOrAModel(): void
    {
        $this->assertSame(2, $this->auth([], 'check'));
        $this->assertStringContainsString('Auth checks require --provider <provider> or --model <model>', $this->err);
    }

    public function testCheckAnswersReadyNotReadyAndProviderNotFoundWithTheirExitCodes(): void
    {
        $this->assertSame(0, $this->auth(['openai' => ['type' => 'api_key', 'key' => 'sk-1']], 'check', '--provider', 'openai'));
        $this->assertSame("ready\n", $this->out);

        $this->assertSame(1, $this->auth([], 'check', '--provider', 'openai', '--json'));
        $this->assertSame(['status' => 'not_ready', 'provider' => 'openai', 'reason' => 'credentials_not_configured'], json_decode($this->out, true));

        $this->assertSame(1, $this->auth([], 'check', '--provider', 'no-such-provider', '--json'));
        $this->assertSame('provider_not_found', json_decode($this->out, true)['reason']);
    }

    public function testCheckWithCredentialsEmitsTheKey(): void
    {
        $this->assertSame(0, $this->auth(['openai' => ['type' => 'api_key', 'key' => 'sk-1']], 'check', '--provider', 'openai', '--credentials', '--json'));
        $this->assertSame(['status' => 'ready', 'provider' => 'openai', 'authType' => 'api_key', 'credentials' => 'sk-1'], json_decode($this->out, true));
    }

    public function testPrintApiKeyPrintsTheStoredKey(): void
    {
        $this->assertSame(0, $this->auth(['openai' => ['type' => 'api_key', 'key' => 'sk-1']], 'print-api-key', '--provider', 'openai'));
        $this->assertSame("sk-1\n", $this->out);
    }

    public function testPrintApiKeyRefusesAnOauthProviderAsUpstreamDoes(): void
    {
        $stored = ['anthropic' => ['type' => 'oauth', 'refresh' => 'r', 'access' => 'a', 'expires' => PHP_INT_MAX]];

        $this->assertSame(1, $this->auth($stored, 'print-api-key', '--provider', 'anthropic'));
        $this->assertStringContainsString('Provider "anthropic" is configured with OAuth, not an API key', $this->err);
    }

    public function testPrintBearerTokenPrintsAnUnexpiredOauthToken(): void
    {
        $stored = ['anthropic' => ['type' => 'oauth', 'refresh' => 'r', 'access' => 'tok', 'expires' => PHP_INT_MAX]];

        $this->assertSame(0, $this->auth($stored, 'print-bearer-token', '--provider', 'anthropic'));
        $this->assertSame("tok\n", $this->out);
    }

    public function testOnlyProviderAndModelAreAccepted(): void
    {
        $this->assertSame(1, $this->auth([], 'print-api-key', '--provider', 'openai', 'extra'));
        $this->assertStringContainsString('Auth commands only accept --provider and --model', $this->err);

        $this->assertSame(1, $this->auth([], 'print-api-key', '--provider', 'openai', '--bogus'));
        $this->assertStringContainsString('Unknown option --bogus for "auth print-api-key".', $this->err);
    }
}
