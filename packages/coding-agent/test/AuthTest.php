<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Settings;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Test\AssertsThrows;
use Pig\Test\WithoutProviderKeys;

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
    use WithoutProviderKeys;

    private string $home;

    /** Where this class writes, and what would otherwise answer for it. */
    private const array CLEARED = [
        'PIG_HOME', 'PI_HOME', 'PI_CODING_AGENT_DIR',
        // Antigravity's pair as well: with both exported in the shell running the suite, the
        // case asserting that a flow with no client credentials never opens a browser would
        // open one and fail for a reason that has nothing to do with the code.
        'ANTIGRAVITY_CLIENT_ID', 'ANTIGRAVITY_CLIENT_SECRET',
        'KEY_FOR_A_DECLARED_PROVIDER',
    ];

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-auth-' . bin2hex(random_bytes(6));

        // `apiKey()` falls through to the environment, and a real key in the shell running
        // the suite would answer some of these for the wrong reason.
        $this->forgetProviderKeys(self::CLEARED);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreProviderKeys();

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

    public function testAnOptionalFieldThatIsThereAndEmptyIsNotSet(): void
    {
        // pig never writes one — `setCredentials()` filters empties out — but this file is pi's
        // as well, so an entry can arrive with a field present and blank. Read as set, `''`
        // reaches `GithubCopilot::baseUrl()` as an enterprise host and Code Assist as a project.
        $auth = $this->given(
            '{"anthropic": {"type": "oauth", "refresh": "r", "access": "a", "expires": 99,'
            . ' "enterpriseUrl": "", "projectId": "", "email": ""}}',
        );

        $credentials = $auth->credentials(Provider::Anthropic);

        $this->assertNotNull($credentials);
        $this->assertNull($credentials->enterpriseUrl);
        $this->assertNull($credentials->projectId);
        $this->assertNull($credentials->email);
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

    // ---- a second place a provider's credentials live --------------------------------------

    public function testASecondStoreIsAskedOnlyWhenThisFileHasNothing(): void
    {
        // `auth.json` holds one credential per provider, and an extension may let somebody sign
        // in with several accounts for one — it keeps the store and copies the active one out to
        // here. `useSecondStore()` is the seam; this is the case where the copy-out never happened.
        $auth = $this->given('{}');
        $auth->useSecondStore(
            'zzp-provider',
            static fn (): ?Credentials => new Credentials('a-refresh-token', 'an-access-token', 9_000_000_000_000, projectId: 'a-cloud-project'),
            static function (): void {
            },
        );

        $credentials = $auth->credentials('zzp-provider');

        $this->assertNotNull($credentials);
        $this->assertSame('an-access-token', $credentials->access);
        $this->assertSame('a-cloud-project', $credentials->projectId);
    }

    public function testThisFileWinsWhenItHasAnEntryOfItsOwn(): void
    {
        // A credential that is in `auth.json` is the one both tools are using — the store is
        // only consulted when there is nothing here.
        $auth = $this->given(json_encode(['zzp-provider' => [
            'type' => 'oauth',
            'refresh' => 'the-one-in-auth-json',
            'access' => 'the-access-token-in-auth-json',
            'expires' => 9_000_000_000_000,
        ]]) ?: '{}');
        $auth->useSecondStore(
            'zzp-provider',
            static fn (): ?Credentials => new Credentials('the-one-in-the-store', 'the-stores-access-token', 0),
            static function (): void {
            },
        );

        $this->assertSame('the-access-token-in-auth-json', $auth->credentials('zzp-provider')?->access);
    }

    public function testNoOtherProviderLooksInThatStore(): void
    {
        // The store is one provider's, and a provider reading somebody else's credentials file
        // is a worse bug than the one this fixes.
        $auth = $this->given('{}');
        $auth->useSecondStore(
            'zzp-provider',
            static fn (): ?Credentials => new Credentials('r', 'a', 0),
            static function (): void {
            },
        );

        $this->assertNull($auth->credentials(Provider::Anthropic));
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

    public function testACodexCredentialKeepsItsAccountIdAsPiWritesIt(): void
    {
        // Upstream's `credentialsFromToken()` stores `accountId` beside the tokens, and the file is
        // shared with pi — so a rewrite that dropped it would take it out of pi's credential too.
        $this->auth()->setCredentials(Provider::OpenAiCodex, new Credentials('r3', 'a3', PHP_INT_MAX, accountId: 'acc_1'));

        $this->assertSame('acc_1', $this->auth()->credentials(Provider::OpenAiCodex)?->accountId);
        $this->assertSame('a3', $this->auth()->apiKey('openai-codex'));
        $this->assertStringContainsString('"accountId": "acc_1"', (string) file_get_contents($this->home . '/auth.json'));
    }

    public function testCodexAsksWhichWayInFirstAndEscapingThatIsACancellation(): void
    {
        $asked = null;

        $credentials = $this->auth()->login(
            Provider::OpenAiCodex,
            static function (): void {
            },
            static fn (): ?string => null,
            onSelect: static function (string $title, array $options) use (&$asked): ?string {
                $asked = [$title, array_column($options, 0)];

                return null;
            },
        );

        $this->assertNull($credentials);
        $this->assertSame(['Select OpenAI Codex login method:', ['browser', 'device_code']], $asked);
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

    // ---- which models can be talked to -------------------------------------------------------

    public function testAModelIsAvailableWhenItsProviderHasAKeyAnywhere(): void
    {
        putenv('ANTHROPIC_API_KEY=from-the-shell');
        $auth = $this->auth();

        $providers = [];

        foreach ($auth->availableModels() as $model) {
            $providers[$model->provider] = true;
        }

        // Anthropic's models and nobody else's: this container has no other provider key, and the
        // point of the list is that a picker drawn from it cannot offer a model whose every turn
        // would fail.
        $this->assertSame(['anthropic'], array_keys($providers));
        $this->assertTrue($auth->hasKeyFor('anthropic'));
        $this->assertFalse($auth->hasKeyFor('openai'));
    }

    public function testNoKeysAnywhereMeansNoModels(): void
    {
        $this->assertSame([], $this->auth()->availableModels());
    }

    public function testAnExpiredOauthTokenStillCountsAsAKey(): void
    {
        // Expired an hour ago. A refresh away, and the refresh happens when a turn needs one:
        // asking `apiKey()` here would renew every signed-in provider just to draw a list, and a
        // provider whose refresh fails throws — so one unreachable network would empty the list.
        $past = (int) (microtime(true) * 1000) - 3600_000;
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "r", "access": "old", "expires": ' . $past . '}}');

        $this->assertTrue($auth->hasKeyFor('anthropic'));
        $this->assertNotSame([], $auth->availableModels());
    }

    public function testADeclaredProvidersKeyVariableIsEnoughToBeAvailable(): void
    {
        putenv('KEY_FOR_A_DECLARED_PROVIDER=a-key');
        $auth = $this->auth();
        $auth->setCustomProviderKeys(['my-box' => 'KEY_FOR_A_DECLARED_PROVIDER']);

        $this->assertTrue($auth->hasKeyFor('my-box'));
    }

    public function testAKeyGivenOnTheCommandLineMakesItsProviderAvailable(): void
    {
        $auth = $this->auth();
        $auth->setRuntimeApiKey('openai', 'for-this-run-only');

        $this->assertTrue($auth->hasKeyFor('openai'));
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
        // Expired, and with nothing to renew it with — refused before a socket is opened. Inside
        // a coroutine, as a turn asks: outside one the stored token is answered as it is.
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "", "access": "old", "expires": 1}}');

        $problem = $this->assertThrows(OauthError::class, static fn (): ?string => Async::run(static fn (): ?string => $auth->apiKey('anthropic')));

        $this->assertStringContainsString('Could not renew', $problem->getMessage());
        // Upstream deletes the stored credential here and carries on to the environment, so
        // one blocked network call throws away a login that was fine — and the person finds
        // out by being asked to sign in again for no reason.
        $this->assertTrue($auth->has('anthropic'));
        $this->assertTrue($this->auth()->has('anthropic'), 'and it is still on disk');
    }

    public function testFreshCredentialsRenewsTheWayApiKeyDoesRatherThanHandingBackTheExpiredHalf(): void
    {
        // What `/antigravity.usage` read: `credentials()->access`, which is the stored token as it
        // stands — an hour after signing in, an expired one, and a 401 from the quota endpoint
        // that reads as a broken extension. The renewal is `apiKey()`'s and only `apiKey()`'s;
        // `freshCredentials()` is the same door for a caller that needs the parts apart. Driven
        // through the refuse-before-a-socket case, which is the one that proves the renewal
        // path is entered without a provider to talk to.
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "", "access": "old", "expires": 1}}');

        $this->assertSame('old', $auth->credentials(Provider::Anthropic)?->access, 'the raw read is the stale one');
        $this->assertThrows(OauthError::class, static fn (): mixed => Async::run(static fn (): mixed => $auth->freshCredentials(Provider::Anthropic)), 'Could not renew');
    }

    public function testFreshCredentialsIsTheStoredOnesWhileTheyStillHold(): void
    {
        $future = (int) (microtime(true) * 1000) + 3600_000;
        $auth = $this->given('{"anthropic": {"type": "oauth", "refresh": "r", "access": "sk-ant-oat-live", "expires": ' . $future . '}}');

        $this->assertSame('sk-ant-oat-live', $auth->freshCredentials(Provider::Anthropic)?->access);
        $this->assertNull($auth->freshCredentials(Provider::GithubCopilot));
    }

    // ---- signing in ----------------------------------------------------------------------

    public function testSigningInShowsAUrlWithTheChallengeInIt(): void
    {
        $seen = null;

        // The copy-code way in, chosen through `$onSelect`. Escaping the paste box: nothing came
        // back, so nothing is exchanged, no request is made, and this is a cancellation rather
        // than a failure — which is why it answers null instead of throwing.
        $credentials = $this->auth()->login(
            Provider::Anthropic,
            static function (string $url, ?string $instructions) use (&$seen): void {
                $seen = $url;
            },
            static fn (string $message, string $placeholder, bool $allowEmpty): ?string => null,
            onSelect: static fn (string $title, array $options): ?string => Anthropic::METHOD_COPY_CODE,
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
            onSelect: static fn (string $title, array $options): ?string => Anthropic::METHOD_COPY_CODE,
        );

        $this->assertNull($credentials);
    }

    public function testAnthropicAsksWhichWayInFirstAndEscapingThatIsACancellation(): void
    {
        $asked = null;
        $shown = 0;

        $credentials = $this->auth()->login(
            Provider::Anthropic,
            static function (string $url, ?string $instructions) use (&$shown): void {
                $shown++;
            },
            static fn (string $message, string $placeholder, bool $allowEmpty): ?string => 'c#s',
            onSelect: static function (string $title, array $options) use (&$asked): ?string {
                $asked = $options;

                return null;
            },
        );

        // pi 1.0's two methods, browser first because it is the default; escaping the choice
        // sends nobody anywhere.
        $this->assertNull($credentials);
        $this->assertSame(
            [Anthropic::METHOD_BROWSER, Anthropic::METHOD_COPY_CODE],
            array_column((array) $asked, 0),
        );
        $this->assertSame(0, $shown);
    }

    public function testAnExtensionsFlowIsSignedInThroughTheSameDoorAndStoredUnderItsId(): void
    {
        // `login()` takes an `OauthFlow` as well as the built-in enum: what an extension registers
        // reaches `/login` through `Auth::signIns()` and is stored under the id the flow names.
        $flow = new class implements OauthFlow {
            public function id(): string
            {
                return 'zzp-provider';
            }

            public function label(): string
            {
                return 'Probe';
            }

            public function isSubscription(): bool
            {
                return false;
            }

            public function login(Closure $onAuth, Closure $onPrompt, ?Closure $onProgress = null, ?AbortSignal $signal = null, ?Closure $onSelect = null): ?Credentials
            {
                $onAuth('https://probe.invalid/authorize', null);

                return new Credentials('r', 'sk-probe', 9_000_000_000_000);
            }

            public function refresh(Credentials $credentials): Credentials
            {
                return $credentials;
            }

            public function apiKey(Credentials $credentials): string
            {
                return $credentials->access;
            }
        };

        $shown = null;
        $credentials = $this->auth()->login(
            $flow,
            static function (string $url, ?string $instructions) use (&$shown): void {
                $shown = $url;
            },
            static fn (string $message, string $placeholder, bool $allowEmpty): ?string => null,
        );

        $this->assertSame('sk-probe', $credentials?->access);
        $this->assertSame('https://probe.invalid/authorize', $shown);
        $this->assertSame('oauth', $this->auth()->kind('zzp-provider'));
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

    public function testAnExpiredTokenOutsideACoroutineReturnsWithoutCrashingOnFutureAwait(): void
    {
        $auth = $this->auth();
        // A credential expired 1 hour ago
        $expired = new Credentials(
            refresh: 'r_token',
            access: 'old_access',
            expires: (time() - 3600) * 1000,
        );
        $auth->setCredentials(Provider::Anthropic, $expired);

        // Outside a coroutine (e.g. during startup, restoreSettings, or peeking): must not throw
        $this->assertNull(\Fiber::getCurrent());
        $key = $auth->apiKey(Provider::Anthropic->value);
        $this->assertNotNull($key);
        $this->assertStringContainsString('old_access', $key);
    }
}
