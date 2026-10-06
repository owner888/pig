<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\Ai\Timestamp;
use Pig\Ai\Utils\Oauth\Credentials;

/**
 * `antigravity-accounts.json`, the several sign-ins the `pi-antigravity` extension keeps.
 *
 * `auth.json` holds **one** credential per provider, so an extension that lets somebody sign in
 * with two Google accounts needs somewhere else to put the second. This is that place, and the
 * relationship between the two files is worth stating because it decides what this class is
 * for: the accounts file is the store, and `auth.json`'s `antigravity` entry is *a copy of
 * whichever account is active*. The extension keeps them in step in both directions —
 * `syncCurrentAuth()` files what `auth.json` holds if the store has never seen it, and
 * `writeActiveCredential()` copies the active account back out.
 *
 * So pig reading `auth.json` alone was already right for the ordinary case, and measuring said
 * so: on the developer's machine both files carried the same access token, the same refresh
 * token and the same expiry. What this adds is the two cases where they come apart —
 *
 * - **a store with accounts and an `auth.json` that has none**, which is what a sign-in through
 *   the extension leaves if the copy-out did not happen, and
 * - **the tokens pig renews itself**, which have to be written back to *both* or the store goes
 *   stale and the extension's next failover reaches for a token that was replaced hours ago.
 *
 * Deliberately not ported: choosing an account, removing one, and the 429 failover that walks
 * the others. Those are the extension's commands, they write the person's files on their say-so,
 * and pig has nowhere to ask. pig reads the active account and keeps what it renews honest.
 *
 * The file's shape, which is `normalizeStore()`'s reading of it rather than a guess:
 *
 * ```json
 * { "version": 1,
 *   "activeAccountId": "dev@example",
 *   "accounts": { "dev@example": {
 *     "refresh": "…", "access": "…", "expires": 1790680528726,
 *     "projectId": "…", "email": "dev@example",
 *     "accountId": "dev@example", "addedAt": 1788, "lastUsedAt": 1790 } } }
 * ```
 */
final class Accounts
{
    public const string FILE = 'antigravity-accounts.json';

    /** The only version this reads. Upstream refuses anything else outright, and so does this. */
    private const int VERSION = 1;

    /** @var array<string, array<string, mixed>> accountId => the whole entry, kept as it was read */
    private array $accounts = [];

    private ?string $active = null;

    /** @var list<string> */
    private array $problems = [];

    public function __construct(private readonly ?string $path)
    {
        $this->reload();
    }

