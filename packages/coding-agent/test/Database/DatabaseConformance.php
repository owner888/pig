<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Database;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Database\Database;
use RuntimeException;

/**
 * What every `Database` does, run against each: the timestamps the database keeps, transactions,
 * and the table calls. `SqliteDatabaseTest` and `MysqlDatabaseTest` say which database.
 */
abstract class DatabaseConformance extends TestCase
{
    abstract protected function db(): Database;

    private function things(): string
    {
        $this->db()->createTable('things', ['k' => 'string', 'v' => '?text', 'n' => 'integer'], ['k'], ['n' => ['n']]);

        return $this->db()->table('things');
    }

    /** @return array<string, mixed> */
    private function row(string $table): array
    {
        $row = $this->db()->query("SELECT * FROM {$table} WHERE k = 'a'")->fetch();
        $this->assertIsArray($row);

        return $row;
    }

    /** Long enough for the clock to move past a millisecond. */
    private static function later(): void
    {
        usleep(10_000);
    }

    public function testATableHasItsColumnsThenTheThreeTimestamps(): void
    {
        $this->things();
        $this->things();

        $this->assertTrue($this->db()->tableExists('things'));
        $this->assertSame(['k', 'v', 'n', 'created_at', 'updated_at', 'deleted_at'], $this->db()->columns('things'));
    }

    public function testTheDatabaseSetsCreatedAndUpdatedOnInsertInUtc(): void
    {
        $table = $this->things();
        $before = (int) floor(microtime(true) * 1000);
        $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");
        $row = $this->row($table);

        $created = $this->db()->timestamp($row['created_at']);
        $this->assertNotNull($created);
        $this->assertLessThan(5000, abs($created - $before), 'UTC, whatever the server zone');
        $this->assertSame($created, $this->db()->timestamp($row['updated_at']));
        $this->assertNull($row['deleted_at']);
        $this->assertNull($this->db()->timestamp($row['deleted_at']));
    }

    public function testUpdatedAtMovesWhenAnotherColumnChangesAndOnlyThen(): void
    {
        $table = $this->things();
        $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");
        $first = $this->row($table);

        self::later();
        $this->db()->query("UPDATE {$table} SET n = 1 WHERE k = 'a'");
        $this->assertSame($first['updated_at'], $this->row($table)['updated_at'], 'nothing changed');

        self::later();
        $this->db()->query("UPDATE {$table} SET v = 'x' WHERE k = 'a'");
        $changed = $this->row($table);
        $this->assertGreaterThan($this->db()->timestamp($first['updated_at']), $this->db()->timestamp($changed['updated_at']));
        $this->assertSame($first['created_at'], $changed['created_at']);

        self::later();
        $this->db()->query("UPDATE {$table} SET deleted_at = ? WHERE k = 'a'", [$this->db()->at()]);
        $this->assertGreaterThan($this->db()->timestamp($changed['updated_at']), $this->db()->timestamp($this->row($table)['updated_at']), 'a soft delete is a change');
    }

    public function testAnUpdatedAtTheCodeSetsIsKept(): void
    {
        $table = $this->things();
        $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");
        $this->db()->query("UPDATE {$table} SET n = 2, updated_at = ? WHERE k = 'a'", [$this->db()->at(1_700_000_000_456)]);

        $this->assertSame(1_700_000_000_456, $this->db()->timestamp($this->row($table)['updated_at']));
    }

    public function testATransactionCommitsWhatItReturnsAndRollsBackWhatThrows(): void
    {
        $table = $this->things();

        $this->assertSame(42, $this->db()->transaction(function () use ($table): int {
            $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");

            return 42;
        }));

        try {
            $this->db()->transaction(function () use ($table): void {
                $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('b', 2)");

                throw new RuntimeException('no');
            });
            $this->fail('the error goes on out');
        } catch (RuntimeException $error) {
            $this->assertSame('no', $error->getMessage());
        }

        $this->assertSame(['a'], $this->db()->query("SELECT k FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testTransactionsDoNotNestButOneJoinsExclusively(): void
    {
        $table = $this->things();

        try {
            $this->db()->transaction(fn () => $this->db()->transaction(fn (): int => 1));
            $this->fail('nested');
        } catch (LogicException) {
        }

        $this->assertSame(3, $this->db()->exclusively('test', fn (): int => $this->db()->transaction(function () use ($table): int {
            $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 3)");

            return 3;
        })));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
    }

    public function testARenamedTableKeepsItsIndexAndTimestampsAndANewOneGetsItsOwn(): void
    {
        $table = $this->things();
        $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");
        $this->db()->renameTable('things', 'things_old');
        $old = $this->db()->table('things_old');
        $new = $this->things();

        $this->assertTrue($this->db()->tableExists('things_old'));
        $this->assertSame([], $this->db()->query("SELECT * FROM {$new}")->fetchAll());

        foreach ([$old, $new] as $each) {
            $this->db()->query("INSERT INTO {$each} (k, n) VALUES ('z', 5)");
            $before = $this->db()->query("SELECT updated_at FROM {$each} WHERE k = 'z'")->fetchColumn();
            self::later();
            $this->db()->query("UPDATE {$each} SET v = 'y' WHERE k = 'z'");
            $this->assertNotSame($before, $this->db()->query("SELECT updated_at FROM {$each} WHERE k = 'z'")->fetchColumn(), "{$each} keeps its updated_at");
        }

        $this->db()->dropTable('things_old');
        $this->assertFalse($this->db()->tableExists('things_old'));
    }

    public function testAColumnAddedLaterIsNullForTheRowsThereAndMovesUpdatedAt(): void
    {
        $table = $this->things();
        $this->db()->query("INSERT INTO {$table} (k, n) VALUES ('a', 1)");
        $this->db()->addColumn('things', 'extra', '?text');
        $this->db()->addColumn('things', 'extra', '?text');

        $this->assertSame(['k', 'v', 'n', 'created_at', 'updated_at', 'deleted_at', 'extra'], $this->db()->columns('things'));
        $this->assertNull($this->row($table)['extra']);

        $before = $this->row($table)['updated_at'];
        self::later();
        $this->db()->query("UPDATE {$table} SET extra = 'x' WHERE k = 'a'");
        $this->assertNotSame($before, $this->row($table)['updated_at'], 'a change to the new column is a change');
    }

    public function testAtAndTimestampAreEachOthersInverse(): void
    {
        $this->assertSame('2023-11-14 22:13:20.456', $this->db()->at(1_700_000_000_456));
        $this->assertSame(1_700_000_000_456, $this->db()->timestamp('2023-11-14 22:13:20.456'));
        $this->assertSame(1_700_000_000_000, $this->db()->timestamp('2023-11-14 22:13:20'));
    }

    public function testANameThatIsNotOneIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->db()->createTable('things', ['k; DROP TABLE x' => 'string'], ['k']);
    }
}
