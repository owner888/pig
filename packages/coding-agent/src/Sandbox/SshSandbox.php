<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use InvalidArgumentException;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Accounts\User;
use Pig\CodingAgent\Web\DirectoryListing;
use Pig\Tui\Process;

/**
 * Where an SSH account works (docs/dev/design/storage.md, phase 4 step 6): as the OS user its
 * connection logs in as (`User::$ssh`), on this server or another machine. The agent itself — its
 * `pig --mode rpc` child, the model calls, the conversations, the keys — stays here, as for a
 * Docker account; only the tools go there (`Tools\RemoteOperations` on an `SshTarget`).
 *
 * - **That user's permissions are the boundary.** It may work anywhere that user may, so every
 *   absolute path is the account's (`contains()`); the page starts it in its workspace.
 * - **Paths are that machine's**, and need not exist here. A process started here for the account
 *   (its child, its terminal) starts in a directory of pig's own (`here()`), told the folder it
 *   works in there.
 * - **The connection** is checked when a conversation or terminal starts (`ensure()`), which also
 *   makes the workspace there, and says what `ssh` said when it could not.
 */
final readonly class SshSandbox implements Runtime
{
    /** @param string $home pig's home: `known_hosts`, the shared connections, `here()` */
    public function __construct(
        public string $home,
        public string $ssh = 'ssh',
    ) {
    }

    /**
     * `$ssh` as an account may be given it, checked: a host, port and user `ssh` takes, a key that
     * is a readable file here (or none), and a workspace given by its whole path.
     *
     * @param array{host: string, port: int, user: string, key: string, workspace: string} $ssh
     * @throws InvalidArgumentException saying what is wrong
     */
    public function checkConfig(array $ssh): void
    {
        SshTarget::check($ssh['host'], $ssh['port'], $ssh['user']);

        if ($ssh['key'] !== '' && (!str_starts_with($ssh['key'], '/') || !is_file($ssh['key']) || !is_readable($ssh['key']))) {
            throw new InvalidArgumentException("There is no key this server can read at {$ssh['key']}.");
        }

        if (!str_starts_with($ssh['workspace'], '/') || str_contains($ssh['workspace'], "\0") || str_contains($ssh['workspace'], "\n")) {
            throw new InvalidArgumentException("The workspace is a folder there, given by its whole path, from /: {$ssh['workspace']}");
        }
    }

    /** @throws AgentError when the account has no connection set */
    #[\Override]
    public function shell(User $user): SshTarget
    {
        $ssh = $user->ssh ?? throw new AgentError("{$user->name} has no SSH connection set; an admin sets it under Accounts.");

        return new SshTarget($ssh['host'], $ssh['port'], $ssh['user'], $ssh['key'], $this->home, $this->ssh);
    }

    #[\Override]
    public function ensure(User $user): SshTarget
    {
        $target = $this->shell($user);
        $workspace = $this->workspace($user);
        [$exit, , $errors] = Process::runAsync($target->exec(['mkdir', '-p', '--', $workspace]), 30.0);

        if ($exit !== 0) {
            $said = trim((string) $errors);

            throw new AgentError("Could not reach {$target->user}@{$target->host}:{$target->port} for {$user->name}" . ($said !== '' ? ": {$said}" : " (exit {$exit})"));
        }

        return $target;
    }

    /** @throws AgentError when the account has no connection set */
    #[\Override]
    public function workspace(User $user): string
    {
        return ($user->ssh ?? throw new AgentError("{$user->name} has no SSH connection set; an admin sets it under Accounts."))['workspace'];
    }

    #[\Override]
    public function roots(User $user): array
    {
        return [$this->workspace($user)];
    }

    #[\Override]
    public function contains(User $user, string $path): bool
    {
        return str_starts_with($path, '/') && !str_contains($path, "\0");
    }

    /** `<home>/ssh/here/<name>-<id>`: empty, pig's own, made when first asked for. */
    #[\Override]
    public function here(User $user, string $cwd): string
    {
        $here = rtrim($this->home, '/') . '/ssh/here/' . $user->name . '-' . substr($user->id, 0, 8);

        if (!is_dir($here) && !mkdir($here, 0o700, true) && !is_dir($here)) {
            throw new AgentError("Could not create {$here}");
        }

        return $here;
    }

    /** Listed there: one `ssh`, which resolves the folder and names its subfolders. */
    #[\Override]
    public function dirs(User $user, string $path, bool $hidden): array
    {
        $script = 'cd -- "$1" 2>/dev/null || exit 3; pwd -P; for d in * .*; do [ -d "$d" ] && printf "%s\0" "$d"; done; exit 0';

        try {
            $workspace = $this->workspace($user);
            $wanted = DirectoryListing::wanted($path, $workspace, $workspace);
            [$exit, $out, $errors] = Process::runAsync($this->shell($user)->exec(['sh', '-c', $script, 'sh', $wanted]), 30.0);
        } catch (AgentError $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($exit !== 0) {
            return ['ok' => false, 'error' => $exit === 3 ? "Not a directory: {$path}" : (trim((string) $errors) ?: "Could not list {$wanted}")];
        }

        $newline = strpos($out, "\n");
        $real = $newline === false ? $wanted : substr($out, 0, $newline);
        $names = $newline === false ? [] : array_values(array_filter(explode("\0", substr($out, $newline + 1)), static fn (string $name): bool => $name !== ''));

        return DirectoryListing::of($real, $workspace, $names, $hidden);
    }
}
