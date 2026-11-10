<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use Pig\Agent\AgentError;

/**
 * One account's long-lived container, seen from the host: what `docker exec` into it looks like,
 * and running something there to the end (docs/dev/design/storage.md, phase 4 step 3b).
 *
 * The account's directory is mounted at the same path inside as outside (`DockerSandbox`), so a
 * path means the same thing on both sides: the model, the session's `cwd`, the web page and the
 * container all say `/srv/pig/bob/app`, and nothing is translated.
 *
 * `$docker` is the `docker` command — a path, or a name on `PATH`. Tests give a stand-in that runs
 * the command here instead, which is the whole of what is assumed about it.
 */
final readonly class DockerContainer implements Shell
{
    use RunsToTheEnd;

    public function __construct(
        public string $name,
        public string $docker = 'docker',
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new AgentError("Not a container name: {$name}");
        }
    }

    #[\Override]
    public function exec(array $inside, ?string $cwd = null, array $env = [], bool $stdin = false, bool $tty = false): array
    {
        $argv = [$this->docker, 'exec'];

        if ($stdin || $tty) {
            $argv[] = '-i';
        }

        if ($tty) {
            $argv[] = '-t';
        }

        if ($cwd !== null) {
            $argv[] = '-w';
            $argv[] = $cwd;
        }

        foreach ($env as $key => $value) {
            $argv[] = '-e';
            $argv[] = "{$key}={$value}";
        }

        return [...$argv, $this->name, ...$inside];
    }

    #[\Override]
    public function environment(): array
    {
        return ['PIG_SANDBOX_CONTAINER' => $this->name, 'PIG_SANDBOX_DOCKER' => $this->docker];
    }
}
