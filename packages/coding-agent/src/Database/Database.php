<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Database;

use Closure;
use PDOException;
use PDOStatement;

/**
 * A SQL database, as little of one as pig needs: statements, a write transaction, and tables made
 * to one rule. `MysqlDatabase` and `SqliteDatabase` are the two; `Databases::open()` picks by DSN.
 *
 * **Every table has `created_at`, `updated_at` and `deleted_at`** (the developer's rule), and the
 * database keeps the first two, never the code. MySQL does it natively: `DEFAULT CURRENT_TIMESTAMP(3)
 * ON UPDATE CURRENT_TIMESTAMP(3)`. SQLite does the same with a default and a trigger that sets
 * `updated_at` when a row's other columns changed and `updated_at` itself was not set. Both keep
 * UTC, to the millisecond, as `Y-m-d H:i:s.v` (`timestamp()` reads one back). `deleted_at` is null
 * until the row is deleted the soft way, and queries that should not see deleted rows say so.
 *
 * Tables are named without the prefix everywhere here (`sessions`); `table()` gives the name to put
 * in SQL (`pig_sessions`), so one database can hold pig's tables beside others.
 */
interface Database
{
    /** `mysql` or `sqlite`. */
    public function dialect(): string;

    /** `$name` as it is in the database: with the prefix. */
    public function table(string $name): string;

    /**
     * Run a statement and hand back its result. Outside a transaction a statement that finds the
     * connection gone (MySQL's `wait_timeout`, a restarted server) reconnects and runs once more.
     *
     * @param list<mixed> $parameters
     * @throws PDOException when the database refuses it
     */
    public function query(string $sql, array $parameters = []): PDOStatement;

    /**
     * Run `$work` as one transaction holding the write lock from its first statement, so what it
     * reads is still true when it writes: `BEGIN IMMEDIATE` on SQLite, and on MySQL a transaction
     * whose reads say `forUpdate()`. Committed when it returns, rolled back when it throws; run
     * again from the start, up to three times in all, when the connection was lost under it or the
     * server ended it to break a deadlock (two first appends to one session on MySQL, say). So
     * `$work` does nothing but database work. Not nested.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed;

    /**
     * Run `$work` while no other process does work under the same `$name`: making or changing
     * tables, which two processes starting on one database at once would otherwise both do.
     * MySQL's `GET_LOCK()`, as its table changes commit by themselves; on SQLite one `BEGIN
     * IMMEDIATE` around all of it, as they do not (a `transaction()` inside is part of it).
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function exclusively(string $name, Closure $work): mixed;

    /** What a read inside `transaction()` ends with to lock the rows it read: ` FOR UPDATE` or nothing. */
    public function forUpdate(): string;

    /**
     * Create `$name` when it is not there: `$columns` (name => type), then `created_at`,
     * `updated_at` and `deleted_at`, the primary key, and an index per entry of `$indexes`.
     *
     * A type is `string` (up to 255 characters, what a key can be), `text` (any length), `integer`,
     * `bigint` or `boolean`; a `?` in front allows null. A column is `NOT NULL` otherwise, with no
     * default: the code says what goes in.
     *
     * @param array<string, string> $columns
     * @param list<string> $primaryKey
     * @param array<string, list<string>> $indexes index name (the table's goes in front) => its columns
     */
    public function createTable(string $name, array $columns, array $primaryKey, array $indexes = []): void;

    /**
     * Add `$column` to `$name` when it is not there yet: a table that has been released growing a
     * column, which `createTable()` (only when there is no table) would not give it. `$type` is one
     * of `createTable()`'s, and a column added to rows already there allows null (`?` or not).
     */
    public function addColumn(string $name, string $column, string $type): void;

    public function tableExists(string $name): bool;

    /**
     * The columns `$name` has, in order.
     *
     * @return list<string>
     */
    public function columns(string $name): array;

    /** Rename `$from`, with its indexes and trigger, to `$to`. */
    public function renameTable(string $from, string $to): void;

    public function dropTable(string $name): void;

    /** A `created_at` / `updated_at` / `deleted_at` value as Unix milliseconds; null for null. */
    public function timestamp(mixed $value): ?int;

    /** Unix milliseconds the way the timestamp columns hold them (`Y-m-d H:i:s.v`, UTC); now when null. */
    public function at(?int $milliseconds = null): string;
}
