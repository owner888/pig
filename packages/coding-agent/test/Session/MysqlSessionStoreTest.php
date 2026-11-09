<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use PDO;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;
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
    use SqlSessionStoreChecks;

    private string $prefix = '';

    /** @var list<Database> every database the test opened, its tables dropped after */
    private array $opened = [];

    private ?SqlSessionStore $store = null;

    #[\Override]
    protected function setUp(): void
    {
        if ((string) getenv('PIG_TEST_MYSQL_DSN') === '') {
            self::markTestSkipped('set PIG_TEST_MYSQL_DSN to run the session store against MySQL');
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_mysql');
        }

        $this->prefix = 'pigtest_' . bin2hex(random_bytes(3)) . '_';
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->opened as $db) {
            foreach (['sessions', 'session_lines', 'session_sidecars', 'sessions_v1', 'session_lines_v1', 'session_sidecars_v1'] as $table) {
                $db->dropTable($table);
            }
        }

        $this->opened = [];
        $this->store = null;
    }

    #[\Override]
    protected function database(string $prefix): Database
    {
        return $this->opened[] = Databases::open(
            (string) getenv('PIG_TEST_MYSQL_DSN'),
            getenv('PIG_TEST_MYSQL_USER') ?: null,
            getenv('PIG_TEST_MYSQL_PASSWORD') ?: null,
            $this->prefix . $prefix,
        );
    }

    #[\Override]
    protected function store(): SessionStore
    {
        return $this->store ??= new SqlSessionStore($this->database(''));
    }
}
