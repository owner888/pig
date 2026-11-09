<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\CodingAgent\Accounts\Accounts;
use Pig\CodingAgent\Accounts\FileAccounts;
use Pig\CodingAgent\Accounts\SqlAccounts;
use Pig\CodingAgent\Accounts\User;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\SqlSessionStore;
use Pig\CodingAgent\Settings;

/**
 * Who may use `pig web`, and the browsers that have logged in.
 *
 * The web UI runs a terminal and agents on this machine, so it is never open: every request but
 * the login page's is refused until a browser has logged in (`Middlewares\LoginMiddleware`).
 * Who may is kept by `Accounts\Accounts` (`configured()` picks one by the `storage` setting):
 *
 * - **Sessions in files:** one account, the admin (`FileAccounts`, `web-login.json`).
 * - **Sessions in a database:** the accounts in the same database (`SqlAccounts`), the first of
 *   them an admin, who adds the others (phase 4).
 *
 * The rest is the same for both:
 *
 * - **Logging in** is a name and a password, either way.
 * - **The password** is a `password_hash()`. `pig web password <name>` sets or changes one, and a
 *   change logs that account's browsers out.
 * - **The first account** is made in the page by whoever opens it first while there is none, or
 *   with `pig web password <name>` on the server. (The one-time setup code that used to guard it
 *   is gone, the developer's call: `pig web` listens on this machine alone unless told otherwise,
 *   and whoever makes it listen further makes the account before saying where.)
 * - **A session** is a random token in an HttpOnly, SameSite=Strict cookie; only its SHA-256 is
 *   kept, so a restart does not log anyone out and what is kept is no key.
 * - **Guessing** is slowed: ten tries a minute per address.
 * - **pig itself**, from a terminal or a conversation in the page, reaches the scheduled tasks with
 *   a bearer token instead (`localToken()`, `web-local-token`, 0600): the `schedule` tool asks
 *   the server that runs the schedule to change it. Readable only by this user, as everything in
 *   the agent directory is, and good for `/api/scheduled` from this machine and nothing else
 *   (`Middlewares\LoginMiddleware`).
 */
final class WebLogin
{
    public const string COOKIE = 'pig_session';

    /** How long a browser stays logged in. */
    public const int SESSION_SECONDS = 30 * 24 * 3600;

    /** Tries per address per minute, at logging in or making the first account. */
    public const int TRIES = 10;

    public const int MIN_PASSWORD = 8;

    /** Why a login or a setup was refused: the reason the page shows in its own language, and English. */
    public const array REASONS = [
        'already_set' => 'There is an account already. Log in, or change a password with `pig web password <name>`.',
        'bad_name' => 'A name is 1 to 64 letters, digits, dots, dashes or underscores.',
        'throttled' => 'Too many tries. Wait a minute.',
        'weak' => 'The password needs at least ' . self::MIN_PASSWORD . ' characters.',
        'no_password' => 'There is no account yet. Make the first one.',
        'wrong_login' => 'Wrong name or password.',
    ];

    /** @var array<string, list<float>> address => when it tried, in the last minute */
    private array $tries = [];

    private ?Accounts $accounts = null;

    private function __construct(
        private readonly ?string $home,
        private readonly ?\Closure $now = null,
        private readonly ?Database $database = null,
    ) {
    }

    /**
     * The login kept in `$home` (`~/.pig/agent`): the one password there, or the accounts in
     * `$database`.
     */
    public static function at(string $home, ?\Closure $now = null, ?Database $database = null): self
    {
        return new self($home, $now, $database);
    }

    /**
     * The login this pig's `storage` setting means: the accounts in the database the sessions are
     * kept in (the one already open, or opened from the setting), or the one password in `$home`.
     *
     * @throws \Pig\Agent\AgentError|\Pig\CodingAgent\CodingAgentError when the database cannot be opened
     */
    public static function configured(string $home): self
    {
        $store = SessionManager::sessionStore();

        if ($store instanceof SqlSessionStore) {
            return self::at($home, database: $store->database());
        }

        $storage = Settings::startupStorage($home);

        return self::at($home, database: $storage === null ? null : Databases::open($storage['dsn'], $storage['user'], $storage['password'], $storage['prefix']));
    }

