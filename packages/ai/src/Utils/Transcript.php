<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\Context;
use Pig\Ai\SystemMessage;
use Pig\Ai\Tool;
use Pig\Ai\ToolReference;
use Pig\Ai\TranscriptContext;

/**
 * Upstream's `utils/transcript.ts`: the prompt and the tool set as a transcript of system
 * messages, and the replay that turns them back into one.
 *
 * The replay helpers read only `SystemMessage`s, so an agent transcript that carries the app's own
 * messages (a compaction summary, a bash execution) can be passed without filtering — upstream's
 * `TranscriptMessages`, "any message list".
 *
 * @phpstan-import-type Message from Context
 */
final class Transcript
{
    /**
     * Build the leading system message for a prompt and tool set. Returns null when both are
     * empty, so an empty transcript stays empty. Upstream's `createInitialSystemMessage()`.
     *
     * @param list<Tool>|null $tools
     */
    public static function createInitialSystemMessage(?string $systemPrompt, ?array $tools): ?SystemMessage
    {
        $hasSystemPrompt = $systemPrompt !== null && $systemPrompt !== '';
        $hasTools = $tools !== null && $tools !== [];

        if (!$hasSystemPrompt && !$hasTools) {
            return null;
        }

        return new SystemMessage($systemPrompt ?? '', null, $hasTools ? array_values($tools) : null, null, 0);
    }

    /**
     * Fold `Context::$systemPrompt` and `Context::$tools` into a leading system message. The only
     * way `Stream` produces a `TranscriptContext`; every provider expects the result. Upstream's
     * `normalizeContext()`.
     */
    public static function normalizeContext(Context $context): TranscriptContext
    {
        $initial = self::createInitialSystemMessage($context->systemPrompt, $context->tools);

        return new TranscriptContext($initial !== null ? [$initial, ...$context->messages] : array_values($context->messages));
    }

    /**
     * Return the leading system message, if the transcript starts with one. Upstream's
     * `getInitialSystemMessage()`.
     *
     * @param list<mixed> $messages
     */
    public static function getInitialSystemMessage(array $messages): ?SystemMessage
    {
        $first = $messages[0] ?? null;

        return $first instanceof SystemMessage ? $first : null;
    }

    /**
     * Drop the leading system message for APIs that carry the prompt outside the message list.
     * Upstream's `withoutInitialSystemMessage()`.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    public static function withoutInitialSystemMessage(array $messages): array
    {
        return self::getInitialSystemMessage($messages) !== null ? array_slice($messages, 1) : $messages;
    }

    /**
     * Resolve the tools available after applying every transcript delta in order. Upstream's
     * `getCurrentTools()`.
     *
     * @param list<mixed> $messages
     * @return list<Tool>
     */
    public static function getCurrentTools(array $messages): array
    {
        $tools = [];

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                continue;
            }

            foreach ($message->toolsRemoved ?? [] as $tool) {
                unset($tools[$tool->name]);
            }

