<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Accounts;

use InvalidArgumentException;
use Pig\CodingAgent\Database\Database;

/**
 * Named accounts in the database the sessions are kept in (`storage`), for a server several people
 * log in to. Two tables, with the timestamps every table has (`Database`):
 *
 * - `users`: id, name, `password_hash`, admin, `runtime` (`User::RUNTIMES`: where the account's
 *   tools run). Removing one marks it deleted; a new account may
 *   take the name again. Names are unique among the accounts that are there, checked in a
 *   transaction rather than by an index, since the deleted keep theirs.
 * - `login_sessions`: by the SHA-256 of the cookie's token, whose, and until when. Logging out
 *   marks one deleted; those that ran out go as new ones come, as `web-login.json`'s did.
 */
final class SqlAccounts implements Accounts
{
    /**
     * Checked against when there is no such account, so a wrong name takes as long as a wrong
     * password (bcrypt at cost 12, PHP 8.4's default, of a random password nobody kept).
     */
    private const string NOBODY = '$2y$12$eqhn0sgBm5OPG0KZp1YjZufJ7iUZC6Zn76L2oigtGjsOdmj9kdJTK';

    private readonly string $users;

    private readonly string $sessions;

    public function __construct(private readonly Database $db)
    {
        $this->users = $db->table('users');
        $this->sessions = $db->table('login_sessions');
        $db->exclusively('accounts', function () use ($db): void {
            $db->createTable('users', [
                'id' => 'string',
                'name' => 'string',
                'password_hash' => 'string',
                'admin' => 'boolean',
                'runtime' => 'string',
            ], ['id'], ['name' => ['name']]);
            $db->createTable('login_sessions', [
                'token_hash' => 'string',
                'user_id' => 'string',
                'expires_at' => 'bigint',
            ], ['token_hash'], ['user_id' => ['user_id']]);
        });
    }

    /** @param array<string, mixed> $row */
    private static function user(array $row): User
    {
        return new User((string) $row['id'], (string) $row['name'], (bool) (int) $row['admin'], (string) $row['runtime']);
    }

    #[\Override]
    public function several(): bool
    {
        return true;
    }

    #[\Override]
    public function any(): bool
    {
        return $this->db->query("SELECT 1 FROM {$this->users} WHERE deleted_at IS NULL LIMIT 1")->fetchColumn() !== false;
    }

    #[\Override]
    public function add(string $name, string $password, bool $admin, string $runtime = User::HOST): User
    {
        self::checkRuntime($runtime);

        return $this->db->transaction(function () use ($name, $password, $admin, $runtime): User {
            if ($this->find($name, lock: true) !== null) {
                throw new InvalidArgumentException("There is already an account named {$name}.");
            }

            $id = bin2hex(random_bytes(8));
            $this->db->query(
                "INSERT INTO {$this->users} (id, name, password_hash, admin, runtime) VALUES (?, ?, ?, ?, ?)",
                [$id, $name, password_hash($password, PASSWORD_DEFAULT), $admin ? 1 : 0, $runtime],
            );

            return new User($id, $name, $admin, $runtime);
        });
    }

    private static function checkRuntime(string $runtime): void
    {
        if (!in_array($runtime, User::RUNTIMES, true)) {
            throw new InvalidArgumentException("Tools run on the host or in docker, not {$runtime}.");
        }
    }

    #[\Override]
    public function update(string $name, ?bool $admin = null, ?string $runtime = null): bool
    {
        if ($runtime !== null) {
            self::checkRuntime($runtime);
        }

        return $this->db->transaction(function () use ($name, $admin, $runtime): bool {
            $row = $this->find($name, lock: true);

            if ($row === null) {
                return false;
            }

            if ($admin === false && (bool) (int) $row['admin']) {
                $this->keepAnAdmin($name);
            }

            $this->db->query(
                "UPDATE {$this->users} SET admin = ?, runtime = ? WHERE id = ?",
                [($admin ?? (bool) (int) $row['admin']) ? 1 : 0, $runtime ?? (string) $row['runtime'], $row['id']],
            );

            return true;
        });
    }

    /** @throws InvalidArgumentException when `$name` is the only admin there is */
    private function keepAnAdmin(string $name): void
    {
        $admins = (int) $this->db->query(
            "SELECT COUNT(*) FROM {$this->users} WHERE admin = 1 AND deleted_at IS NULL" . $this->db->forUpdate(),
        )->fetchColumn();

        if ($admins <= 1) {
            throw new InvalidArgumentException("{$name} is the last admin; make another admin first.");
        }
    }

    /** @return array<string, mixed>|null the account named `$name` that is there */
    private function find(string $name, bool $lock = false): ?array
    {
        $row = $this->db->query(
            "SELECT id, name, password_hash, admin, runtime FROM {$this->users} WHERE name = ? AND deleted_at IS NULL" . ($lock ? $this->db->forUpdate() : ''),
            [$name],
        )->fetch();

        return is_array($row) ? $row : null;
    }

    #[\Override]
    public function verify(string $name, string $password): ?User
    {
        $row = $this->find($name);

        if ($row === null) {
            password_verify($password, self::NOBODY);

            return null;
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->query("UPDATE {$this->users} SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }

        return self::user($row);
    }

    #[\Override]
    public function setPassword(string $name, string $password): bool
    {
        return $this->db->transaction(function () use ($name, $password): bool {
            $row = $this->find($name, lock: true);

            if ($row === null) {
                return false;
            }

            $this->db->query("UPDATE {$this->users} SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            $this->endSessionsOf((string) $row['id']);

            return true;
        });
    }

    private function endSessionsOf(string $id): void
    {
        $this->db->query("UPDATE {$this->sessions} SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL", [$this->db->at(), $id]);
    }

    #[\Override]
    public function openSession(User $user, string $key, int $expires, int $now): void
    {
        $this->db->query("DELETE FROM {$this->sessions} WHERE expires_at <= ?", [$now]);
        $this->db->query("INSERT INTO {$this->sessions} (token_hash, user_id, expires_at) VALUES (?, ?, ?)", [$key, $user->id, $expires]);
    }

    #[\Override]
    public function sessionUser(string $key, int $now): ?User
    {
        $row = $this->db->query(
            "SELECT u.id, u.name, u.admin, u.runtime FROM {$this->sessions} s JOIN {$this->users} u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ? AND s.deleted_at IS NULL AND u.deleted_at IS NULL",
            [$key, $now],
        )->fetch();

        return is_array($row) ? self::user($row) : null;
    }

    #[\Override]
    public function closeSession(string $key): void
    {
        $this->db->query("UPDATE {$this->sessions} SET deleted_at = ? WHERE token_hash = ? AND deleted_at IS NULL", [$this->db->at(), $key]);
    }

    #[\Override]
    public function users(): array
    {
        return array_map(
            self::user(...),
            $this->db->query("SELECT id, name, admin, runtime FROM {$this->users} WHERE deleted_at IS NULL ORDER BY name")->fetchAll(),
        );
    }

    #[\Override]
    public function remove(string $name): bool
    {
        return $this->db->transaction(function () use ($name): bool {
            $row = $this->find($name, lock: true);

            if ($row === null) {
                return false;
            }

            if ((bool) (int) $row['admin']) {
                $this->keepAnAdmin($name);
            }

            $this->db->query("UPDATE {$this->users} SET deleted_at = ? WHERE id = ?", [$this->db->at(), $row['id']]);
            $this->endSessionsOf((string) $row['id']);

            return true;
        });
    }
}
