<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\CodingAgent\Session\BashExecution;

/**
 * A `user_bash` handler ran the command itself, and this is what came of it.
 *
 * Upstream's result has a second arm, `operations`, which swaps the executor and lets pi run
 * the command through it. Not ported: pig's `Run` is not pluggable, and a handler that wants
 * the command run elsewhere can run it there and hand back the outcome — one way in, not two.
 */
final readonly class UserBashEventResult
{
    public function __construct(public BashExecution $result)
    {
    }
}
