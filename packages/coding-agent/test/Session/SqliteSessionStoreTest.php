<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use PDO;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;
use Pig\CodingAgent\Session\SessionStore;
use Pig\CodingAgent\Session\SqlSessionStore;

/** The conformance suite against `SqlSessionStore` on SQLite, a file of its own per test. */
final class SqliteSessionStoreTest extends SessionStoreConformance
{
    use SqlSessionStoreChecks;

    private string $file;

    private ?SqlSessionStore $store = null;

    #[\Override]
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_sqlite');
        }

        $this->file = sys_get_temp_dir() . '/pig-sql-store-' . bin2hex(random_bytes(4)) . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->store = null;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->file . $suffix)) {
                unlink($this->file . $suffix);
            }
        }
    }

    #[\Override]
    protected function store(): SessionStore
    {
        return $this->store ??= SqlSessionStore::open('sqlite:' . $this->file);
    }

    #[\Override]
    protected function database(string $prefix): Database
    {
        return Databases::open('sqlite:' . $this->file, prefix: $prefix);
    }

    public function testALocationEndsInPisFileName(): void
    {
        $this->assertSame('sql:2026-01-01T00-00-00-000Z_x.jsonl', $this->store()->locationFor('/project/a', '2026-01-01T00-00-00-000Z_x'));
    }

    public function testASecondStoreOnTheSameDatabaseSeesTheSameSessions(): void
    {
        $location = $this->store()->locationFor('/project/a', '2026-01-01T00-00-00-000Z_x');
        $this->store()->append($location, "{\"type\":\"session\",\"cwd\":\"/project/a\"}\n");

        // Opening again runs the migrations again, which must change nothing.
        $again = SqlSessionStore::open('sqlite:' . $this->file);

        $this->assertTrue($again->exists($location));
        $this->assertSame(['{"type":"session","cwd":"/project/a"}'], [...($again->lines($location) ?? [])]);
    }

    public function testAnUnknownDriverIsRefusedRatherThanFallenBackFrom(): void
    {
        $this->expectExceptionMessage('The storage setting is `jsonl`, a `mysql:` DSN or a `sqlite:` path');
        SqlSessionStore::open('pgsql:host=localhost');
    }
}
