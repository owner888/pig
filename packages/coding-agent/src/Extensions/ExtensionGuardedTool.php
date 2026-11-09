<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\PreparesArguments;
use Pig\Agent\ReplayableTool;
use Pig\Agent\ToolReplay;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Extensions\Events\ToolCallEvent;
use Pig\CodingAgent\Extensions\Events\ToolResultEvent;
use Throwable;

/**
 * A tool with the hooks wrapped around it.
 *
 * `tool_call` before, which can stop the call, and `tool_result` after, which can change
 * what the model is shown. Everything else — the definition, the label, the progress
 * callback — passes straight through, so a wrapped tool is indistinguishable from the
 * tool itself to everything except a hook.
 *
 * Ported from upstream's `core/hooks/tool-wrapper.ts`, which spreads the tool into an
 * object literal and replaces `execute`. A decorator here, because PHP has interfaces
 * rather than structural types and `{...tool, execute}` is not a thing one can write.
 *
 * **A hook that fails blocks the call.** That is deliberate and it is upstream's rule
 * too: a hook asked whether a tool may run and answered by throwing has not said yes.
 * The alternative — carry on when the guard is broken — is a `rm -rf` guard that stops
 * working the day someone leaves a typo in it, which is exactly the day it matters.
 */
final readonly class ExtensionGuardedTool implements AgentTool, ReplayableTool, PreparesArguments
{
    public function __construct(
        private AgentTool $tool,
        private ExtensionRunner $extensionRunner,
    ) {
    }

    /** Every tool in a set, wrapped. @param list<AgentTool> $tools @return list<AgentTool> */
    public static function wrap(array $tools, ExtensionRunner $extensionRunner): array
    {
        if ($extensionRunner->isEmpty()) {
            return $tools;
        }

        return array_map(static fn (AgentTool $tool): AgentTool => new self($tool, $extensionRunner), $tools);
    }

    /** What is underneath, for a caller that needs the real thing. */
    public function inner(): AgentTool
    {
        return $this->tool;
    }

    public function definition(): Tool
    {
        return $this->tool->definition();
    }

    /** What the tool underneath declares. */
    public function replay(): ToolReplay
    {
        return ToolReplay::of($this->tool);
    }

    /** What the tool underneath makes of the arguments, or the arguments when it reshapes none. */
    #[\Override]
    public function prepareArguments(array $arguments): array
    {
        return $this->tool instanceof PreparesArguments ? $this->tool->prepareArguments($arguments) : $arguments;
    }

    public function label(): string
    {
        return $this->tool->label();
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        // Whose call this is: set for a call another tool made through the session (a codemode
        // script's), null for the model's — upstream's `parentToolCallId` on `tool_call` and
        // `tool_result`, which its `_beforeToolCall()` / `_afterToolCall()` spread in. A permission
        // gate can hold a script's call to a different rule than the model's.
        $parentToolCallId = $this->extensionRunner->nestedParentOf($toolCallId);
        $name = $this->definition()->name;

        $arguments = $this->guard($name, $toolCallId, $arguments, $parentToolCallId);

        try {
            $result = $this->tool->execute($toolCallId, $arguments, $signal, $onUpdate);
        } catch (Throwable $error) {
            // Failures are shown to the hooks too — a hook that counts what goes wrong
            // cannot do it from the successes — and then rethrown unchanged, because the
            // loop is what decides what a failed tool means.
            $this->announce($name, $toolCallId, $arguments, [new TextContent($error->getMessage())], null, true, $parentToolCallId);

            throw $error;
        }

        $answer = $this->announce($name, $toolCallId, $arguments, $result->content, $result->details, false, $parentToolCallId);

        if ($answer === null) {
            return $result;
        }

        // `isError` is not on AgentToolResult — the loop decides that from whether
        // execute() threw — so a hook that sets it has to raise it the same way.
        if ($answer->isError === true) {
            throw new AgentError(self::text($answer->content ?? $result->content));
        }

        // Upstream's `afterResult.structuredContent ?? (afterResult.content ? undefined :
        // result.structuredContent)`: a hook that replaced the content drops the structured
        // result, which may no longer describe it. A hook here cannot supply one of its own.
        return new AgentToolResult(
            $answer->content ?? $result->content,
            $answer->details ?? $result->details,
            $result->usage,
            $answer->content === null ? $result->structuredContent : null,
        );
    }

    /**
     * Ask the hooks whether this call may go ahead, and with what arguments.
     *
     * The event's `input` comes back as the arguments to run with: a hook that edits it in place
     * has changed the call, which is upstream's way of letting a gate soften a command rather
     * than only refuse it.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the arguments as the hooks left them
     * @throws AgentError when a hook blocks it, or when one fails while being asked
     */
    private function guard(string $name, string $toolCallId, array $arguments, ?string $parentToolCallId = null): array
    {
        if (!$this->extensionRunner->hasHandlers('tool_call')) {
            return $arguments;
        }

        $event = new ToolCallEvent($name, $toolCallId, $arguments, $parentToolCallId);

        try {
            $result = $this->extensionRunner->emitToolCall($event);
        } catch (Throwable $error) {
            throw new AgentError(
                "Blocked: a tool_call hook failed, and a hook that cannot answer is not consent. "
                . $error::class . ': ' . $error->getMessage(),
            );
        }

        if ($result !== null && $result->block) {
            throw new AgentError($result->message());
        }

        return $event->input;
    }

    /**
     * @param array<string, mixed>      $arguments
     * @param list<\Pig\Ai\UserContent> $content
     */
    private function announce(
        string $name,
        string $toolCallId,
        array $arguments,
        array $content,
        mixed $details,
        bool $isError,
        ?string $parentToolCallId = null,
    ): ?Results\ToolResultEventResult {
        if (!$this->extensionRunner->hasHandlers('tool_result')) {
            return null;
        }

        return $this->extensionRunner->emitToolResult(
            new ToolResultEvent($name, $toolCallId, $arguments, $content, $details, $isError, $parentToolCallId),
        );
    }

    /** @param list<\Pig\Ai\UserContent> $content */
    private static function text(array $content): string
    {
        $parts = [];

        foreach ($content as $piece) {
            if ($piece instanceof TextContent) {
                $parts[] = $piece->text;
            }
        }

        return $parts === [] ? 'Tool failed' : implode("\n", $parts);
    }
}
