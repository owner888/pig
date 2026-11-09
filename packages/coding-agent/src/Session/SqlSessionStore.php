<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use PDO;
use PDOException;
use Pig\Agent\AgentError;

/**
 * Sessions in a SQL database — MySQL through `pdo_mysql`, or SQLite through `pdo_sqlite` — for a
 * server, or several users, rather than a directory of files (docs/dev/design/storage.md, phase 3).
 *
 * What is kept is still pi's session file, line for line: `session_lines` holds each line as it
 * would be in the `.jsonl`, in order, so a session exports back to the exact file pi reads. Three
 * tables, named with `$prefix`:
 *
 * - `sessions`: one row per session — its location, the id and the `cwd` its header names, the
 *   `owner` (empty until accounts come, phase 4), how many lines and bytes, when it was last written
 * - `session_lines`: the lines, by location and position
 * - `session_sidecars`: `ToolProgress`' running output and the like, by location and name
 *
 * A location is `sql:<timestamp>_<id>.jsonl`: unique by the session id in it, and with pi's file
 * name at the end, so what shows a session by its file name (the web sidebar, an export's default
 * name) shows the same name as for a file.
 *
 * Each append is one transaction; the line count is read and moved in it, under a write lock
 * (`BEGIN IMMEDIATE` on SQLite, `SELECT … FOR UPDATE` on MySQL), so two processes appending to one
 * session cannot take the same position.
 */
final class SqlSessionStore implements SessionStore
{
    /** What every location starts with. */
    public const string SCHEME = 'sql:';

    private readonly bool $mysql;

    private readonly string $sessions;

    private readonly string $lines;

    private readonly string $sidecars;

