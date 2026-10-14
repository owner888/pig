<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use Closure;
use InvalidArgumentException;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Web\ManagedSession;
use Pig\CodingAgent\Web\SessionPool;
use RuntimeException;
use Throwable;

/**
 * Scheduled tasks for the web shell: when each one runs, starting the run, and what became of it.
 *
 * pig's own, after the Claude app's scheduled tasks; upstream has none. It needs no machinery of its
 * own beyond a timer per task, because the rest is already here: a task's conversation is an
 * ordinary session file the sidebar lists, made when the task is saved (`ScheduledTask::$sessionFile`),
 * and a run is a turn in it — sent to the child a tab already has on that file, or to one the
 * `SessionPool` starts for it (`SessionPool::open()`), which opening the conversation then binds to.
 *
 * Five rules, each the developer's or the Claude app's:
 *
 * - **Missed runs happen once.** The next occurrence is counted from the task's `anchorAt`, so an
 *   occurrence that passed while the machine slept or the server was down is overdue rather than
 *   skipped, and fires as soon as it is seen. After a run the anchor moves to now, not to the
 *   occurrence, so a week asleep is one run and not a hundred and sixty-eight.
 * - **A run that is still going is not started twice.** The occurrence is written down as
 *   `skipped`, so the log says why nothing happened.
 * - **A hook's question waits for an answer**, as the Claude app waits for approval: the run is
 *   `needs_input`, the page says so, and opening the conversation shows the question
 *   (`ManagedSession::$pendingUi`). Nothing answers it on the person's behalf.
 * - **One server runs the schedule.** `pig web start -d`, `pig --mode web` and `/web` can all be up
 *   at once in one home, and two of them firing the same task is two runs. The first takes a lock on
 *   `scheduled-tasks.lock` and keeps it; the others show the tasks and refuse to change them,
 *   because their copy would be out of date the moment the owner wrote. The owner writes where it
 *   listens into the lock file, so pig elsewhere on this machine — the `schedule` tool — can find it
 *   (`owner()`) and ask it to make a change (`ScheduleClient`).
 * - **The timer wakes at least once a minute**, rather than sleeping until the next occurrence.
 *   The occurrence is compared against the wall clock each time, so a clock that was changed, a
 *   timezone shift or a long sleep is noticed within a minute.
 *
 * Tasks and their run logs are one JSON file, `scheduled-tasks.json` under `Config::home()`. A task
 * that no longer reads — a folder deleted, a hand edit — is kept as it was written and reported in
 * `problems()`, so saving the others does not delete it.
 */
final class ScheduleManager
{
    /** Seconds the timer sleeps at most before looking at the clock again. */
    public const float MAX_WAIT = 60.0;

    /** Runs kept per task; the oldest go first. */
    public const int MAX_RUNS = 50;

    public const string RUNNING = 'running';
    public const string NEEDS_INPUT = 'needs_input';
    public const string DONE = 'done';
    public const string ERROR = 'error';
    public const string SKIPPED = 'skipped';

    private readonly string $file;
    private readonly string $lockFile;
    private readonly Closure $now;

    /** @var resource|null held for as long as this manager runs the schedule */
    private mixed $lock = null;

    private bool $started = false;

    /** @var array<string, ScheduledTask> by id, in the order they were saved */
    private array $tasks = [];

    /** @var list<array<string, mixed>> entries that no longer read, written back as they were */
    private array $broken = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, list<array<string, mixed>>> taskId => runs, oldest first */
    private array $runs = [];

    /** @var array<string, string> taskId => loop timer id */
    private array $timers = [];

    /** @var array<int, array{task: string, run: string, error: ?string}> by spl_object_id of the child */
    private array $active = [];

