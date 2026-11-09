<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use PDO;
use Pig\CodingAgent\Session\SessionStore;
use Pig\CodingAgent\Session\SqlSessionStore;

/**
 * The conformance suite against `SqlSessionStore` on MySQL — run when `PIG_TEST_MYSQL_DSN` names a
 * database to use (`mysql:host=127.0.0.1;dbname=pig_test`, with `PIG_TEST_MYSQL_USER` and
 * `PIG_TEST_MYSQL_PASSWORD`), skipped otherwise. Each test gets tables of its own, by prefix, and
 * drops them after.
 */
final class MysqlSessionStoreTest extends SessionStoreConformance
{
    private ?PDO $pdo = null;

    private string $prefix = '';

    private ?SqlSessionStore $store = null;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = (string) getenv('PIG_TEST_MYSQL_DSN');

        if ($dsn === '') {
            self::markTestSkipped('set PIG_TEST_MYSQL_DSN to run the session store against MySQL');
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_mysql');
        }

        $this->pdo = new PDO($dsn, getenv('PIG_TEST_MYSQL_USER') ?: null, getenv('PIG_TEST_MYSQL_PASSWORD') ?: null);
        $this->prefix = 'pigtest_' . bin2hex(random_bytes(3)) . '_';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach (['sessions', 'session_lines', 'session_sidecars'] as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS {$this->prefix}{$table}");
            }
        }

        $this->store = null;
        $this->pdo = null;
    }

    #[\Override]
    protected function store(): SessionStore
    {
        return $this->store ??= new SqlSessionStore($this->pdo ?? throw new \LogicException('no database'), $this->prefix);
    }
}
