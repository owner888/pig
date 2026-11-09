<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Accounts;

use InvalidArgumentException;

/**
 * Who may log in to `pig web`, and the browsers that have (docs/dev/design/storage.md, phase 4).
 * `Web\WebLogin` keeps everything else of logging in: slowing guesses, cookies, the local token.
 *
 * - `FileAccounts`: one account, the admin, in `web-login.json`. What pig with session files uses:
 *   keeping each person's sessions apart needs the database.
 * - `SqlAccounts`: as many accounts as the admin adds, in the database the sessions are kept in.
 *
 * Every account has a name, and logging in asks for it, with either.
 *
 * A login session is kept by the SHA-256 of its cookie's token (`$key`), never the token.
 */
interface Accounts
{
    /** Whether there can be more than one account (a database), which the admin then manages. */
    public function several(): bool;

    /** Whether there is any account yet; until then the page offers to make the first. */
    public function any(): bool;

    /**
     * A new account, its tools running where `$runtime` says (`User::RUNTIMES`). `FileAccounts`
     * makes its one, the admin on this machine (`$admin` and `$runtime` unused), and no second.
     *
     * @throws InvalidArgumentException when an account of that name is there, or with files any
     */
    public function add(string $name, string $password, bool $admin, string $runtime = User::HOST): User;

    /**
     * Change whether `$name` is an admin and where their tools run; null leaves either as it is.
     * False when there is no such account.
     *
     * @throws InvalidArgumentException for taking the last admin's admin away
     */
    public function update(string $name, ?bool $admin = null, ?string $runtime = null): bool;

    /** The account `$name` and `$password` open, or null. */
    public function verify(string $name, string $password): ?User;

    /** Change `$name`'s password and end its login sessions. False when there is no such account. */
    public function setPassword(string $name, string $password): bool;

    /** Keep a login session for `$user` until `$expires` (Unix seconds); ones run out by `$now` go. */
    public function openSession(User $user, string $key, int $expires, int $now): void;

    /** Whose login session `$key` is, while it runs; null otherwise. */
    public function sessionUser(string $key, int $now): ?User;

    /** End a login session. */
    public function closeSession(string $key): void;

    /** @return list<User> by name */
    public function users(): array;

    /**
     * Remove `$name` and end its login sessions. False when there is no such account.
     *
     * @throws InvalidArgumentException for the last admin, without whom nobody could add accounts
     */
    public function remove(string $name): bool;
}
