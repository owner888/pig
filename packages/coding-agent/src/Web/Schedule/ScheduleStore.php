<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use RuntimeException;

/**
 * Where `ScheduleManager` keeps the tasks and their runs (docs/dev/design/storage.md, phase 4):
 * `FileScheduleStore`, `scheduled-tasks.json`, with sessions in files; `SqlScheduleStore`, two
 * tables beside the sessions, with sessions in a database.
 *
 * The manager keeps everything in memory and hands all of it over after each change: tasks as
 * `ScheduledTask::toArray()` writes them (and entries that no longer read, as they were), runs by
 * task id, oldest first.
 */
interface ScheduleStore
{
    /**
     * @return array{tasks: list<array<string, mixed>>, runs: array<string, list<array<string, mixed>>>}
     * @throws RuntimeException when what is kept cannot be read — somebody has to look at it
     */
    public function load(): array;

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, list<array<string, mixed>>> $runs
     * @throws RuntimeException when it could not be written
     */
    public function save(array $tasks, array $runs): void;
}
