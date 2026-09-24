<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Settings;
use Pig\Test\AssertsThrows;

/**
 * The keys and the tokens on disk.
 *
 * Nothing here reaches the network. The one path that would — renewing an expired token — is
 * exercised through a credential whose refresh token is empty, which `Provider::refresh()`
 * refuses before it opens a socket, so the interesting half (what happens to the stored
 * credential when a renewal fails) is answerable without one.
 */
final class AuthTest extends TestCase
{
    use AssertsThrows;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-auth-' . bin2hex(random_bytes(6));

        // `apiKey()` falls through to the environment, and a real key in the shell running
        // the suite would answer some of these for the wrong reason.
        foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_OAUTH_TOKEN', 'PIG_HOME', 'PI_HOME', 'PI_AGENT_DIR', 'GEMINI_CLI_CLIENT_ID', 'GEMINI_CLI_CLIENT_SECRET'] as $variable) {
            putenv($variable);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_OAUTH_TOKEN', 'PIG_HOME', 'PI_HOME', 'PI_AGENT_DIR', 'GEMINI_CLI_CLIENT_ID', 'GEMINI_CLI_CLIENT_SECRET'] as $variable) {
            putenv($variable);
        }

        self::remove($this->home);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function auth(): Auth
    {
        return new Auth($this->home . '/auth.json');
    }

    private function given(string $contents): Auth
    {
        mkdir($this->home, 0o700, true);
        file_put_contents($this->home . '/auth.json', $contents);

        return $this->auth();
    }

    // ---- reading -------------------------------------------------------------------------

    public function testNoFileIsNoCredentialsAndNoComplaint(): void
    {
        $auth = $this->auth();

        $this->assertSame([], $auth->providers());
        $this->assertSame([], $auth->problems());
        $this->assertFalse($auth->has('anthropic'));
    }

    public function testAFileThatIsNotJsonIsNamedRatherThanIgnored(): void
    {
        $auth = $this->given('{oops');

        // The same call `Settings` makes about the same mistake: a credentials file with a
        // typo in it is otherwise a login that quietly stopped working.
        $this->assertSame([], $auth->providers());
        $this->assertCount(1, $auth->problems());
        $this->assertStringContainsString('not valid JSON', $auth->problems()[0]);
    }

    public function testAnApiKeyComesBackAsItself(): void
    {
        $auth = $this->given('{"openai": {"type": "api_key", "key": "sk-abc"}}');

        $this->assertSame(['openai'], $auth->providers());
        $this->assertSame('api_key', $auth->kind('openai'));
        $this->assertSame('sk-abc', $auth->apiKey('openai'));
    }

    public function testStoredTokensComeBackAsACredential(): void
    {
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "r1", "access": "sk-ant-oat-1", "expires": 99}}');

        $credentials = $auth->credentials(Provider::Anthropic);

        $this->assertNotNull($credentials);
        $this->assertSame('r1', $credentials->refresh);
        $this->assertSame('sk-ant-oat-1', $credentials->access);
        $this->assertSame(99, $credentials->expires);
    }

    public function testAnApiKeyEntryIsNotReadAsTokens(): void
    {
        // Both live under the same provider name, told apart only by `type`. Reading one as
        // the other would send a key as a bearer token, which fails as an auth error.
        $auth = $this->given('{"anthropic": {"type": "api_key", "key": "sk-ant-plain"}}');

        $this->assertNull($auth->credentials(Provider::Anthropic));
    }

    public function testAProviderNeitherToolKnowsIsKeptRatherThanDropped(): void
    {
        $auth = $this->given('{"something-new": {"type": "api_key", "key": "k"}}');

        // The file is shared with pi, so a name this pig has no enum case for is still an
        // entry that has to survive being read and written back.
        $this->assertSame(['something-new'], $auth->providers());

        $auth->setApiKey('openai', 'sk-new');

        $written = json_decode((string) file_get_contents($this->home . '/auth.json'), true);

        $this->assertArrayHasKey('something-new', $written);
        $this->assertArrayHasKey('openai', $written);
    }

    // ---- writing -------------------------------------------------------------------------

    public function testAKeyIsStillThereWhenItIsOpenedAgain(): void
    {
        $this->auth()->setApiKey('groq', 'gsk-1');

        $this->assertSame('gsk-1', $this->auth()->apiKey('groq'));
    }

    public function testTokensSurviveBeingWrittenAndReadBack(): void
    {
        $this->auth()->setCredentials(Provider::Anthropic, new Credentials('r2', 'a2', 1_234, email: 'me@example.com'));

        $back = $this->auth()->credentials(Provider::Anthropic);

        $this->assertNotNull($back);
        $this->assertSame('r2', $back->refresh);
        $this->assertSame(1_234, $back->expires);
        $this->assertSame('me@example.com', $back->email);
        // The three optional fields are left out when they are null rather than written as
        // `null`, so the file matches what pi writes.
        $this->assertNull($back->projectId);
    }

    public function testTheFileAndItsDirectoryAreReadableOnlyByWhoeverWroteThem(): void
    {
        $this->auth()->setApiKey('groq', 'gsk-1');

        // A credential readable by every account on the machine is the kind of thing nobody
        // notices until it matters.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->home . '/auth.json')), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->home)), -4));
    }

    public function testForgettingAProviderLeavesTheOthers(): void
    {
        $auth = $this->auth();
        $auth->setApiKey('groq', 'g');
        $auth->setApiKey('openai', 'o');

        $auth->remove('groq');

        $this->assertSame(['openai'], $auth->providers());
        $this->assertSame(['openai'], $this->auth()->providers());
    }

    public function testInMemoryCredentialsAreNotWrittenAnywhere(): void
    {
        $auth = Auth::inMemory();
        $auth->setApiKey('groq', 'g');

        $this->assertSame('g', $auth->apiKey('groq'));
        $this->assertNull($auth->path());
        $this->assertSame([], $this->auth()->providers(), 'nothing landed on disk');
    }

    // ---- which key wins ------------------------------------------------------------------

    public function testATypedKeyBeatsEverythingStored(): void
    {
        $auth = $this->given('{"anthropic": {"type": "api_key", "key": "stored"}}');
        $auth->setRuntimeApiKey('anthropic', 'typed');

        // The most recent thing anybody said. It is also never written down.
        $this->assertSame('typed', $auth->apiKey('anthropic'));
        $this->assertSame('api_key', $auth->kind('anthropic'));
    }

    public function testAStoredKeyBeatsTheEnvironment(): void
    {
        putenv('ANTHROPIC_API_KEY=from-the-shell');
        $auth = $this->given('{"anthropic": {"type": "api_key", "key": "stored"}}');

        $this->assertSame('stored', $auth->apiKey('anthropic'));
    }

    public function testATokenThatHasNotExpiredIsSentAsItIs(): void
    {
        $future = (int) (microtime(true) * 1000) + 3600_000;
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "r", "access": "sk-ant-oat-live", "expires": ' . $future . '}}');

        // No renewal, so no request: an unexpired token is the whole answer.
        $this->assertSame('sk-ant-oat-live', $auth->apiKey('anthropic'));
    }

    public function testTheEnvironmentIsTheLastResortAndKnowsItsOwnNames(): void
    {
        putenv('ANTHROPIC_API_KEY=from-the-shell');

        $this->assertSame('from-the-shell', $this->auth()->apiKey('anthropic'));
    }

    public function testAnOauthTokenInTheEnvironmentBeatsAnApiKeyThere(): void
    {
        putenv('ANTHROPIC_API_KEY=the-key');
        putenv('ANTHROPIC_OAUTH_TOKEN=the-token');

        // Not this class's rule — it is `Stream::envApiKey()`'s, which is upstream's
        // `getEnvApiKey()`. Asserted here because this is the only caller that relies on it.
        $this->assertSame('the-token', $this->auth()->apiKey('anthropic'));
    }

    public function testNothingAnywhereIsNull(): void
    {
        $this->assertNull($this->auth()->apiKey('anthropic'));
    }

    // ---- a renewal that fails ------------------------------------------------------------

    public function testARenewalThatFailsIsReportedAndTheCredentialIsKept(): void
    {
        // Expired, and with nothing to renew it with — refused before a socket is opened.
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "", "access": "old", "expires": 1}}');

        $problem = $this->assertThrows(OauthError::class, static fn (): ?string => $auth->apiKey('anthropic'));

        $this->assertStringContainsString('Could not renew', $problem->getMessage());
        // Upstream deletes the stored credential here and carries on to the environment, so
        // one blocked network call throws away a login that was fine — and the person finds
        // out by being asked to sign in again for no reason.
        $this->assertTrue($auth->has('anthropic'));
        $this->assertTrue($this->auth()->has('anthropic'), 'and it is still on disk');
    }

    // ---- signing in ----------------------------------------------------------------------

    public function testSigningInShowsAUrlWithTheChallengeInIt(): void
    {
        $seen = null;

        // Escaping the paste box: nothing came back, so nothing is exchanged, no request is
        // made, and this is a cancellation rather than a failure — which is why it answers
        // null instead of throwing.
        $credentials = $this->auth()->login(
            Provider::Anthropic,
            static function (string $url, ?string $instructions) use (&$seen): void {
                $seen = $url;
            },
            static fn (string $message, string $placeholder, bool $allowEmpty): ?string => null,
        );

        $this->assertNull($credentials);
        $this->assertIsString($seen);
        $this->assertStringContainsString('code_challenge=', (string) $seen);
        $this->assertStringContainsString('claude.ai/oauth/authorize', (string) $seen);
        $this->assertSame([], $this->auth()->providers(), 'nothing was stored');
    }

    public function testAnEmptyPasteIsACancellationToo(): void
    {
        $credentials = $this->auth()->login(
            Provider::Anthropic,
            static function (string $url, ?string $instructions): void {
            },
            static fn (string $message, string $placeholder, bool $allowEmpty): ?string => '   ',
        );

        $this->assertNull($credentials);
    }

    public function testAProviderThatIsNotPortedIsRefusedBeforeAUrlIsShown(): void
    {
        $shown = 0;

        $problem = $this->assertThrows(OauthError::class, function () use (&$shown): void {
            $this->auth()->login(
                Provider::GoogleAntigravity,
                static function (string $url, ?string $instructions) use (&$shown): void {
                    $shown++;
                },
                static fn (string $message, string $placeholder, bool $allowEmpty): ?string => 'c#s',
            );
        });

        $this->assertStringContainsString('not ported', $problem->getMessage());
        $this->assertSame(0, $shown, 'nobody was sent anywhere');
    }

    public function testCopilotIsAskedWhichGitHubBeforeAnythingElse(): void
    {
        $asked = [];

        // Escaping the domain prompt ends it before a request is made, which is what makes
        // this answerable without reaching github.com.
        $credentials = $this->auth()->login(
            Provider::GithubCopilot,
            static function (string $url, ?string $instructions): void {
            },
            static function (string $message, string $placeholder, bool $allowEmpty) use (&$asked): ?string {
                $asked[] = [$message, $allowEmpty];

                return null;
            },
        );

        $this->assertNull($credentials);
        $this->assertCount(1, $asked);
        $this->assertStringContainsString('Enterprise', $asked[0][0]);
        // Blank means github.com, so the prompt has to say an empty answer is allowed — the
        // flow that asked is what enforces that, not the UI.
        $this->assertTrue($asked[0][1]);
    }

    public function testSomethingThatIsNotADomainIsRefusedRatherThanReadAsGithubCom(): void
    {
        $problem = $this->assertThrows(OauthError::class, function (): void {
            $this->auth()->login(
                Provider::GithubCopilot,
                static function (string $url, ?string $instructions): void {
                },
                static fn (string $message, string $placeholder, bool $allowEmpty): ?string => '???',
            );
        });

        // Carrying on against github.com would sign somebody in to the wrong GitHub and look
        // like it had worked.
        $this->assertStringContainsString('not a GitHub Enterprise domain', $problem->getMessage());
    }

    // ---- where the file is ---------------------------------------------------------------

    public function testPisFileIsUsedWhenThereIsOne(): void
    {
        mkdir($this->home . '/pi', 0o700, true);
        file_put_contents($this->home . '/pi/auth.json', '{}');
        putenv('PI_HOME=' . $this->home . '/pi');
        putenv('PIG_HOME=' . $this->home . '/pig');

        // One file, because Anthropic rotates refresh tokens: two copies of the same token
        // is two tools taking it in turns to log each other out.
        $this->assertSame($this->home . '/pi/auth.json', Auth::discover()->path());
    }

    public function testPigsOwnFileWhenPiHasNone(): void
    {
        putenv('PI_HOME=' . $this->home . '/pi');
        putenv('PIG_HOME=' . $this->home . '/pig');

        $this->assertSame($this->home . '/pig/auth.json', Auth::discover()->path());
    }

    // ---- Google's client credentials, which pig does not ship -----------------------------

    public function testTheEnvironmentIsAskedForGooglesClientCredentialsFirst(): void
    {
        putenv('GEMINI_CLI_CLIENT_ID=from-the-shell');
        putenv('GEMINI_CLI_CLIENT_SECRET=secret-from-the-shell');

        $this->assertSame(['from-the-shell', 'secret-from-the-shell'], $this->auth()->googleClient());
    }

    public function testTheSettingsFileIsTheOtherPlaceTheyCanLive(): void
    {
        $settings = Settings::inMemory([
            'geminiCli' => ['clientId' => 'from-settings', 'clientSecret' => 'secret-from-settings'],
        ]);

        $auth = new Auth($this->home . '/auth.json', $settings);

        $this->assertSame(['from-settings', 'secret-from-settings'], $auth->googleClient());
    }

    public function testTheEnvironmentBeatsTheSettingsFile(): void
    {
        putenv('GEMINI_CLI_CLIENT_ID=from-the-shell');
        putenv('GEMINI_CLI_CLIENT_SECRET=secret-from-the-shell');

        $settings = Settings::inMemory([
            'geminiCli' => ['clientId' => 'from-settings', 'clientSecret' => 'secret-from-settings'],
        ]);

        // The order everything else in pig uses: what was typed, then the environment, then what
        // was chosen last time.
        $this->assertSame(['from-the-shell', 'secret-from-the-shell'], (new Auth(null, $settings))->googleClient());
    }

    public function testWithoutThemTheRefusalSaysWhereTheyGoAndThatPigHasNone(): void
    {
        $problem = $this->assertThrows(OauthError::class, fn (): array => $this->auth()->googleClient());

        // Somebody who has not got them needs both halves: where they go, and that this is not
        // something pig can supply.
        $this->assertStringContainsString('GEMINI_CLI_CLIENT_ID', $problem->getMessage());
        $this->assertStringContainsString('geminiCli.clientId', $problem->getMessage());
        $this->assertStringContainsString('does not ship', $problem->getMessage());
    }
}
