<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Config;

/**
 * pi's session files: `~/.pig/agent/sessions/<slug>/<timestamp>_<id>.jsonl`, read beside pi's own
 * `~/.pi/agent/sessions/`, or every session in one `--session-dir`. A location is the file's path.
 *
 * This is what `SessionManager` did itself before `SessionStore` existed, moved here unchanged:
 * the directory and slug rules, the globs that list and find, `FILE_APPEND | LOCK_EX` for an append,
 * `x` for a fork, the best-effort rewrite of a migrated file, `ToolProgress`' `.running.json`
 * renamed into place, and a removal that goes to the system's trash.
 */
final class JsonlSessionStore implements SessionStore
{
    /** `sessionDir` / `--session-dir`, when one was given: see `useSessionDir()`. */
    private static ?string $sessionDir = null;

    /**
     * Upstream's `sessionDir`: one directory for every session, in place of one per project.
     * Process-wide for `Shell::useShellPath()`'s reason — `bin/pig` sets it once, and `/new`,
     * `/resume`, `--continue` and the web mode all reach it through `directory()`.
     *
     * With it set, pi's directory is not read, and the listings keep only the sessions whose
     * header names this project — upstream's `filterCwd`, which it skips when the directory given
     * is the project's own default.
     */
    public static function useSessionDir(?string $dir): void
    {
        self::$sessionDir = $dir === null || $dir === '' ? null : rtrim($dir, '/');
    }

    public static function sessionDir(): ?string
    {
        return self::$sessionDir;
    }

    /** Where $cwd's sessions live: one directory per project, named after its path. */
    public static function directory(string $cwd, ?string $home = null): string
    {
        if ($home === null && self::$sessionDir !== null) {
            return self::$sessionDir;
        }

        return ($home ?? Config::home()) . '/sessions/' . self::slug($cwd);
    }

    /**
     * A directory name for a working directory, exactly as pi builds one.
     *
     * The leading separator goes, every `/`, `\\` and `:` becomes a dash, and the whole
     * thing is wrapped in `--`. pig used to squash every run of unusual characters into
     * one dash and not wrap it, which named the same project two different things — so
     * pointing pig at pi's directory would have found nothing.
     */
    public static function slug(string $cwd): string
    {
        $flat = (string) preg_replace('#^[/\\\\]#', '', $cwd);
        $flat = (string) preg_replace('#[/\\\\:]#', '-', $flat);

        return '--' . $flat . '--';
    }

    #[\Override]
    public function locationFor(string $cwd, string $name): string
    {
        return self::directory($cwd) . '/' . $name . '.jsonl';
    }

