<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

use Pig\Agent\AgentError;

/** `Shell::run()` for a shell whose `exec()` is a command on this machine. */
trait RunsToTheEnd
{
    /**
     * @param list<string> $inside
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $inside, ?string $input = null, ?string $cwd = null): array
    {
        $argv = $this->exec($inside, $cwd, stdin: $input !== null);
        $process = proc_open(
            $argv,
            [0 => $input !== null ? ['pipe', 'r'] : ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new AgentError("Could not run {$argv[0]}");
        }

        $out = ['', '', ''];
        $writing = $input ?? '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        if ($input !== null) {
            stream_set_blocking($pipes[0], false);
        }

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        // Both ways at once: a large file written in while the command's error output fills its
        // pipe would otherwise wait on each other.
        while ($open !== [] || ($input !== null && isset($pipes[0]))) {
            $read = array_values($open);
            $write = $input !== null && isset($pipes[0]) ? [$pipes[0]] : [];
            $except = null;

            if (stream_select($read, $write, $except, 30) === false) {
                break;
            }

            if ($write !== []) {
                $sent = fwrite($pipes[0], $writing);
                $writing = $sent === false ? '' : substr($writing, $sent);

                if ($writing === '') {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }

            foreach ($read as $pipe) {
                $fd = array_search($pipe, $open, true);
                $chunk = fread($pipe, 65536);

                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    fclose($pipe);
                    unset($open[$fd]);

                    continue;
                }

                $out[$fd] .= $chunk;
            }
        }

        return [proc_close($process), $out[1], $out[2]];
    }
}
