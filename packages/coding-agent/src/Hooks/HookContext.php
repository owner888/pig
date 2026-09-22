<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\Ai\Model;
use Pig\CodingAgent\Session\SessionManager;

/**
 * The session, as a handler is allowed to see it.
 *
 * Second argument to every handler. It is deliberately not the `AgentSession`: a hook
 * that could call `prompt()` from inside a `tool_call` would be re-entering the loop
 * that is waiting for it. What is here is what can be read at any moment, plus `abort()`,
 * which is safe because stopping is the one thing that is always allowed.
 *
 * Upstream also carries `ui` and `hasUI` — a whole prompting API (`select`, `confirm`,
 * `input`, `editor`) that lets a hook ask the person something mid-turn. Not ported:
 * that needs the interactive mode to be able to open a picker from inside a tool call,
 * which pig cannot do yet. See CLAUDE.md.
 */
final readonly class HookContext
{
    /**
     * @param Closure(): bool|null $isIdle            whether the agent is between runs
     * @param Closure(): void|null $abort             stop whatever is running
     * @param Closure(): bool|null $hasQueuedMessages whether someone typed while it worked
     */
    public function __construct(
        public string $cwd,
        public ?SessionManager $store = null,
        public ?Model $model = null,
        private ?Closure $isIdle = null,
        private ?Closure $abort = null,
        private ?Closure $hasQueuedMessages = null,
    ) {
    }

    public function isIdle(): bool
    {
        return $this->isIdle === null || ($this->isIdle)();
    }

    public function abort(): void
    {
        if ($this->abort !== null) {
            ($this->abort)();
        }
    }

    public function hasQueuedMessages(): bool
    {
        return $this->hasQueuedMessages !== null && ($this->hasQueuedMessages)();
    }
}
