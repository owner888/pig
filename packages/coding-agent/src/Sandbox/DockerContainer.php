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
final readonly class DockerContainer
{
    public function __construct(
        public string $name,
        public string $docker = 'docker',
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new AgentError("Not a container name: {$name}");
        }
    }

    /**
     * The command that runs `$inside` in the container: in `$cwd` there when given, with `$env` set
     * there (the host's own environment never goes in), reading standard input when `$stdin`, on a
     * terminal when `$tty` (the web terminal's shell).
     *
     * @param list<string> $inside
     * @param array<string, string> $env
     * @return list<string>
     */
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

    /**
     * Run `$inside` in the container to the end, `$input` on its standard input: its exit code,
     * what it wrote, and what it said on standard error. Synchronous, as a local file read is:
     * these are short (a `cat`, a `stat`, a `mkdir`).
     *
     * @param list<string> $inside
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $inside, ?string $input = null, ?string $cwd = null): array
    {
        $process = proc_open(
            $this->exec($inside, $cwd, stdin: $input !== null),
            [0 => $input !== null ? ['pipe', 'r'] : ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new AgentError("Could not run {$this->docker}");
        }

        $out = ['', '', ''];
        $writing = $input ?? '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        if ($input !== null) {
            stream_set_blocking($pipes[0], false);
        }

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        // Both ways at once: a large file written in while the command's error output fills its
        // pipe would otherwise wait on each other.
        while ($open !== [] || ($input !== null && isset($pipes[0]))) {
            $read = array_values($open);
            $write = $input !== null && isset($pipes[0]) ? [$pipes[0]] : [];
            $except = null;

            if (stream_select($read, $write, $except, 30) === false) {
                break;
            }

            if ($write !== []) {
                $sent = fwrite($pipes[0], $writing);
                $writing = $sent === false ? '' : substr($writing, $sent);

                if ($writing === '') {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }

            foreach ($read as $pipe) {
                $fd = array_search($pipe, $open, true);
                $chunk = fread($pipe, 65536);

                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    fclose($pipe);
                    unset($open[$fd]);

                    continue;
                }

                $out[$fd] .= $chunk;
            }
        }

        return [proc_close($process), $out[1], $out[2]];
    }
}
