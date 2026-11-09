<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Session\SessionStore;

/**
 * What every `SessionStore` must do, run against each one: `JsonlSessionStoreTest`,
 * `MemorySessionStoreTest`, and the SQL stores when they come (docs/dev/design/storage.md). A
 * store that passes this is one `SessionManager` can keep sessions in.
 *
 * pi-durable's `testing/` does the same for its storages; this is the same idea for pig's seam.
 */
abstract class SessionStoreConformance extends TestCase
{
    abstract protected function store(): SessionStore;

    private const string HEADER = '{"type":"session","version":3,"id":"aaaa1111-0000-4000-8000-000000000001","timestamp":"2026-01-01T00:00:00.000Z","cwd":"/project/a"}';

    /** @param list<string> $lines */
    private static function joined(array $lines): string
    {
        return implode('', array_map(static fn (string $line): string => $line . "\n", $lines));
    }

    /** @return list<string> */
    private static function all(?iterable $lines): array
    {
        return $lines === null ? [] : array_values([...$lines]);
    }

    private function newSession(string $cwd = '/project/a', string $id = 'aaaa1111-0000-4000-8000-000000000001'): string
    {
        return $this->store()->locationFor($cwd, '2026-01-01T00-00-00-000Z_' . $id);
    }

    public function testASessionThatWasNeverWrittenIsNotThere(): void
    {
        $store = $this->store();
        $location = $this->newSession();

        $this->assertFalse($store->exists($location));
        $this->assertNull($store->lines($location));
        $this->assertNull($store->firstLine($location));
        $this->assertNull($store->modifiedAt($location));
        $this->assertNull($store->size($location));
        $this->assertSame([], self::all($store->linesFromEnd($location)));
    }

    public function testAppendsAreReadBackInOrderWithoutTheirNewlines(): void
    {
        $store = $this->store();
        $location = $this->newSession();

        $store->append($location, self::joined([self::HEADER, '{"type":"message","id":"1"}']));
        $store->append($location, self::joined(['{"type":"message","id":"2"}']));

        $this->assertTrue($store->exists($location));
        $this->assertSame([self::HEADER, '{"type":"message","id":"1"}', '{"type":"message","id":"2"}'], self::all($store->lines($location)));
        $this->assertSame(self::HEADER, $store->firstLine($location));
        $this->assertSame(['{"type":"message","id":"2"}', '{"type":"message","id":"1"}', self::HEADER], self::all($store->linesFromEnd($location)));
        $this->assertGreaterThan(0, $store->size($location));
        $this->assertIsInt($store->modifiedAt($location));
    }

    public function testLinesFromTheEndSurviveLinesLongerThanAnyBlock(): void
    {
        // A screenshot is megabytes on one line; reading backwards in blocks must not cut it.
        $store = $this->store();
        $location = $this->newSession();
        $long = '{"type":"message","data":"' . str_repeat('x', 200_000) . '"}';

        $store->append($location, self::joined([self::HEADER, $long, '{"type":"session_info","name":"n"}']));

        $this->assertSame(['{"type":"session_info","name":"n"}', $long, self::HEADER], self::all($store->linesFromEnd($location)));
    }

    public function testCreateRefusesASessionThatIsAlreadyThere(): void
    {
        $store = $this->store();
        $location = $this->newSession();

        $store->create($location, self::joined([self::HEADER]));
        $this->assertSame([self::HEADER], self::all($store->lines($location)));

        $this->expectException(AgentError::class);
        $store->create($location, self::joined([self::HEADER]));
    }

    public function testReplaceKeepsOnlyTheNewLines(): void
    {
        $store = $this->store();
        $location = $this->newSession();

        $store->append($location, self::joined([self::HEADER, '{"old":1}', '{"old":2}']));
        $store->replace($location, [self::HEADER, '{"new":1}']);

        $this->assertSame([self::HEADER, '{"new":1}'], self::all($store->lines($location)));
    }

    public function testAProjectsSessionsAreItsCandidatesAndEveryonesToo(): void
    {
        $store = $this->store();
        $a = $this->newSession('/project/a', 'aaaa1111-0000-4000-8000-000000000001');
        $b = $this->newSession('/project/b', 'bbbb2222-0000-4000-8000-000000000002');
        $store->append($a, self::joined([self::HEADER]));
        $store->append($b, self::joined([str_replace('/project/a', '/project/b', self::HEADER)]));

        [$candidates] = $store->candidates('/project/a');

        $this->assertContains($a, $candidates);
        $this->assertNotContains($b, $candidates);
        $this->assertEqualsCanonicalizing([$a, $b], $store->every());
    }

    public function testASessionIsFoundByItsIdOrThePrefixOfOne(): void
    {
        $store = $this->store();
        $a = $this->newSession('/project/a', 'aaaa1111-0000-4000-8000-000000000001');
        $store->append($a, self::joined([self::HEADER]));

        $this->assertSame($a, $store->findLocal('/project/a', 'aaaa1111-0000-4000-8000-000000000001'));
        $this->assertSame($a, $store->findLocal('/project/a', 'aaaa1111'));
        $this->assertNull($store->findLocal('/project/b', 'aaaa1111'), 'another project is not searched locally');
        $this->assertSame($a, $store->findGlobal('aaaa1111'));
        $this->assertNull($store->findGlobal('ffff'));
    }

    public function testProjectsCountTheirSessions(): void
    {
        $store = $this->store();
        $store->append($this->newSession('/project/a', 'aaaa1111-0000-4000-8000-000000000001'), self::joined([self::HEADER]));
        $store->append($this->newSession('/project/a', 'aaaa1111-0000-4000-8000-000000000003'), self::joined([self::HEADER]));

        $counts = [];

        foreach ($store->projects() as ['cwd' => $cwd, 'sessions' => $sessions]) {
            $counts[$cwd] = ($counts[$cwd] ?? 0) + $sessions;
        }

        $this->assertSame(['/project/a' => 2], $counts);
    }

    public function testASidecarIsKeptReplacedAndRemoved(): void
    {
        $store = $this->store();
        $location = $this->newSession();
        $store->append($location, self::joined([self::HEADER]));

        $this->assertNull($store->readSidecar($location, 'running.json'));

        $store->writeSidecar($location, 'running.json', '{"a":1}');
        $store->writeSidecar($location, 'running.json', '{"a":2}');
        $this->assertSame('{"a":2}', $store->readSidecar($location, 'running.json'));

        $store->deleteSidecar($location, 'running.json');
        $this->assertNull($store->readSidecar($location, 'running.json'));

        // Removing one that is not there is not an error.
        $store->deleteSidecar($location, 'running.json');
        $this->assertSame([self::HEADER], self::all($store->lines($location)), 'the session itself is untouched');
    }

    public function testARemovedSessionIsGoneWithItsSidecars(): void
    {
        $store = $this->store();
        $location = $this->newSession();
        $store->append($location, self::joined([self::HEADER]));
        $store->writeSidecar($location, 'running.json', '{}');

        // A session of this store; named as the store names it, which for a file is its real path
        // (`/var` is `/private/var` on macOS), and naming it again changes nothing.
        $canonical = $store->canonical($location);
        $this->assertNotNull($canonical);
        $this->assertSame($canonical, $store->canonical($canonical));
        $this->assertNull($store->remove($location));
        $this->assertFalse($store->exists($location));
        $this->assertNull($store->readSidecar($location, 'running.json'));
        $this->assertNull($store->canonical($location), 'what is not there is not a session of this store');
        $this->assertNull($store->remove($location), 'removing it again is not an error');
    }
}
