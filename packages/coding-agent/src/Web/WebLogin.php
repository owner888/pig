<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

/**
 * Who may use `pig web`: one password, the developer's, and the browsers that have given it.
 *
 * The web UI runs a terminal and agents on this machine, so it is never open: every request but
 * the login page's is refused until a browser has logged in (`Middlewares\LoginMiddleware`). One
 * person, one password — not accounts.
 *
 * - **The password** is a `password_hash()` in `web-login.json` beside the other agent files,
 *   written 0600. `pig web password` sets or changes it, and a change logs every browser out.
 * - **The first password** is set in the page, with a one-time setup code the server prints where
 *   it was started (`setupCode()`, also kept in `web-setup-code` so `pig web start -d` and
 *   `pig web status` can print it): whoever reaches the page first cannot take it without the
 *   terminal, which matters once the server listens beyond this machine.
 * - **A session** is a random token in an HttpOnly, SameSite=Strict cookie; only its SHA-256 is
 *   kept, in the same file, so a restart does not log anyone out and the file is no key.
 * - **Guessing** is slowed: ten tries a minute per address, for the password and the setup code.
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

    /** Tries per address per minute, at the password or the setup code. */
    public const int TRIES = 10;

    public const int MIN_PASSWORD = 8;

    /** Why a login or a setup was refused: the reason the page shows in its own language, and English. */
    public const array REASONS = [
        'already_set' => 'A password is already set. Log in, or change it with `pig web password`.',
        'throttled' => 'Too many tries. Wait a minute.',
        'wrong_code' => 'That is not the setup code. It is printed where `pig web` was started.',
        'weak' => 'The password needs at least ' . self::MIN_PASSWORD . ' characters.',
        'no_password' => 'No password is set yet. Set one with the setup code first.',
        'wrong_password' => 'Wrong password.',
    ];

    /** @var array<string, list<float>> address => when it tried, in the last minute */
    private array $tries = [];

    private function __construct(
        private readonly ?string $home,
        private readonly ?\Closure $now = null,
    ) {
    }

    /** The login kept in `$home` (`~/.pig/agent`). */
    public static function at(string $home, ?\Closure $now = null): self
    {
        return new self($home, $now);
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

    public function hasPassword(): bool
    {
        return is_string($this->read()['password'] ?? null);
    }

    /**
     * The one-time code the first password is set with, made when there is none; null once a
     * password is set.
     */
    public function setupCode(): ?string
    {
        if (!$this->enabled() || $this->hasPassword()) {
            return null;
        }

        $file = $this->codeFile();
        $code = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code) !== 1) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)] . ($i === 3 ? '-' : '');
            }
            self::writePrivate($file, $code . "\n");
        }

        return $code;
    }

    /**
     * The first password, given the setup code. Answers why not (a `REASONS` key), or null and a
     * session token.
     *
     * @return array{0: ?string, 1: ?string} [reason, token]
     */
    public function setUp(string $code, string $password, string $address): array
    {
        if ($this->hasPassword()) {
            return ['already_set', null];
        }
        if ($this->throttled($address)) {
            return ['throttled', null];
        }

        $expected = $this->setupCode();
        if ($expected === null || !hash_equals($expected, strtoupper(trim($code)))) {
            return ['wrong_code', null];
        }
        if (self::weak($password) !== null) {
            return ['weak', null];
        }

        $this->setPassword($password);

        return [null, $this->openSession()];
    }

    /**
     * Log in with the password. Answers why not (a `REASONS` key), or null and a session token.
     *
     * @return array{0: ?string, 1: ?string} [reason, token]
     */
    public function logIn(string $password, string $address): array
    {
        if (!$this->hasPassword()) {
            return ['no_password', null];
        }
        if ($this->throttled($address)) {
            return ['throttled', null];
        }
        if (!password_verify($password, (string) $this->read()['password'])) {
            return ['wrong_password', null];
        }

        return [null, $this->openSession()];
    }

    /**
     * Set or change the password — `pig web password`. Every browser is logged out, and the setup
     * code, if there was one, is spent.
     */
    public function setPassword(string $password): void
    {
        if (($problem = self::weak($password)) !== null) {
            throw new \InvalidArgumentException($problem);
        }

        $this->write(['password' => password_hash($password, PASSWORD_DEFAULT), 'sessions' => []]);
        if (is_file($this->codeFile())) {
            unlink($this->codeFile());
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
            self::writePrivate($this->localTokenFile(), $token . "\n");
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
        if (!$this->enabled()) {
            return true;
        }

        $token = self::tokenIn($headers);
        if ($token === null) {
            return false;
        }

        $expires = $this->read()['sessions'][hash('sha256', $token)] ?? null;

        return is_int($expires) && $expires > $this->now();
    }

    /** End the session the request's cookies hold. */
    public function logOut(array $headers): void
    {
        $token = self::tokenIn($headers);
        if (!$this->enabled() || $token === null) {
            return;
        }

        $state = $this->read();
        unset($state['sessions'][hash('sha256', $token)]);
        $this->write($state);
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

    private function openSession(): string
    {
        $token = bin2hex(random_bytes(32));
        $state = $this->read();
        $now = $this->now();
        // Sessions that have run out are dropped as new ones come, so the file does not grow.
        $state['sessions'] = array_filter($state['sessions'] ?? [], static fn (mixed $expires): bool => is_int($expires) && $expires > $now);
        $state['sessions'][hash('sha256', $token)] = $now + self::SESSION_SECONDS;
        $this->write($state);

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

    private function file(): string
    {
        return "{$this->home}/web-login.json";
    }

    private function codeFile(): string
    {
        return "{$this->home}/web-setup-code";
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

    /** @return array{password?: string, sessions?: array<string, int>} */
    private function read(): array
    {
        if (!$this->enabled() || !is_file($this->file())) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->file()), true);
        if (!is_array($state)) {
            throw new \RuntimeException("{$this->file()} is not valid JSON. Remove it and set a password again with `pig web password`.");
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        self::writePrivate($this->file(), json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    /** Written whole, readable by this user only. */
    private static function writePrivate(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $content) === false) {
            throw new \RuntimeException("Cannot write {$file}");
        }
        chmod($temp, 0o600);
        if (!rename($temp, $file)) {
            unlink($temp);
            throw new \RuntimeException("Cannot write {$file}");
        }
    }
}
