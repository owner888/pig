<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use InvalidArgumentException;

/**
 * Where an SSH account's tools run: an OS user on a machine reached over SSH — another user on
 * this server (`127.0.0.1`), or another machine (docs/dev/design/storage.md, phase 4 step 6). That
 * user's own permissions are the boundary: pig asks nothing of the remote side but a POSIX shell,
 * and the tools need bash, coreutils, util-linux's `setsid`, and `rg` and `fd` for grep and find.
 *
 * How it is reached, every time:
 * - **`BatchMode=yes`**: a key, never a password prompt nobody would answer.
 * - **`StrictHostKeyChecking=accept-new`**, against pig's own `known_hosts` in its home rather
 *   than the server user's: a machine's key is learnt the first time and must not change after.
 * - **One connection, shared** (`ControlMaster=auto`, `ControlPersist`): each file the agent
 *   reads is an `ssh`, and a new connection each time would be a handshake each time. The
 *   sockets are under pig's home (`ssh/`), named by `%C`.
 *
 * The remote command is a line for the user's login shell: `cd -- '<cwd>' && exec env
 * 'K=V' '<argv>'…`, each word in single quotes.
 */
final readonly class SshTarget implements Shell
{
    use RunsToTheEnd;

    /**
     * @param string $key a private key on this machine, or '' for the server user's own
     *     (`~/.ssh/id_*`, its agent)
     * @param string $home pig's home, where `known_hosts` and the shared connections are kept
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $key,
        public string $home,
        public string $ssh = 'ssh',
    ) {
        self::check($host, $port, $user);
    }

    /**
     * @throws InvalidArgumentException for a host, port or user that `ssh` would read as an option
     *     or that cannot be one
     */
    public static function check(string $host, int $port, string $user): void
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:-]*$/', $host) !== 1) {
            throw new InvalidArgumentException("Not a host name or address: {$host}");
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Not a port: {$port}");
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $user) !== 1) {
            throw new InvalidArgumentException("Not a user name: {$user}");
        }
    }

    /** `ssh`'s own options, before the destination. @return list<string> */
    private function options(): array
    {
        $sockets = rtrim($this->home, '/') . '/ssh';

        if (!is_dir($sockets)) {
            @mkdir($sockets, 0o700, true);
        }

        return [
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . rtrim($this->home, '/') . '/known_hosts',
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ControlMaster=auto',
            '-o', "ControlPath={$sockets}/%C",
            '-o', 'ControlPersist=600',
            '-o', 'LogLevel=ERROR',
            ...($this->key !== '' ? ['-i', $this->key, '-o', 'IdentitiesOnly=yes'] : []),
            '-p', (string) $this->port,
            '-l', $this->user,
        ];
    }

    #[\Override]
    public function exec(array $inside, ?string $cwd = null, array $env = [], bool $stdin = false, bool $tty = false): array
    {
        $line = ($cwd !== null ? 'cd -- ' . self::quote($cwd) . ' && ' : '')
            . 'exec '
            . ($env !== [] ? 'env ' . implode(' ', array_map(static fn (string $key, string $value): string => self::quote("{$key}={$value}"), array_keys($env), $env)) . ' ' : '')
            . implode(' ', array_map(self::quote(...), $inside));

        return [
            $this->ssh,
            ...$this->options(),
            $tty ? '-t' : '-T',
            ...(!$stdin && !$tty ? ['-n'] : []),
            $this->host,
            $line,
        ];
    }

    #[\Override]
    public function environment(): array
    {
        return ['PIG_SANDBOX_SSH' => (string) json_encode([
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'key' => $this->key,
            'home' => $this->home,
            'ssh' => $this->ssh,
        ], JSON_UNESCAPED_SLASHES)];
    }

    /** `environment()` read back; null for anything else. */
    public static function fromEnvironment(string $value): ?self
    {
        $data = json_decode($value, true);

        if (!is_array($data) || !is_string($data['host'] ?? null) || !is_int($data['port'] ?? null) || !is_string($data['user'] ?? null)) {
            return null;
        }

        return new self(
            $data['host'],
            $data['port'],
            $data['user'],
            is_string($data['key'] ?? null) ? $data['key'] : '',
            is_string($data['home'] ?? null) ? $data['home'] : \Pig\CodingAgent\Config::home(),
            is_string($data['ssh'] ?? null) && $data['ssh'] !== '' ? $data['ssh'] : 'ssh',
        );
    }

    /** One word for a POSIX shell, whatever it holds. */
    public static function quote(string $word): string
    {
        return "'" . str_replace("'", "'\\''", $word) . "'";
    }
}
