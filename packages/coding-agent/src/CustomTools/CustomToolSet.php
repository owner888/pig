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
 * `notify()` is called from the **modes** rather than from the session, because all four
 * moments a tool hears about — startup, `/new` and `/resume`, `/tree`, quitting — are things
 * somebody asked for, and the mode is what knows. Routing them through the hook runner was
 * the other option and was worse: `/hooks` would then list tool files as hooks. All four fire
 * in the terminal and over RPC; `PrintMode` fires `start` and `shutdown`, which is all of
 * them that exist there, since it has no `/tree` and no way to switch sessions. (This said
 * "the interactive mode" for a while, which was a claim about the rest of the repository that
 * two other modes had already made false.)
 */
final class CustomToolSet
{
    /** @var list<LoadedCustomTool> */
    private array $tools;

    private ?Closure $context = null;

    /** Told after the set changed — what re-sets the agent's tools. */
    private ?Closure $changed = null;

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
        // And to the shared API object, so `$pi->exec()` can reach the turn's signal.
        $this->api?->withContext($context);
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /**
     * Follow an extension's tools as they change after load.
     *
     * The loader copied `$api->tools()` into this set once, at startup. An MCP server's tools
     * only exist once it has connected, which is later and in a fiber, so the extension's API is
     * told to call back here, and this set replaces whatever that extension had contributed with
     * what it offers now — then tells whoever wired `onChange()`, which is the agent.
     */
    public function adopt(\Pig\CodingAgent\Extensions\LoadedExtension $extension): void
    {
        $extension->api->onToolsChanged(function (\Pig\CodingAgent\Extensions\ExtensionApi $api) use ($extension): void {
            $this->tools = array_values(array_filter(
                $this->tools,
                static fn (LoadedCustomTool $one): bool => $one->resolvedPath !== $extension->resolved,
            ));

            foreach ($api->tools() as $tool) {
                $this->tools[] = new LoadedCustomTool($extension->path, $extension->resolved, $tool);
            }

            if ($this->changed !== null) {
                ($this->changed)($this);
            }
        });
    }

    /** @param Closure(self): void $listener */
    public function onChange(Closure $listener): void
    {
        $this->changed = $listener;
    }

    /**
     * What the tools in the set want the system prompt to say — `CustomTool::$promptSnippet` by
     * name, and every `$promptGuidelines` line. Both empty for a set with nothing to add.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public function promptContributions(): array
    {
        $snippets = [];
        $guidelines = [];

        foreach ($this->tools as $one) {
            if ($one->tool->promptSnippet !== null) {
                $snippets[$one->tool->name] = $one->tool->promptSnippet;
            }

            foreach ($one->tool->promptGuidelines as $line) {
                $guidelines[] = $line;
            }
        }

        return [$snippets, $guidelines];
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

    /**
     * The declaration behind a tool name, or null when it is a built-in.
     *
     * For the transcript: it is the declaration that carries `renderCall` and
     * `renderResult`, and the component drawing a call only knows the name.
     */
    public function find(string $name): ?CustomTool
    {
        foreach ($this->tools as $one) {
            if ($one->tool->name === $name) {
                return $one->tool;
            }
        }

        return null;
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
        //
        // **A mode that reaches this has forgotten to call `withContext()`**, which is what
        // `PrintMode` had done: the stub answers every question without a session behind it,
        // so a tool got no model, no conversation, an `abort()` that does nothing and a
        // working directory of `.`. Nothing distinguishes that from a tool being asked
        // something legitimately early, which is why it went unnoticed — the three modes all
        // wire it now, and `PrintModeTest::testACustomToolGetsTheRealSessionHereToo` is what
        // keeps the third one honest.
        return $this->context ?? static fn (): HookContext => new HookContext('.');
    }
}
