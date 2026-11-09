<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Database;

use LogicException;
use PDO;

/**
 * SQLite through `pdo_sqlite`. `updated_at` is kept as MySQL keeps it: a trigger sets it after an
 * update that changed another column and left `updated_at` as it was, so one the code sets itself
 * stays.
 */
final class SqliteDatabase extends PdoDatabase
{
    /** How SQLite writes the present, as the timestamp columns hold it. */
    private const string NOW = "strftime('%Y-%m-%d %H:%M:%f', 'now')";

    #[\Override]
    public function dialect(): string
    {
        return 'sqlite';
    }

    #[\Override]
    protected function configure(PDO $pdo): void
    {
        // Several pig processes on one file: wait for a lock rather than fail at once, readers
        // beside a writer (WAL), and a committed write on disk when the call returns.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    #[\Override]
    protected function begin(PDO $pdo): void
    {
        // A deferred transaction takes its write lock at its first write, after its reads: two
        // processes could read the same thing. IMMEDIATE takes it now.
        $pdo->exec('BEGIN IMMEDIATE');
    }

    // Begun outside PDO, so ended outside it too.
    #[\Override]
    protected function commit(PDO $pdo): void
    {
        $pdo->exec('COMMIT');
    }

    #[\Override]
    protected function rollBack(PDO $pdo): void
    {
        $pdo->exec('ROLLBACK');
    }

    #[\Override]
    public function forUpdate(): string
    {
        return '';
    }

    #[\Override]
    protected function type(string $type): string
    {
        return match ($type) {
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'integer', 'bigint', 'boolean' => 'INTEGER',
            default => throw new LogicException("Not a column type: {$type}"),
        };
    }

    #[\Override]
    protected function timestampDefinitions(): array
    {
        return [
            '"created_at" TEXT NOT NULL DEFAULT (' . self::NOW . ')',
            '"updated_at" TEXT NOT NULL DEFAULT (' . self::NOW . ')',
            '"deleted_at" TEXT NULL',
        ];
    }

    #[\Override]
    public function createTable(string $name, array $columns, array $primaryKey, array $indexes = []): void
    {
        $table = $this->table($name);
        $definitions = [...$this->columnDefinitions($columns), 'PRIMARY KEY (' . $this->quoteAll($primaryKey) . ')'];
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->quote($table) . " (\n    " . implode(",\n    ", $definitions) . "\n)");

        foreach ($indexes as $index => $on) {
            $this->query('CREATE INDEX IF NOT EXISTS ' . $this->quote("{$table}_{$index}") . ' ON ' . $this->quote($table) . ' (' . $this->quoteAll($on) . ')');
        }

        // Every column but `updated_at` itself: one changed, and `updated_at` did not, is MySQL's
        // ON UPDATE.
        $changed = implode(' OR ', array_map(
            fn (string $column): string => 'NEW.' . $this->quote($column) . ' IS NOT OLD.' . $this->quote($column),
            [...array_keys($columns), 'created_at', 'deleted_at'],
        ));
        $this->query('CREATE TRIGGER IF NOT EXISTS ' . $this->quote("{$table}_updated_at") . ' AFTER UPDATE ON ' . $this->quote($table)
            . ' FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at" AND (' . $changed . ')'
            . ' BEGIN UPDATE ' . $this->quote($table) . ' SET "updated_at" = ' . self::NOW . ' WHERE rowid = NEW.rowid; END');
    }

    #[\Override]
    public function tableExists(string $name): bool
    {
        return $this->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$this->table($name)])->fetchColumn() !== false;
    }

    #[\Override]
    public function columns(string $name): array
    {
        return array_map(
            static fn (array $column): string => (string) $column['name'],
            $this->query('PRAGMA table_info(' . $this->quote($this->table($name)) . ')')->fetchAll(),
        );
    }

    #[\Override]
    public function renameTable(string $from, string $to): void
    {
        $old = $this->table($from);
        $new = $this->table($to);
        $this->query('ALTER TABLE ' . $this->quote($old) . ' RENAME TO ' . $this->quote($new));

        // The indexes and trigger follow the table but keep their names, which a new `$from` would
        // ask for and, with IF NOT EXISTS, quietly go without: they are made again under `$to`'s.
        $named = $this->query(
            "SELECT name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL AND substr(name, 1, ?) = ?",
            [$new, strlen($old) + 1, $old . '_'],
        )->fetchAll();

        foreach ($named as $object) {
            $name = (string) $object['name'];
            $renamed = $new . substr($name, strlen($old));
            $sql = (string) preg_replace('/' . preg_quote($name, '/') . '/', $renamed, (string) $object['sql'], 1);
            $this->query((str_starts_with(strtoupper(ltrim($sql)), 'CREATE TRIGGER') ? 'DROP TRIGGER ' : 'DROP INDEX ') . $this->quote($name));
            $this->query($sql);
        }
    }

    #[\Override]
    public function dropTable(string $name): void
    {
        $this->query('DROP TABLE IF EXISTS ' . $this->quote($this->table($name)));
    }
}
