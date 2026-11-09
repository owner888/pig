<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use RuntimeException;

/** The tasks and their runs as one JSON file, `scheduled-tasks.json` under `Config::home()`. */
final readonly class FileScheduleStore implements ScheduleStore
{
    public function __construct(public string $file)
    {
    }

    #[\Override]
    public function load(): array
    {
        if (!is_file($this->file)) {
            return ['tasks' => [], 'runs' => []];
        }

        $raw = file_get_contents($this->file);
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            throw new RuntimeException("{$this->file} is not JSON; fix or remove it.");
        }

        return self::read($json);
    }

    /**
     * What a file's JSON holds, as `load()` answers it — `SqlScheduleStore` reads the file it takes
     * over the same way.
     *
     * @param array<string, mixed> $json
     * @return array{tasks: list<array<string, mixed>>, runs: array<string, list<array<string, mixed>>>}
     */
    public static function read(array $json): array
    {
        $runs = [];

        foreach ((array) ($json['runs'] ?? []) as $taskId => $list) {
            if (is_array($list)) {
                $runs[(string) $taskId] = array_values(array_filter($list, static fn ($r): bool => is_array($r) && is_string($r['id'] ?? null) && is_string($r['status'] ?? null)));
            }
        }

        return [
            'tasks' => array_values(array_filter((array) ($json['tasks'] ?? []), is_array(...))),
            'runs' => $runs,
        ];
    }

    #[\Override]
    public function save(array $tasks, array $runs): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }

        $json = json_encode(
            ['tasks' => $tasks, 'runs' => (object) $runs],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        // A rename, so a reader never sees half a file.
        $temp = $this->file . '.' . getmypid() . '.tmp';

        if (file_put_contents($temp, $json . "\n") === false || !rename($temp, $this->file)) {
            throw new RuntimeException("Could not write {$this->file}.");
        }
    }
}
