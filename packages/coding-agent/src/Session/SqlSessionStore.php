<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use PDO;
use PDOException;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;

/**
 * Sessions in a SQL database — MySQL through `pdo_mysql`, or SQLite through `pdo_sqlite` — for a
 * server, or several users, rather than a directory of files (docs/dev/design/storage.md, phase 3).
 *
 * What is kept is still pi's session file, line for line: `session_lines` holds each line as it
 * would be in the `.jsonl`, in order, so a session exports back to the exact file pi reads. Three
 * tables, each with the `created_at` / `updated_at` / `deleted_at` every table has (`Database`):
 *
 * - `sessions`: one row per session — its location, the id and the `cwd` its header names, the
 *   `owner` (null until accounts come, phase 4), how many lines and bytes; its `updated_at` is when
 *   it was last written
 * - `session_lines`: the lines, by location and position
 * - `session_sidecars`: `ToolProgress`' running output and the like, by location and name
 *
 * Removing a session deletes it the soft way: its rows get a `deleted_at` and nothing reads them
 * again. A session started again at that location (an append after a remove, as a file would be
 * written again) clears what was left first. `replace()` rewrites the lines outright: they are the
 * file's contents, not records of their own.
 *
 * **Owners** (phase 4): `withOwner()` is a store whose new sessions are that account's — what
 * `pig --mode rpc` uses when the web shell starts it for somebody (`PIG_SESSION_OWNER`) — and
 * `visibleTo()` one that finds only that account's sessions, which the web shell lists through for
 * everybody — an admin's own list included, with the sessions that have no owner (from before
 * accounts, the admin's); an admin asks for somebody else's, or everybody's, by name. A session
 * belongs to whoever started it, and a fork to whoever owns what it was forked from.
 *
 * A location is `sql:<timestamp>_<id>.jsonl`: unique by the session id in it, and with pi's file
 * name at the end, so what shows a session by its file name (the web sidebar, an export's default
 * name) shows the same name as for a file.
 *
 * Each append is one transaction; the line count is read and moved in it, under the write lock
 * (`Database::transaction()`), so two processes appending to one session cannot take the same
 * position.
 */
final class SqlSessionStore implements SessionStore
{
    /** What every location starts with. */
    public const string SCHEME = 'sql:';

    /** The tables, without the prefix. */
    private const array TABLES = ['sessions', 'session_lines', 'session_sidecars'];

    private readonly string $sessions;

    private readonly string $lines;

    private readonly string $sidecars;

    /** The account new sessions are written for; null for none. */
    private ?string $writer = null;

    /** The account whose sessions are found; null for everybody's. */
    private ?string $reader = null;

    /** Whether the reader also finds the sessions nobody owns. */
    private bool $unowned = false;

    public function __construct(private readonly Database $db)
    {
        $this->sessions = $db->table('sessions');
        $this->lines = $db->table('session_lines');
        $this->sidecars = $db->table('session_sidecars');
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
        return new self(Databases::open($dsn, $user, $password, $prefix));
    }

    /** This store, starting sessions as `$owner`'s (an account id). */
    public function withOwner(?string $owner): self
    {
        $store = clone $this;
        $store->writer = $owner === '' ? null : $owner;

        return $store;
    }

    /**
     * This store, finding only `$owner`'s sessions — and with `$unowned` the ones nobody owns, an
     * admin's own list — or everybody's for null.
     */
    public function visibleTo(?string $owner, bool $unowned = false): self
    {
        $store = clone $this;
        $store->reader = $owner;
        $store->unowned = $unowned;

        return $store;
    }

