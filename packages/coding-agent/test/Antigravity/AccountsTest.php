<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Antigravity;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\CodingAgent\Antigravity\Accounts;

/**
 * `antigravity-accounts.json`, the several sign-ins the `pi-antigravity` extension keeps.
 *
 * The reading half is upstream's `normalizeStore()` condition for condition, and every case
 * below is one of its conditions: what makes an entry an account, where an account's id comes
 * from, and what an `activeAccountId` naming nothing means. The writing half is one method, and
 * what it has to get right is that this file belongs to another program — so a renewal updates
 * the entry it matched and leaves everything else exactly as it found it.
 */
final class AccountsTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pig-accounts-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testTheActiveAccountIsTheOneNamedRatherThanTheFirstOne(): void
    {
        // Picking one on somebody's behalf is the thing this must not do: they are different
        // Google accounts with different quotas.
        $this->write([
            'version' => 1,
            'activeAccountId' => 'second@example',
            'accounts' => [
                'first@example' => $this->account('first@example'),
                'second@example' => $this->account('second@example'),
            ],
        ]);

        $active = $this->accounts()->active();

        self::assertNotNull($active);
        self::assertSame('second@example', $active->email);
        self::assertSame('refresh-second@example', $active->refresh);
        self::assertSame('project-second@example', $active->projectId);
    }

    public function testAnActiveAccountIdNamingNothingMeansThereIsNoActiveAccount(): void
    {
        $this->write([
            'version' => 1,
            'activeAccountId' => 'removed@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        $accounts = $this->accounts();

        self::assertSame(1, $accounts->count());
        self::assertNull($accounts->active());
    }

    public function testAnActiveAccountIdNamingNothingIsNotWrittenBackOut(): void
    {
        // `active()` answers null either way — the lookup misses — so the dangling id only shows
        // up once something writes the file again, and then the extension inherits a store whose
        // active account does not exist. Upstream drops it while reading, which is what makes
        // the renewed account the active one here rather than leaving the ghost in place.
        $this->write([
            'version' => 1,
            'activeAccountId' => 'removed@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        $accounts = $this->accounts();
        $accounts->renewed(
            new Credentials('refresh-first@example', 'a', 1, null, null, 'first@example'),
            new Credentials('refresh-first@example', 'a-fresh-access-token', 2, null, null, 'first@example'),
        );

        self::assertSame('first@example', $this->read()['activeAccountId']);
        self::assertSame('a-fresh-access-token', $this->accounts()->active()?->access);
    }

    public function testTheOlderSpellingOfTheActiveAccountIsReadToo(): void
    {
        // `activeEmail` is what an earlier extension called it, and upstream still reads it.
        $this->write([
            'version' => 1,
            'activeEmail' => 'first@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        self::assertSame('first@example', $this->accounts()->active()?->email);
    }

    public function testAVersionThisDoesNotKnowIsAnEmptyStoreAndNotAComplaint(): void
    {
        // The extension writes the version, and a future one is not this pig's business.
        $this->write([
            'version' => 2,
            'activeAccountId' => 'first@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        $accounts = $this->accounts();

        self::assertSame(0, $accounts->count());
        self::assertSame([], $accounts->problems());
    }

    public function testAnEntryWithNoRefreshTokenIsNotAnAccount(): void
    {
        // The one thing that makes an entry an account. `access` missing is ordinary — it is the
        // half that expires — so only the refresh token is required.
        $this->write([
            'version' => 1,
            'accounts' => [
                'broken@example' => ['email' => 'broken@example', 'access' => 'a'],
                'fine@example' => ['refresh' => 'r', 'email' => 'fine@example'],
            ],
        ]);

        $accounts = $this->accounts();

        self::assertSame(1, $accounts->count());
    }

    public function testAnAccountIsFiledUnderTheKeyWhenItCarriesNoIdOfItsOwn(): void
    {
        $this->write([
            'version' => 1,
            'activeAccountId' => 'keyed@example',
            'accounts' => ['keyed@example' => ['refresh' => 'r', 'access' => 'a']],
        ]);

        self::assertNotNull($this->accounts()->active());
    }

    public function testARenewedTokenIsWrittenBackAndTheRestOfTheEntryIsLeftAlone(): void
    {
        // Or the extension's copy of this account goes stale: it reaches for these tokens when a
        // quota wall comes back, so one pig renewed and did not write down is one it will
        // present as valid hours after it was replaced.
        $this->write([
            'version' => 1,
            'activeAccountId' => 'first@example',
            'accounts' => ['first@example' => [
                ...$this->account('first@example'),
                'addedAt' => 1_700_000_000_000,
                'lastUsedAt' => 1_700_000_000_000,
            ]],
        ]);

        $accounts = $this->accounts();
        $previous = $accounts->active();

        self::assertNotNull($previous);

        $accounts->renewed($previous, new Credentials(
            'refresh-first@example',
            'a-fresh-access-token',
            9_000_000_000_000,
            null,
            'project-first@example',
            'first@example',
        ));

        $written = $this->read();
        $entry = $written['accounts']['first@example'];

        self::assertSame('a-fresh-access-token', $entry['access']);
        self::assertSame(9_000_000_000_000, $entry['expires']);
        self::assertSame(1_700_000_000_000, $entry['addedAt'], 'addedAt is the order accounts were added in');
        self::assertGreaterThan(1_700_000_000_000, $entry['lastUsedAt']);
        self::assertSame(1, $written['version']);
        self::assertSame('first@example', $written['activeAccountId']);

        // Read back by a fresh reader, because a file this writes is a file the extension reads.
        self::assertSame('a-fresh-access-token', $this->accounts()->active()?->access);
    }

    public function testARefreshTokenNothingMatchesIsNotAddedAsANewAccount(): void
    {
        // pig renewing a token is not pig signing somebody in, and a store this has never seen
        // is one the extension is entitled to own.
        $this->write([
            'version' => 1,
            'activeAccountId' => 'first@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        $accounts = $this->accounts();
        $accounts->renewed(
            new Credentials('a-refresh-token-from-nowhere', 'a', 1),
            new Credentials('another', 'b', 2, null, null, 'stranger@example'),
        );

        self::assertSame(1, $this->accounts()->count());
        self::assertSame('refresh-first@example', $this->accounts()->active()?->refresh);
    }

    public function testAnAccountIsFoundByItsEmailWhenTheRefreshTokenHasChanged(): void
    {
        // Upstream's `findAccount` matches on either, case-folded and trimmed: a store written
        // from one sign-in and an `auth.json` written from another can disagree about the
        // capitalisation of an address and mean the same account.
        $this->write([
            'version' => 1,
            'activeAccountId' => 'first@example',
            'accounts' => ['first@example' => $this->account('first@example')],
        ]);

        $accounts = $this->accounts();
        $accounts->renewed(
            new Credentials('a-token-that-was-rotated-away', 'a', 1, null, null, '  First@Example  '),
            new Credentials('a-rotated-refresh-token', 'b', 2, null, null, 'first@example'),
        );

        self::assertSame('a-rotated-refresh-token', $this->accounts()->active()?->refresh);
    }

    public function testNoFileAtAllIsNoAccountsAndNoComplaint(): void
    {
        // The normal case: most machines have never run the extension.
        $accounts = $this->accounts();

        self::assertSame(0, $accounts->count());
        self::assertNull($accounts->active());
        self::assertSame([], $accounts->problems());
    }

    public function testAStoreThatIsNotJsonIsNamedRatherThanIgnored(): void
    {
        file_put_contents($this->directory . '/' . Accounts::FILE, 'not json at all');

        self::assertCount(1, $this->accounts()->problems());
    }

    public function testASessionThatWritesNothingDownReadsNoStore(): void
    {
        // `--no-save` and `Auth::inMemory()` both come through here with no path.
        $accounts = Accounts::beside(null);

        self::assertNull($accounts->path());
        self::assertNull($accounts->active());
    }

    /** @return array<string, mixed> */
    private function account(string $email): array
    {
        return [
            'refresh' => 'refresh-' . $email,
            'access' => 'access-' . $email,
            'expires' => 9_000_000_000_000,
            'projectId' => 'project-' . $email,
            'email' => $email,
            'accountId' => $email,
            'addedAt' => 1,
            'lastUsedAt' => 1,
        ];
    }

    private function accounts(): Accounts
    {
        return Accounts::beside($this->directory . '/auth.json');
    }

    /** @param array<string, mixed> $store */
    private function write(array $store): void
    {
        file_put_contents($this->directory . '/' . Accounts::FILE, json_encode($store));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return json_decode((string) file_get_contents($this->directory . '/' . Accounts::FILE), true);
    }
}
