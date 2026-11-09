<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Database;

use PDO;
use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Database\Databases;

/** The `Database` conformance suite on SQLite, a file of its own per test. */
final class SqliteDatabaseTest extends DatabaseConformance
{
    private string $file;

    private ?Database $db = null;

    #[\Override]
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('needs pdo_sqlite');
        }

        $this->file = sys_get_temp_dir() . '/pig-db-' . bin2hex(random_bytes(4)) . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db = null;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->file . $suffix)) {
                unlink($this->file . $suffix);
            }
        }
    }

    #[\Override]
    protected function db(): Database
    {
        return $this->db ??= Databases::open('sqlite:' . $this->file);
    }

    public function testAFileDatabaseIsInWalMode(): void
    {
        $this->assertSame('wal', $this->db()->query('PRAGMA journal_mode')->fetchColumn());
    }
}
