<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookUi;
use Throwable;

/**
 * The custom tools this session has, and the one thing they need that a tool cannot do
 * for itself: being told the session moved.
 *
 * Upstream's `CustomToolsLoadResult` plays this part — the loaded tools plus a way to
 * hand them what only exists once a mode is running. There that is the UI context; here it
 * is that, the session context, and the lifecycle events.
 *
 * `notify()` is called from the interactive mode rather than from the session, because
 * all four moments a tool hears about — startup, `/new` and `/resume`, `/tree`, quitting —
 * are things someone did in the UI. Routing them through the hook runner was the other
 * option and was worse: `/hooks` would then list tool files as hooks.
 */
final class CustomToolSet
{
    /** @var list<LoadedCustomTool> */
    private array $tools;

    private ?Closure $context = null;

    /**
     * @param list<LoadedCustomTool> $tools
     * @param CustomToolApi|null     $api the one the factories were handed, so `withUi()`
     *        reaches what they closed over rather than a fresh copy of it
     */
    public function __construct(array $tools = [], private readonly ?CustomToolApi $api = null)
    {
        $this->tools = $tools;
    }

    /**
     * Hand the tools the terminal's UI, once a mode has one.
     *
     * Upstream's `setUIContext`. Called after the mode is up rather than at load, because
     * at load there is no screen to draw a dialog on.
     */
    public function withUi(HookUi $ui): void
    {
        $this->api?->withUi($ui);
    }

    /**
     * Say how to build the session context a tool's `execute` and `onSession` are given.
     *
     * Separate from the constructor because the tools are loaded before there is a
     * session to describe — and a closure rather than a value, so a tool that asks which
     * model is answering is told the current one rather than the one at startup.
     *
     * @param Closure(): HookContext $context
     */
    public function withContext(Closure $context): void
    {
        $this->context = $context;
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /** What each tool is called, in load order. @return list<string> */
    public function names(): array
    {
        return array_map(static fn (LoadedCustomTool $one): string => $one->tool->name, $this->tools);
    }

    /** Where each one came from. @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (LoadedCustomTool $one): string => $one->path, $this->tools);
    }

    /** @return list<LoadedCustomTool> */
    public function loaded(): array
    {
        return $this->tools;
    }

    /** These tools as the agent takes them. @return list<AgentTool> */
    public function agentTools(): array
    {
        return WrappedCustomTool::wrap($this->tools, $this->contextFn());
    }

    /**
     * Tell every tool that holds state what just happened.
     *
     * Returns what went wrong rather than throwing: one tool failing to clean up is not a
     * reason for `/new` to fail, and the caller is the one with somewhere to say it.
     *
     * @param 'start'|'switch'|'tree'|'shutdown' $reason
     * @return list<ToolProblem>
     */
    public function notify(string $reason, ?string $previousSessionFile = null): array
    {
        $event = new CustomToolSessionEvent($reason, $previousSessionFile);
        $context = ($this->contextFn())();
        $problems = [];

        foreach ($this->tools as $one) {
            if ($one->tool->onSession === null) {
                continue;
            }

            try {
                ($one->tool->onSession)($event, $context);
            } catch (Throwable $error) {
                $problems[] = new ToolProblem(
                    $one->path,
                    "onSession({$reason}): " . $error::class . ': ' . $error->getMessage(),
                );
            }
        }

        return $problems;
    }

    /** @return Closure(): HookContext */
    private function contextFn(): Closure
    {
        // A context with nothing wired up rather than null: a tool reading
        // `$ctx->isIdle()` outside a session should get an answer, not a TypeError.
        return $this->context ?? static fn (): HookContext => new HookContext('.');
    }
}
