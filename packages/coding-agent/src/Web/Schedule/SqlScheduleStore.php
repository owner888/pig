<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use Pig\CodingAgent\Database\Database;
use RuntimeException;

/**
 * The tasks and their runs in the database the sessions are in (docs/dev/design/storage.md, phase
 * 4 step 5), with the timestamps every table has:
 *
 * - `scheduled_tasks`: id, `owner` (the account, null for the admin's from before accounts), and
 *   the task as `ScheduledTask::toArray()` writes it, in `data`;
 * - `scheduled_runs`: id, `task_id`, and the run in `data`.
 *
 * A save writes what changed: a row whose `data` is the same is left alone, so its `updated_at` is
 * when it last changed. What is no longer there — a deleted task, a run past the last fifty — is
 * deleted the soft way.
 *
 * **The first time**, with no task in the table and `$takeOver` naming a `scheduled-tasks.json`, that
 * file's tasks and runs are copied in, and the file renamed `…json.imported` so it is not copied
 * twice and nothing is lost.
 */
final class SqlScheduleStore implements ScheduleStore
{
    private readonly string $tasks;

    private readonly string $runs;

    public function __construct(private readonly Database $db, private readonly ?string $takeOver = null)
    {
        $this->tasks = $db->table('scheduled_tasks');
        $this->runs = $db->table('scheduled_runs');
        $db->exclusively('schedule', function () use ($db): void {
            $db->createTable('scheduled_tasks', ['id' => 'string', 'owner' => '?string', 'data' => 'text'], ['id'], ['owner' => ['owner']]);
            $db->createTable('scheduled_runs', ['id' => 'string', 'task_id' => 'string', 'data' => 'text'], ['id'], ['task' => ['task_id']]);
            $this->takeOver();
        });
    }

    private function takeOver(): void
    {
        if ($this->takeOver === null || !is_file($this->takeOver)
            || $this->db->query("SELECT 1 FROM {$this->tasks} LIMIT 1")->fetchColumn() !== false) {
            return;
        }

        $json = json_decode((string) file_get_contents($this->takeOver), true);

        if (!is_array($json)) {
            throw new RuntimeException("{$this->takeOver} is not JSON; fix or remove it.");
        }

        ['tasks' => $tasks, 'runs' => $runs] = FileScheduleStore::read($json);
        $this->save($tasks, $runs);
        rename($this->takeOver, $this->takeOver . '.imported');
    }

    #[\Override]
    public function load(): array
    {
        $tasks = [];

        foreach ($this->db->query("SELECT data FROM {$this->tasks} WHERE deleted_at IS NULL ORDER BY created_at, id")->fetchAll() as $row) {
            $task = json_decode((string) $row['data'], true);

            if (is_array($task)) {
                $tasks[] = $task;
            }
        }

        $runs = [];

        foreach ($this->db->query("SELECT task_id, data FROM {$this->runs} WHERE deleted_at IS NULL ORDER BY created_at, id")->fetchAll() as $row) {
            $run = json_decode((string) $row['data'], true);

            if (is_array($run) && is_string($run['id'] ?? null) && is_string($run['status'] ?? null)) {
                $runs[(string) $row['task_id']][] = $run;
            }
        }

        // Oldest first within a task: by when each started, as the file kept them.
        foreach ($runs as $taskId => $list) {
            usort($list, static fn (array $a, array $b): int => ($a['startedAt'] ?? 0) <=> ($b['startedAt'] ?? 0));
            $runs[$taskId] = $list;
        }

        return ['tasks' => $tasks, 'runs' => $runs];
    }

    #[\Override]
    public function save(array $tasks, array $runs): void
    {
        $this->db->transaction(function () use ($tasks, $runs): void {
            $keep = [];

            foreach ($tasks as $task) {
                $id = is_string($task['id'] ?? null) && $task['id'] !== '' ? $task['id'] : null;

                // An entry that never read and has no id cannot be kept apart from another: dropped.
                if ($id === null) {
                    continue;
                }

                $keep[] = $id;
                $this->put($this->tasks, $id, ['owner' => is_string($task['owner'] ?? null) && $task['owner'] !== '' ? $task['owner'] : null], $task);
            }

            $this->forgetAllBut($this->tasks, $keep);
            $keep = [];

            foreach ($runs as $taskId => $list) {
                foreach ($list as $run) {
                    $keep[] = (string) $run['id'];
                    $this->put($this->runs, (string) $run['id'], ['task_id' => (string) $taskId], $run);
                }
            }

            $this->forgetAllBut($this->runs, $keep);
        });
    }

    /**
     * Write `$data` under `$id` in `$table`, with `$columns`: inserted when new, updated when it
     * changed, brought back when it had been deleted, left alone when it is the same.
     *
     * @param array<string, ?string> $columns
     * @param array<string, mixed> $data
     */
    private function put(string $table, string $id, array $columns, array $data): void
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $row = $this->db->query("SELECT data, deleted_at FROM {$table} WHERE id = ?" . $this->db->forUpdate(), [$id])->fetch();

        if (!is_array($row)) {
            $names = implode(', ', array_keys($columns));
            $marks = implode(', ', array_fill(0, count($columns), '?'));
            $this->db->query("INSERT INTO {$table} (id, {$names}, data) VALUES (?, {$marks}, ?)", [$id, ...array_values($columns), $json]);

            return;
        }

        if ($row['data'] === $json && $row['deleted_at'] === null) {
            return;
        }

        $sets = implode(', ', array_map(static fn (string $name): string => "{$name} = ?", array_keys($columns)));
        $this->db->query("UPDATE {$table} SET {$sets}, data = ?, deleted_at = NULL WHERE id = ?", [...array_values($columns), $json, $id]);
    }

    /** @param list<string> $ids */
    private function forgetAllBut(string $table, array $ids): void
    {
        $live = array_map('strval', $this->db->query("SELECT id FROM {$table} WHERE deleted_at IS NULL")->fetchAll(\PDO::FETCH_COLUMN));
        $gone = array_values(array_diff($live, $ids));

        foreach (array_chunk($gone, 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $this->db->query("UPDATE {$table} SET deleted_at = ? WHERE id IN ({$marks})", [$this->db->at(), ...$chunk]);
        }
    }
}
