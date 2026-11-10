<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Operations whose files are not this machine's, so `grep`'s `rg` and `find`'s `fd` have to run
 * where the files are: `RemoteOperations`, in the account's container or over SSH. pig's, not upstream's —
 * upstream's `grep` takes only `isDirectory()` from its operations and runs `rg` here, and its
 * `find` swaps `fd` for `glob()`; neither reaches files only another machine has.
 */
interface RunsElsewhere
{
    /**
     * `$argv` (a program by name, `rg` or `fd`, and its arguments), to be run in `$cwd` where the
     * files are, as the command to start here and the directory to start it in.
     *
     * @param list<string> $argv
     * @return array{0: list<string>, 1: ?string}
     */
    public function elsewhere(array $argv, ?string $cwd): array;
}
