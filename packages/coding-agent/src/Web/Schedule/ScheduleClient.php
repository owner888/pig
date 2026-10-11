<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Async\SocketError;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Web\WebLogin;
use RuntimeException;

/**
 * The scheduled tasks from outside the server that runs them: what the `schedule` tool
 * (`builtin:schedule`) changes them through, from a terminal or from a conversation in the page.
 *
 * The tasks are `ScheduleManager`'s, in memory in the one `pig web` that holds the lock; writing
 * `scheduled-tasks.json` from here would be undone the next time that server saved. So this asks
 * that server, over the same `/api/scheduled` the page uses: its address is in the lock
 * (`ScheduleManager::owner()`), and the way in is the local token (`WebLogin::localToken()`), which
 * opens nothing else.
 *
 * No server, no change — and no file written for one that might start later: the tasks run only
 * while a `pig web` does, so the person is told to start one.
 */
final class ScheduleClient
{
    private function __construct(
        public readonly string $url,
        private readonly string $token,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * The server that runs the schedule in `$home` (`Config::home()`).
     *
     * @throws RuntimeException saying what to do when no server runs it, or one too old to be asked
     */
    public static function find(?string $home = null, ?HttpClient $http = null): self
    {
        $home ??= Config::home();
        $owner = ScheduleManager::owner($home);

        if ($owner === null) {
            throw new RuntimeException(
                'No `pig web` server is running on this machine, and scheduled tasks are kept and run by one. '
                . 'Start it with `pig web start -d` (or `/web`), then try again.',
            );
        }

        if ($owner['url'] === null) {
            throw new RuntimeException(
                'The `pig web` server running the scheduled tasks' . ($owner['pid'] !== null ? " (pid {$owner['pid']})" : '')
                . ' is older than this pig and cannot be asked to change them. Restart it with `pig web restart`.',
            );
        }

        return new self($owner['url'], WebLogin::at($home)->localToken(), $http ?? new HttpClient(10.0));
    }

    /** @return list<array<string, mixed>> every task, with `nextRunAt`, `running` and `lastRun` */
    public function list(): array
    {
        return $this->call('GET', '/api/scheduled')['tasks'] ?? [];
    }

    /** @return array<string, mixed>|null one task as `list()` has it, or null when there is none */
    public function get(string $id): ?array
    {
        foreach ($this->list() as $task) {
            if (($task['id'] ?? null) === $id) {
                return $task;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> a task's runs, newest first */
    public function runs(string $id): array
    {
        return $this->call('GET', '/api/scheduled/runs?id=' . rawurlencode($id))['runs'] ?? [];
    }

    /**
     * Create a task, or replace the one `$task['id']` names.
     *
     * @param array<string, mixed> $task the fields `ScheduledTask::fromArray()` reads
     * @return array<string, mixed> the task as saved
     */
    public function save(array $task): array
    {
        return $this->call('POST', '/api/scheduled/save', $task)['task'];
    }

    public function delete(string $id): void
    {
        $this->call('POST', '/api/scheduled/delete', ['id' => $id]);
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->call('POST', '/api/scheduled/toggle', ['id' => $id, 'enabled' => $enabled]);
    }

    public function runNow(string $id): void
    {
        $this->call('POST', '/api/scheduled/run', ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws RuntimeException with the server's reason
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->http->send(new Request($method, $this->url . $path, $headers, $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES)));
        } catch (HttpError | SocketError $e) {
            throw new RuntimeException("Could not reach the `pig web` server at {$this->url}: {$e->getMessage()}", 0, $e);
        }

        $answer = json_decode($response->body->all(), true);

        if ($response->status === 401) {
            throw new RuntimeException("The `pig web` server at {$this->url} refused this pig's local token. Restart it with `pig web restart`.");
        }

        if ($response->status !== 200 || !is_array($answer)) {
            $why = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : "HTTP {$response->status}";

            throw new RuntimeException($why);
        }

        return $answer;
    }
}
