<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Accounts\User;

/**
 * Where an account that does not work on this machine works (docs/dev/design/storage.md, phase 4):
 * a container of its own (`DockerSandbox`) or an OS user reached over SSH (`SshSandbox`). The web
 * server asks it where the account's things are and how to reach them, and never touches them
 * itself.
 */
interface Runtime
{
    /**
     * The account's shell, ready: a container started, a connection made. Waits on the loop.
     *
     * @throws AgentError saying why it could not
     */
    public function ensure(User $user): Shell;

    /** The account's shell, without making sure it can be reached (`ensure()`). */
    public function shell(User $user): Shell;

    /** Where the account's conversations start when nothing else is said. */
    public function workspace(User $user): string;

    /**
     * The folders the page offers the account to start in: its directory first.
     *
     * @return list<string>
     */
    public function roots(User $user): array;

    /** Whether the account may work in `$path`, as the page names it. */
    public function contains(User $user, string $path): bool;

    /**
     * A directory of this machine to start a process for the account in (its `pig --mode rpc`
     * child, its terminal) that works in `$cwd` there.
     */
    public function here(User $user, string $cwd): string;

    /**
     * `/api/dirs` for the account: `$path` as typed, listed where the account works.
     *
     * @return array<string, mixed> `Web\DirectoryListing`'s answer
     */
    public function dirs(User $user, string $path, bool $hidden): array;
}
