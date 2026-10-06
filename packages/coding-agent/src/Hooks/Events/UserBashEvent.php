<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Somebody typed `!command` (or `!!command`). Upstream's `user_bash`.
 *
 * A handler may answer `UserBashEventResult` with a finished `BashExecution` and the command is
 * not run here at all — the extension ran it somewhere else (a container, a remote box) and
 * that is the output the person sees and, for `!`, the model reads. `$excludeFromContext` is
 * true for `!!`.
 */
final readonly class UserBashEvent implements HookEvent
{
    public function __construct(
        public string $command,
        public bool $excludeFromContext,
        public string $cwd,
    ) {
    }

    public function type(): string
    {
        return 'user_bash';
    }
}
