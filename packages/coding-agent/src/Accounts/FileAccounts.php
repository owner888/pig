<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Accounts;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * The one account of a pig that keeps its sessions in files, in `web-login.json` beside the other
 * agent files, written 0600: its name, a `password_hash()`, and the login sessions by key with when
 * each runs out. It is the admin, on this machine.
 *
 * A file from before accounts had no name: its password is the account `admin`'s, so whoever set it
 * logs in as `admin` with it.
 */
final class FileAccounts implements Accounts
{
    public function __construct(private readonly string $home)
    {
    }

    /** The account's name when the file from before accounts gave none. */
    public const string NAME = 'admin';

    private function user(): User
    {
        $name = $this->read()['name'] ?? null;

        return new User('', is_string($name) && $name !== '' ? $name : self::NAME, true);
    }

    #[\Override]
    public function several(): bool
    {
        return false;
    }

    #[\Override]
    public function any(): bool
    {
        return is_string($this->read()['password'] ?? null);
    }

    #[\Override]
    public function add(string $name, string $password, bool $admin, string $runtime = User::HOST): User
    {
        if ($this->any()) {
            throw new InvalidArgumentException('With sessions kept in files there is one account, and it is there already.');
        }

        $this->write(['name' => $name, 'password' => password_hash($password, PASSWORD_DEFAULT), 'sessions' => []]);

        return $this->user();
    }

    #[\Override]
    public function verify(string $name, string $password): ?User
    {
        $hash = $this->read()['password'] ?? null;
        $user = $this->user();

        return is_string($hash) && $name === $user->name && password_verify($password, $hash) ? $user : null;
    }

    /** Change the account's password; every login session ends. False for another name. */
    #[\Override]
    public function setPassword(string $name, string $password): bool
    {
        if (!$this->any() || $name !== $this->user()->name) {
            return false;
        }

        $this->write(['name' => $name, 'password' => password_hash($password, PASSWORD_DEFAULT), 'sessions' => []]);

        return true;
    }

    #[\Override]
    public function openSession(User $user, string $key, int $expires, int $now): void
    {
        $state = $this->read();
        // Sessions that have run out are dropped as new ones come, so the file does not grow.
        $state['sessions'] = array_filter($state['sessions'] ?? [], static fn (mixed $at): bool => is_int($at) && $at > $now);
        $state['sessions'][$key] = $expires;
        $this->write($state);
    }

    #[\Override]
    public function sessionUser(string $key, int $now): ?User
    {
        $expires = $this->read()['sessions'][$key] ?? null;

        return is_int($expires) && $expires > $now ? $this->user() : null;
    }

    #[\Override]
    public function closeSession(string $key): void
    {
        $state = $this->read();
        unset($state['sessions'][$key]);
        $this->write($state);
    }

    #[\Override]
    public function users(): array
    {
        return $this->any() ? [$this->user()] : [];
    }

    #[\Override]
    public function update(string $name, ?bool $admin = null, ?string $runtime = null): bool
    {
        throw new LogicException('With sessions kept in files there is one account, and nothing about it to change but its password.');
    }

    #[\Override]
    public function remove(string $name): bool
    {
        throw new LogicException('With sessions kept in files there is one account, which stays.');
    }

    private function file(): string
    {
        return "{$this->home}/web-login.json";
    }

    /** @return array{name?: string, password?: string, sessions?: array<string, int>} */
    private function read(): array
    {
        if (!is_file($this->file())) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->file()), true);
        if (!is_array($state)) {
            throw new RuntimeException("{$this->file()} is not valid JSON. Remove it and set a password again with `pig web password`.");
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        self::writePrivate($this->file(), json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    /** Written whole, readable by this user only. */
    public static function writePrivate(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $content) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        chmod($temp, 0o600);
        if (!rename($temp, $file)) {
            unlink($temp);
            throw new RuntimeException("Cannot write {$file}");
        }
    }
}
