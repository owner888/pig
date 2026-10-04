<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Web\ManagedSession;
use Pig\CodingAgent\Web\SessionPool;

final class SessionPoolTest extends TestCase
{
    private const string FAKE = __DIR__ . '/../fixtures/fake-rpc-agent.php';

    /** @var list<array{ManagedSession, array<string, mixed>}> */
    private array $events = [];

    private int $spawned = 0;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->events = [];
        $this->spawned = 0;
    }

    private function pool(float $idleTtl = 0.15): SessionPool
    {
        return new SessionPool(
            spawn: function (string $cwd, ?string $sessionFile): RpcClient {
                $this->spawned++;

                return new RpcClient(
                    cwd: $cwd,
                    binary: self::FAKE,
                    arguments: $sessionFile !== null ? ['--session', $sessionFile] : [],
                    timeout: 5.0,
                );
            },
            onEvent: function (ManagedSession $s, array $event): void {
                $this->events[] = [$s, $event];
            },
            idleTtl: $idleTtl,
        );
    }

    /** @return list<string> the types of events seen for one session */
    private function typesFor(ManagedSession $s): array
    {
        $types = [];
        foreach ($this->events as [$session, $event]) {
            if ($session === $s) {
                $types[] = $event['type'];
            }
        }

        return $types;
    }

    // ---- keys ------------------------------------------------------------------------------

    public function testTheKeyIsTheDirectoryAndTheFileAndANewSessionCarriesTheClient(): void
    {
        $this->assertSame('/p::a.jsonl', SessionPool::key('/p/', 'a.jsonl'));
        $this->assertSame('/p::a.jsonl', SessionPool::key('/p', '/somewhere/else/a.jsonl'), 'the basename, so two spellings of one path agree');
        $this->assertSame('/p::__new__::c1', SessionPool::key('/p', null, 'c1'));
        $this->assertSame('/p::__new__::shared', SessionPool::key('/p', ''));
        $this->assertSame('/::x', SessionPool::key('/', 'x'), 'root does not become the empty string');
    }

    // ---- one child per conversation --------------------------------------------------------

    public function testTwoConnectionsOnOneFileShareOneChildAndBothHearIt(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'conv.jsonl', 'c1');
            $b = $pool->bind(2, '/tmp', 'conv.jsonl', 'c2');

            $this->assertSame($a, $b, 'same file, same child');
            $this->assertSame(1, $this->spawned);
            $this->assertSame([1 => true, 2 => true], $a->clients);

            $a->client->prompt('hi');
            $a->client->waitForIdle(2.0);

            $this->assertContains('agent_start', $this->typesFor($a));
            $this->assertContains('agent_end', $this->typesFor($a));

            $pool->shutdown();
        });
    }

    public function testTwoFilesAreTwoChildrenAndEventsDoNotCross(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $b = $pool->bind(2, '/tmp', 'b.jsonl', 'c2');

            $this->assertNotSame($a, $b);
            $this->assertSame(2, $this->spawned);

            $a->client->prompt('only a');
            $a->client->waitForIdle(2.0);

            $this->assertContains('agent_start', $this->typesFor($a));
            $this->assertNotContains('agent_start', $this->typesFor($b), 'a turn in tab A is not drawn in tab B');

            $pool->shutdown();
        });
    }

    public function testTwoNewSessionsFromTwoBrowsersAreTwoChildren(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', null, 'browser-1');
            $b = $pool->bind(2, '/tmp', null, 'browser-2');

            $this->assertNotSame($a, $b, '"new" from two browsers is two conversations, not one');
            $this->assertSame(2, $this->spawned);

            $pool->shutdown();
        });
    }

    public function testRebindingToTheSameSessionIsANoOpAndElsewhereDetachesFirst(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $again = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $this->assertSame($a, $again);
            $this->assertSame(1, $this->spawned);

            $b = $pool->bind(1, '/tmp', 'b.jsonl', 'c1');
            $this->assertNotSame($a, $b);
            $this->assertSame([], $a->clients, 'connection 1 left a');
            $this->assertSame([1 => true], $b->clients, 'and is on b');
            $this->assertSame($b, $pool->boundTo(1));

            $pool->shutdown();
        });
    }

    // ---- a child learns its own file ----------------------------------------------------------

    public function testAFileTheChildReportsBecomesASecondKeySoAnotherBrowserCanFindIt(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $fresh = $pool->bind(1, '/tmp', null, 'browser-1');
            $this->assertNull($pool->find('/tmp', 'fake-x.jsonl'), 'not known by file yet');

            // The relay path: a browser's get_state, id and all, answered as an event.
            $fresh->client->relay(['type' => 'get_state', 'id' => 'q1']);
            Async::delay(0.2);

            $state = null;
            foreach ($this->events as [$s, $e]) {
                if ($s === $fresh && ($e['command'] ?? null) === 'get_state') {
                    $state = $e;
                }
            }
            $this->assertNotNull($state, 'the response reached the listener as an event');
            $file = basename($state['data']['sessionFile']);

            $this->assertSame($fresh, $pool->find('/tmp', $file), 'now reachable by the file it named');
            $this->assertSame($fresh, $pool->bind(2, '/tmp', $file, 'browser-2'), 'a second browser joins the same child');
            $this->assertSame(1, $this->spawned);

            $pool->shutdown();
        });
    }

    // ---- idle reaping ----------------------------------------------------------------------

    public function testAChildWithNobodyLookingIsStoppedAfterTheTtl(): void
    {
        $pool = $this->pool(idleTtl: 0.15);

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $this->assertTrue($a->client->isRunning());

            $pool->detach(1);
            $this->assertTrue($a->client->isRunning(), 'not at once — a page reload is a detach and a rebind');
            $this->assertNotNull($a->idleTimer);

            Async::delay(0.4);
            $this->assertFalse($a->client->isRunning(), 'gone after the TTL');
            $this->assertTrue($a->closing);
            $this->assertNull($pool->find('/tmp', 'a.jsonl'));
        });
    }

    public function testARebindBeforeTheTtlCancelsTheReap(): void
    {
        $pool = $this->pool(idleTtl: 0.15);

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $pool->detach(1);
            $this->assertNotNull($a->idleTimer);

            $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $this->assertNull($a->idleTimer, 'cancelled');

            Async::delay(0.4);
            $this->assertTrue($a->client->isRunning(), 'still here');
            $this->assertSame(1, $this->spawned, 'and not respawned');

            $pool->shutdown();
        });
    }

    public function testATurnInFlightKeepsAnUnwatchedChildAlive(): void
    {
        $pool = $this->pool(idleTtl: 0.15);

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            // A turn that takes longer than the TTL. Wait for `agent_start` rather than a
            // fixed delay: the child is a PHP process, and its startup is longer than the TTL
            // on a loaded machine.
            $a->client->relay(['type' => 'prompt', 'message' => 'slow', 'holdMs' => 400, 'id' => 'p1']);
            $deadline = microtime(true) + 3.0;
            while (!$a->running && microtime(true) < $deadline) {
                Async::delay(0.01);
            }
            $this->assertTrue($a->running, 'the turn began');

            $pool->detach(1);
            $this->assertNull($a->idleTimer, 'no countdown while a turn runs');

            Async::delay(0.25);
            $this->assertTrue($a->client->isRunning(), 'past the TTL, still here, because it is working');

            $deadline = microtime(true) + 3.0;
            while ($a->running && microtime(true) < $deadline) {
                Async::delay(0.01);
            }
            $this->assertFalse($a->running, 'the turn ended');
            $this->assertNotNull($a->idleTimer, 'and with nobody bound, the countdown started then');

            $pool->shutdown();
        });
    }

    // ---- a child that dies -----------------------------------------------------------------

    public function testAChildThatDiesIsUnregisteredAndItsBrowsersAreTold(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $a->client->relay(['type' => 'die', 'id' => 'd1']);
            Async::delay(0.3);

            $types = $this->typesFor($a);
            $this->assertContains('session_ended', $types, 'the tab is told, not left spinning');
            $this->assertTrue($a->closing);
            $this->assertNull($pool->boundTo(1), 'the connection is unbound');
            $this->assertNull($pool->find('/tmp', 'a.jsonl'), 'and the key is free');

            // The browser's next start_session is the restart.
            $b = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $this->assertNotSame($a, $b);
            $this->assertSame(2, $this->spawned);
            $this->assertTrue($b->client->isRunning());

            $pool->shutdown();
        });
    }

    public function testShutdownStopsEveryChild(): void
    {
        $pool = $this->pool();

        Async::run(function () use ($pool): void {
            $a = $pool->bind(1, '/tmp', 'a.jsonl', 'c1');
            $b = $pool->bind(2, '/tmp', 'b.jsonl', 'c2');
            $this->assertCount(2, $pool->all());

            $pool->shutdown();

            $this->assertFalse($a->client->isRunning());
            $this->assertFalse($b->client->isRunning());
            $this->assertSame([], $pool->all());
        });

        // Not `isIdle()`: `Async::run()` leaves its own `wake()` no-op in the queue by design.
        $loop = new \ReflectionClass(Loop::class);
        foreach (['timers', 'readers', 'writers'] as $kind) {
            $this->assertSame([], $loop->getProperty($kind)->getValue(Loop::get()), "no {$kind} left behind");
        }
    }
}
