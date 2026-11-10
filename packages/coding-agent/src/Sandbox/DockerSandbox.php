<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Accounts\User;
use Pig\Tui\Process;

/**
 * Where a Docker account works (docs/dev/design/storage.md, phase 4 step 3b): a directory of its
 * own on the host, and a long-lived container of its own that the directory is mounted in.
 *
 * - **The directory** is `<workspaces>/<name>-<id>`: the name for the admin who backs it up, the
 *   id so a new account given a removed one's name does not get its files.
 * - **The container** is `pig-<id>`, from `$image`, started the first time it is needed and
 *   restarted with Docker (`--restart unless-stopped`). It runs as this server's own uid and gid,
 *   which own the directory, with `HOME` there, so what is installed under `~` is kept with the
 *   files. The directory is mounted **at the same path** inside, so a path is the same on both
 *   sides and nothing is translated.
 * - **The image is never pulled here** (`--pull never`): the first conversation would otherwise
 *   wait minutes on a download with nothing on screen. The admin builds or pulls it once
 *   (`docker/sandbox/Dockerfile`).
 *
 * Docker is asked on the loop (`Process::runAsync()`): a container being made does not stop the
 * other people's pages.
 *
 * **Folders the admin gives the account** (`User::$folders`) are mounted at their own path too,
 * read-only ones `:ro`. Which they are is written on the container (`dev.pigagent.mounts`): when
 * the admin has changed them, the next conversation makes the container again (`docker rm -f`,
 * then `run`) — what was running in it stops, and what was installed outside the account's
 * directory goes with it, as with a new image.
 *
 * A container made from an older image stays on it until the admin removes it (`docker rm -f
 * pig-<id>`); the next conversation makes it again, from the image as it is then.
 */
