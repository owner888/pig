<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Accounts;

/**
 * Somebody who logs in to `pig web`. With accounts (a database), each has an id, a name, whether
 * they are an admin, and where their tools run. With the one password in a file there is one,
 * unnamed, the admin, on this machine.
 */
final readonly class User
{
    /** Tools on this machine, as the server's OS user. */
    public const string HOST = 'host';

    /** Tools in a container of the account's own (docs/dev/design/storage.md, phase 4 step 3). */
    public const string DOCKER = 'docker';

    /** Tools as an OS user reached over SSH, here or on another machine (phase 4 step 6). */
    public const string SSH = 'ssh';

    public const array RUNTIMES = [self::HOST, self::DOCKER, self::SSH];

    /**
     * @param list<array{path: string, readonly: bool}> $folders folders of this machine the admin
     *     has given a Docker account, mounted in its container at the same path
     *     (`Sandbox\DockerSandbox`); read-only ones it may read and not change
     * @param array{host: string, port: int, user: string, key: string, workspace: string}|null $ssh
     *     where an SSH account connects (`Sandbox\SshSandbox`): the key a path on this machine
     *     ('' for the server user's own), the workspace a directory there
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $admin,
        public string $runtime = self::HOST,
        public array $folders = [],
        public ?array $ssh = null,
    ) {
    }

    /**
     * What the page is told about an account.
     *
     * @return array{name: string, admin: bool, runtime: string, folders: list<array{path: string, readonly: bool}>, ssh: array{host: string, port: int, user: string, key: string, workspace: string}|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'admin' => $this->admin, 'runtime' => $this->runtime, 'folders' => $this->folders, 'ssh' => $this->ssh];
    }

    /**
     * `$ssh` as kept, from what was stored or sent: null when it names no host.
     *
     * @param array<mixed>|null $ssh
     * @return array{host: string, port: int, user: string, key: string, workspace: string}|null
     */
    public static function ssh(?array $ssh): ?array
    {
        $text = static fn (string $key): string => is_string($ssh[$key] ?? null) ? trim($ssh[$key]) : '';

        if ($ssh === null || $text('host') === '') {
            return null;
        }

        $port = $ssh['port'] ?? 22;

        return [
            'host' => $text('host'),
            'port' => is_int($port) ? $port : (is_string($port) && ctype_digit(trim($port)) ? (int) trim($port) : 0),
            'user' => $text('user'),
            'key' => $text('key'),
            'workspace' => $text('workspace') === '/' ? '/' : rtrim($text('workspace'), '/'),
        ];
    }

    /**
     * `$folders` as kept: each a path (no trailing slash) and whether read-only, once each, by path.
     *
     * @param array<mixed> $folders
     * @return list<array{path: string, readonly: bool}>
     */
    public static function folders(array $folders): array
    {
        $kept = [];

        foreach ($folders as $folder) {
            $path = is_array($folder) && is_string($folder['path'] ?? null) ? trim($folder['path']) : '';
            $path = $path === '/' ? $path : rtrim($path, '/');

            if ($path !== '') {
                $kept[$path] = ['path' => $path, 'readonly' => ($folder['readonly'] ?? false) === true];
            }
        }

        ksort($kept);

        return array_values($kept);
    }
}
