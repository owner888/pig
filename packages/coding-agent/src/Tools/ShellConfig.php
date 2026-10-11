<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * A shell and how to hand it a command — upstream's `ShellConfig` in `utils/shell.ts`.
 *
 * `argv` is the ordinary way: the command is the last argument, after `args` (`bash -c <command>`,
 * `pwsh … -Command <command>`). `stdin` is for the legacy WSL `bash.exe` under `System32`, which
 * mangles a command passed as an argument, so it is started with `-s` and the command is written to
 * its standard input instead.
 */
final readonly class ShellConfig
{
    /**
     * @param list<string> $args what goes between the shell and the command
     * @param 'argv'|'stdin' $commandTransport
     */
    public function __construct(
        public string $shell,
        public array $args = ['-c'],
        public string $commandTransport = 'argv',
    ) {
    }

    /**
     * What `proc_open()` is given for this command.
     *
     * @return list<string>
     */
    public function argv(string $command): array
    {
        return $this->commandTransport === 'stdin'
            ? [$this->shell, ...$this->args]
            : [$this->shell, ...$this->args, $command];
    }
}
