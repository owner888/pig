<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Extension\EnvApiKeyAuth;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Utils\Oauth\OauthCredentials;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Cli\AuthLogin;
use Pig\CodingAgent\Cli\AuthLogout;
use Pig\CodingAgent\ModelsRefreshResult;

/**
 * Upstream's `auth-login.test.ts` and `auth-logout.test.ts`: `pig auth login` and `logout`, with a
 * scripted terminal and a provider of the test's own — an account sign-in that asks which way in,
 * as Anthropic's and OpenAI's do, and an API key.
 */
final class AuthLoginTest extends TestCase
{
    private string $home;

    /** `PIG_OFFLINE` as the suite had it, put back afterwards: the catalog refresh reads it. */
    private string|false $offline = false;

    /** @var list<string> */
    private array $out = [];

    /** @var list<string> */
    private array $err = [];

    /** @var list<string> */
    private array $opened = [];

    /** @var list<string> the questions asked, secret ones marked */
    private array $asked = [];

    /** @var list<?string> what the terminal answers, in order */
    private array $answers = [];

    /** @var list<array{0: string, 1: string}> the ways in the test flow offers */
    private array $methods = [['browser', 'Browser login'], ['code', 'Copy code login']];

    /** @var ?string the way in the flow was given */
    private ?string $picked = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-auth-login-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        $this->offline = getenv('PIG_OFFLINE');
        putenv('PIG_OFFLINE');

        $test = $this;
        ProviderRegistry::register(new Provider('zzp-login', 'Zzp Account', [], oauth: new class($test) implements OauthFlow {
            public function __construct(private readonly AuthLoginTest $test)
            {
            }

            public function id(): string
            {
                return 'zzp-login';
            }

            public function label(): string
            {
                return 'Zzp Account';
            }

            public function isSubscription(): bool
            {
                return false;
            }

            public function login(Closure $onAuth, Closure $onPrompt, ?Closure $onProgress = null, ?AbortSignal $signal = null, ?Closure $onSelect = null): ?OauthCredentials
            {
                return $this->test->runFlow($onAuth, $onPrompt, $onSelect);
            }

            public function refresh(OauthCredentials $credentials): OauthCredentials
            {
                return $credentials;
            }

            public function apiKey(OauthCredentials $credentials): string
            {
                return $credentials->access;
            }
        }));
        ProviderRegistry::register(new Provider('zzp-key', 'Zzp Key', [], apiKeyAuth: new EnvApiKeyAuth('Zzp API key', [])));
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        putenv($this->offline === false ? 'PIG_OFFLINE' : "PIG_OFFLINE={$this->offline}");
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    /** The test flow: which way in, then the URL, then — for the copy-code way — the pasted code. */
    public function runFlow(Closure $onAuth, Closure $onPrompt, ?Closure $onSelect): ?OauthCredentials
    {
        $this->picked = $onSelect === null ? 'browser' : $onSelect('Select login method', $this->methods);

        if ($this->picked === null) {
            return null;
        }

        $onAuth('https://login.invalid/authorize?x=1', null);

        if ($this->picked !== 'browser' && $onPrompt('Paste the code', 'code#state', false) === null) {
            return null;
        }

        return new OauthCredentials('r', 'sk-zzp', 9_000_000_000_000);
    }

    private function auth(): Auth
    {
        return Auth::discover($this->home . '/auth.json');
    }

    /** @param list<string> $args */
    private function login(array $args, bool $interactive = true, ?Closure $refresh = null, ?AbortSignal $signal = null): int
    {
        $answer = function (string $message, AbortSignal $signal, bool $secret): ?string {
            $this->asked[] = ($secret ? '[secret] ' : '') . $message;

            return array_shift($this->answers);
        };

        return (new AuthLogin(
            $this->auth(),
            function (string $line): void { $this->out[] = $line; },
            function (string $line): void { $this->err[] = $line; },
            static fn (string $message, AbortSignal $signal): ?string => $answer($message, $signal, false),
            static fn (string $message, AbortSignal $signal): ?string => $answer($message, $signal, true),
            function (string $url): void { $this->opened[] = $url; },
            $interactive,
            $refresh ?? static fn (string $provider, AbortSignal $signal): ModelsRefreshResult => new ModelsRefreshResult(false, []),
        ))->run($args, $signal ?? (new AbortController())->signal);
    }