    /**
     * The store beside a given `auth.json`.
     *
     * A sibling rather than a path of its own, because that is what makes the pair coherent:
     * `Auth::discover()` opens pi's `auth.json` when there is one and pig's otherwise, and the
     * accounts file that belongs to a credential is the one in the same directory as it.
     */
    public static function beside(?string $authPath): self
    {
        return new self($authPath === null ? null : dirname($authPath) . '/' . self::FILE);
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The account `activeAccountId` names, or null.
     *
     * Null rather than "the first one" when nothing is active, which is upstream's reading:
     * `normalizeStore()` drops an `activeAccountId` that names no account, and every method
     * that then wants one asks for it by name. Picking an account on somebody's behalf is the
     * one thing this must not do — they are different Google accounts with different quotas.
     */
    public function active(): ?Credentials
    {
        $entry = $this->active === null ? null : $this->accounts[$this->active] ?? null;

        return $entry === null ? null : self::credentials($entry);
    }

    /** @return array<string, array<string, mixed>> */
    public function accounts(): array
    {
        return $this->accounts;
    }

    public function activeId(): ?string
    {
        return $this->active;
    }

    public function activate(string $id): void
    {
        if (isset($this->accounts[$id])) {
            $this->active = $id;
            $this->save();
        }
    }

    public function remove(string $id): void
    {
        if (isset($this->accounts[$id])) {
            unset($this->accounts[$id]);
            if ($this->active === $id) {
                $this->active = array_key_first($this->accounts);
            }
            $this->save();
        }
    }

    /**
     * Switch to the next available account in the store (round-robin failover).
     *
     * @return Credentials|null Returns new active credentials if rotated, or null if only one or no accounts.
     */
    public function rotateNext(): ?Credentials
    {
        $keys = array_keys($this->accounts);
        $total = count($keys);

        if ($total <= 1) {
            return null;
        }

        $currentIndex = array_search($this->active, $keys, true);
        $nextIndex = $currentIndex === false ? 0 : ($currentIndex + 1) % $total;
        $nextId = $keys[$nextIndex];

        if ($nextId === $this->active) {
            return null;
        }

        $this->activate($nextId);

        return $this->active();
    }

    /** How many sign-ins are in the store, which is the only thing `/login` would want to say. */
    public function count(): int
    {
        return count($this->accounts);
    }

    /**
     * Write a renewed token back, against the credential it replaces.
     *
     * Upstream's `updateRememberedAccount`: the account is found by the **old** refresh token or
     * by its email, re-keyed if the email changed, and `addedAt` is carried over so the order
     * accounts were added in survives. A refresh nothing matches is left alone rather than added
     * — pig renewing a token is not pig signing somebody in, and a store this has never seen is
     * one the extension is entitled to own.
     */
    public function renewed(Credentials $previous, Credentials $next): void
    {
        $existing = $this->find($previous);

        if ($existing === null || $this->path === null) {
            return;
        }

        $id = self::idFor($next) ?? $existing;
        $was = $this->accounts[$existing];
        unset($this->accounts[$existing]);

        $now = Timestamp::nowMs();
        $this->accounts[$id] = [
            ...$was,
            'refresh' => $next->refresh,
            'access' => $next->access,
            'expires' => $next->expires,
            'projectId' => $next->projectId ?? ($was['projectId'] ?? null),
            'email' => $next->email ?? ($was['email'] ?? null),
            'accountId' => $id,
            'addedAt' => is_int($was['addedAt'] ?? null) ? $was['addedAt'] : $now,
            'lastUsedAt' => $now,
        ];

        if ($this->active === $existing || $this->active === null) {
            $this->active = $id;
        }

        $this->save();
    }

    public function reload(): void
    {
        $this->accounts = [];
        $this->active = null;

        if ($this->path === null || !is_file($this->path)) {
            return;
        }

        $raw = is_readable($this->path) ? file_get_contents($this->path) : false;

        if ($raw === false) {
            $this->problems[] = "Could not read {$this->path}";

            return;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $this->problems[] = "{$this->path} is not valid JSON, so no Antigravity accounts were read";

            return;
        }

        // Upstream's `normalizeStore()`, condition for condition: a version that is not 1 or an
        // `accounts` that is not an object means an empty store rather than a complaint, because
        // the extension writes the version and a future one is not this pig's business.
        if (($decoded['version'] ?? null) !== self::VERSION || !is_array($decoded['accounts'] ?? null)) {
            return;
        }

        foreach ($decoded['accounts'] as $key => $entry) {
            // The one thing that makes an entry an account: a refresh token. Everything else can
            // be missing, and `access` missing is ordinary — it is the half that expires.
            if (!is_array($entry) || !is_string($entry['refresh'] ?? null) || $entry['refresh'] === '') {
                continue;
            }

            // Its own `accountId` when it has one, the key it was filed under otherwise. Both
            // are normally the email; the two disagree when somebody edited the file by hand.
            $id = is_string($entry['accountId'] ?? null) && trim($entry['accountId']) !== ''
                ? $entry['accountId']
                : (string) $key;

            $this->accounts[$id] = [...$entry, 'accountId' => $id];
        }

        // `activeEmail` is what an older extension called it, and upstream still reads it.
        $active = $decoded['activeAccountId'] ?? $decoded['activeEmail'] ?? null;

        $this->active = is_string($active) && isset($this->accounts[$active]) ? $active : null;
    }

    /**
     * The account a credential belongs to, by refresh token or by email.
     *
     * Upstream's `findAccount`, including the case-folded and trimmed email comparison — a store
     * written from one sign-in and an `auth.json` written from another can disagree about the
     * capitalisation of an address and mean the same account.
     */
    private function find(Credentials $credentials): ?string
    {
        $email = $credentials->email === null ? null : mb_strtolower(trim($credentials->email));

        foreach ($this->accounts as $id => $entry) {
            if (($entry['refresh'] ?? null) === $credentials->refresh) {
                return $id;
            }

            $theirs = is_string($entry['email'] ?? null) ? mb_strtolower(trim($entry['email'])) : null;

            if ($email !== null && $email !== '' && $theirs === $email) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    private static function credentials(array $entry): ?Credentials
    {
        $refresh = $entry['refresh'] ?? null;

        if (!is_string($refresh) || $refresh === '') {
            return null;
        }

        return new Credentials(
            $refresh,
            is_string($entry['access'] ?? null) ? $entry['access'] : '',
            is_int($entry['expires'] ?? null) ? $entry['expires'] : 0,
            null,
            is_string($entry['projectId'] ?? null) ? $entry['projectId'] : null,
            is_string($entry['email'] ?? null) ? $entry['email'] : null,
        );
    }

    /** Upstream's `accountIdFor`, minus the hashed fallback — see `renewed()` for why. */
    private static function idFor(Credentials $credentials): ?string
    {
        $email = $credentials->email === null ? null : mb_strtolower(trim($credentials->email));

        return $email === null || $email === '' ? null : $email;
    }

    /**
     * `0600`, and the mode is set before there is anything to read.
     *
     * The same rule as `Auth::save()`, and this file needs it for the same reason: it holds
     * refresh tokens, and writing it and then chmodding it leaves them world-readable for as
     * long as those two calls take.
     */
    private function save(): void
    {
        if ($this->path === null) {
            return;
        }

        // `activeAccountId` is left out rather than written as null when there is none, which is
        // both what `normalizeStore()` reads and the shape the extension writes. The key order
        // is upstream's too: a file two programs take turns rewriting should not churn.
        $store = array_filter(
            [
                'version' => self::VERSION,
                'activeAccountId' => $this->active,
                'accounts' => $this->accounts,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Narrowed before there is anything in it to read, as `Auth::save()` does it and for the
        // same reason: this file holds refresh tokens.
        if ($json === false || (!is_file($this->path) && (!touch($this->path) || !chmod($this->path, 0o600)))) {
            $this->problems[] = "Could not write {$this->path}";

            return;
        }

        if (file_put_contents($this->path, $json . "\n") === false) {
            // Named and carried on where `Auth::save()` throws, and the difference is what the
            // two files are: `auth.json` not sticking is a sign-in that said it worked, and this
            // one not sticking leaves the extension holding a token it will renew itself.
            $this->problems[] = "Could not write {$this->path}";
        }
    }
}
