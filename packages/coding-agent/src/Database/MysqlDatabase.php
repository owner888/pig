<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Database;

use LogicException;
use PDO;

/**
 * MySQL (or MariaDB) through `pdo_mysql`. Each connection speaks `utf8mb4` and UTC, so the
 * timestamps MySQL keeps are UTC whatever the server's own zone; tables compare text byte for
 * byte (`utf8mb4_bin`), as SQLite does.
 */
final class MysqlDatabase extends PdoDatabase
{
    #[\Override]
    public function dialect(): string
    {
        return 'mysql';
    }

    #[\Override]
    protected function configure(PDO $pdo): void
    {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin, time_zone = '+00:00'");
    }

    #[\Override]
    protected function begin(PDO $pdo): void
    {
        $pdo->beginTransaction();
    }

    #[\Override]
    protected function commit(PDO $pdo): void
    {
        $pdo->commit();
    }

    #[\Override]
    protected function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    #[\Override]
    public function forUpdate(): string
    {
        return ' FOR UPDATE';
    }

    #[\Override]
    protected function type(string $type): string
    {
        return match ($type) {
            'string' => 'VARCHAR(255)',
            'text' => 'LONGTEXT',
            'integer' => 'INT',
            'bigint' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            default => throw new LogicException("Not a column type: {$type}"),
        };
    }

    #[\Override]
    protected function timestampDefinitions(): array
    {
        return [
            '`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)',
            '`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)',
            '`deleted_at` DATETIME(3) NULL',
        ];
    }

    #[\Override]
    public function createTable(string $name, array $columns, array $primaryKey, array $indexes = []): void
    {
        $table = $this->table($name);
        $definitions = [...$this->columnDefinitions($columns), 'PRIMARY KEY (' . $this->quoteAll($primaryKey) . ')'];
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->quote($table) . " (\n    " . implode(",\n    ", $definitions)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

        // MySQL has no CREATE INDEX IF NOT EXISTS: asked first.
        foreach ($indexes as $index => $on) {
            $exists = $this->query(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, "{$table}_{$index}"],
            )->fetchColumn();

            if ($exists === false) {
                $this->query('CREATE INDEX ' . $this->quote("{$table}_{$index}") . ' ON ' . $this->quote($table) . ' (' . $this->quoteAll($on) . ')');
            }
        }
    }

    #[\Override]
    public function tableExists(string $name): bool
    {
        return $this->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$this->table($name)],
        )->fetchColumn() !== false;
    }

    #[\Override]
    public function columns(string $name): array
    {
        return array_map('strval', $this->query(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$this->table($name)],
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    #[\Override]
    public function renameTable(string $from, string $to): void
    {
        // Index names belong to their table in MySQL: no clash with a new `$from`'s.
        $this->query('RENAME TABLE ' . $this->quote($this->table($from)) . ' TO ' . $this->quote($this->table($to)));
    }

    #[\Override]
    public function dropTable(string $name): void
    {
        $this->query('DROP TABLE IF EXISTS ' . $this->quote($this->table($name)));
    }
}