final readonly class DockerSandbox implements Runtime
{
    /** Folders of the machine a container's own would be hidden by: never given to an account. */
    private const array SYSTEM = ['/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/proc', '/run', '/sbin', '/sys', '/usr'];

    /** pig's own directory (`Config::home()`), whose keys and settings no account may be given. */
    public string $home;

    public function __construct(
        public string $image,
        public string $workspaces,
        public string $docker = 'docker',
        ?string $home = null,
    ) {
        $this->home = $home ?? \Pig\CodingAgent\Config::home();
    }

    /** The account's directory on the host — and, at the same path, in its container. */
    #[\Override]
    public function workspace(User $user): string
    {
        return rtrim($this->workspaces, '/') . '/' . $user->name . '-' . substr($user->id, 0, 8);
    }

    /** The account's container, whether or not it is running. */
    public function container(User $user): DockerContainer
    {
        return new DockerContainer('pig-' . $user->id, $this->docker);
    }

    #[\Override]
    public function shell(User $user): Shell
    {
        return $this->container($user);
    }

    /** Its paths are this machine's: a process for it starts where it works. */
    #[\Override]
    public function here(User $user, string $cwd): string
    {
        return $cwd;
    }

    /** Listed here, inside its directory and the folders given it; above one of those there is no way up. */
    #[\Override]
    public function dirs(User $user, string $path, bool $hidden): array
    {
        $workspace = $this->workspace($user);
        if (!is_dir($workspace)) {
            @mkdir($workspace, 0o700, true);
        }

        $path = trim($path);
        $listing = \Pig\CodingAgent\Web\DirectoryListing::list($path !== '' && $this->contains($user, $path) ? $path : $workspace, $workspace, $workspace, $hidden);
        if (is_string($listing['parent'] ?? null) && !$this->contains($user, $listing['parent'])) {
            $listing['parent'] = null;
        }

        return $listing;
    }

    /**
     * The account's container, running: started when stopped, made when there is none, with its
     * directory made first.
     *
     * @throws AgentError saying what Docker said, when it could not
     */
    #[\Override]
    public function ensure(User $user): DockerContainer
    {
        $container = $this->container($user);
        $workspace = $this->workspace($user);

        if (!is_dir($workspace) && !mkdir($workspace, 0o700, true) && !is_dir($workspace)) {
            throw new AgentError("Could not create {$workspace}");
        }

        $mounts = $this->mounts($user);
        [$exit, $state] = Process::runAsync([$this->docker, 'inspect', '-f', '{{.State.Running}} {{index .Config.Labels "dev.pigagent.mounts"}}', $container->name], 15.0);
        [$running, $label] = array_pad(explode(' ', trim($state), 2), 2, '');

        // Made with other folders than the admin has given since: made again, with these. One from
        // before folders (2.0, no label) has just its directory, as one given none still would.
        if ($exit === 0 && $label !== self::label($mounts) && ($label !== '' || $user->folders !== [])) {
            Process::runAsync([$this->docker, 'rm', '-f', $container->name], 60.0);
            $exit = 1;
        }

        if ($exit === 0 && $running === 'true') {
            return $container;
        }

        [$exit, , $errors] = $exit === 0
            ? Process::runAsync([$this->docker, 'start', $container->name], 60.0)
            : Process::runAsync([
                $this->docker, 'run', '-d',
                '--name', $container->name,
                '--label', 'dev.pigagent.account=' . $user->name,
                '--label', 'dev.pigagent.mounts=' . self::label($mounts),
                '--restart', 'unless-stopped',
                '--pull', 'never',
                '--init',
                '--user', self::owner(),
                '-e', "HOME={$workspace}",
                ...array_merge(...array_map(static fn (string $mount): array => ['-v', $mount], $mounts)),
                '-w', $workspace,
                $this->image,
                'sleep', 'infinity',
            ], 60.0);

        if ($exit !== 0) {
            throw new AgentError("Could not start the container for {$user->name} ({$this->image}): " . trim((string) $errors));
        }

        return $container;
    }

    /**
     * `-v`'s for the account's container: its directory, then the folders it has been given, each
     * at its own path.
     *
     * @return list<string>
     */
    public function mounts(User $user): array
    {
        $workspace = $this->workspace($user);

        return [
            "{$workspace}:{$workspace}",
            ...array_map(static fn (array $folder): string => "{$folder['path']}:{$folder['path']}" . ($folder['readonly'] ? ':ro' : ''), $user->folders),
        ];
    }

    /**
     * What is written on a container of its mounts, to tell when they have changed.
     *
     * @param list<string> $mounts
     */
    private static function label(array $mounts): string
    {
        return substr(hash('sha256', implode("\n", $mounts)), 0, 16);
    }

    /**
     * The account's directory, then the folders it has been given: where the web page lets it look
     * and start conversations.
     *
     * @return list<string>
     */
    #[\Override]
    public function roots(User $user): array
    {
        return [$this->workspace($user), ...array_column($user->folders, 'path')];
    }

    /**
     * Whether `$path` is inside the account's directory or a folder it has been given, links
     * resolved: what the web page may show, list or put a file in for a Docker account. A link the
     * account made to `/etc` resolves to the host's `/etc`, which is not inside.
     */
    #[\Override]
    public function contains(User $user, string $path): bool
    {
        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        foreach ($this->roots($user) as $root) {
            $root = realpath($root);

            if ($root !== false && self::within($real, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$path` as a folder that may be given to an account: an existing directory, links resolved,
     * that neither holds nor is inside pig's own directory (its keys and settings), the accounts'
     * directories, or a folder of the system the container needs its own of.
     *
     * @throws \InvalidArgumentException saying why not
     */
    public function checkFolder(string $path): string
    {
        $path = trim($path);

        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("A folder is given by its whole path, from /: {$path}");
        }

        $real = realpath($path);

        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("There is no folder {$path} on this machine.");
        }

        foreach ([$this->home, $this->workspaces] as $kept) {
            $kept = realpath($kept) ?: rtrim($kept, '/');

            if (self::within($real, $kept) || self::within($kept, $real)) {
                throw new \InvalidArgumentException("{$real} holds or is inside {$kept}, which is pig's own.");
            }
        }

        foreach (self::SYSTEM as $system) {
            if ($real === '/' || self::within($real, $system)) {
                throw new \InvalidArgumentException("{$real} is the system's; the container has its own.");
            }
        }

        return $real;
    }

    /** Whether `$path` is `$root` or inside it. */
    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    /** This server's uid and gid, which own the account's directory. */
    private static function owner(): string
    {
        return function_exists('posix_getuid') ? posix_getuid() . ':' . posix_getgid() : (string) getmyuid() . ':' . (string) getmygid();
    }
}