    /**
     * @param string $prefix put in front of each table's name, so one database can hold pig's
     *        tables beside others
     */
    public function __construct(private readonly PDO $pdo, string $prefix = 'pig_')
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new AgentError("A table prefix is letters, digits and underscores, not {$prefix}");
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new AgentError("Sessions can be kept in MySQL or SQLite, not {$driver}");
        }

        $this->mysql = $driver === 'mysql';
        $this->sessions = $prefix . 'sessions';
        $this->lines = $prefix . 'session_lines';
        $this->sidecars = $prefix . 'session_sidecars';
        $this->migrate();
    }

    /**
     * A store on `$dsn` — `mysql:host=…;dbname=…` or `sqlite:/path/to/pig.db`.
     *
     * @throws AgentError saying what is missing when the driver is not installed or the database
     *         cannot be reached; never a quiet fall back to files
     */
    public static function open(string $dsn, ?string $user = null, ?string $password = null, string $prefix = 'pig_'): self
    {
        $driver = strtolower(strstr($dsn, ':', true) ?: '');
        $extension = match ($driver) {
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            default => throw new AgentError("The storage setting is `jsonl`, a `mysql:` DSN or a `sqlite:` path, not {$dsn}"),
        };

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new AgentError("Sessions in {$driver} need PHP's {$extension} extension, which this PHP does not have.");
        }

        if ($driver === 'sqlite') {
            $file = substr($dsn, 7);
            $directory = dirname($file);

            if ($file !== '' && $file !== ':memory:' && !is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
                throw new AgentError("Could not create {$directory} for the session database");
            }
        }

        try {
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $error) {
            throw new AgentError("Could not open the session database ({$driver}): {$error->getMessage()}", previous: $error);
        }

        if ($driver === 'sqlite') {
            // Several pig processes on one file: wait for a lock rather than fail at once.
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return new self($pdo, $prefix);
    }

    private function migrate(): void
    {
        $text = $this->mysql ? 'LONGTEXT' : 'TEXT';
        $table = $this->mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->sessions} (
            location VARCHAR(255) NOT NULL PRIMARY KEY,
            id VARCHAR(255) NOT NULL,
            cwd {$text} NOT NULL,
            owner VARCHAR(255) NOT NULL DEFAULT '',
            line_count INTEGER NOT NULL,
            bytes BIGINT NOT NULL,
            modified_at BIGINT NOT NULL
        ){$table}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->lines} (
            location VARCHAR(255) NOT NULL,
            seq INTEGER NOT NULL,
            line {$text} NOT NULL,
            PRIMARY KEY (location, seq)
        ){$table}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->sidecars} (
            location VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            contents {$text} NOT NULL,
            PRIMARY KEY (location, name)
        ){$table}");

        // `CREATE INDEX IF NOT EXISTS` is SQLite's and not MySQL's: asked first there.
        if (!$this->mysql) {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$this->sessions}_id ON {$this->sessions} (id)");
        } elseif ((int) $this->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$this->sessions, $this->sessions . '_id'])->fetchColumn() === 0) {
            $this->pdo->exec("CREATE INDEX {$this->sessions}_id ON {$this->sessions} (id)");
        }
    }

    /** @param list<mixed> $parameters */
    private function query(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    /** The session id in a location: what follows the last `_`, without `.jsonl`. */
    private static function idOf(string $location): string
    {
        $name = str_ends_with($location, '.jsonl') ? substr($location, 0, -6) : $location;
        $at = strrpos($name, '_');

        return $at === false ? substr($name, strlen(self::SCHEME)) : substr($name, $at + 1);
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    #[\Override]
    public function locationFor(string $cwd, string $name): string
    {
        return self::SCHEME . $name . '.jsonl';
    }

    /**
     * One write, all of it or none, with the session's row locked for its whole length.
     *
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    private function transaction(\Closure $write): mixed
    {
        if ($this->mysql) {
            $this->pdo->beginTransaction();
        } else {
            // A deferred transaction takes its write lock at the first write, after the count was
            // read: two processes could read the same count. IMMEDIATE takes it now.
            $this->pdo->exec('BEGIN IMMEDIATE');
        }

        try {
            $result = $write();
            $this->mysql ? $this->pdo->commit() : $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $error) {
            $this->mysql ? $this->pdo->rollBack() : $this->pdo->exec('ROLLBACK');

            throw $error;
        }
    }

    /** @return array{line_count: int, bytes: int}|null the session's row, locked on MySQL */
    private function locked(string $location): ?array
    {
        $row = $this->query(
            "SELECT line_count, bytes FROM {$this->sessions} WHERE location = ?" . ($this->mysql ? ' FOR UPDATE' : ''),
            [$location],
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['line_count' => (int) $row['line_count'], 'bytes' => (int) $row['bytes']] : null;
    }

    /** @return list<string> the lines of `$lines`, without newlines or empty ones */
    private static function split(string $lines): array
    {
        return array_values(array_filter(explode("\n", $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Add `$new` after what the session holds, starting it when `$existing` is null.
     *
     * @param array{line_count: int, bytes: int}|null $existing
     * @param list<string> $new
     */
    private function write(string $location, ?array $existing, array $new): void
    {
        $seq = $existing['line_count'] ?? 0;
        $bytes = $existing['bytes'] ?? 0;
        $insert = $this->pdo->prepare("INSERT INTO {$this->lines} (location, seq, line) VALUES (?, ?, ?)");

        foreach ($new as $line) {
            $insert->execute([$location, $seq++, $line]);
            $bytes += strlen($line) + 1;
        }

        if ($existing === null) {
            $header = json_decode($new[0] ?? '', true);
            $cwd = is_array($header) && is_string($header['cwd'] ?? null) ? $header['cwd'] : '';
            $this->query(
                "INSERT INTO {$this->sessions} (location, id, cwd, line_count, bytes, modified_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$location, self::idOf($location), $cwd, $seq, $bytes, self::now()],
            );
        } else {
            $this->query(
                "UPDATE {$this->sessions} SET line_count = ?, bytes = ?, modified_at = ? WHERE location = ?",
                [$seq, $bytes, self::now(), $location],
            );
        }
    }

    #[\Override]
    public function append(string $location, string $lines): void
    {
        $new = self::split($lines);

        try {
            $this->transaction(function () use ($location, $new): void {
                $this->write($location, $this->locked($location), $new);
            });
        } catch (PDOException $error) {
            throw new AgentError("Could not write the session to {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function create(string $location, string $lines): void
    {
        $new = self::split($lines);

        try {
            $this->transaction(function () use ($location, $new): void {
                if ($this->locked($location) !== null) {
                    throw new AgentError("Could not write the forked session to {$location}: it is already there");
                }

                $this->write($location, null, $new);
            });
        } catch (PDOException $error) {
            throw new AgentError("Could not write the forked session to {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function replace(string $location, array $lines): void
    {
        $new = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        $this->transaction(function () use ($location, $new): void {
            if ($this->locked($location) === null) {
                return;
            }

            $this->query("DELETE FROM {$this->lines} WHERE location = ?", [$location]);
            $this->write($location, ['line_count' => 0, 'bytes' => 0], $new);
        });
    }

    #[\Override]
    public function exists(string $location): bool
    {
        return $this->query("SELECT 1 FROM {$this->sessions} WHERE location = ?", [$location])->fetchColumn() !== false;
    }

    #[\Override]
    public function lines(string $location): ?iterable
    {
        if (!$this->exists($location)) {
            return null;
        }

        $statement = $this->query("SELECT line FROM {$this->lines} WHERE location = ? ORDER BY seq", [$location]);

        return (static function () use ($statement): \Generator {
            while (($line = $statement->fetchColumn()) !== false) {
                yield (string) $line;
            }
        })();
    }

    #[\Override]
    public function linesFromEnd(string $location): iterable
    {
        $statement = $this->query("SELECT line FROM {$this->lines} WHERE location = ? ORDER BY seq DESC", [$location]);

        while (($line = $statement->fetchColumn()) !== false) {
            yield (string) $line;
        }
    }

    #[\Override]
    public function firstLine(string $location): ?string
    {
        $line = $this->query("SELECT line FROM {$this->lines} WHERE location = ? AND seq = 0", [$location])->fetchColumn();

        return $line === false ? null : (string) $line;
    }

    #[\Override]
    public function modifiedAt(string $location): ?int
    {
        $at = $this->query("SELECT modified_at FROM {$this->sessions} WHERE location = ?", [$location])->fetchColumn();

        return $at === false ? null : (int) $at;
    }

    #[\Override]
    public function size(string $location): ?int
    {
        $bytes = $this->query("SELECT bytes FROM {$this->sessions} WHERE location = ?", [$location])->fetchColumn();

        return $bytes === false ? null : (int) $bytes;
    }

    #[\Override]
    public function candidates(string $cwd): array
    {
        return [array_map('strval', $this->query("SELECT location FROM {$this->sessions} WHERE cwd = ?", [$cwd])->fetchAll(PDO::FETCH_COLUMN)), false];
    }

    #[\Override]
    public function every(): array
    {
        return array_map('strval', $this->query("SELECT location FROM {$this->sessions}")->fetchAll(PDO::FETCH_COLUMN));
    }

    #[\Override]
    public function findLocal(string $cwd, string $arg): ?string
    {
        if ($this->exists($arg)) {
            return $arg;
        }

        return $this->byId($arg, $cwd);
    }

    #[\Override]
    public function findGlobal(string $arg): ?string
    {
        return $this->byId($arg, null);
    }

    /** An exact id, then an id prefix, within `$cwd`'s sessions or everyone's. */
    private function byId(string $arg, ?string $cwd): ?string
    {
        $where = $cwd === null ? '' : ' AND cwd = ?';
        $scope = $cwd === null ? [] : [$cwd];

        $exact = $this->query("SELECT location FROM {$this->sessions} WHERE id = ?{$where} ORDER BY location LIMIT 1", [$arg, ...$scope])->fetchColumn();

        if ($exact !== false) {
            return (string) $exact;
        }

        // `!` as the escape: a backslash is read differently by MySQL's string literals and SQLite's.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $arg);
        $prefix = $this->query(
            "SELECT location FROM {$this->sessions} WHERE id LIKE ? ESCAPE '!'{$where} ORDER BY location LIMIT 1",
            [$escaped . '%', ...$scope],
        )->fetchColumn();

        return $prefix === false ? null : (string) $prefix;
    }

    #[\Override]
    public function projects(): array
    {
        $projects = [];

        foreach ($this->query("SELECT cwd, COUNT(*) AS sessions FROM {$this->sessions} WHERE cwd <> '' GROUP BY cwd")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projects[] = ['cwd' => (string) $row['cwd'], 'sessions' => (int) $row['sessions']];
        }

        return $projects;
    }

    #[\Override]
    public function readSidecar(string $location, string $name): ?string
    {
        $contents = $this->query("SELECT contents FROM {$this->sidecars} WHERE location = ? AND name = ?", [$location, $name])->fetchColumn();

        return $contents === false ? null : (string) $contents;
    }

    #[\Override]
    public function writeSidecar(string $location, string $name, string $contents): void
    {
        try {
            $this->transaction(function () use ($location, $name, $contents): void {
                $this->query("DELETE FROM {$this->sidecars} WHERE location = ? AND name = ?", [$location, $name]);
                $this->query("INSERT INTO {$this->sidecars} (location, name, contents) VALUES (?, ?, ?)", [$location, $name, $contents]);
            });
        } catch (PDOException $error) {
            throw new AgentError("Could not write {$name} for {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function deleteSidecar(string $location, string $name): void
    {
        try {
            $this->query("DELETE FROM {$this->sidecars} WHERE location = ? AND name = ?", [$location, $name]);
        } catch (PDOException $error) {
            throw new AgentError("Could not remove {$name} for {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function remove(string $location): ?string
    {
        try {
            $this->transaction(function () use ($location): void {
                $this->query("DELETE FROM {$this->sidecars} WHERE location = ?", [$location]);
                $this->query("DELETE FROM {$this->lines} WHERE location = ?", [$location]);
                $this->query("DELETE FROM {$this->sessions} WHERE location = ?", [$location]);
            });
        } catch (PDOException $error) {
            return $error->getMessage();
        }

        return null;
    }

    #[\Override]
    public function canonical(string $location): ?string
    {
        return str_starts_with($location, self::SCHEME) && $this->exists($location) ? $location : null;
    }
}