    /**
     * Who may log in — what the page that manages accounts works on. Made when first asked, so the
     * tables are only made where the login is used.
     */
    public function accounts(): Accounts
    {
        if ($this->home === null) {
            throw new \LogicException('The login is off: there are no accounts.');
        }

        return $this->accounts ??= $this->database !== null ? new SqlAccounts($this->database) : new FileAccounts($this->home);
    }

    /**
     * No login at all: every request is let through. For tests of the server's other behaviour,
     * which talk to it without a browser — never what `pig web` runs with.
     */
    public static function off(): self
    {
        return new self(null);
    }

    public function enabled(): bool
    {
        return $this->home !== null;
    }

    /** Whether there is an account (the password, without accounts) yet. */
    public function hasPassword(): bool
    {
        return $this->enabled() && $this->accounts()->any();
    }

    /** Whether there can be more than one account (sessions in a database), which the admin manages. */
    public function several(): bool
    {
        return $this->enabled() && $this->accounts()->several();
    }

    /** What a name may be: what fits in a column and reads well in a list. */
    public static function validName(string $name): bool
    {
        return preg_match('/^[\p{L}\p{N}._-]{1,64}$/u', $name) === 1;
    }

    /**
     * The first account, an admin, made in the page while there is none. Answers why not (a
     * `REASONS` key), or null and a session token.
     *
     * @return array{0: ?string, 1: ?string} [reason, token]
     */
    public function setUp(string $name, string $password, string $address): array
    {
        if ($this->hasPassword()) {
            return ['already_set', null];
        }
        if ($this->throttled($address)) {
            return ['throttled', null];
        }
        if (!self::validName($name)) {
            return ['bad_name', null];
        }
        if (self::weak($password) !== null) {
            return ['weak', null];
        }

        $user = $this->accounts()->add($name, $password, true);
        $this->forgetSetupCode();

        return [null, $this->openSession($user)];
    }

    /**
     * Log in with a name and its password. Answers why not (a `REASONS` key), or null and a session
     * token. A wrong name is answered as a wrong password is, so nobody learns the names.
     *
     * @return array{0: ?string, 1: ?string} [reason, token]
     */
    public function logIn(string $name, string $password, string $address): array
    {
        if (!$this->hasPassword()) {
            return ['no_password', null];
        }
        if ($this->throttled($address)) {
            return ['throttled', null];
        }

        $user = $this->accounts()->verify($name, $password);
        if ($user === null) {
            return ['wrong_login', null];
        }

        return [null, $this->openSession($user)];
    }

    /**
     * Set or change `$name`'s password — `pig web password <name>`. Its browsers are logged out.
     * With no account yet, `$name` becomes the first, an admin, as the page would have made it.
     * With files, a name not given is the one account's.
     *
     * @throws \InvalidArgumentException saying what is wrong with the password or the name
     */
    public function setPassword(string $password, string $name = ''): void
    {
        if (($problem = self::weak($password)) !== null) {
            throw new \InvalidArgumentException($problem);
        }

        if ($name === '' && !$this->several()) {
            $name = $this->accounts()->users()[0]->name ?? FileAccounts::NAME;
        }

        if (!self::validName($name)) {
            throw new \InvalidArgumentException(self::REASONS['bad_name']);
        }

        if (!$this->accounts()->any()) {
            $this->accounts()->add($name, $password, true);
        } elseif (!$this->accounts()->setPassword($name, $password)) {
            throw new \InvalidArgumentException("There is no account named {$name}.");
        }

        $this->forgetSetupCode();
    }

