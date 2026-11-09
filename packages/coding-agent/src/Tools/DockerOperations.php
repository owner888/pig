<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Sandbox\DockerContainer;
use Pig\Tui\Images\ImageType;

/**
 * Every built-in tool's operations, in an account's container (docs/dev/design/storage.md, phase
 * 4 step 3b): a Docker account's `pig --mode rpc` child is given these, and nothing it reads,
 * writes, lists, searches or runs touches the host.
 *
 * Each file operation is one `docker exec` of a coreutils command — `cat`, `test`, `stat`,
 * `mkdir -p`, `find -print0` — so a symbolic link the account made pointing at `/etc` is followed in the
 * container, where it names the container's `/etc`. `rg` and `fd` run there too
 * (`RunsElsewhere`), and `bash` is `bash -c` there in its own session, so stopping it reaches
 * everything it started (`stop()`). The image needs bash, coreutils, findutils, util-linux's
 * `setsid`, `rg` and `fd`.
 *
 * Of the session's environment only pig's own `PIG_*` variables go in: the host's `PATH`, `HOME`
 * and above all its keys stay out.
 */
final class DockerOperations implements ReadOperations, WriteOperations, EditOperations, LsOperations, FindOperations, GrepOperations, BashOperations, RunsElsewhere
{
    /** Where a running command's process group is written, so it can be stopped from outside. */
    private const string RUNS = '/tmp/.pig-runs';

    public function __construct(public readonly DockerContainer $container)
    {
    }

    /** @param list<string> $inside */
    private function run(array $inside, ?string $input = null): array
    {
        return $this->container->run($inside, $input);
    }

    /** What a failed command said, as the reason a file could not be had. */
    private static function said(string $errors): ?string
    {
        $said = trim($errors);

        // `cat: /x: No such file or directory` — the reason is the last part, as PHP's warnings give it.
        return $said === '' ? null : (string) preg_replace('/^(?:[^:\n]+: )+/', '', strtok($said, "\n") ?: $said);
    }

    // ---- read, edit -------------------------------------------------------------------------

    #[\Override]
    public function readFile(string $absolutePath): string
    {
        [$exit, $bytes, $errors] = $this->run(['cat', '--', $absolutePath]);

        if ($exit !== 0) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not read {$absolutePath}", self::said($errors));
        }

