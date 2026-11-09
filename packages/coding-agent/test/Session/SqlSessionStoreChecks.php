<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use Pig\CodingAgent\Database\Database;
use Pig\CodingAgent\Session\SqlSessionStore;

/**
 * What `SqlSessionStore` does beyond the conformance suite, on each database: removing the soft
 * way, moving 1.9.9's tables over, and owners.
 */
trait SqlSessionStoreChecks
{
    /** A database of the test's own; `$prefix` keeps its tables apart from the store's. */
    abstract protected function database(string $prefix): Database;

    public function testARemovedSessionsRowsAreKeptMarkedAndAFreshStartClearsThem(): void
    {
        $db = $this->database('soft_');
        $store = new SqlSessionStore($db);
        $location = $store->locationFor('/p', '2026_aaaa');
        $store->append($location, "{\"type\":\"session\",\"cwd\":\"/p\"}\n{\"m\":1}\n");
        $store->writeSidecar($location, 'running.json', '{}');

        $this->assertNull($store->remove($location));
        $this->assertNull($store->findGlobal('aaaa'));
        $this->assertSame([], $store->projects());

        foreach (['sessions' => 1, 'session_lines' => 2, 'session_sidecars' => 1] as $table => $rows) {
            $this->assertSame($rows, (int) $db->query("SELECT COUNT(*) FROM {$db->table($table)} WHERE location = ? AND deleted_at IS NOT NULL", [$location])->fetchColumn(), $table);
        }

        $store->append($location, "{\"type\":\"session\",\"cwd\":\"/p\"}\n");
        $this->assertSame(['{"type":"session","cwd":"/p"}'], [...($store->lines($location) ?? [])]);
        $this->assertNull($store->readSidecar($location, 'running.json'));
        $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM {$db->table('session_lines')} WHERE location = ?", [$location])->fetchColumn());
    }

    public function testADeletedSidecarComesBackWhenWrittenAgain(): void
    {
        $store = new SqlSessionStore($this->database('side_'));
        $location = $store->locationFor('/p', '2026_aaaa');
        $store->writeSidecar($location, 'running.json', '1');
        $store->deleteSidecar($location, 'running.json');
        $store->writeSidecar($location, 'running.json', '2');

        $this->assertSame('2', $store->readSidecar($location, 'running.json'));
    }

    public function testASessionIsItsOwnersAndAViewOfOneOwnerFindsOnlyTheirs(): void
    {
        $db = $this->database('own_');
        $store = new SqlSessionStore($db);
        $write = static function (SqlSessionStore $as, string $cwd, string $id): string {
            $location = $as->locationFor($cwd, "2026_{$id}");
            $as->append($location, json_encode(['type' => 'session', 'cwd' => $cwd]) . "\n");

            return $location;
        };
        $ada = $write($store->withOwner('ada-id'), '/p', 'aaaa1111');
        $bob = $write($store->withOwner('bob-id'), '/p', 'bbbb2222');
        $bobs2 = $write($store->withOwner('bob-id'), '/q', 'bbbb3333');
        $nobody = $write($store->withOwner(''), '/p', 'cccc4444');

        $this->assertSame('ada-id', $store->ownerOf($ada));
        $this->assertNull($store->ownerOf($nobody), 'no owner is null, as before accounts');
        $this->assertNull($store->ownerOf('sql:nothing.jsonl'));

        $bobs = $store->visibleTo('bob-id');
        $this->assertTrue($bobs->exists($bob));
        $this->assertFalse($bobs->exists($ada));
        $this->assertFalse($bobs->exists($nobody), 'nobody\'s is the admin\'s');
        $this->assertNull($bobs->lines($ada));
        $this->assertNull($bobs->canonical($ada));
        $every = $bobs->every();
        sort($every);
        $this->assertSame([$bob, $bobs2], $every);
        $this->assertSame([[$bob], false], $bobs->candidates('/p'));
        $this->assertNull($bobs->findGlobal('aaaa'));
        $this->assertNull($bobs->findLocal('/p', 'aaaa1111'));
        $this->assertSame($bob, $bobs->findGlobal('bbbb2'));
        $projects = $bobs->projects();
        usort($projects, static fn (array $a, array $b): int => $a['cwd'] <=> $b['cwd']);
        $this->assertSame([['cwd' => '/p', 'sessions' => 1], ['cwd' => '/q', 'sessions' => 1]], $projects);

        $admins = $store->visibleTo('ada-id', unowned: true)->every();
        sort($admins);
        $this->assertSame([$ada, $nobody], $admins, 'an admin\'s own list: theirs and nobody\'s');
        $this->assertTrue($store->visibleTo('ada-id', unowned: true)->exists($nobody));
        $this->assertFalse($store->visibleTo('ada-id', unowned: true)->exists($bob));

        $this->assertCount(4, $store->every(), 'the store itself sees everybody\'s');
        $this->assertCount(4, $store->visibleTo(null)->every());

        // Appending to somebody's session keeps it theirs, whoever's store writes it.
        $store->withOwner('ada-id')->append($bob, "{\"m\":1}\n");
        $this->assertSame('bob-id', $store->ownerOf($bob));
    }

