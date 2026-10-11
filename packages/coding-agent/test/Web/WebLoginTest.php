<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\WebLogin;

/**
 * `pig web`'s one password: set the first time with the code the terminal printed, then logged in
 * with, sessions that outlive a restart and run out, guessing slowed, and a change that logs
 * everybody out.
 */
final class WebLoginTest extends TestCase
{
    private string $home;

    private int $now = 1_800_000_000;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-web-login-' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->home));
    }

    private function login(): WebLogin
    {
        return WebLogin::at($this->home, fn (): int => $this->now);
    }

    /** @return array<string, string> */
    private static function with(?string $token): array
    {
        return $token === null ? [] : ['cookie' => 'theme=dark; ' . WebLogin::COOKIE . "={$token}"];
    }

    public function testTheFirstPasswordNeedsTheCodeTheTerminalPrinted(): void
    {
        $login = $this->login();
        $code = $login->setupCode();

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $code);
        $this->assertSame($code, $this->login()->setupCode(), 'the same code for the daemon and the command that started it');
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->home}/web-setup-code")), -4));

        [$error, $token] = $login->setUp('WRON-GCOD', 'long enough', '1.2.3.4');
        $this->assertNull($token);
        $this->assertSame('wrong_code', $error);

        [$error] = $login->setUp((string) $code, 'short', '1.2.3.4');
        $this->assertSame('weak', $error);

        [$error, $token] = $login->setUp(strtolower((string) $code), 'long enough', '1.2.3.4');
        $this->assertNull($error);
        $this->assertTrue($login->isLoggedIn(self::with($token)));
        $this->assertNull($login->setupCode(), 'spent');
        $this->assertFileDoesNotExist("{$this->home}/web-setup-code");
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->home}/web-login.json")), -4));

        [$error] = $login->setUp((string) $code, 'another one', '1.2.3.4');
        $this->assertSame('already_set', $error);
    }

    public function testThePasswordOpensASessionThatOutlivesARestartAndRunsOut(): void
    {
        $this->login()->setPassword('correct horse');

        [$error, $token] = $this->login()->logIn('wrong horse', '1.2.3.4');
        $this->assertSame(['wrong_password', null], [$error, $token]);

        [$error, $token] = $this->login()->logIn('correct horse', '1.2.3.4');
        $this->assertNull($error);
        $this->assertTrue($this->login()->isLoggedIn(self::with($token)), 'another instance: a restart');
        $this->assertFalse($this->login()->isLoggedIn(self::with(str_repeat('0', 64))));
        $this->assertFalse($this->login()->isLoggedIn([]));
        $this->assertStringNotContainsString((string) $token, (string) file_get_contents("{$this->home}/web-login.json"), 'only its hash is kept');

        $this->now += WebLogin::SESSION_SECONDS + 1;
        $this->assertFalse($this->login()->isLoggedIn(self::with($token)));
    }

    public function testLoggingOutAndChangingThePasswordEndSessions(): void
    {
        $this->login()->setPassword('correct horse');
        [, $a] = $this->login()->logIn('correct horse', '1.2.3.4');
        [, $b] = $this->login()->logIn('correct horse', '1.2.3.4');

        $this->login()->logOut(self::with($a));
        $this->assertFalse($this->login()->isLoggedIn(self::with($a)));
        $this->assertTrue($this->login()->isLoggedIn(self::with($b)));

        $this->login()->setPassword('battery staple');
        $this->assertFalse($this->login()->isLoggedIn(self::with($b)), 'a new password logs everybody out');
        $this->assertNull($this->login()->logIn('correct horse', '1.2.3.4')[1]);
        $this->assertNotNull($this->login()->logIn('battery staple', '1.2.3.4')[1]);
    }

    public function testGuessingIsSlowedPerAddress(): void
    {
        $login = $this->login();
        $login->setPassword('correct horse');

        for ($i = 0; $i < WebLogin::TRIES; $i++) {
            $this->assertSame('wrong_password', $login->logIn("guess {$i}", '6.6.6.6')[0]);
        }
        $this->assertSame('throttled', $login->logIn('correct horse', '6.6.6.6')[0], 'even the right one');
        $this->assertNull($login->logIn('correct horse', '1.2.3.4')[0], 'another address is not slowed');

        $this->now += 61;
        $this->assertNull($login->logIn('correct horse', '6.6.6.6')[0], 'a minute later');
    }

    public function testAWeakPasswordIsRefusedFromTheCommandLineToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->login()->setPassword('short');
    }

    public function testOffLetsEverybodyIn(): void
    {
        $off = WebLogin::off();

        $this->assertFalse($off->enabled());
        $this->assertTrue($off->isLoggedIn([]));
        $this->assertNull($off->setupCode());
    }

    public function testTheCookieIsHttpOnlyAndStrict(): void
    {
        $this->assertSame('pig_session=abc; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . WebLogin::SESSION_SECONDS, WebLogin::cookie('abc'));
        $this->assertStringContainsString('Max-Age=0', WebLogin::cookie(null));
    }

    public function testTheLocalTokenIsMadeOnceKeptPrivateAndSentAsABearer(): void
    {
        $login = $this->login();
        $this->assertFalse($login->hasLocalToken(['authorization' => 'Bearer ' . str_repeat('a', 64)]), 'none made yet: nothing matches');

        $token = $login->localToken();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $this->login()->localToken(), 'the same one for every pig on this machine');
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->home}/web-local-token")), -4));

        $this->assertTrue($login->hasLocalToken(['authorization' => "Bearer {$token}"]));
        $this->assertFalse($login->hasLocalToken(['authorization' => 'Bearer ' . str_repeat('0', 64)]));
        $this->assertFalse($login->hasLocalToken(['cookie' => WebLogin::COOKIE . "={$token}"]), 'a header, not a cookie a page could be given');
        $this->assertFalse($login->isLoggedIn(['authorization' => "Bearer {$token}"]), 'not a browser session');

        $this->expectException(\LogicException::class);
        WebLogin::off()->localToken();
    }
}