    /**
     * @param Closure(): void $onChange called after anything the page shows has changed
     * @param (Closure(): int)|null $now epoch milliseconds; a test hands in its own clock
     * @param float $maxWait `MAX_WAIT`; a test whose clock jumps a day looks again sooner
     * @param string|null $url where this server can be reached from this machine, written into the
     *        lock once it is this server's (`owner()`)
     */
    public function __construct(
        private readonly SessionPool $pool,
        private readonly Closure $onChange,
        ?string $dataDir = null,
        ?Closure $now = null,
        private readonly float $maxWait = self::MAX_WAIT,
        private readonly ?string $url = null,
    ) {
        $dir = $dataDir ?? Config::home();
        $this->file = $dir . '/scheduled-tasks.json';
        $this->lockFile = $dir . '/scheduled-tasks.lock';
        $this->now = $now ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    /**
     * Load the tasks and, when no other server holds the schedule, arm a timer for each.
     *
     * @throws RuntimeException when the file is there and is not JSON — somebody has to look at it
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->load();

        if (!$this->takeLock()) {
            return;
        }

        // Runs this file says are going were going in a server that is not here any more. One whose
        // conversation was written is carried on from it; one that never wrote anything has nothing
        // to carry on from, and says so.
        $resume = [];

        foreach ($this->runs as $taskId => $runs) {
            foreach ($runs as $at => $run) {
                if (!in_array($run['status'], [self::RUNNING, self::NEEDS_INPUT], true)) {
                    continue;
                }

                $file = $run['sessionFile'] ?? null;

                if (is_string($file) && is_file($file) && isset($this->tasks[$taskId])) {
                    $this->runs[$taskId][$at] = [...$run, 'status' => self::RUNNING, 'resumedAt' => ($this->now)()];
                    $resume[] = [(string) $taskId, (string) $run['id'], $file, (string) ($run['cwd'] ?? $this->tasks[$taskId]->cwd)];

                    continue;
                }

                $this->runs[$taskId][$at] = [
                    ...$run,
                    'status' => self::ERROR,
                    'error' => 'The server stopped before this run wrote anything to carry on from.',
                    'finishedAt' => $run['finishedAt'] ?? ($this->now)(),
                ];
            }
        }

        $this->write();

        foreach ($resume as [$taskId, $runId, $file, $cwd]) {
            Async::spawn(fn () => $this->carryOn($taskId, $runId, $file, $cwd));
        }

        foreach ($this->tasks as $task) {
            $this->arm($task);
        }
    }

    /** Disarm every timer and let another server take the schedule. Runs in flight are the pool's to stop. */
    public function stop(): void
    {
        foreach ($this->timers as $timer) {
            Loop::get()->cancel($timer);
        }

        $this->timers = [];
        $this->started = false;

        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** True when this server runs the schedule; false when another one does and this one only shows it. */
    public function owns(): bool
    {
        return $this->lock !== null;
    }

    /** @return list<string> entries in the file that could not be read, each saying why */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<array<string, mixed>> every task, with when it next runs and how it last went */
    public function list(): array
    {
        if (!$this->owns()) {
            $this->load();
        }

        $list = [];

        foreach ($this->tasks as $id => $task) {
            $runs = $this->runs[$id] ?? [];
            $last = $runs === [] ? null : $runs[array_key_last($runs)];

            $list[] = [
                ...$task->toArray(),
                'nextRunAt' => $this->nextRunAt($task),
                'running' => $this->activeRunOf($id) !== null,
                'lastRun' => $last,
                'unread' => $this->hasUnread($id),
            ];
        }

        return $list;
    }

    /** @return list<array<string, mixed>> a task's runs, newest first */
    public function runs(string $taskId): array
    {
        if (!$this->owns()) {
            $this->load();
        }

        return array_reverse($this->runs[$taskId] ?? []);
    }

    /**
     * Basename of every task's conversation — and of each conversation a run had to itself before
     * tasks had one — => the task's name, for the sidebar's clock.
     *
     * @return array<string, string>
     */
    public function sessionTasks(): array
    {
        $map = [];

        foreach ($this->tasks as $task) {
            if ($task->sessionFile !== null) {
                $map[basename($task->sessionFile)] = $task->name;
            }
        }

        // Runs from before tasks had a conversation of their own were one conversation each.
        foreach ($this->runs as $taskId => $runs) {
            $name = isset($this->tasks[$taskId]) ? $this->tasks[$taskId]->name : null;

            foreach ($runs as $run) {
                if ($name !== null && is_string($run['sessionFile'] ?? null)) {
                    $map[basename($run['sessionFile'])] = $name;
                }
            }
        }

        return $map;
    }

    /**
     * Basename of each task's conversation that has a run nobody has looked at since it finished,
     * => true: the sidebar's dot, as the Claude app puts one on a task whose run has news.
     *
     * @return array<string, true>
     */
    public function unread(): array
    {
        $map = [];

        foreach ($this->tasks as $id => $task) {
            if ($task->sessionFile !== null && $this->hasUnread($id)) {
                $map[basename($task->sessionFile)] = true;
            }
        }

        return $map;
    }

    /**
     * Somebody has the conversation `$sessionFile` (a path or its basename) on screen: every
     * finished run of the task it belongs to is read. Nothing for a conversation no task has.
     */
    public function markSeen(string $sessionFile): void
    {
        $this->mustOwn();
        $name = basename($sessionFile);

        foreach ($this->tasks as $id => $task) {
            if ($task->sessionFile === null || basename($task->sessionFile) !== $name || !$this->hasUnread($id)) {
                continue;
            }

            foreach ($this->runs[$id] as $at => $run) {
                if (($run['seen'] ?? true) === false && in_array($run['status'], [self::DONE, self::ERROR], true)) {
                    $this->runs[$id][$at]['seen'] = true;
                }
            }

            $this->write();
            ($this->onChange)();
        }
    }

    /**
     * Create or replace a task. Saving counts as switching it on again: the next occurrence is
     * counted from now, so an edit is never an instant catch-up run.
     *
     * @param array<string, mixed> $data what the page sent
     * @throws InvalidArgumentException naming what is wrong
     */
    public function save(array $data): ScheduledTask
    {
        $this->mustOwn();

        $id = is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : null;
        $existing = $id !== null ? ($this->tasks[$id] ?? null) : null;

        if ($id !== null && $existing === null) {
            throw new InvalidArgumentException("There is no task '{$id}'.");
        }

        $task = ScheduledTask::fromArray([
            ...$data,
            'createdAt' => $existing?->createdAt ?? ($this->now)(),
            'anchorAt' => ($this->now)(),
            'sessionFile' => null,
        ]);
        $this->refuseAPast($task);

        // The task keeps its conversation; one moved to another folder starts a new one there,
        // since a conversation's folder is where its file lives.
        $task = $task->with(sessionFile: $existing !== null && $existing->cwd === $task->cwd && $existing->sessionFile !== null
            ? $existing->sessionFile
            : $this->conversationFor($task));

        $this->tasks[$task->id] = $task;
        $this->write();
        $this->arm($task);
        ($this->onChange)();

        return $task;
    }

    /** Delete a task and its run log. A run in flight carries on; it is only no longer written down. */
    public function delete(string $id): void
    {
        $this->mustOwn();
        $this->task($id);

        $this->disarm($id);
        unset($this->tasks[$id], $this->runs[$id]);

        foreach ($this->active as $key => $entry) {
            if ($entry['task'] === $id) {
                unset($this->active[$key]);
            }
        }

        $this->write();
        ($this->onChange)();
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->mustOwn();
        $task = $this->task($id)->with(enabled: $enabled, anchorAt: ($this->now)());
        $this->refuseAPast($task);

        $this->tasks[$id] = $task;
        $this->write();
        $this->arm($task);
        ($this->onChange)();
    }

    /** Run a task now, whatever its schedule says. Leaves the schedule as it was. */
    public function runNow(string $id): void
    {
        $this->mustOwn();
        $task = $this->task($id);

        if ($this->activeRunOf($id) !== null) {
            throw new InvalidArgumentException('This task is already running.');
        }

        $this->fire($task, 'manual');
    }

    /**
     * What a child said, for the run it belongs to. `HttpServer` hands every event over after the
     * pool has seen it, so `$managed->pendingUi` is already up to date.
     *
     * @param array<string, mixed> $event
     */
    public function observe(ManagedSession $managed, array $event): void
    {
        $key = spl_object_id($managed);
        $entry = $this->active[$key] ?? null;

        if ($entry === null) {
            return;
        }

        $type = $event['type'] ?? null;

        if ($type === 'response' && ($event['command'] ?? null) === 'get_state' && is_string($event['data']['sessionFile'] ?? null)) {
            $this->updateRun($entry, ['sessionFile' => $event['data']['sessionFile']]);
        } elseif ($type === 'extension_ui_request' && in_array($event['method'] ?? null, SessionPool::DIALOGS, true)) {
            $this->updateRun($entry, ['status' => self::NEEDS_INPUT]);
        } elseif ($type === 'agent_end') {
            $error = $event['error'] ?? null;
            $this->active[$key]['error'] = is_string($error) && $error !== '' ? $error : null;
        } elseif ($type === 'agent_settled') {
            $error = $this->active[$key]['error'];
            $aborted = ($event['aborted'] ?? false) === true;
            $this->finish($key, $error !== null || $aborted ? self::ERROR : self::DONE, $error ?? ($aborted ? 'Stopped before it finished.' : null));
        } elseif ($type === 'session_ended') {
            $this->finish($key, self::ERROR, 'The agent process ended: ' . (is_string($event['reason'] ?? null) ? $event['reason'] : 'no reason given'));
        } elseif ($managed->pendingUi === [] && ($this->runOf($entry)['status'] ?? null) === self::NEEDS_INPUT) {
            $this->updateRun($entry, ['status' => self::RUNNING]);
        }
    }

    // ---- timers --------------------------------------------------------------------------------

    private function arm(ScheduledTask $task): void
    {
        $this->disarm($task->id);

        if (!$this->owns() || $this->nextRunAt($task) === null) {
            return;
        }

        $wait = ($this->nextRunAt($task) - ($this->now)()) / 1000;

        $this->timers[$task->id] = Loop::get()->delay(
            max(0.0, min($wait, $this->maxWait)),
            fn () => $this->wake($task->id),
        );
    }

    private function disarm(string $id): void
    {
        if (isset($this->timers[$id])) {
            Loop::get()->cancel($this->timers[$id]);
            unset($this->timers[$id]);
        }
    }

    private function wake(string $id): void
    {
        unset($this->timers[$id]);
        $task = $this->tasks[$id] ?? null;

        if ($task === null) {
            return;
        }

        $next = $this->nextRunAt($task);

        if ($next !== null && $next <= ($this->now)()) {
            // The anchor moves to now and not to the occurrence: everything missed since is one run.
            $task = $task->with(
                anchorAt: ($this->now)(),
                enabled: $task->schedule === ScheduledTask::ONCE ? false : $task->enabled,
            );
            $this->tasks[$id] = $task;
            $this->fire($task, 'schedule');
        }

        $this->arm($task);
    }

    /** `ScheduledTask::nextRunAt()`, or null when the expression has no future (February 30th). */
    private function nextRunAt(ScheduledTask $task): ?int
    {
        try {
            return $task->nextRunAt();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // ---- runs ----------------------------------------------------------------------------------

    private function fire(ScheduledTask $task, string $trigger): void
    {
        $runId = bin2hex(random_bytes(6));
        $run = [
            'id' => $runId,
            'trigger' => $trigger,
            'startedAt' => ($this->now)(),
            'cwd' => $task->cwd,
            'status' => self::RUNNING,
            // Unread once it has finished, until its conversation is opened (`markSeen()`). Runs
            // written before this was kept have no `seen` and count as read.
            'seen' => false,
        ];

        if ($this->activeRunOf($task->id) !== null) {
            $this->appendRun($task->id, [
                ...$run,
                'status' => self::SKIPPED,
                'finishedAt' => $run['startedAt'],
                'error' => 'The previous run was still going.',
                'seen' => true,
            ]);
            $this->write();
            ($this->onChange)();

            return;
        }

        $this->appendRun($task->id, $run);
        $this->write();
        ($this->onChange)();

        // The commands below park their fiber until the child answers, and a loop timer is not in one.
        Async::spawn(fn () => $this->launch($task, $runId));
    }

    /**
     * Open a run's conversation and carry it on (`AgentSession::continueRun()`). A conversation
     * that had already finished is a finished run; one the child refuses, with its reason, is an
     * error and is not run again.
     */
    private function carryOn(string $taskId, string $runId, string $file, string $cwd): void
    {
        $entry = ['task' => $taskId, 'run' => $runId];

        try {
            $managed = $this->pool->open($cwd, $file);
        } catch (Throwable $e) {
            $this->updateRun($entry, [
                'status' => self::ERROR,
                'error' => 'Could not start the agent to carry the run on: ' . $e->getMessage(),
                'finishedAt' => ($this->now)(),
            ]);

            return;
        }

        $key = spl_object_id($managed);
        $this->active[$key] = [...$entry, 'error' => null];

        try {
            if ($managed->client->continueRun() === 'finished') {
                $this->finish($key, self::DONE, null);
            }
        } catch (Throwable $e) {
            $this->finish($key, self::ERROR, 'Not carried on after the server stopped: ' . $e->getMessage());
        }
    }

    private function launch(ScheduledTask $task, string $runId): void
    {
        $entry = ['task' => $task->id, 'run' => $runId];

        try {
            // A task saved before tasks had conversations, or whose conversation was deleted from
            // the sidebar, gets a new one now.
            if ($task->sessionFile === null || !is_file($task->sessionFile)) {
                $task = $task->with(sessionFile: $this->conversationFor($task));
                $this->tasks[$task->id] = $task;
                $this->write();
            }

            $managed = $this->pool->open($task->cwd, (string) $task->sessionFile);
        } catch (Throwable $e) {
            $this->updateRun($entry, [
                'status' => self::ERROR,
                'error' => 'Could not start the agent: ' . $e->getMessage(),
                'finishedAt' => ($this->now)(),
            ]);

            return;
        }

        $this->updateRun($entry, ['sessionFile' => $task->sessionFile]);

        // Somebody is talking in the task's conversation: a prompt now would be queued behind
        // their turn and answered as part of it, so this occurrence is let go, as one that finds
        // the last run still going is.
        if ($managed->running) {
            $this->updateRun($entry, [
                'status' => self::SKIPPED,
                'error' => 'Its conversation was busy with another turn.',
                'finishedAt' => ($this->now)(),
                'seen' => true,
            ]);

            return;
        }

        $key = spl_object_id($managed);
        $this->active[$key] = [...$entry, 'error' => null];
        $client = $managed->client;

        try {
            if ($task->model !== null) {
                [$provider, $modelId] = explode('/', $task->model, 2);
                $client->setModel($provider, $modelId);
            }

            if ($task->thinkingLevel !== null) {
                $client->setThinkingLevel($task->thinkingLevel);
            }

            $client->prompt($task->prompt);
        } catch (Throwable $e) {
            // The child is the pool's: with no turn running, its idle timer stops it.
            $this->finish($key, self::ERROR, $e->getMessage());
        }
    }

    /**
     * A new conversation for `$task`, in its folder and under its name, written at once so the
     * sidebar lists it before the first run. The path of its file.
     */
    private function conversationFor(ScheduledTask $task): string
    {
        $session = SessionManager::create($task->cwd);
        $session->setSessionName($task->name);
        $session->persist();

        return $session->path;
    }

    private function finish(int $key, string $status, ?string $error): void
    {
        $entry = $this->active[$key] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->active[$key]);
        $this->updateRun($entry, ['status' => $status, 'error' => $error, 'finishedAt' => ($this->now)()]);
    }

    /** @return array{task: string, run: string, error: ?string}|null */
    private function activeRunOf(string $taskId): ?array
    {
        foreach ($this->active as $entry) {
            if ($entry['task'] === $taskId) {
                return $entry;
            }
        }

        // Started but not yet launched: the run is written down before the child exists.
        foreach ($this->runs[$taskId] ?? [] as $run) {
            if (in_array($run['status'], [self::RUNNING, self::NEEDS_INPUT], true)) {
                return ['task' => $taskId, 'run' => $run['id'], 'error' => null];
            }
        }

        return null;
    }

    /** Whether a run of `$taskId` has finished, done or failed, since its conversation was last open. */
    private function hasUnread(string $taskId): bool
    {
        foreach ($this->runs[$taskId] ?? [] as $run) {
            if (($run['seen'] ?? true) === false && in_array($run['status'], [self::DONE, self::ERROR], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $run */
    private function appendRun(string $taskId, array $run): void
    {
        $this->runs[$taskId][] = $run;
        $this->runs[$taskId] = array_slice($this->runs[$taskId], -self::MAX_RUNS);
    }

    /**
     * @param array{task: string, run: string} $entry
     * @return array<string, mixed>|null
     */
    private function runOf(array $entry): ?array
    {
        foreach ($this->runs[$entry['task']] ?? [] as $run) {
            if ($run['id'] === $entry['run']) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @param array{task: string, run: string} $entry
     * @param array<string, mixed> $changes
     */
    private function updateRun(array $entry, array $changes): void
    {
        foreach ($this->runs[$entry['task']] ?? [] as $at => $run) {
            if ($run['id'] !== $entry['run']) {
                continue;
            }

            $updated = [...$run, ...$changes];

            if ($updated === $run) {
                return;
            }

            $this->runs[$entry['task']][$at] = $updated;
            $this->write();
            ($this->onChange)();

            return;
        }
    }

    // ---- the file ------------------------------------------------------------------------------

    private function task(string $id): ScheduledTask
    {
        return $this->tasks[$id] ?? throw new InvalidArgumentException("There is no task '{$id}'.");
    }

    private function refuseAPast(ScheduledTask $task): void
    {
        if ($task->enabled && $task->schedule === ScheduledTask::ONCE && (int) $task->runAt <= ($this->now)()) {
            throw new InvalidArgumentException('That time has already passed.');
        }
    }

    private function mustOwn(): void
    {
        if (!$this->owns()) {
            throw new RuntimeException('Another pig web server is running the scheduled tasks; change them there.');
        }
    }

    private function takeLock(): bool
    {
        $this->ensureDirectory();
        $handle = fopen($this->lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$this->lockFile}.");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lock = $handle;

        // What the last owner wrote goes either way: an address left behind is a server gone.
        ftruncate($handle, 0);
        if ($this->url !== null) {
            fwrite($handle, (string) json_encode(['url' => $this->url, 'pid' => getmypid()], JSON_UNESCAPED_SLASHES) . "\n");
        }
        fflush($handle);

        return true;
    }

    /**
     * The server that runs the schedule in `$dataDir` (`Config::home()`): where it listens and its
     * pid, or null when no server does. Read from the lock file, and only believed while the lock
     * is held — a server that has stopped leaves its address behind and lets go of the lock. A
     * `url` of null is a server that holds the lock and wrote no address: one older than this pig.
     *
     * @return array{url: ?string, pid: ?int}|null
     */
    public static function owner(?string $dataDir = null): ?array
    {
        $file = ($dataDir ?? Config::home()) . '/scheduled-tasks.lock';
        $handle = is_file($file) ? fopen($file, 'r') : false;
        if ($handle === false) {
            return null;
        }

        try {
            if (flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);

                return null;
            }

            $owner = json_decode((string) stream_get_contents($handle), true);

            return [
                'url' => is_array($owner) && is_string($owner['url'] ?? null) ? $owner['url'] : null,
                'pid' => is_array($owner) && is_int($owner['pid'] ?? null) ? $owner['pid'] : null,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function load(): void
    {
        $this->tasks = [];
        $this->broken = [];
        $this->problems = [];
        $this->runs = [];

        if (!is_file($this->file)) {
            return;
        }

        $raw = file_get_contents($this->file);
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            throw new RuntimeException("{$this->file} is not JSON; fix or remove it.");
        }

        foreach ((array) ($json['tasks'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            try {
                $task = ScheduledTask::fromArray($entry);
                $this->tasks[$task->id] = $task;
            } catch (InvalidArgumentException $e) {
                $this->broken[] = $entry;
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : '(no name)';
                $this->problems[] = "{$name}: {$e->getMessage()}";
            }
        }

        foreach ((array) ($json['runs'] ?? []) as $taskId => $runs) {
            if (is_array($runs)) {
                $this->runs[(string) $taskId] = array_values(array_filter($runs, static fn ($r): bool => is_array($r) && is_string($r['id'] ?? null) && is_string($r['status'] ?? null)));
            }
        }
    }

    private function write(): void
    {
        if (!$this->owns()) {
            return;
        }

        $this->ensureDirectory();
        $json = json_encode([
            'tasks' => [...array_values(array_map(static fn (ScheduledTask $t): array => $t->toArray(), $this->tasks)), ...$this->broken],
            'runs' => (object) $this->runs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        // A rename, so a reader never sees half a file.
        $temp = $this->file . '.' . getmypid() . '.tmp';

        if (file_put_contents($temp, $json . "\n") === false || !rename($temp, $this->file)) {
            throw new RuntimeException("Could not write {$this->file}.");
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }
    }
}