    /** The file of the setup code older versions printed, which nothing reads any more. */
    private function forgetSetupCode(): void
    {
        $file = "{$this->home}/web-setup-code";

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * The token pig on this machine sends as `Authorization: Bearer …` to reach the scheduled
     * tasks — made the first time it is asked for. Not for a browser: it never leaves this machine.
     */
    public function localToken(): string
    {
        if (!$this->enabled()) {
            throw new \LogicException('The login is off: there is no local token.');
        }

        $token = $this->readLocalToken();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            FileAccounts::writePrivate($this->localTokenFile(), $token . "\n");
        }

        return $token;
    }

    /** Whether the request carries the local token (`localToken()`) — never, when none was made. */
    public function hasLocalToken(array $headers): bool
    {
        $expected = $this->enabled() ? $this->readLocalToken() : null;
        if ($expected === null || preg_match('/^Bearer\s+([0-9a-f]{64})$/', trim($headers['authorization'] ?? ''), $m) !== 1) {
            return false;
        }

        return hash_equals($expected, $m[1]);
    }

    /** Whether the request's cookies hold a live session — always, when the login is off. */
    public function isLoggedIn(array $headers): bool
    {
        return $this->user($headers) !== null;
    }

    /**
     * Who the request's cookies are logged in as, or null. With the login off, an unnamed admin.
     *
     * @param array<string, string> $headers lower-cased names
     */
    public function user(array $headers): ?User
    {
        if (!$this->enabled()) {
            return new User('', '', true);
        }

        $token = self::tokenIn($headers);

        return $token === null ? null : $this->accounts()->sessionUser(hash('sha256', $token), $this->now());
    }

    /**
     * What identifies the session the request's cookies hold — the key its row is kept under —
     * for the server to tie a WebSocket to the session it was opened with, so that logging the
     * session out can close the sockets that would otherwise keep a page alive on it. Null when
     * the login is off or there is no session cookie.
     *
     * @param array<string, string> $headers lower-cased names
     */
    public function sessionKey(array $headers): ?string
    {
        $token = self::tokenIn($headers);

        return $this->enabled() && $token !== null ? hash('sha256', $token) : null;
    }

    /** End the session the request's cookies hold. */
    public function logOut(array $headers): void
    {
        $token = self::tokenIn($headers);
        if (!$this->enabled() || $token === null) {
            return;
        }

        $this->accounts()->closeSession(hash('sha256', $token));
    }

    /** The `Set-Cookie` that carries a new session, or ends one when `$token` is null. */
    public static function cookie(?string $token): string
    {
        return $token === null
            ? self::COOKIE . '=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0'
            : self::COOKIE . "={$token}; Path=/; HttpOnly; SameSite=Strict; Max-Age=" . self::SESSION_SECONDS;
    }

    /** Why a password will not do, or null. */
    public static function weak(string $password): ?string
    {
        return mb_strlen($password) < self::MIN_PASSWORD ? self::REASONS['weak'] : null;
    }

    private function openSession(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->accounts()->openSession($user, hash('sha256', $token), $now + self::SESSION_SECONDS, $now);

        return $token;
    }

    /** Count a try from `$address`, and say whether it is one too many. */
    private function throttled(string $address): bool
    {
        $now = $this->now();
        $recent = array_values(array_filter($this->tries[$address] ?? [], static fn (float $at): bool => $at > $now - 60));
        $recent[] = $now;
        $this->tries[$address] = $recent;

        return count($recent) > self::TRIES;
    }

    /** @param array<string, string> $headers lower-cased names, as `Http` parses them */
    private static function tokenIn(array $headers): ?string
    {
        foreach (explode(';', $headers['cookie'] ?? '') as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === self::COOKIE && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function now(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }

    private function localTokenFile(): string
    {
        return "{$this->home}/web-local-token";
    }

    private function readLocalToken(): ?string
    {
        $file = $this->localTokenFile();
        $token = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }
}
