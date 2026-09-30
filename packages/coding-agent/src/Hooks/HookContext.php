<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\UserMessage;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Session\AgentSession;
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
    public readonly HookState $state;

    /**
     * @param Closure(): bool|null $isIdle            whether the agent is between runs
     * @param Closure(): void|null $abort             stop whatever is running
     * @param Closure(): bool|null $hasQueuedMessages whether someone typed while it worked
     * @param HookUi|null          $ui                `NoUi` when there is no terminal
     * @param AbortSignal|null     $signal            the turn in progress's, null between
     *        turns — what a handler's own waiting should park on, so escape cuts it short
     *        rather than the person waiting it out. `$pi->exec()` uses it without being told
     * @param HookState|null       $state             shared state container for handlers
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
        public ?AbortSignal $signal = null,
        ?HookState $state = null,
        private ?Closure $getApiKey = null,
        public ?AgentSession $session = null,
    ) {
        $this->ui = $ui ?? new NoUi();
        $this->state = $state ?? new HookState();
    }

    public function set(string $key, mixed $value): void
    {
        $this->state->set($key, $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state->get($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->state->has($key);
    }

    public function sessionName(): ?string
    {
        return $this->store?->sessionName();
    }

    public function setSessionName(string $name): void
    {
        $this->store?->setSessionName($name);
    }

    public function apiKey(?Model $model = null): ?string
    {
        $target = $model ?? $this->model;
        if ($target === null) {
            return null;
        }

        return $this->getApiKey !== null ? ($this->getApiKey)($target) : null;
    }

    /**
     * Run a one-off completion using the session's model and credentials.
     * Useful for background tasks like auto-summarizing session titles.
     */
    public function complete(string $prompt, ?Model $model = null, int $maxTokens = 1000): ?string
    {
        $target = $model ?? $this->model;
        if ($target === null) {
            return null;
        }

        $key = $this->apiKey($target);
        $options = new SimpleStreamOptions(maxTokens: $maxTokens, apiKey: $key);
        $context = new Context([new UserMessage($prompt)]);

        $stream = Stream::simple($target, $context, $options);
        foreach ($stream as $event) {
            // iterate to complete
        }

        $res = $stream->result()->await();
        if ($res instanceof AssistantMessage) {
            $text = '';
            foreach ($res->content as $c) {
                if ($c instanceof TextContent) {
                    $text .= $c->text;
                }
            }

            return $text;
        }

        return null;
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
