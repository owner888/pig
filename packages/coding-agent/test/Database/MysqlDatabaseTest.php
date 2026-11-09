<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Database;

use PDO;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;

/**
 * The `Database` conformance suite on MySQL — run when `PIG_TEST_MYSQL_DSN` names a database to
 * use (with `PIG_TEST_MYSQL_USER` and `PIG_TEST_MYSQL_PASSWORD`), skipped otherwise. Each test gets
 * tables of its own, by prefix, and drops them after.
 */
final class MysqlDatabaseTest extends DatabaseConformance
{
    private ?Database $db = null;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = (string) getenv('PIG_TEST_MYSQL_DSN');

        if ($dsn === '') {
            self::markTestSkipped('set PIG_TEST_MYSQL_DSN to run the database against MySQL');
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_mysql');
        }

        $this->db = Databases::open($dsn, getenv('PIG_TEST_MYSQL_USER') ?: null, getenv('PIG_TEST_MYSQL_PASSWORD') ?: null, 'pigtest_' . bin2hex(random_bytes(3)) . '_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['things', 'things_old'] as $table) {
            $this->db?->dropTable($table);
        }

        $this->db = null;
    }

    #[\Override]
    protected function db(): Database
    {
        return $this->db ?? throw new \LogicException('no database');
    }

    public function testEachConnectionKeepsUtc(): void
    {
        $this->assertSame('+00:00', $this->db()->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testAKilledConnectionIsMadeAgainForTheNextStatementAndTransaction(): void
    {
        $killer = Databases::open((string) getenv('PIG_TEST_MYSQL_DSN'), getenv('PIG_TEST_MYSQL_USER') ?: null, getenv('PIG_TEST_MYSQL_PASSWORD') ?: null);
        $kill = function () use ($killer): void {
            $killer->query('KILL ' . (int) $this->db()->query('SELECT CONNECTION_ID()')->fetchColumn());
            usleep(100_000);
        };

        $kill();
        $this->assertSame(1, (int) $this->db()->query('SELECT 1')->fetchColumn());
        $this->assertSame('+00:00', $this->db()->query('SELECT @@session.time_zone')->fetchColumn(), 'set up again');

        $runs = 0;
        $result = $this->db()->transaction(function () use ($kill, &$runs): int {
            if (++$runs === 1) {
                $kill();
            }

            return (int) $this->db()->query('SELECT 7')->fetchColumn();
        });

        $this->assertSame(7, $result);
        $this->assertSame(2, $runs, 'run again from the start');
    }
}
