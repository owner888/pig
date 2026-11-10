<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Sandbox\Shell;
use Pig\Tui\Images\ImageType;

/**
 * Every built-in tool's operations, somewhere other than this process (`Sandbox\Shell`): an
 * account's container, or an OS user over SSH (docs/dev/design/storage.md, phase 4 steps 3b and
 * 6). Such an account's `pig --mode rpc` child is given these, and nothing it reads, writes, lists,
 * searches or runs touches the host.
 *
 * Each file operation is one command there of coreutils — `cat`, `test`, `stat`, `mkdir -p`,
 * `find -print0` — so a symbolic link the account made pointing at `/etc` is followed there, where
 * it names that side's `/etc`. `rg` and `fd` run there too (`RunsElsewhere`), and `bash` is
 * `bash -c` there in its own session, so stopping it reaches everything it started (`stop()`).
 * That side needs bash, coreutils, findutils, util-linux's `setsid`, `rg` and `fd`.
 *
 * Of the session's environment only pig's own `PIG_*` variables go in, not `PIG_SANDBOX_*`: the
 * host's `PATH`, `HOME` and above all its keys stay out.
 */
final class RemoteOperations implements ReadOperations, WriteOperations, EditOperations, LsOperations, FindOperations, GrepOperations, BashOperations, RunsElsewhere
{
    /**
     * Where a running command's process group is written, so it can be stopped from outside: a
     * directory of the user's own there, since several accounts may be users of one machine.
     */
    private const string RUNS = '"${TMPDIR:-/tmp}/.pig-runs-$(id -u)"';

    public function __construct(public readonly Shell $shell)
    {
    }

    /**
     * Run `$inside` there to the end (`Shell::run()`).
     *
     * @param list<string> $inside
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $inside, ?string $input = null, ?string $cwd = null): array
    {
        return $this->shell->run($inside, $input, $cwd);
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
        $process = proc_open($this->shell->exec(['cat', '--', $absolutePath]), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

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

    /** Not asked: `FindTool` runs `fd` there instead (`elsewhere()`). */
    #[\Override]
    public function glob(string $pattern, string $cwd, array $ignore, int $limit): array
    {
        throw new \LogicException('RemoteOperations runs fd there; glob() is not used.');
    }

    #[\Override]
    public function elsewhere(array $argv, ?string $cwd): array
    {
        return [$this->shell->exec($argv, $cwd), null];
    }

    // ---- bash -------------------------------------------------------------------------------

    #[\Override]
    public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
    {
        $run = bin2hex(random_bytes(8));
        // pig's own variables but how this side reaches that one (`PIG_SANDBOX_*`: the key's path,
        // pig's home), which is the server's business.
        $inside = array_filter($env ?? [], static fn (string $key): bool => str_starts_with($key, 'PIG_') && !str_starts_with($key, 'PIG_SANDBOX_'), ARRAY_FILTER_USE_KEY);

        // `setsid -w` makes the command the leader of a process group of its own, and the inner
        // bash writes that group's id down: stopping the `docker exec` or `ssh` here does not stop
        // what runs there, so `stop()` signals the group from a second one. `-w` waits, and hands
        // the command's code back. The command itself is the outer bash's `$0`; where the group
        // is written goes in as `PIG_RUN_FILE`, and is gone before the command starts.
        $wrapper = 'd=' . self::RUNS . "; mkdir -p \"\$d\" && PIG_RUN_FILE=\"\$d/{$run}\" exec setsid -w bash -c 'echo \$\$ > \"\$PIG_RUN_FILE\"; unset PIG_RUN_FILE; exec bash -c \"\$0\"' \"\$0\"";
        // The command is a word of what runs there, as every other is: `ssh` sends one line, and a
        // word added after it here would join that line unquoted. `LocalShellRun` adds the last
        // word itself.
        $argv = $this->shell->exec(['bash', '-c', $wrapper, $command], $cwd, $inside);
        $last = (string) array_pop($argv);

        $state = new LocalShellRun($onData);
        // The host's environment for `docker` or `ssh` itself (its `DOCKER_HOST`, its agent); none of it goes in.
        $state->start($last, '/', new ShellConfig($argv[0], array_slice($argv, 1)), null);
        $exit = $state->wait($signal, $timeout);

        if ($state->aborted || $state->timedOut) {
            $this->stop($run);

            throw new CommandStopped($state->timedOut, $exit);
        }

        $this->run(['sh', '-c', 'rm -f ' . self::RUNS . '/"$1"', 'sh', $run]);

        return $exit;
    }

    /** Stop the command `$run` and everything it started, there. */
    private function stop(string $run): void
    {
        $this->run(['sh', '-c', 'f=' . self::RUNS . '/$1; test -f "$f" || exit 0; g=$(cat "$f"); kill -TERM -- -"$g" 2>/dev/null; sleep 1; kill -KILL -- -"$g" 2>/dev/null; rm -f "$f"', 'sh', $run]);
    }
}