            foreach ($message->toolsAdded ?? [] as $tool) {
                // A `Map.set()` of a name already present keeps its place; `unset()` above is the
                // `delete()` that moves a removed-then-added name to the end, as upstream's does.
                $tools[$tool->name] = $tool;
            }
        }

        return array_values($tools);
    }

    /**
     * Replay every system message into one leading system message holding the current prompt and
     * tools. Later `content` is appended to the base prompt, `sections` are patched by name, and
     * tools are resolved with `getCurrentTools()`. Upstream's `getCurrentSystemMessage()`.
     *
     * @param list<mixed> $messages
     */
    public static function getCurrentSystemMessage(array $messages): ?SystemMessage
    {
        $content = [];
        $sections = [];
        $timestamp = null;

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                continue;
            }

            $timestamp ??= $message->timestamp;
            $text = Text::contentText($message->content);

            if ($text !== '') {
                $content[] = $text;
            }

            foreach ($message->sections ?? [] as $name => $value) {
                if ($value === null) {
                    unset($sections[$name]);
                } else {
                    $sections[$name] = $value;
                }
            }
        }

        $tools = self::getCurrentTools($messages);

        if ($timestamp === null && $tools === []) {
            return null;
        }

        return new SystemMessage(
            implode("\n\n", $content),
            $sections !== [] ? $sections : null,
            $tools !== [] ? $tools : null,
            null,
            $timestamp ?? 0,
        );
    }

    /**
     * Render the current system prompt text after replaying every system message. Upstream's
     * `getCurrentSystemPrompt()`.
     *
     * @param list<mixed> $messages
     */
    public static function getCurrentSystemPrompt(array $messages): string
    {
        $message = self::getCurrentSystemMessage($messages);

        return $message !== null ? Text::getSystemMessageText($message) : '';
    }

    /**
     * Rebuild the transcript for APIs without mid-conversation system messages: the replayed system
     * message leads, and every later system message is dropped. Upstream's `collapseSystemMessages()`.
     */
    public static function collapseSystemMessages(TranscriptContext $context): TranscriptContext
    {
        $head = self::getCurrentSystemMessage($context->messages);
        $messages = array_values(array_filter(
            $context->messages,
            static fn (mixed $message): bool => !$message instanceof SystemMessage,
        ));

        return new TranscriptContext($head !== null ? [$head, ...$messages] : $messages);
    }

    /**
     * Keep later system messages in place when the model accepts them; otherwise collapse them.
     * Upstream's `resolveTranscript()`.
     */
    public static function resolveTranscript(TranscriptContext $context, ?bool $supportsMidConvoSystemMessages): TranscriptContext
    {
        return $supportsMidConvoSystemMessages === true ? $context : self::collapseSystemMessages($context);
    }

    /**
     * Strip executable and display-only fields from a tool before transcript comparison or
     * persistence. Upstream's `toToolDeclaration()`.
     *
     * pig's `Tool` is already the declaration, so what is stripped is its one field that is not:
     * `$typeBox`, which only `Agent\ToolArguments` reads off the live tool. The parameters are a
     * value copy already; upstream's JSON round trip exists to drop typebox's symbol keys, which a
     * PHP array cannot carry.
     */
    public static function toToolDeclaration(Tool $tool): Tool
    {
        return new Tool($tool->name, $tool->description, $tool->parameters, $tool->constrainedSampling);
    }

    /**
     * Whether two tools declare the same interface to the model. Upstream's `declarationsEqual()`,
     * which compares the serialized declarations.
     *
     * The JSON is canonicalized once more here: PHP has two spellings of an empty object — `[]`
     * and `new \stdClass()` — and a declaration read back from a session file has the first where
     * the live tool may have the second. Without this every resume would redeclare those tools.
     */
    public static function declarationsEqual(Tool $left, Tool $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    /**
     * Compare two complete tool states. A changed definition is a removal followed by an addition.
     * Upstream's `getToolStateChanges()`.
     *
     * @param list<Tool> $previous
     * @param list<Tool> $current
     * @return array{toolsAdded: list<Tool>, toolsRemoved: list<ToolReference>}
     */
    public static function getToolStateChanges(array $previous, array $current): array
    {
        $previousTools = [];

        foreach ($previous as $tool) {
            $previousTools[$tool->name] = $tool;
        }

        $currentTools = [];

        foreach ($current as $tool) {
            $currentTools[$tool->name] = $tool;
        }

        $added = [];

        foreach ($current as $tool) {
            $previousTool = $previousTools[$tool->name] ?? null;

            if ($previousTool === null || !self::declarationsEqual($previousTool, $tool)) {
                $added[] = self::toToolDeclaration($tool);
            }
        }

        $removed = [];

        foreach ($previous as $tool) {
            $currentTool = $currentTools[$tool->name] ?? null;

            if ($currentTool === null || !self::declarationsEqual($tool, $currentTool)) {
                $removed[] = new ToolReference($tool->name);
            }
        }

        return ['toolsAdded' => $added, 'toolsRemoved' => $removed];
    }

    /**
     * Every definition referenced by transcript tool state, in first-declaration order. Upstream's
     * `getDeclaredTools()`.
     *
     * @param list<mixed> $messages
     * @return list<Tool>
     */
    public static function getDeclaredTools(array $messages): array
    {
        $definitions = [];

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                continue;
            }

            foreach ($message->toolsAdded ?? [] as $tool) {
                $definitions[$tool->name] = $tool;
            }
        }

        return array_values($definitions);
    }

    /**
     * Whether a tool name was declared twice with different definitions. Upstream's
     * `hasToolRedefinitions()`, which it keeps deprecated for API compatibility — "No built-in
     * transport needs this anymore"; ported for the same reason.
     *
     * @param list<mixed> $messages
     */
    public static function hasToolRedefinitions(array $messages): bool
    {
        $declared = [];

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                continue;
            }

            foreach ($message->toolsAdded ?? [] as $tool) {
                $previous = $declared[$tool->name] ?? null;

                if ($previous !== null && !self::declarationsEqual($previous, $tool)) {
                    return true;
                }

                $declared[$tool->name] = $tool;
            }
        }

        return false;
    }

    /**
     * Whether tool history contains a removal or same-name redeclaration that an addition-only
     * transport cannot replay. Upstream's `hasNonAdditiveToolChanges()`.
     *
     * @param list<mixed> $messages
     */
    public static function hasNonAdditiveToolChanges(array $messages): bool
    {
        $declared = [];

        foreach ($messages as $message) {
            if (!$message instanceof SystemMessage) {
                continue;
            }

            if (($message->toolsRemoved ?? []) !== []) {
                return true;
            }

            foreach ($message->toolsAdded ?? [] as $tool) {
                if (isset($declared[$tool->name])) {
                    return true;
                }

                $declared[$tool->name] = true;
            }
        }

        return false;
    }

    /**
     * Split tool declarations between the top-level request field and in-place additions —
     * upstream's `resolveTranscriptTools()`. Transports that can anchor additions at a system
     * message keep the initial tools at the top and load later ones where they appear; that only
     * works when no tool was removed or redeclared, so everything else sends the current tool list.
     *
     * @param list<mixed> $messages
     * @return array{requestTools: list<Tool>, anchorsAdditions: bool} `requestTools` are sent in the
     *         top-level request field; `anchorsAdditions` says whether later system messages carry
     *         their own `toolsAdded` as in-place additions (when false, `requestTools` already
     *         holds the complete current tool set)
     */
    public static function resolveTranscriptTools(array $messages, bool $supportsToolAdditions): array
    {
        $anchorsAdditions = $supportsToolAdditions && !self::hasNonAdditiveToolChanges($messages);

        return [
            'requestTools' => $anchorsAdditions
                ? (self::getInitialSystemMessage($messages)?->toolsAdded ?? [])
                : self::getCurrentTools($messages),
            'anchorsAdditions' => $anchorsAdditions,
        ];
    }

    private static function canonical(Tool $tool): string
    {
        $json = (string) json_encode(MessageJson::encodeTool(self::toToolDeclaration($tool)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return (string) json_encode(json_decode($json, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
