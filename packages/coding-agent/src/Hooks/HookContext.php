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
use Pig\Agent\AgentToolResult;
use Pig\Agent\ThinkingLevel;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\ContextUsage;
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
     * @param Closure(): bool|null $hasPendingMessages whether someone typed while it worked
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
        private ?Closure $hasPendingMessages = null,
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

    public function hasPendingMessages(): bool
    {
        return $this->hasPendingMessages !== null && ($this->hasPendingMessages)();
    }

    // ---- upstream's ExtensionContext, the parts that need the session -------------------
    //
    // Each answers the empty thing — null, `print`, true — when there is no session, which is a
    // hook file being read at startup or a test that built a context by hand. Not a fallback over
    // a failure: there is genuinely nothing to report yet.

    /** `tui`, `print`, `json`, `rpc` or `mcp`. Upstream's `ctx.mode`; `mcp` is pig's, for `--mode mcp`. */
    public function mode(): string
    {
        return $this->session?->mode() ?? 'print';
    }

    public function thinkingLevel(): ?ThinkingLevel
    {
        return $this->session?->thinkingLevel();
    }

    /**
     * Upstream's `scopedModels`: what `--models` narrowed this session to, each with the thinking
     * level its pattern named, or empty when nothing is scoped and every available model is usable.
     *
     * @return list<\Pig\CodingAgent\ModelChoice>
     */
    public function scopedModels(): array
    {
        return $this->session?->modelScope() ?? [];
    }

    /** Whether `<cwd>/.pig/` was allowed to load. */
    public function isProjectTrusted(): bool
    {
        return $this->session?->isProjectTrusted() ?? true;
    }

    /** How full the context window is; null with no model or no window. */
    public function getContextUsage(): ?ContextUsage
    {
        return $this->session?->contextUsage();
    }

    /** The system prompt the next request carries. */
    public function getSystemPrompt(): string
    {
        return $this->session?->systemPrompt() ?? '';
    }

    /**
     * Summarise the conversation, in the background. Upstream's `ctx.compact()`.
     *
     * Fire-and-forget, because a handler that waited would be waiting inside the turn it wants
     * to make room after. `$onComplete` gets the summary (null when it was cancelled), `$onError`
     * the throwable — "Agent is working" among them, when it is asked mid-turn.
     *
     * @param (Closure(CompactionSummary|null): void)|null $onComplete
     * @param (Closure(\Throwable): void)|null            $onError
     */
    public function compact(?string $customInstructions = null, ?Closure $onComplete = null, ?Closure $onError = null): void
    {
        $session = $this->session;

        if ($session === null) {
            return;
        }

        Async::spawn(static function () use ($session, $customInstructions, $onComplete, $onError): void {
            try {
                $summary = $session->compact($customInstructions);
            } catch (\Throwable $error) {
                if ($onError !== null) {
                    $onError($error);
                }

                return;
            }

            if ($onComplete !== null) {
                $onComplete($summary);
            }
        });
    }

    /** Ask pig to quit — now if idle, once the prompt settles otherwise. */
    public function shutdown(): void
    {
        $this->session?->requestShutdown();
    }

    /**
     * Run one of the model's tools, through the same gate the model's calls go through.
     *
     * @param array<string, mixed> $arguments
     */
    public function executeTool(string $name, array $arguments = []): AgentToolResult
    {
        $session = $this->session ?? throw new \LogicException('executeTool() needs a session — call it from a handler.');

        return $session->executeTool($name, $arguments, $this->signal);
    }
}