    #[\Override]
    public function append(string $location, string $lines): void
    {
        $directory = dirname($location);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory}");
        }

        $reason = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$reason): bool {
            $reason = $errstr;

            return true;
        });

        try {
            $ok = file_put_contents($location, $lines, FILE_APPEND | LOCK_EX) !== false;
        } finally {
            restore_error_handler();
        }

        if (!$ok) {
            throw new AgentError("Could not write the session to {$location}" . ($reason !== null ? ": {$reason}" : ''));
        }
    }

    #[\Override]
    public function create(string $location, string $lines): void
    {
        $directory = dirname($location);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory} for the forked session.");
        }

        // Upstream's `{ flag: "wx" }`: a fork never lands on a file that is already there.
        $handle = is_file($location) ? false : fopen($location, 'x');

        if ($handle === false) {
            throw new AgentError("Could not write the forked session to {$location}");
        }

        fwrite($handle, $lines);
        fclose($handle);
    }

    /**
     * Put the upgraded lines back, and carry on regardless if that cannot be done.
     *
     * A read-only checkout or somebody else's file that is not ours to write is not a reason to
     * refuse the conversation: the entries in memory are already right, and the only cost is that
     * the next open upgrades it again. `Migrations`' rule — every step skipped at the first sign of
     * trouble, because half-migrating somebody else's data is worse than not starting.
     */
    #[\Override]
    public function replace(string $location, array $lines): void
    {
        if (!is_writable($location)) {
            return;
        }

        file_put_contents($location, implode("\n", $lines) . "\n");
    }

    #[\Override]
    public function exists(string $location): bool
    {
        return is_file($location);
    }

    /**
     * Read line by line, as `SessionManager::open()` always did: a session of screenshots is tens
     * of megabytes, and a list row needs only its top.
     *
     * The readability is checked rather than the warning suppressed: `@` hides every other thing
     * that could go wrong in the same call, and a session that will not open is exactly when you
     * want to be told why.
     */
    #[\Override]
    public function lines(string $location): ?iterable
    {
        if (!is_file($location) || !is_readable($location)) {
            return null;
        }

        $handle = fopen($location, 'rb');

        if ($handle === false) {
            return null;
        }

        return (static function () use ($handle): \Generator {
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = rtrim($line, "\n");

                    if ($line !== '') {
                        yield $line;
                    }
                }
            } finally {
                fclose($handle);
            }
        })();
    }

    /**
     * Read from the end in 64 KB blocks: the name a session was last given is usually near its
     * end, and the file before it can be megabytes of screenshots.
     */
    #[\Override]
    public function linesFromEnd(string $location): iterable
    {
        $handle = is_file($location) && is_readable($location) ? fopen($location, 'rb') : false;

        if ($handle === false) {
            return;
        }

        try {
            $block = 1 << 16;
            $end = (int) fstat($handle)['size'];
            // The start of the line the last block began inside, kept until its beginning is read.
            $partial = '';

            while ($end > 0) {
                $start = max(0, $end - $block);
                fseek($handle, $start);
                $chunk = (string) fread($handle, $end - $start) . $partial;
                $end = $start;
                $lines = explode("\n", $chunk);
                // The first piece may be the tail of a line that starts in an earlier block.
                $partial = $start > 0 ? (string) array_shift($lines) : '';

                foreach (array_reverse($lines) as $line) {
                    if ($line !== '') {
                        yield $line;
                    }
                }
            }

            if ($partial !== '') {
                yield $partial;
            }
        } finally {
            fclose($handle);
        }
    }

    #[\Override]
    public function firstLine(string $location): ?string
    {
        $handle = is_file($location) && is_readable($location) ? fopen($location, 'r') : false;

        if ($handle === false) {
            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        return $line === false ? null : trim($line);
    }

    #[\Override]
    public function modifiedAt(string $location): ?int
    {
        if (!is_file($location)) {
            return null;
        }

        $time = filemtime($location);

        return $time === false ? null : $time * 1000;
    }

    #[\Override]
    public function size(string $location): ?int
    {
        if (!is_file($location)) {
            return null;
        }

        $size = filesize($location);

        return $size === false ? null : $size;
    }

    #[\Override]
    public function candidates(string $cwd): array
    {
        if (self::$sessionDir !== null) {
            $filter = self::$sessionDir !== Config::home() . '/sessions/' . self::slug($cwd);

            return [glob(self::$sessionDir . '/*.jsonl') ?: [], $filter];
        }

        return [[
            ...(glob(self::directory($cwd) . '/*.jsonl') ?: []),
            ...(glob(self::directory($cwd, Config::piHome()) . '/*.jsonl') ?: []),
        ], false];
    }

    #[\Override]
    public function every(): array
    {
        return self::$sessionDir !== null
            ? (glob(self::$sessionDir . '/*.jsonl') ?: [])
            : [
                ...(glob(Config::home() . '/sessions/*/*.jsonl') ?: []),
                ...(glob(Config::piHome() . '/sessions/*/*.jsonl') ?: []),
            ];
    }

    /**
     * Upstream's `resolveSessionPath()`, its first two steps:
     * 1. Direct path: if it contains a slash or ends in .jsonl, resolve as a file path.
     * 2. Local exact or prefix match: search the project's session directory in pig and pi.
     */
    #[\Override]
    public function findLocal(string $cwd, string $arg): ?string
    {
        // Direct path with directory separators: resolve relative to cwd or as absolute path
        if (str_contains($arg, '/') || str_contains($arg, '\\')) {
            if (is_file($arg)) {
                return $arg;
            }

            $local = $cwd . '/' . $arg;

            return is_file($local) ? $local : null;
        }

        // Bare filename ending in .jsonl: check cwd first
        if (str_ends_with($arg, '.jsonl')) {
            if (is_file($arg)) {
                return $arg;
            }

            $local = $cwd . '/' . $arg;

            if (is_file($local)) {
                return $local;
            }
        }

        // Local project session directory (pig and pi)
        $localDirs = self::$sessionDir !== null ? [self::$sessionDir] : [
            self::directory($cwd),
            self::directory($cwd, Config::piHome()),
        ];

        foreach ($localDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            // 0. Direct filename match inside project session directory (e.g. "2026-10-04...jsonl")
            if (str_ends_with($arg, '.jsonl') && is_file($dir . '/' . $arg)) {
                return $dir . '/' . $arg;
            }

            // 1. Exact ID match (files are named {timestamp}_{id}.jsonl)
            $exact = glob($dir . '/*_' . $arg . '.jsonl') ?: [];

            if ($exact !== []) {
                return $exact[0];
            }

            // 2. Prefix match
            $prefix = glob($dir . '/*_' . $arg . '*.jsonl') ?: [];

            if ($prefix !== []) {
                return $prefix[0];
            }
        }

        return null;
    }

    /**
     * Upstream's `resolveSessionPath()`, its last step: every project's session directory in pig and
     * pi. With a `sessionDir` every session is already in that one directory, searched by
     * `findLocal()`.
     */
    #[\Override]
    public function findGlobal(string $arg): ?string
    {
        $globalRoots = self::$sessionDir !== null ? [] : [
            Config::home() . '/sessions',
            Config::piHome() . '/sessions',
        ];

        foreach ($globalRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $exact = glob($root . '/*/*_' . $arg . '.jsonl') ?: [];

            if ($exact !== []) {
                return $exact[0];
            }

            $prefix = glob($root . '/*/*_' . $arg . '*.jsonl') ?: [];

            if ($prefix !== []) {
                return $prefix[0];
            }
        }

        return null;
    }

    /**
     * A project per directory under pig's and pi's `sessions/`, named by the `cwd` in the header of
     * its first file — what the web UI's folder list counted with a glob of its own.
     */
    #[\Override]
    public function projects(): array
    {
        $projects = [];

        foreach ([Config::home() . '/sessions', Config::piHome() . '/sessions'] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) ?: [] as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir($root . '/' . $dir)) {
                    continue;
                }

                $files = glob($root . '/' . $dir . '/*.jsonl') ?: [];

                if ($files === []) {
                    continue;
                }

                $header = json_decode((string) $this->firstLine($files[0]), true);
                $cwd = is_array($header) ? ($header['cwd'] ?? null) : null;

                if (is_string($cwd) && $cwd !== '') {
                    $projects[] = ['cwd' => $cwd, 'sessions' => count($files)];
                }
            }
        }

        return $projects;
    }

    /** `<session without .jsonl>.<name>`, beside the session file. */
    public static function sidecarPath(string $location, string $name): string
    {
        return (str_ends_with($location, '.jsonl') ? substr($location, 0, -6) : $location) . '.' . $name;
    }

    #[\Override]
    public function readSidecar(string $location, string $name): ?string
    {
        $path = self::sidecarPath($location, $name);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new AgentError("Could not read {$path}");
        }

        return $contents;
    }

    #[\Override]
    public function writeSidecar(string $location, string $name, string $contents): void
    {
        $path = self::sidecarPath($location, $name);
        $temp = $path . '.tmp';

        // Readable by the user alone, as the spill file is: a command's output can be a `.env`.
        // Renamed into place, so a crash mid-write leaves the last whole copy rather than half of one.
        if (file_put_contents($temp, $contents) === false || !chmod($temp, 0600) || !rename($temp, $path)) {
            throw new AgentError("Could not write {$path}");
        }
    }

    #[\Override]
    public function deleteSidecar(string $location, string $name): void
    {
        $path = self::sidecarPath($location, $name);

        if (is_file($path) && !unlink($path)) {
            throw new AgentError("Could not remove {$path}");
        }
    }

    /**
     * Upstream's `deleteSessionFile()`: the file to the system trash/recycle bin
     * (`SessionList::moveToSystemTrash()`), `unlink()` when that is not available. A tool's output
     * kept for `continueRun()` goes with its conversation.
     */
    #[\Override]
    public function remove(string $location): ?string
    {
        if (!file_exists($location)) {
            return null;
        }

        try {
            $this->deleteSidecar($location, ToolProgress::SIDECAR);
        } catch (AgentError) {
            return 'could not remove ' . ToolProgress::pathOf($location);
        }

        if (\Pig\CodingAgent\Cli\SessionList::moveToSystemTrash($location)) {
            return null;
        }

        [$removed, $error] = (static function () use ($location): array {
            $error = null;
            set_error_handler(static function (int $no, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });

            try {
                return [unlink($location), $error];
            } finally {
                restore_error_handler();
            }
        })();

        return $removed ? null : ($error ?? 'could not remove the file');
    }

    /** A `.jsonl` file under pig's or pi's `sessions/`, links resolved. */
    #[\Override]
    public function canonical(string $location): ?string
    {
        $resolved = is_file($location) ? realpath($location) : false;

        if ($resolved === false || !str_ends_with($resolved, '.jsonl')) {
            return null;
        }

        foreach ([realpath(Config::home() . '/sessions'), realpath(Config::piHome() . '/sessions')] as $root) {
            if ($root !== false && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                return $resolved;
            }
        }

        return null;
    }
}
