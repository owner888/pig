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
 * `ui` is upstream's, and it is the interesting one: a handler can ask the person a
 * question and wait for the answer, which is what makes a `tool_call` guard more than a
 * yes-or-no rule. `hasUI` says whether asking will reach anybody — with no terminal the
 * answers are `NoUi`'s, and a hook that wants to behave differently rather than take them
 * can check first.
 */
final readonly class HookContext
{
    public readonly HookUi $ui;

    /**
     * @param Closure(): bool|null $isIdle            whether the agent is between runs
     * @param Closure(): void|null $abort             stop whatever is running
     * @param Closure(): bool|null $hasQueuedMessages whether someone typed while it worked
     * @param HookUi|null          $ui                `NoUi` when there is no terminal
     */
    public function __construct(
        public string $cwd,
        public ?SessionManager $store = null,
        public ?Model $model = null,
        private ?Closure $isIdle = null,
        private ?Closure $abort = null,
        private ?Closure $hasQueuedMessages = null,
        ?HookUi $ui = null,
        public bool $hasUi = false,
    ) {
        $this->ui = $ui ?? new NoUi();
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
