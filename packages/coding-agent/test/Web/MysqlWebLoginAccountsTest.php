<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PDO;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;

/**
 * `WebLoginAccountsTest` on MySQL — run when `PIG_TEST_MYSQL_DSN` names a database to use (with
 * `PIG_TEST_MYSQL_USER` and `PIG_TEST_MYSQL_PASSWORD`), skipped otherwise. Tables of its own, by
 * prefix, dropped after.
 */
final class MysqlWebLoginAccountsTest extends WebLoginAccountsTest
{
    private ?Database $mysql = null;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = (string) getenv('PIG_TEST_MYSQL_DSN');

        if ($dsn === '') {
            self::markTestSkipped('set PIG_TEST_MYSQL_DSN to run the accounts against MySQL');
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_mysql');
        }

        parent::setUp();
        $this->mysql = Databases::open($dsn, getenv('PIG_TEST_MYSQL_USER') ?: null, getenv('PIG_TEST_MYSQL_PASSWORD') ?: null, 'pigtest_' . bin2hex(random_bytes(3)) . '_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['users', 'login_sessions'] as $table) {
            $this->mysql?->dropTable($table);
        }

        $this->mysql = null;
        parent::tearDown();
    }

    #[\Override]
    protected function database(): Database
    {
        return $this->mysql ?? throw new \LogicException('no database');
    }
}