    public function testAccountSignInTakesTheBrowserAndNamesTheOtherWayIn(): void
    {
        $this->assertSame(0, $this->login(['zzp-login']));

        $this->assertSame('browser', $this->picked);
        $this->assertSame(['https://login.invalid/authorize?x=1'], $this->opened);
        $this->assertContains('To use "Copy code login" instead, run "pig auth login zzp-login --no-browser"', $this->out);
        $this->assertContains("Logged in to Zzp Account. Credentials saved to {$this->home}/auth.json", $this->out);
        $this->assertSame('oauth', $this->auth()->kind('zzp-login'));
        $this->assertSame([], $this->err);
    }

    public function testTheProviderIsFoundByItsNameAndCaseDoesNotMatter(): void
    {
        $this->assertSame(0, $this->login(['  ZZP account ']));
        $this->assertSame('oauth', $this->auth()->kind('zzp-login'));
    }

    public function testNoBrowserTakesTheOtherWayInWithoutOpeningAnything(): void
    {
        $this->answers = ['code#state'];

        $this->assertSame(0, $this->login(['zzp-login', '--no-browser']));

        $this->assertSame('code', $this->picked);
        $this->assertSame([], $this->opened, 'the URL is printed, not opened');
        $this->assertContains('Select login method: Copy code login', $this->out);
        $this->assertSame(['Paste the code (code#state): '], $this->asked);
        $this->assertNotContains('To use "Copy code login" instead, run "pig auth login zzp-login --no-browser"', $this->out);
    }

    public function testNoBrowserAsksWhenThereAreSeveralOtherWays(): void
    {
        $this->methods = [['browser', 'Browser login'], ['code', 'Copy code login'], ['device', 'Device code']];
        $this->answers = ['7', '2', 'code'];

        $this->assertSame(0, $this->login(['zzp-login', '--no-browser']));

        $this->assertSame('device', $this->picked);
        $this->assertSame(['  1. Copy code login', '  2. Device code'], array_values(array_filter($this->out, static fn (string $line): bool => str_starts_with($line, '  '))));
        $this->assertContains('Enter a number from 1 to 2.', $this->out);
    }

    public function testSeveralOtherWaysAreNamedUnderTheUrl(): void
    {
        $this->methods = [['browser', 'Browser login'], ['code', 'Copy code login'], ['device', 'Device code']];

        $this->assertSame(0, $this->login(['zzp-login']));

        $this->assertContains('Other sign-in methods: "Copy code login", "Device code". Run "pig auth login zzp-login --no-browser" to choose one.', $this->out);
    }

    public function testAnApiKeyIsAskedForWithoutEchoAndSaved(): void
    {
        $this->answers = ['sk-typed'];

        $this->assertSame(0, $this->login(['zzp-key']));

        $this->assertSame(['[secret] Enter Zzp API key: '], $this->asked);
        $this->assertSame('api_key', $this->auth()->kind('zzp-key'));
        $this->assertContains("Saved API key for Zzp Key. Credentials saved to {$this->home}/auth.json", $this->out);
    }

    public function testApiKeyIsRefusedForAProviderThatHasNone(): void
    {
        $this->assertSame(1, $this->login(['zzp-login', '--api-key']));
        $this->assertSame(['Zzp Account has no API key login. Omit --api-key to sign in.'], $this->err);
    }

    public function testAMalformedCommandIsTheUsage(): void
    {
        foreach ([[], ['a', 'b'], ['--frobnicate', 'zzp-login']] as $args) {
            $this->err = [];
            $this->assertSame(1, $this->login($args));
            $this->assertSame(['Usage: pig auth login <provider> [--api-key] [--no-browser]'], $this->err);
        }

        $this->err = [];
        $this->assertSame(1, $this->login(['zzp-login', '--api-key', '--no-browser']));
        $this->assertSame(['--no-browser only applies to account sign-in; omit it with --api-key.'], $this->err);
    }