        return $bytes;
    }

    #[\Override]
    public function access(string $absolutePath): void
    {
        [$exit] = $this->run(['sh', '-c', 'test -f "$1" || exit 2; test -r "$1" || exit 3', 'sh', $absolutePath]);

        match ($exit) {
            0 => null,
            3 => throw new FileAccessError(FileAccessError::NOT_ALLOWED, "Not readable: {$absolutePath}"),
            default => throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file: {$absolutePath}"),
        };
    }

    #[\Override]
    public function detectImageMimeType(string $absolutePath): ?string
    {
        [$exit, $head] = $this->run(['head', '-c', '64', '--', $absolutePath]);

        return $exit === 0 ? ImageType::of($head) : null;
    }

    #[\Override]
    public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable
    {
        $process = proc_open($this->container->exec(['cat', '--', $absolutePath]), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new AgentError("Could not read {$absolutePath}");
        }

        try {
            while (!feof($pipes[1])) {
                $data = fread($pipes[1], ReadTool::CHUNK_BYTES);

                if ($data === false) {
                    break;
                }

                if ($data !== '') {
                    yield $data;
                }
            }

            $errors = (string) stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        }

        if ($exit !== 0) {
            throw new AgentError("Could not read {$absolutePath}" . (($reason = self::said($errors)) !== null ? ": {$reason}" : ''));
        }
    }

    // ---- write ------------------------------------------------------------------------------

    #[\Override]
    public function writeFile(string $absolutePath, string $content): void
    {
        [$exit, , $errors] = $this->run(['sh', '-c', 'cat > "$1"', 'sh', $absolutePath], $content);

        if ($exit !== 0) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not write {$absolutePath}", self::said($errors));
        }
    }

    #[\Override]
    public function mkdir(string $dir): void
    {
        [$exit, , $errors] = $this->run(['mkdir', '-p', '--', $dir]);

        if ($exit !== 0) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not create {$dir}", self::said($errors));
        }
    }

    // ---- ls, find, grep ---------------------------------------------------------------------

    #[\Override]
    public function exists(string $absolutePath): bool
    {
        return $this->run(['test', '-e', $absolutePath])[0] === 0;
    }

    #[\Override]
    public function stat(string $absolutePath): FileStat
    {
        // A dangling link is there, as an entry, and is not a directory.
        [$exit, $kind] = $this->run(['sh', '-c', 'if test -d "$1"; then echo d; elif test -e "$1" || test -L "$1"; then echo f; else exit 1; fi', 'sh', $absolutePath]);

        if ($exit !== 0) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file or directory: {$absolutePath}");
        }

        return new FileStat(trim($kind) === 'd');
    }

    #[\Override]
    public function readdir(string $absolutePath): array
    {
        // NUL-separated: a name may hold a newline.
        [$exit, $paths, $errors] = $this->run(['find', $absolutePath, '-mindepth', '1', '-maxdepth', '1', '-print0']);

        if ($exit !== 0) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not read directory: {$absolutePath}", self::said($errors));
        }

        $entries = array_map(basename(...), array_values(array_filter(explode("\0", $paths), static fn (string $path): bool => $path !== '')));
        sort($entries);

        return $entries;
    }

    #[\Override]
    public function isDirectory(string $absolutePath): bool
    {
        if (!$this->exists($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file or directory: {$absolutePath}");
        }

        return $this->run(['test', '-d', $absolutePath])[0] === 0;
    }

    /** Not asked: `FindTool` runs `fd` in the container instead (`elsewhere()`). */
    #[\Override]
    public function glob(string $pattern, string $cwd, array $ignore, int $limit): array
    {
        throw new \LogicException('DockerOperations runs fd in the container; glob() is not used.');
    }

    #[\Override]
    public function elsewhere(array $argv, ?string $cwd): array
    {
        return [$this->container->exec($argv, $cwd), null];
    }

    // ---- bash -------------------------------------------------------------------------------

    #[\Override]
    public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
    {
        $run = bin2hex(random_bytes(8));
        $inside = array_filter($env ?? [], static fn (string $key): bool => str_starts_with($key, 'PIG_'), ARRAY_FILTER_USE_KEY);

        // `setsid -w` makes the command the leader of a process group of its own, and the inner
        // bash writes that group's id down: stopping the `docker exec` here does not stop what
        // runs there, so `stop()` signals the group from a second `docker exec`. `-w` waits, and
        // hands the command's code back. The command itself is the outer bash's `$0`.
        $file = self::RUNS . "/{$run}";
        $wrapper = 'mkdir -p ' . self::RUNS . " && exec setsid -w bash -c 'echo \$\$ > {$file}; exec bash -c \"\$0\"' \"\$0\"";
        $argv = $this->container->exec(['bash', '-c', $wrapper], $cwd, $inside);

        $state = new LocalShellRun($onData);
        // The host's environment for `docker` itself (its `DOCKER_HOST`, its `PATH`); none of it goes in.
        $state->start($command, '/', new ShellConfig($argv[0], array_slice($argv, 1)), null);
        $exit = $state->wait($signal, $timeout);

        if ($state->aborted || $state->timedOut) {
            $this->stop($run);

            throw new CommandStopped($state->timedOut, $exit);
        }

        $this->run(['rm', '-f', self::RUNS . "/{$run}"]);

        return $exit;
    }

    /** Stop the command `$run` and everything it started, in the container. */
    private function stop(string $run): void
    {
        $this->run(['sh', '-c', 'f=' . self::RUNS . '/$1; test -f "$f" || exit 0; g=$(cat "$f"); kill -TERM -- -"$g" 2>/dev/null; sleep 1; kill -KILL -- -"$g" 2>/dev/null; rm -f "$f"', 'sh', $run]);
    }
}
