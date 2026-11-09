<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use RuntimeException;

/**
 * A command `BashOperations::exec()` stopped before it finished: aborted, or out of time —
 * upstream's rejections `"aborted"` and `"timeout:<seconds>"`, as a type.
 */
final class CommandStopped extends RuntimeException
{
    /** @param int|null $exitCode what the stopped process reported, when there was anything */
    public function __construct(public readonly bool $timedOut, public readonly ?int $exitCode = null)
    {
        parent::__construct($timedOut ? 'timeout' : 'aborted');
    }
}
