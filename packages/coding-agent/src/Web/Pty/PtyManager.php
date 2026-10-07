<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Pty;

use Closure;

/**
 * PtyManager — Manages PTY sessions.
 *
 * Owned per conversation/client or shared across web sessions.
 * Matches upstream pi-web-ui terminal management semantics:
 * - Cap of 16 live terminals per manager
 * - Reconnect / tab switch scrollback re-emission
 * - Clean disposal on session teardown
 */
final class PtyManager
{
    private const MAX_TERMINALS = 16;

    /** @var array<string, PtyProcess> */
    private array $terminals = [];

    /**
     * @param Closure(string $terminalId, string $data): void $onOutput
     * @param Closure(string $terminalId, ?int $exitCode): void $onExit
     */
    public function __construct(
        private readonly ?Closure $onOutput = null,
        private readonly ?Closure $onExit = null,
    ) {
    }

    public function create(
        string $id,
        string $cwd,
        int $cols = 80,
        int $rows = 24,
        ?string $command = null,
    ): PtyProcess {
        // If an existing terminal with this ID is already running, return it or restart
        if (isset($this->terminals[$id])) {
            $existing = $this->terminals[$id];
            if ($existing->isRunning()) {
                $existing->resize($cols, $rows);
                return $existing;
            }
            $existing->kill();
            unset($this->terminals[$id]);
        }

        // Enforce maximum live terminals
        $runningCount = 0;
        foreach ($this->terminals as $term) {
            if ($term->isRunning()) {
                $runningCount++;
            }
        }

        if ($runningCount >= self::MAX_TERMINALS) {
            // Prune oldest stopped terminal
            foreach ($this->terminals as $tid => $term) {
                if (!$term->isRunning()) {
                    unset($this->terminals[$tid]);
                    break;
                }
            }

            // If still full, throw
            if (count($this->terminals) >= self::MAX_TERMINALS) {
                throw new \RuntimeException(sprintf('最多同时运行 %d 个终端实例', self::MAX_TERMINALS));
            }
        }

        $process = new PtyProcess(
            $id,
            $cwd,
            $cols,
            $rows,
            $command,
            onOutput: function (string $tid, string $data): void {
                if ($this->onOutput !== null) {
                    ($this->onOutput)($tid, $data);
                }
            },
            onExit: function (string $tid, ?int $code): void {
                if ($this->onExit !== null) {
                    ($this->onExit)($tid, $code);
                }
            },
        );

        $this->terminals[$id] = $process;

        return $process;
    }

    public function input(string $id, string $data): void
    {
        ($this->terminals[$id] ?? null)?->input($data);
    }

    public function resize(string $id, int $cols, int $rows): void
    {
        ($this->terminals[$id] ?? null)?->resize($cols, $rows);
    }

    public function kill(string $id): void
    {
        if (isset($this->terminals[$id])) {
            $this->terminals[$id]->kill();
            unset($this->terminals[$id]);
        }
    }

    public function get(string $id): ?PtyProcess
    {
        return $this->terminals[$id] ?? null;
    }

    /**
     * @return list<array{id: string, cwd: string, cols: int, rows: int, running: bool}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->terminals as $t) {
            $result[] = [
                'id' => $t->id,
                'cwd' => $t->cwd,
                'cols' => $t->cols(),
                'rows' => $t->rows(),
                'running' => $t->isRunning(),
            ];
        }

        return $result;
    }

    public function dispose(): void
    {
        foreach ($this->terminals as $t) {
            $t->kill();
        }
        $this->terminals = [];
    }
}
