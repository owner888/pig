<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PDO;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;
use Pig\CodingAgent\Web\WebLogin;

/**
 * `pig web` with accounts, as with sessions kept in a database (`Accounts\SqlAccounts`): the first
 * made in the page and an admin, logging in by name, a password change ending only that
 * account's browsers, and removing. On SQLite; `MysqlWebLoginAccountsTest` runs the same on MySQL.
 */
class WebLoginAccountsTest extends TestCase
{
    /** Empty until `setUp()` gets as far as making it: a skipped test has nothing to remove. */
    protected string $home = '';

    protected int $now = 1_800_000_000;

    private string $file;

    private ?Database $db = null;

    #[\Override]
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_sqlite');
        }

        $this->home = sys_get_temp_dir() . '/pig-web-accounts-' . bin2hex(random_bytes(4));
        $this->file = $this->home . '/pig.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db = null;

        if ($this->home !== '') {
            shell_exec('rm -rf ' . escapeshellarg($this->home));
        }
    }

    protected function database(): Database
    {
        return $this->db ??= Databases::open('sqlite:' . $this->file);
    }

    private function login(): WebLogin
    {
        return WebLogin::at($this->home, fn (): int => $this->now, $this->database());
    }

    /** @return array<string, string> */
    private static function with(?string $token): array
    {
        return $token === null ? [] : ['cookie' => WebLogin::COOKIE . "={$token}"];
    }

    /** The first account, `ada`, an admin, as the page makes it. */
    private function setUpAda(): string
    {
        $login = $this->login();
        [$error, $token] = $login->setUp('ada', 'correct horse', '1.2.3.4');
        $this->assertNull($error);

        return (string) $token;
    }

    public function testTheFirstAccountIsMadeWithTheSetupCodeAndIsAnAdmin(): void
    {
        $login = $this->login();
        $this->assertTrue($login->several());
        $this->assertFalse($login->hasPassword());
        $this->assertSame('bad_name', $login->setUp('', 'correct horse', '1.2.3.4')[0]);
        $this->assertSame('bad_name', $login->setUp('a b', 'correct horse', '1.2.3.4')[0]);

        [$error, $token] = $login->setUp('ada', 'correct horse', '1.2.3.4');
        $this->assertNull($error);
        $user = $login->user(self::with($token));
        $this->assertNotNull($user);
        $this->assertSame(['name' => 'ada', 'admin' => true, 'runtime' => 'host'], $user->toArray());
        $this->assertFileDoesNotExist("{$this->home}/web-login.json", 'nothing in the file with accounts');
        $this->assertSame('already_set', $login->setUp('bob', 'correct horse', '1.2.3.4')[0], 'nobody makes another this way');
    }

    public function testLoggingInTakesTheNameAndThePassword(): void
    {
        $this->setUpAda();
        $this->login()->accounts()->add('bob', 'battery staple', false);

        $this->assertSame('wrong_login', $this->login()->logIn('bob', 'correct horse', '1.2.3.4')[0]);
        $this->assertSame('wrong_login', $this->login()->logIn('nobody', 'correct horse', '1.2.3.4')[0], 'the same answer: no names are told');

        [$error, $token] = $this->login()->logIn('bob', 'battery staple', '1.2.3.4');
        $this->assertNull($error);
        $this->assertSame(['name' => 'bob', 'admin' => false, 'runtime' => 'host'], $this->login()->user(self::with($token))?->toArray());

        $this->now += WebLogin::SESSION_SECONDS + 1;
        $this->assertNull($this->login()->user(self::with($token)), 'runs out');
    }

    public function testAPasswordChangeEndsThatAccountsBrowsersOnly(): void
    {
        $ada = $this->setUpAda();
        $this->login()->accounts()->add('bob', 'battery staple', false);
        [, $bob] = $this->login()->logIn('bob', 'battery staple', '1.2.3.4');

        $this->login()->setPassword('new battery', 'bob');
        $this->assertFalse($this->login()->isLoggedIn(self::with($bob)));
        $this->assertTrue($this->login()->isLoggedIn(self::with($ada)));
        $this->assertNotNull($this->login()->logIn('bob', 'new battery', '1.2.3.4')[1]);

        $this->login()->logOut(self::with($ada));
        $this->assertFalse($this->login()->isLoggedIn(self::with($ada)));

        $this->expectExceptionMessage('There is no account named carol.');
        $this->login()->setPassword('long enough', 'carol');
    }

    public function testThePasswordCommandMakesTheFirstAccountWhenThereIsNone(): void
    {
        $this->login()->setPassword('correct horse', 'ada');

        $this->assertSame([['name' => 'ada', 'admin' => true, 'runtime' => 'host']], array_map(static fn ($u) => $u->toArray(), $this->login()->accounts()->users()));
    }

    public function testRemovingAnAccountEndsItsSessionsFreesItsNameAndKeepsAnAdmin(): void
    {
        $this->setUpAda();
        $accounts = $this->login()->accounts();
        $accounts->add('bob', 'battery staple', false);
        [, $bob] = $this->login()->logIn('bob', 'battery staple', '1.2.3.4');

        try {
            $accounts->add('bob', 'another one', false);
            $this->fail('a name is one account');
        } catch (\InvalidArgumentException) {
        }

        $this->assertTrue($accounts->remove('bob'));
        $this->assertFalse($this->login()->isLoggedIn(self::with($bob)));
        $this->assertSame('wrong_login', $this->login()->logIn('bob', 'battery staple', '1.2.3.4')[0]);
        $this->assertFalse($accounts->remove('bob'), 'gone');

        $accounts->add('bob', 'a new bob', false);
        $this->assertNotNull($this->login()->logIn('bob', 'a new bob', '1.2.3.4')[1], 'the name is free again');

        try {
            $accounts->remove('ada');
            $this->fail('the last admin stays');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('last admin', $error->getMessage());
        }

        $accounts->add('cy', 'another admin', true);
        $this->assertTrue($accounts->remove('ada'), 'with another admin there');
    }

    public function testAnAccountIsMadeAnAdminOrNotAndRunsItsToolsOnTheHostOrInDocker(): void
    {
        $this->setUpAda();
        $accounts = $this->login()->accounts();
        $bob = $accounts->add('bob', 'battery staple', false, 'docker');
        $this->assertSame('docker', $bob->runtime);

        $this->assertTrue($accounts->update('bob', admin: true));
        $this->assertTrue($accounts->update('bob', runtime: 'host'));
        $this->assertSame(
            [['name' => 'ada', 'admin' => true, 'runtime' => 'host'], ['name' => 'bob', 'admin' => true, 'runtime' => 'host']],
            array_map(static fn ($u) => $u->toArray(), $accounts->users()),
        );
        $this->assertFalse($accounts->update('carol', admin: true), 'no such account');

        $this->assertTrue($accounts->update('ada', admin: false), 'bob is an admin now');
        try {
            $accounts->update('bob', admin: false);
            $this->fail('the last admin stays one');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('last admin', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $accounts->add('carol', 'long enough', false, 'vm');
    }

    public function testFilesHaveOneAccountNamedAsTheyAre(): void
    {
        $login = WebLogin::at($this->home, fn (): int => $this->now);
        $this->assertFalse($login->several());

        [, $token] = $login->setUp('kim', 'correct horse', '1.2.3.4');
        $this->assertSame(['name' => 'kim', 'admin' => true, 'runtime' => 'host'], $login->user(self::with($token))?->toArray());

        $this->expectExceptionMessage('one account');
        $login->accounts()->add('lee', 'long enough', false);
    }
}
