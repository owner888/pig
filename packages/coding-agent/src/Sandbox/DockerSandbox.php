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
 * A container made from an older image stays on it until the admin removes it (`docker rm -f
 * pig-<id>`); the next conversation makes it again, from the image as it is then.
 */
final readonly class DockerSandbox
{
    public function __construct(
        public string $image,
        public string $workspaces,
        public string $docker = 'docker',
    ) {
    }

    /** The account's directory on the host — and, at the same path, in its container. */
    public function workspace(User $user): string
    {
        return rtrim($this->workspaces, '/') . '/' . $user->name . '-' . substr($user->id, 0, 8);
    }

    /** The account's container, whether or not it is running. */
    public function container(User $user): DockerContainer
    {
        return new DockerContainer('pig-' . $user->id, $this->docker);
    }

    /**
     * The account's container, running: started when stopped, made when there is none, with its
     * directory made first.
     *
     * @throws AgentError saying what Docker said, when it could not
     */
    public function ensure(User $user): DockerContainer
    {
        $container = $this->container($user);
        $workspace = $this->workspace($user);

        if (!is_dir($workspace) && !mkdir($workspace, 0o700, true) && !is_dir($workspace)) {
            throw new AgentError("Could not create {$workspace}");
        }

        [$exit, $running] = Process::runAsync([$this->docker, 'inspect', '-f', '{{.State.Running}}', $container->name], 15.0);

        if ($exit === 0 && trim($running) === 'true') {
            return $container;
        }

        [$exit, , $errors] = $exit === 0
            ? Process::runAsync([$this->docker, 'start', $container->name], 60.0)
            : Process::runAsync([
                $this->docker, 'run', '-d',
                '--name', $container->name,
                '--label', 'dev.pigagent.account=' . $user->name,
                '--restart', 'unless-stopped',
                '--pull', 'never',
                '--init',
                '--user', self::owner(),
                '-e', "HOME={$workspace}",
                '-v', "{$workspace}:{$workspace}",
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
     * Whether `$path` is inside the account's directory, links resolved: what the web page may
     * show, list or put a file in for a Docker account. A link the account made to `/etc` resolves
     * to the host's `/etc`, which is not inside.
     */
    public function contains(User $user, string $path): bool
    {
        $root = realpath($this->workspace($user));
        $real = realpath($path);

        return $root !== false && $real !== false && ($real === $root || str_starts_with($real, $root . '/'));
    }

    /** This server's uid and gid, which own the account's directory. */
    private static function owner(): string
    {
        return function_exists('posix_getuid') ? posix_getuid() . ':' . posix_getgid() : (string) getmyuid() . ':' . (string) getmygid();
    }
}
