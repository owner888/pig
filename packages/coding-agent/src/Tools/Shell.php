<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;
use Pig\Tui\Process;

/**
 * Which shell to run commands with, and how to stop them.
 *
 * bash rather than whatever `$SHELL` says: the model writes bash, and a command that
 * works when the person happens to use bash and fails when they use fish is worse than
 * one that always behaves the same.
 */
final class Shell
{
    private static ?string $bash = null;

    /** The shell to run commands with. */
    public static function bash(): string
    {
        if (self::$bash !== null) {
            return self::$bash;
        }

        $candidates = ['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash', '/opt/homebrew/bin/bash'];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return self::$bash = $path;
            }
        }

        // sh will run most of what a model writes, and the ones it will not fail loudly.
        if (is_executable('/bin/sh')) {
            return self::$bash = '/bin/sh';
        }

        throw new AgentError('No shell found. Looked for: ' . implode(', ', [...$candidates, '/bin/sh']));
    }

    /** @internal Tests. */
    public static function forget(): void
    {
        self::$bash = null;
    }

    /**
     * Kill a command and everything it started.
     *
     * Killing the shell alone is not enough. `npm test` is the shell's child and the
     * test runner is its grandchild; kill the shell and the runner keeps going, holding
     * the port it bound and writing to a terminal that has moved on.
     *
     * The tree is walked from `ps` rather than by putting the command in its own process
     * group — PHP cannot do that without forking by hand, and macOS has no `setsid`.
     * Children are killed before their parents, so nothing gets a chance to start more.
     */
    public static function killTree(int $pid): void
    {
        foreach (array_reverse(self::descendants($pid)) as $child) {
            self::kill($child);
        }

        self::kill($pid);
    }

    /**
     * Every process below $pid, parents before children.
     *
     * @return list<int>
     */
    private static function descendants(int $pid): array
    {
        $children = self::childrenByParent();
        $found = [];
        $queue = [$pid];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($children[$current] ?? [] as $child) {
                $found[] = $child;
                $queue[] = $child;
            }
        }

        return $found;
    }

    /** @return array<int, list<int>> */
    private static function childrenByParent(): array
    {
        // One `ps` for the whole tree: asking per process would be a fork per node, and
        // this runs when someone has just pressed Escape and is waiting.
        $output = Process::capture(['ps', '-eo', 'pid=,ppid='], 2.0);

        if ($output === null) {
            return [];
        }

        $children = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $match) === 1) {
                $children[(int) $match[2]][] = (int) $match[1];
            }
        }

        return $children;
    }

    private static function kill(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);

            return;
        }

        Process::capture(['kill', '-9', (string) $pid], 2.0);
    }
}
