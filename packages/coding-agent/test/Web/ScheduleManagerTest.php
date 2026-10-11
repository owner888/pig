<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Web\ManagedSession;
use Pig\CodingAgent\Web\Schedule\ScheduledTask;
use Pig\CodingAgent\Web\Schedule\ScheduleManager;
use Pig\CodingAgent\Web\SessionPool;
use RuntimeException;

final class ScheduleManagerTest extends TestCase
{
    private const string FAKE = __DIR__ . '/../fixtures/fake-rpc-agent.php';

    /** 2026-10-11 09:00:00 in Singapore, in milliseconds. */
    private const int NINE_AM = 1791680400000;

    private string $dir;

    private int $now = self::NINE_AM;

    private int $changes = 0;

    /** @var list<ScheduleManager> */
    private array $managers = [];

    /** @var list<SessionPool> */
    private array $pools = [];

    private string|false $previousHome = false;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->dir = sys_get_temp_dir() . '/pig-schedule-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        // A task's conversation is a session file, written under the agent directory's `sessions/`.
        $this->previousHome = getenv('PIG_CODING_AGENT_DIR');
        putenv('PIG_CODING_AGENT_DIR=' . $this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->stop();
        }

        Async::run(function (): void {
            foreach ($this->pools as $pool) {
                $pool->shutdown();
            }
        });