    /** Whose session `$location` is: an account id, or null for none (or no such session). */
    public function ownerOf(string $location): ?string
    {
        $owner = $this->query("SELECT owner FROM {$this->sessions} WHERE location = ? AND deleted_at IS NULL", [$location])->fetchColumn();

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * What a query for sessions adds to see only the reader's, and the parameter it takes.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function mine(): array
    {
        return match (true) {
            $this->reader === null => ['', []],
            $this->unowned => [' AND (owner = ? OR owner IS NULL)', [$this->reader]],
            default => [' AND owner = ?', [$this->reader]],
        };
    }

    /** The database the sessions are in, for what is kept beside them (the web accounts). */
    public function database(): Database
    {
        return $this->db;
    }

    /**
     * Make the tables, moving 1.9.9's over first: those had `modified_at` (milliseconds) and no
     * timestamps. Each old table is renamed `…_v1`, the new ones made, the rows copied in one
     * transaction, the old tables dropped; a start that stopped half way picks up where it was.
     * One process at a time: pig started twice on a new database makes the tables once.
     */
    private function migrate(): void
    {
        $this->db->exclusively('migrate', $this->makeTables(...));
    }

    private function makeTables(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table) && !in_array('created_at', $this->db->columns($table), true)) {
                $this->db->renameTable($table, "{$table}_v1");
            }
        }

        $this->db->createTable('sessions', [
            'location' => 'string',
            'id' => 'string',
            'cwd' => 'text',
            'owner' => '?string',
            'line_count' => 'integer',
            'bytes' => 'bigint',
        ], ['location'], ['id' => ['id']]);
        $this->db->createTable('session_lines', [
            'location' => 'string',
            'seq' => 'integer',
            'line' => 'text',
        ], ['location', 'seq']);
        $this->db->createTable('session_sidecars', [
            'location' => 'string',
            'name' => 'string',
            'contents' => 'text',
        ], ['location', 'name']);

        if ($this->db->tableExists('sessions_v1')) {
            $this->copyFromV1();
        }
    }

    private function copyFromV1(): void
    {
        $old = fn (string $table): string => $this->db->table("{$table}_v1");

        // Copied once: a start that stopped after the copy committed finds the rows already here.
        if ($this->db->query("SELECT 1 FROM {$this->sessions} LIMIT 1")->fetchColumn() === false) {
            $this->db->transaction(function () use ($old): void {
                $this->db->query("INSERT INTO {$this->sessions} (location, id, cwd, owner, line_count, bytes)
                    SELECT location, id, cwd, NULLIF(owner, ''), line_count, bytes FROM {$old('sessions')}");

                // When a session was written is when it was written, not when it was copied.
                foreach ($this->db->query("SELECT location, modified_at FROM {$old('sessions')}")->fetchAll() as $row) {
                    $at = $this->db->at((int) $row['modified_at']);
                    $this->db->query("UPDATE {$this->sessions} SET created_at = ?, updated_at = ? WHERE location = ?", [$at, $at, $row['location']]);
                }

                if ($this->db->tableExists('session_lines_v1')) {
                    $this->db->query("INSERT INTO {$this->lines} (location, seq, line) SELECT location, seq, line FROM {$old('session_lines')}");
                }

                if ($this->db->tableExists('session_sidecars_v1')) {
                    $this->db->query("INSERT INTO {$this->sidecars} (location, name, contents) SELECT location, name, contents FROM {$old('session_sidecars')}");
                }
            });
        }

        foreach (self::TABLES as $table) {
            $this->db->dropTable("{$table}_v1");
        }
    }

    /** @param list<mixed> $parameters */
    private function query(string $sql, array $parameters = []): \PDOStatement
    {
        return $this->db->query($sql, $parameters);
    }

    /** The session id in a location: what follows the last `_`, without `.jsonl`. */
    private static function idOf(string $location): string
    {
        $name = str_ends_with($location, '.jsonl') ? substr($location, 0, -6) : $location;
        $at = strrpos($name, '_');

        return $at === false ? substr($name, strlen(self::SCHEME)) : substr($name, $at + 1);
    }

    #[\Override]
    public function locationFor(string $cwd, string $name): string
    {
        return self::SCHEME . $name . '.jsonl';
    }

    /** @return array{line_count: int, bytes: int}|null the session's row, locked for the transaction */
    private function locked(string $location): ?array
    {
        $row = $this->query(
            "SELECT line_count, bytes FROM {$this->sessions} WHERE location = ? AND deleted_at IS NULL" . $this->db->forUpdate(),
            [$location],
        )->fetch();

        return is_array($row) ? ['line_count' => (int) $row['line_count'], 'bytes' => (int) $row['bytes']] : null;
    }

    /** @return list<string> the lines of `$lines`, without newlines or empty ones */
    private static function split(string $lines): array
    {
        return array_values(array_filter(explode("\n", $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Add `$new` after what the session holds, starting it when `$existing` is null — after clearing
     * what a removed session left at the location.
     *
     * @param array{line_count: int, bytes: int}|null $existing
     * @param list<string> $new
     */
    private function write(string $location, ?array $existing, array $new): void
    {
        if ($existing === null) {
            foreach ([$this->sidecars, $this->lines, $this->sessions] as $table) {
                $this->query("DELETE FROM {$table} WHERE location = ?", [$location]);
            }
        }

        $seq = $existing['line_count'] ?? 0;
        $bytes = $existing['bytes'] ?? 0;

        foreach ($new as $line) {
            $this->query("INSERT INTO {$this->lines} (location, seq, line) VALUES (?, ?, ?)", [$location, $seq++, $line]);
            $bytes += strlen($line) + 1;
        }

        if ($existing === null) {
            $header = json_decode($new[0] ?? '', true);
            $cwd = is_array($header) && is_string($header['cwd'] ?? null) ? $header['cwd'] : '';
            $this->query(
                "INSERT INTO {$this->sessions} (location, id, cwd, owner, line_count, bytes) VALUES (?, ?, ?, ?, ?, ?)",
                [$location, self::idOf($location), $cwd, $this->writer, $seq, $bytes],
            );
        } else {
            $this->query("UPDATE {$this->sessions} SET line_count = ?, bytes = ? WHERE location = ?", [$seq, $bytes, $location]);
        }
    }

    #[\Override]
    public function append(string $location, string $lines): void
    {
        $new = self::split($lines);

        try {
            $this->db->transaction(function () use ($location, $new): void {
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
            $this->db->transaction(function () use ($location, $new): void {
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

        $this->db->transaction(function () use ($location, $new): void {
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
        [$mine, $owner] = $this->mine();

        return $this->query("SELECT 1 FROM {$this->sessions} WHERE location = ? AND deleted_at IS NULL{$mine}", [$location, ...$owner])->fetchColumn() !== false;
    }

    #[\Override]
    public function lines(string $location): ?iterable
    {
        if (!$this->exists($location)) {
            return null;
        }

        $statement = $this->query("SELECT line FROM {$this->lines} WHERE location = ? AND deleted_at IS NULL ORDER BY seq", [$location]);

        return (static function () use ($statement): \Generator {
            while (($line = $statement->fetchColumn()) !== false) {
                yield (string) $line;
            }
        })();
    }

    #[\Override]
    public function linesFromEnd(string $location): iterable
    {
        $statement = $this->query("SELECT line FROM {$this->lines} WHERE location = ? AND deleted_at IS NULL ORDER BY seq DESC", [$location]);

        while (($line = $statement->fetchColumn()) !== false) {
            yield (string) $line;
        }
    }

    #[\Override]
    public function firstLine(string $location): ?string
    {
        $line = $this->query("SELECT line FROM {$this->lines} WHERE location = ? AND seq = 0 AND deleted_at IS NULL", [$location])->fetchColumn();

        return $line === false ? null : (string) $line;
    }

    #[\Override]
    public function modifiedAt(string $location): ?int
    {
        $at = $this->query("SELECT updated_at FROM {$this->sessions} WHERE location = ? AND deleted_at IS NULL", [$location])->fetchColumn();

        return $at === false ? null : $this->db->timestamp($at);
    }

    #[\Override]
    public function size(string $location): ?int
    {
        $bytes = $this->query("SELECT bytes FROM {$this->sessions} WHERE location = ? AND deleted_at IS NULL", [$location])->fetchColumn();

        return $bytes === false ? null : (int) $bytes;
    }

    #[\Override]
    public function candidates(string $cwd): array
    {
        [$mine, $owner] = $this->mine();

        return [array_map('strval', $this->query("SELECT location FROM {$this->sessions} WHERE cwd = ? AND deleted_at IS NULL{$mine}", [$cwd, ...$owner])->fetchAll(PDO::FETCH_COLUMN)), false];
    }

    #[\Override]
    public function every(): array
    {
        [$mine, $owner] = $this->mine();

        return array_map('strval', $this->query("SELECT location FROM {$this->sessions} WHERE deleted_at IS NULL{$mine}", $owner)->fetchAll(PDO::FETCH_COLUMN));
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
        [$mine, $owner] = $this->mine();
        $where = ' AND deleted_at IS NULL' . ($cwd === null ? '' : ' AND cwd = ?') . $mine;
        $scope = [...($cwd === null ? [] : [$cwd]), ...$owner];

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

        [$mine, $owner] = $this->mine();

        foreach ($this->query("SELECT cwd, COUNT(*) AS sessions FROM {$this->sessions} WHERE cwd <> '' AND deleted_at IS NULL{$mine} GROUP BY cwd", $owner)->fetchAll() as $row) {
            $projects[] = ['cwd' => (string) $row['cwd'], 'sessions' => (int) $row['sessions']];
        }

        return $projects;
    }

    #[\Override]
    public function readSidecar(string $location, string $name): ?string
    {
        $contents = $this->query(
            "SELECT contents FROM {$this->sidecars} WHERE location = ? AND name = ? AND deleted_at IS NULL",
            [$location, $name],
        )->fetchColumn();

        return $contents === false ? null : (string) $contents;
    }

    #[\Override]
    public function writeSidecar(string $location, string $name, string $contents): void
    {
        try {
            $this->db->transaction(function () use ($location, $name, $contents): void {
                $there = $this->query(
                    "SELECT 1 FROM {$this->sidecars} WHERE location = ? AND name = ?" . $this->db->forUpdate(),
                    [$location, $name],
                )->fetchColumn();

                if ($there === false) {
                    $this->query("INSERT INTO {$this->sidecars} (location, name, contents) VALUES (?, ?, ?)", [$location, $name, $contents]);
                } else {
                    $this->query(
                        "UPDATE {$this->sidecars} SET contents = ?, deleted_at = NULL WHERE location = ? AND name = ?",
                        [$contents, $location, $name],
                    );
                }
            });
        } catch (PDOException $error) {
            throw new AgentError("Could not write {$name} for {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function deleteSidecar(string $location, string $name): void
    {
        try {
            $this->query(
                "UPDATE {$this->sidecars} SET deleted_at = ? WHERE location = ? AND name = ? AND deleted_at IS NULL",
                [$this->db->at(), $location, $name],
            );
        } catch (PDOException $error) {
            throw new AgentError("Could not remove {$name} for {$location}: {$error->getMessage()}", previous: $error);
        }
    }

    #[\Override]
    public function remove(string $location): ?string
    {
        try {
            $this->db->transaction(function () use ($location): void {
                $at = $this->db->at();

                foreach ([$this->sidecars, $this->lines, $this->sessions] as $table) {
                    $this->query("UPDATE {$table} SET deleted_at = ? WHERE location = ? AND deleted_at IS NULL", [$at, $location]);
                }
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