    public function testAnUnknownProviderListsTheOnesThereAre(): void
    {
        $this->assertSame(1, $this->login(["no\x1bsuch"]));

        $this->assertSame('Unknown provider "no?such".', $this->err[0], 'an escape typed in is not sent to the terminal');
        $this->assertStringStartsWith('Providers: ', $this->err[1]);
        $this->assertStringContainsString('zzp-key', $this->err[1]);
        $this->assertStringContainsString('zzp-login', $this->err[1]);
    }

    public function testItNeedsATerminal(): void
    {
        $this->assertSame(1, $this->login(['zzp-login'], interactive: false));
        $this->assertSame(['"pig auth login <provider> [--api-key] [--no-browser]" requires an interactive terminal'], $this->err);
    }

    public function testACancelledLoginStoresNothing(): void
    {
        $this->answers = [null];

        $this->assertSame(1, $this->login(['zzp-login', '--no-browser']));

        $this->assertSame(['Login cancelled.'], $this->err);
        $this->assertNull($this->auth()->kind('zzp-login'));
    }

    public function testACatalogThatCannotBeRefreshedLeavesTheLoginStanding(): void
    {
        $refresh = static fn (string $provider, AbortSignal $signal): ModelsRefreshResult => new ModelsRefreshResult(false, [$provider => new \RuntimeException('down')]);

        $this->assertSame(0, $this->login(['zzp-login'], refresh: $refresh));

        $this->assertContains('The Zzp Account model catalog could not be refreshed; pig retries on next start.', $this->out);
        $this->assertSame('oauth', $this->auth()->kind('zzp-login'));
    }

    // ---- logout -------------------------------------------------------------------------------

    /** @param list<string> $args */
    private function logout(array $args): int
    {
        return (new AuthLogout(
            $this->auth(),
            function (string $line): void { $this->out[] = $line; },
            function (string $line): void { $this->err[] = $line; },
        ))->run($args);
    }

    public function testLogoutRemovesAnAccountAndSaysSo(): void
    {
        $this->login(['zzp-login']);
        $this->out = [];

        $this->assertSame(0, $this->logout(['zzp-login']));

        $this->assertSame(['Logged out of Zzp Account.'], $this->out);
        $this->assertNull($this->auth()->kind('zzp-login'));
    }

    public function testLogoutRemovesAKeyAndLeavesTheEnvironment(): void
    {
        $this->auth()->setApiKey('zzp-key', 'sk-stored');

        $this->assertSame(0, $this->logout(['Zzp Key']));

        $this->assertSame(['Removed stored API key for Zzp Key. Environment variables and models.json config are unchanged.'], $this->out);
        $this->assertNull($this->auth()->kind('zzp-key'));
    }

    public function testLogoutWithNothingStoredIsNotAnError(): void
    {
        $this->assertSame(0, $this->logout(['zzp-login']));
        $this->assertSame(['No stored credentials for Zzp Account. Environment variables and models.json config are unchanged.'], $this->out);
    }

    public function testLogoutOfAnUnknownProviderListsWhatIsStored(): void
    {
        $this->auth()->setApiKey('zzp-key', 'sk-stored');

        $this->assertSame(1, $this->logout(['nobody']));
        $this->assertSame(['Unknown provider "nobody".', 'Stored credentials: zzp-key'], $this->err);
    }

    public function testAStoredCredentialOfAProviderNotLoadedHereIsStillRemoved(): void
    {
        // An extension's provider is not loaded for `pig auth`: its stored id matches on its own.
        $this->auth()->setApiKey('from-an-extension', 'sk-x');

        $this->assertSame(0, $this->logout(['from-an-extension']));
        $this->assertNull($this->auth()->kind('from-an-extension'));
    }

    public function testLogoutUsage(): void
    {
        $this->assertSame(1, $this->logout([]));
        $this->assertSame(1, $this->logout(['a', 'b']));
        $this->assertSame(['Usage: pig auth logout <provider>', 'Usage: pig auth logout <provider>'], $this->err);
    }
}