    public function testTablesFrom199AreMovedOverWithWhenEachSessionWasWritten(): void
    {
        $db = $this->database('old_');
        $text = $db->dialect() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        [$sessions, $lines, $sidecars] = [$db->table('sessions'), $db->table('session_lines'), $db->table('session_sidecars')];
        $db->query("CREATE TABLE {$sessions} (location VARCHAR(255) NOT NULL PRIMARY KEY, id VARCHAR(255) NOT NULL, cwd {$text} NOT NULL,
            owner VARCHAR(255) NOT NULL DEFAULT '', line_count INTEGER NOT NULL, bytes BIGINT NOT NULL, modified_at BIGINT NOT NULL)");
        $db->query("CREATE TABLE {$lines} (location VARCHAR(255) NOT NULL, seq INTEGER NOT NULL, line {$text} NOT NULL, PRIMARY KEY (location, seq))");
        $db->query("CREATE TABLE {$sidecars} (location VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, contents {$text} NOT NULL, PRIMARY KEY (location, name))");
        $db->query("CREATE INDEX {$sessions}_id ON {$sessions} (id)");
        $db->query("INSERT INTO {$sessions} VALUES ('sql:2025_old1.jsonl', 'old1', '/p', '', 2, 40, 1700000000456)");
        $db->query("INSERT INTO {$lines} VALUES ('sql:2025_old1.jsonl', 0, '{\"type\":\"session\",\"cwd\":\"/p\"}'), ('sql:2025_old1.jsonl', 1, '{\"m\":1}')");
        $db->query("INSERT INTO {$sidecars} VALUES ('sql:2025_old1.jsonl', 'running.json', '{}')");

        $store = new SqlSessionStore($db);

        $this->assertSame(['{"type":"session","cwd":"/p"}', '{"m":1}'], [...($store->lines('sql:2025_old1.jsonl') ?? [])]);
        $this->assertSame(1_700_000_000_456, $store->modifiedAt('sql:2025_old1.jsonl'));
        $this->assertSame(40, $store->size('sql:2025_old1.jsonl'));
        $this->assertSame('{}', $store->readSidecar('sql:2025_old1.jsonl', 'running.json'));
        $this->assertSame('sql:2025_old1.jsonl', $store->findGlobal('old'));
        $this->assertNull($db->query("SELECT owner FROM {$sessions}")->fetchColumn(), 'no owner yet is null, not empty');
        $this->assertFalse($db->tableExists('sessions_v1'));
        $this->assertContains('created_at', $db->columns('session_lines'));

        $again = new SqlSessionStore($db);
        $this->assertSame(1_700_000_000_456, $again->modifiedAt('sql:2025_old1.jsonl'), 'opened again, moved once');
    }
}