        putenv($this->previousHome === false ? 'PIG_CODING_AGENT_DIR' : 'PIG_CODING_AGENT_DIR=' . $this->previousHome);
        exec('rm -rf ' . escapeshellarg($this->dir));
        Loop::reset();
    }

    private function manager(?string $url = null): ScheduleManager
    {
        $manager = null;
        $pool = new SessionPool(
            spawn: static fn (string $cwd, ?string $file): RpcClient => new RpcClient(cwd: $cwd, binary: self::FAKE, timeout: 5.0),
            onEvent: static function (ManagedSession $managed, array $event) use (&$manager): void {
                $manager?->observe($managed, $event);
            },
            idleTtl: 0.2,
        );

        $manager = new ScheduleManager(
            $pool,
            function (): void {
                $this->changes++;
            },
            $this->dir,
            fn (): int => $this->now,
            0.02,
            $url,
        );

        $this->pools[] = $pool;
        $this->managers[] = $manager;

        return $manager;
    }

    /** @return array<string, mixed> */
    private function task(array $changes = []): array
    {
        return [
            'name' => 'Morning brief',
            'prompt' => 'say hello',
            'cwd' => $this->dir,
            'schedule' => 'cron',
            'cron' => '0 9 * * *',
            'timezone' => 'Asia/Singapore',
            ...$changes,
        ];
    }

    /** Turn the loop until `$done` says so, or fail after two seconds. */
    private function until(Closure $done, string $what): void
    {
        $deadline = microtime(true) + 2.0;

        while (!$done()) {
            if (microtime(true) > $deadline) {
                self::fail("Timed out waiting for {$what}");
            }

            Async::delay(0.01);
        }
    }

    /** @return array<string, mixed> */
    private static function lastRun(ScheduleManager $manager, string $id): array
    {
        return $manager->runs($id)[0] ?? [];
    }

    // ---- firing --------------------------------------------------------------------------------

    public function testADueTaskRunsOnceAndTheRunSaysHowItWent(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task())->id;

            $this->now = self::NINE_AM + 86_400_000; // the next morning
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run to finish');

            $run = self::lastRun($manager, $id);
            self::assertCount(1, $manager->runs($id));
            self::assertSame('schedule', $run['trigger']);
            self::assertNull($run['error']);
            self::assertSame($manager->list()[0]['sessionFile'], $run['sessionFile'], 'a turn in the task\'s conversation');
            self::assertSame([basename($run['sessionFile']) => 'Morning brief'], $manager->sessionTasks());
        });
    }

    public function testAWeekOfMissedMorningsIsOneRunAndTheNextIsTomorrow(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task())->id;

            $this->now = self::NINE_AM + 7 * 86_400_000 + 3_600_000; // a week later, at ten
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run');

            self::assertCount(1, $manager->runs($id));
            self::assertSame(self::NINE_AM + 8 * 86_400_000, $manager->list()[0]['nextRunAt']);
        });
    }

    public function testNothingRunsBeforeItsTime(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task())->id;

            $this->now = self::NINE_AM + 86_400_000 - 1;
            Async::delay(0.1);

            self::assertSame([], $manager->runs($id));
        });
    }

    public function testAOnceTaskIsSwitchedOffAfterItRuns(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task(['schedule' => 'once', 'cron' => null, 'runAt' => self::NINE_AM + 60_000]))->id;

            $this->now = self::NINE_AM + 60_000;
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run');

            self::assertFalse($manager->list()[0]['enabled']);
            self::assertNull($manager->list()[0]['nextRunAt']);
        });
    }

    public function testRunNowLeavesTheScheduleAlone(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task())->id;
            $next = $manager->list()[0]['nextRunAt'];

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run');

            self::assertSame('manual', self::lastRun($manager, $id)['trigger']);
            self::assertSame($next, $manager->list()[0]['nextRunAt']);
        });
    }

    // ---- the task's conversation -------------------------------------------------------------

    public function testATaskHasItsConversationInTheSidebarFromTheMomentItIsSaved(): void
    {
        $manager = $this->manager();
        $manager->start();
        $task = $manager->save($this->task());

        self::assertNotNull($task->sessionFile);
        self::assertFileExists($task->sessionFile);
        self::assertStringStartsWith(SessionManager::directory($this->dir), $task->sessionFile, 'in its folder\'s sessions');
        self::assertSame('Morning brief', SessionManager::open($task->sessionFile)->getSessionName());
        self::assertSame([basename($task->sessionFile) => 'Morning brief'], $manager->sessionTasks(), 'the sidebar\'s clock');
        self::assertSame($task->sessionFile, $this->manager()->list()[0]['sessionFile'] ?? null, 'kept in the tasks file');
    }

    public function testEveryRunIsATurnInTheSameConversation(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $task = $manager->save($this->task(['schedule' => 'manual', 'cron' => null]));

            foreach ([1, 2] as $n) {
                $manager->runNow($task->id);
                $this->until(static fn (): bool => count($manager->runs($task->id)) === $n && $manager->runs($task->id)[0]['status'] === ScheduleManager::DONE, "run {$n}");
            }

            self::assertSame([$task->sessionFile, $task->sessionFile], array_column($manager->runs($task->id), 'sessionFile'));
        });
    }

    public function testAnEditKeepsTheConversationAndAnotherFolderStartsOneThere(): void
    {
        $manager = $this->manager();
        $manager->start();
        $first = $manager->save($this->task());

        $renamed = $manager->save($this->task(['id' => $first->id, 'name' => 'Evening brief', 'sessionFile' => '/etc/passwd']));
        self::assertSame($first->sessionFile, $renamed->sessionFile, 'the same conversation, and not one a client names');

        mkdir($this->dir . '/elsewhere');
        $moved = $manager->save($this->task(['id' => $first->id, 'cwd' => $this->dir . '/elsewhere']));
        self::assertNotSame($first->sessionFile, $moved->sessionFile);
        self::assertStringStartsWith(SessionManager::directory($this->dir . '/elsewhere'), (string) $moved->sessionFile);
        self::assertFileExists((string) $first->sessionFile, 'the old one is a conversation like any other, and stays');
    }

    public function testAConversationThatWasDeletedIsStartedAgainAtTheNextRun(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $task = $manager->save($this->task(['schedule' => 'manual', 'cron' => null]));
            unlink((string) $task->sessionFile);

            $manager->runNow($task->id);
            $this->until(static fn (): bool => ($manager->runs($task->id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run');

            $now = $manager->list()[0]['sessionFile'];
            self::assertNotSame($task->sessionFile, $now);
            self::assertFileExists($now);
            self::assertSame($now, self::lastRun($manager, $task->id)['sessionFile']);
        });
    }

    public function testARunThatFindsItsConversationBusyIsSkipped(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $task = $manager->save($this->task(['schedule' => 'manual', 'cron' => null]));

            // Somebody is in the middle of a turn in the task's conversation — parked on a question.
            $theirs = end($this->pools)->open($this->dir, (string) $task->sessionFile);
            $theirs->client->prompt('ask: mine');
            $this->until(static fn (): bool => $theirs->running, 'their turn to start');

            $manager->runNow($task->id);
            $this->until(static fn (): bool => ($manager->runs($task->id)[0]['status'] ?? null) === ScheduleManager::SKIPPED, 'the run to be let go');
            self::assertSame('Its conversation was busy with another turn.', self::lastRun($manager, $task->id)['error']);
        });
    }

    public function testAFinishedRunIsUnreadUntilItsConversationIsOpened(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $task = $manager->save($this->task(['schedule' => 'manual', 'cron' => null]));
            self::assertSame([], $manager->unread());
            self::assertFalse($manager->list()[0]['unread']);

            $manager->runNow($task->id);
            self::assertSame([], $manager->unread(), 'not while it is going');
            $this->until(static fn (): bool => ($manager->runs($task->id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run');

            $file = basename((string) $task->sessionFile);
            self::assertSame([$file => true], $manager->unread());
            self::assertTrue($manager->list()[0]['unread']);

            $changes = $this->changes;
            $manager->markSeen('some-other.jsonl');
            self::assertSame($changes, $this->changes, 'a conversation no task has changes nothing');

            $manager->markSeen($file);
            self::assertSame([], $manager->unread());
            self::assertSame($changes + 1, $this->changes, 'every page hears it');
            self::assertSame([], $this->manager()->unread(), 'kept in the tasks file');

            $manager->markSeen($file);
            self::assertSame($changes + 1, $this->changes, 'read twice is one change');
        });
    }

    public function testRunsFromBeforeUnreadWasKeptCountAsRead(): void
    {
        file_put_contents($this->dir . '/scheduled-tasks.json', json_encode([
            'tasks' => [[...$this->task(), 'id' => 'told', 'createdAt' => self::NINE_AM, 'anchorAt' => self::NINE_AM, 'sessionFile' => $this->dir . '/x.jsonl']],
            'runs' => ['told' => [['id' => 'r1', 'status' => 'done', 'startedAt' => self::NINE_AM, 'finishedAt' => self::NINE_AM]]],
        ]));

        $manager = $this->manager();
        $manager->start();
        self::assertSame([], $manager->unread());
    }

    public function testATurnThatEndsInAnErrorIsAFailedRunThatSaysWhy(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task(['prompt' => 'fail: on purpose']))->id;

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::ERROR, 'the failure');

            self::assertSame('boom', self::lastRun($manager, $id)['error']);
        });
    }

    public function testAFolderThatHasGoneIsAFailedRunAndNotASilentOne(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $gone = $this->dir . '/gone';
            mkdir($gone);
            $id = $manager->save($this->task(['cwd' => $gone]))->id;
            rmdir($gone);

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::ERROR, 'the failure');

            self::assertStringContainsString('Could not start the agent', self::lastRun($manager, $id)['error']);
        });
    }

    // ---- a question nobody is there to answer ----------------------------------------------

    public function testAQuestionParksTheRunUntilSomebodyAnswersIt(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task(['prompt' => 'ask: may I?']))->id;

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::NEEDS_INPUT, 'the question');

            self::assertTrue($manager->list()[0]['running']);

            $this->expectExceptionObject(new InvalidArgumentException('This task is already running.'));
            $manager->runNow($id);
        });
    }

    public function testAnOccurrenceWhileTheLastRunIsStillGoingIsWrittenDownAsSkipped(): void
    {
        Async::run(function (): void {
            $manager = $this->manager();
            $manager->start();
            $id = $manager->save($this->task(['prompt' => 'ask: may I?']))->id;

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::NEEDS_INPUT, 'the question');

            $this->now = self::NINE_AM + 86_400_000;
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::SKIPPED, 'the skip');

            self::assertSame('The previous run was still going.', self::lastRun($manager, $id)['error']);
            self::assertSame(ScheduleManager::NEEDS_INPUT, $manager->runs($id)[1]['status']);
        });
    }

    public function testTheQuestionIsKeptForWhoeverOpensTheConversationAndAnAnswerCarriesOn(): void
    {
        Async::run(function (): void {
            $managed = null;
            $manager = null;
            $pool = new SessionPool(
                spawn: static fn (string $cwd, ?string $file): RpcClient => new RpcClient(cwd: $cwd, binary: self::FAKE, timeout: 5.0),
                onEvent: static function (ManagedSession $m, array $event) use (&$manager, &$managed): void {
                    $managed = $m;
                    $manager?->observe($m, $event);
                },
                idleTtl: 0.2,
            );
            $manager = new ScheduleManager($pool, static fn () => null, $this->dir, fn (): int => $this->now, 0.02);
            $this->pools[] = $pool;
            $this->managers[] = $manager;
            $manager->start();
            $id = $manager->save($this->task(['prompt' => 'ask: may I?']))->id;

            $manager->runNow($id);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::NEEDS_INPUT, 'the question');

            self::assertSame(['q1'], array_keys($managed->pendingUi));
            self::assertSame('confirm', $managed->pendingUi['q1']['method']);

            $managed->client->relay(['type' => 'extension_ui_response', 'id' => 'q1', 'confirmed' => true]);
            $this->until(static fn (): bool => ($manager->runs($id)[0]['status'] ?? null) === ScheduleManager::DONE, 'the run to finish');

            self::assertSame([], $managed->pendingUi, 'a settled turn has nothing left to ask');
        });
    }

    // ---- saving --------------------------------------------------------------------------------

    public function testATimeThatHasPassedIsRefused(): void
    {
        $manager = $this->manager();
        $manager->start();

        $this->expectExceptionObject(new InvalidArgumentException('That time has already passed.'));
        $manager->save($this->task(['schedule' => 'once', 'runAt' => self::NINE_AM - 1]));
    }

    public function testAnExpressionThatIsNotOneIsRefusedNamingTheField(): void
    {
        $manager = $this->manager();
        $manager->start();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hour field is out of range');
        $manager->save($this->task(['cron' => '0 25 * * *']));
    }

    public function testSavingCountsFromNowSoAnEditIsNeverACatchUpRun(): void
    {
        $manager = $this->manager();
        $manager->start();
        $task = $manager->save($this->task());

        $this->now = self::NINE_AM + 3 * 86_400_000 + 1;
        $manager->save([...$task->toArray(), 'name' => 'Renamed']);

        self::assertSame(self::NINE_AM + 4 * 86_400_000, $manager->list()[0]['nextRunAt']);
        self::assertSame($task->createdAt, $manager->list()[0]['createdAt'], 'an edit keeps when it was made');
    }

    public function testATaskOffHasNoNextRunAndOnAgainCountsFromNow(): void
    {
        $manager = $this->manager();
        $manager->start();
        $id = $manager->save($this->task())->id;

        $manager->setEnabled($id, false);
        self::assertNull($manager->list()[0]['nextRunAt']);

        $this->now = self::NINE_AM + 5 * 86_400_000 + 1;
        $manager->setEnabled($id, true);
        self::assertSame(self::NINE_AM + 6 * 86_400_000, $manager->list()[0]['nextRunAt']);
    }

    public function testEveryChangeIsToldToThePage(): void
    {
        $manager = $this->manager();
        $manager->start();
        $id = $manager->save($this->task())->id;
        $manager->setEnabled($id, false);
        $manager->delete($id);

        self::assertSame(3, $this->changes);
        self::assertSame([], $manager->list());
    }

    // ---- the file ------------------------------------------------------------------------------

    public function testTheTasksSurviveARestart(): void
    {
        $manager = $this->manager();
        $manager->start();
        $manager->save($this->task());
        $manager->stop();

        $again = $this->manager();
        $again->start();

        self::assertSame('Morning brief', $again->list()[0]['name']);
    }

    public function testARunTheLastServerLeftGoingIsAFailureNotAForeverSpinner(): void
    {
        file_put_contents($this->dir . '/scheduled-tasks.json', json_encode([
            'tasks' => [ScheduledTask::fromArray($this->task(['id' => 'tx']))->toArray()],
            'runs' => ['tx' => [['id' => 'r1', 'trigger' => 'schedule', 'status' => 'running', 'startedAt' => 1]]],
        ]));

        $manager = $this->manager();
        $manager->start();

        self::assertSame(ScheduleManager::ERROR, self::lastRun($manager, 'tx')['status']);
        self::assertSame('The server stopped while this was running.', self::lastRun($manager, 'tx')['error']);
        self::assertFalse($manager->list()[0]['running']);
    }

    public function testAnEntryThatNoLongerReadsIsReportedAndKept(): void
    {
        $broken = ['id' => 'tb', 'name' => 'Old', 'prompt' => 'x', 'cwd' => '/nowhere/at/all', 'schedule' => 'manual', 'timezone' => 'UTC'];
        file_put_contents($this->dir . '/scheduled-tasks.json', json_encode(['tasks' => [$broken], 'runs' => []]));

        $manager = $this->manager();
        $manager->start();
        $manager->save($this->task());

        self::assertSame(["Old: The folder '/nowhere/at/all' does not exist."], $manager->problems());
        $written = json_decode((string) file_get_contents($this->dir . '/scheduled-tasks.json'), true);
        self::assertContains($broken, $written['tasks'], 'saving the others does not delete it');
    }

    public function testAFileThatIsNotJsonStopsTheScheduleAndSaysWhere(): void
    {
        file_put_contents($this->dir . '/scheduled-tasks.json', '{not json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scheduled-tasks.json is not JSON');
        $this->manager()->start();
    }

    public function testASecondServerShowsTheTasksAndRefusesToChangeThem(): void
    {
        $owner = $this->manager();
        $owner->start();
        $owner->save($this->task());

        $other = $this->manager();
        $other->start();

        self::assertTrue($owner->owns());
        self::assertFalse($other->owns());
        self::assertSame('Morning brief', $other->list()[0]['name']);

        $this->expectExceptionObject(new RuntimeException('Another pig web server is running the scheduled tasks; change them there.'));
        $other->save($this->task());
    }

    public function testStoppingLetsAnotherServerTakeTheSchedule(): void
    {
        $first = $this->manager();
        $first->start();
        $first->stop();

        $second = $this->manager();
        $second->start();

        self::assertTrue($second->owns());
    }

    public function testTheOwnerSaysWhereItListensWhileItHoldsTheLock(): void
    {
        self::assertNull(ScheduleManager::owner($this->dir), 'no server');

        $first = $this->manager('http://127.0.0.1:8088');
        $first->start();
        self::assertSame(['url' => 'http://127.0.0.1:8088', 'pid' => getmypid()], ScheduleManager::owner($this->dir));

        $second = $this->manager('http://127.0.0.1:8090');
        $second->start();
        self::assertSame('http://127.0.0.1:8088', ScheduleManager::owner($this->dir)['url'] ?? null, 'the one that does not own it writes nothing');

        $first->stop();
        self::assertNull(ScheduleManager::owner($this->dir), 'the address left behind is not believed once the lock is let go');

        $older = $this->manager();
        $older->start();
        self::assertSame(['url' => null, 'pid' => null], ScheduleManager::owner($this->dir), 'a server that wrote no address: held, but nowhere to ask');
    }
}
