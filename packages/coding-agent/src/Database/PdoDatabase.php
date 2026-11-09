<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Database;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Pig\Agent\AgentError;

/**
 * What `MysqlDatabase` and `SqliteDatabase` share: the connection, kept and made again when it is
 * lost, statements, transactions, and timestamps.
 */
abstract class PdoDatabase implements Database
{
    private PDO $pdo;

    /** How many times `transaction()` runs its work before giving up on a lost connection or a deadlock. */
    private const int ATTEMPTS = 3;

    private bool $inTransaction = false;

    /** Whether the open transaction is `exclusively()`'s, which a `transaction()` inside joins. */
    private bool $joinable = false;

    /**
     * @param Closure(): PDO $connect makes a new connection: at the start, and again when one is lost
     * @param string $prefix put in front of each table's name
     */
    public function __construct(private readonly Closure $connect, private readonly string $prefix = '')
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new AgentError("A table prefix is letters, digits and underscores, not {$prefix}");
        }

        $this->pdo = $this->connect();
    }

    /** Settings each new connection needs before its first statement. */
    abstract protected function configure(PDO $pdo): void;

    /** Start the transaction `transaction()` runs in. */
    abstract protected function begin(PDO $pdo): void;

    abstract protected function commit(PDO $pdo): void;

    abstract protected function rollBack(PDO $pdo): void;

    private function connect(): PDO
    {
        $pdo = ($this->connect)();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->configure($pdo);

        return $pdo;
    }

    #[\Override]
    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    #[\Override]
    public function query(string $sql, array $parameters = []): PDOStatement
    {
        try {
            return $this->run($sql, $parameters);
        } catch (PDOException $error) {
            // In a transaction the work so far went with the connection: `transaction()` starts over.
            if ($this->inTransaction || !self::isConnectionLost($error)) {
                throw $error;
            }

            $this->pdo = $this->connect();

            return $this->run($sql, $parameters);
        }
    }

    /** @param list<mixed> $parameters */
    private function run(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    #[\Override]
    public function transaction(Closure $work): mixed
    {
        if ($this->inTransaction) {
            return $this->joinable ? $work() : throw new LogicException('Transactions do not nest');
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->begin($this->pdo);
                $this->inTransaction = true;
                $result = $work();
                $this->commit($this->pdo);
                $this->inTransaction = false;

                return $result;
            } catch (\Throwable $error) {
                $this->inTransaction = false;
                $this->abandon();

                if ($attempt >= self::ATTEMPTS || !$error instanceof PDOException) {
                    throw $error;
                }

                if (self::isConnectionLost($error)) {
                    $this->pdo = $this->connect();
                } elseif (!self::isDeadlock($error)) {
                    throw $error;
                }
            }
        }
    }

    #[\Override]
    public function exclusively(string $name, Closure $work): mixed
    {
        if ($this->dialect() === 'sqlite') {
            $this->joinable = true;

            try {
                return $this->transaction($work);
            } finally {
                $this->joinable = false;
            }
        }

        $lock = 'pig:' . $this->table($name);

        if ((int) $this->query('SELECT GET_LOCK(?, 60)', [$lock])->fetchColumn() !== 1) {
            throw new AgentError("Waited a minute for another pig to finish with the database ({$lock})");
        }

        try {
            return $work();
        } finally {
            $this->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Roll back what is open; with the connection gone the server has done it already. */
    private function abandon(): void
    {
        try {
            $this->rollBack($this->pdo);
        } catch (PDOException) {
        }
    }

    /**
     * Whether `$error` says the connection is gone rather than the statement wrong: MySQL's
     * 2006 (gone away), 2013 and 2055 (lost), 1927 (killed), and what drivers say without a code.
     */
    public static function isConnectionLost(PDOException $error): bool
    {
        if (in_array($error->errorInfo[1] ?? null, [2006, 2013, 2055, 1927], true)) {
            return true;
        }

        $message = strtolower($error->getMessage());

        foreach (['server has gone away', 'lost connection', 'connection was killed', 'broken pipe', 'connection reset by peer', 'no connection to the server'] as $lost) {
            if (str_contains($message, $lost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$error` is the server giving up one transaction so another can go on, which it says
     * to run again: MySQL's 1213 (deadlock) and 1205 (lock wait timeout), SQLSTATE 40001.
     */
    public static function isDeadlock(PDOException $error): bool
    {
        return in_array($error->errorInfo[1] ?? null, [1213, 1205], true) || ($error->errorInfo[0] ?? null) === '40001';
    }

    #[\Override]
    public function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Uv');
    }

    #[\Override]
    public function at(?int $milliseconds = null): string
    {
        $at = $milliseconds === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000), new DateTimeZone('UTC'));

        if ($at === false) {
            throw new LogicException("Not a time: {$milliseconds}");
        }

        return $at->format('Y-m-d H:i:s.v');
    }

    /**
     * The column definitions `createTable()` makes, timestamps last.
     *
     * @param array<string, string> $columns
     * @return list<string>
     */
    protected function columnDefinitions(array $columns): array
    {
        $definitions = [];

        foreach ($columns as $name => $type) {
            $nullable = str_starts_with($type, '?');
            $definitions[] = $this->quote($name) . ' ' . $this->type(ltrim($type, '?')) . ($nullable ? ' NULL' : ' NOT NULL');
        }

        return [...$definitions, ...$this->timestampDefinitions()];
    }

    /** The SQL type for one of `createTable()`'s. */
    abstract protected function type(string $type): string;

    /** @return list<string> `created_at`, `updated_at` and `deleted_at` */
    abstract protected function timestampDefinitions(): array;

    /** `$identifier` quoted, after checking it is one. */
    protected function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new LogicException("Not a name for a table or column: {$identifier}");
        }

        return $this->dialect() === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    /** @param list<string> $columns */
    protected function quoteAll(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }
}
