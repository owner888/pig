<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\Cost;
use Pig\Ai\DiagnosticErrorInfo;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolReference;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use stdClass;

/**
 * The four message types of `Pig\Ai`, to JSON and back.
 *
 * Upstream needs none of this: a message there is a plain object and `JSON.stringify` is the whole
 * of its persistence layer. PHP objects do not survive that trip, so the shapes are written out by
 * hand — which is more code, and also the only place that has to change when a message type gains
 * a field.
 *
 * The wire format is upstream's, so a session file from either project can be read by the other:
 * `{"role": "user", "content": [{"type": "text", "text": "…"}], "timestamp": …}`.
 *
 * **It lives in `pig/ai`, next to the types it encodes, because three different things send this
 * exact shape**: the session file, RPC mode's tool results, and `Agent\StreamProxy`'s request to a
 * gateway. Upstream has them share it by accident — they are the same objects through the same
 * `JSON.stringify` — and the first version of this had a copy in `coding-agent` that
 * `agent-core` could not reach, which would have meant a second hand-written notion of what a
 * message looks like. That is the mistake `Agent\ToolArguments` had already made once about tool
 * schemas; twice is a pattern, so it moved here instead.
 *
 * `CodingAgent\Session\SessionCodec` still owns the roles that only pig has — a compaction
 * summary, a branch summary, a bash execution — and delegates these four.
 */
final class MessageJson
{
    /**
     * @return array<string, mixed>|null null for anything that is not one of the four
     */
    public static function encode(mixed $message): ?array
    {
        return match (true) {
            // Upstream's `SystemMessage` as `JSON.stringify` writes it: the optional fields only
            // when set, `content` as the string or the blocks it was.
            $message instanceof SystemMessage => [
                'role' => 'system',
                'content' => is_string($message->content) ? $message->content : self::encodeContent($message->content),
                ...($message->sections === null ? [] : ['sections' => $message->sections === [] ? new stdClass() : $message->sections]),
                ...($message->toolsAdded === null ? [] : ['toolsAdded' => array_map(self::encodeTool(...), $message->toolsAdded)]),
                ...($message->toolsRemoved === null ? [] : ['toolsRemoved' => array_map(
                    static fn (ToolReference $tool): array => ['name' => $tool->name],
                    $message->toolsRemoved,
                )]),
                'timestamp' => $message->timestamp,
            ],
            $message instanceof UserMessage => [
                'role' => 'user',
                'content' => self::encodeContent($message->content),
                'timestamp' => $message->timestamp,
            ],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'content' => self::encodeContent($message->content),
                'api' => $message->api->value,
                'provider' => $message->provider,
                'model' => $message->model,
                'usage' => self::encodeUsage($message->usage),
                'stopReason' => $message->stopReason->value,
                'errorMessage' => $message->errorMessage,
                'timestamp' => $message->timestamp,
            ] + self::optional($message),
            $message instanceof ToolResultMessage => [
                'role' => 'toolResult',
                'toolCallId' => $message->toolCallId,
                'toolName' => $message->toolName,
                'content' => self::encodeContent($message->content),
                'isError' => $message->isError,
                'details' => self::plain($message->details),
                'timestamp' => $message->timestamp,
            ],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @return SystemMessage|UserMessage|AssistantMessage|ToolResultMessage|null null for any other role
     */
    public static function decode(array $entry): mixed
    {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;

        return match ($entry['role'] ?? null) {
            // Upstream's `sessionEntryToContextMessages()`: "Session files are parsed without
            // validation", so a null or missing `content` reads as "".
            'system' => new SystemMessage(
                is_array($entry['content'] ?? null) ? self::decodeContent($entry['content']) : (string) ($entry['content'] ?? ''),
                is_array($entry['sections'] ?? null) ? self::decodeSections($entry['sections']) : null,
                is_array($entry['toolsAdded'] ?? null) ? array_values(array_filter(array_map(
                    static fn (mixed $tool): ?Tool => is_array($tool) ? self::decodeTool($tool) : null,
                    $entry['toolsAdded'],
                ))) : null,
                is_array($entry['toolsRemoved'] ?? null) ? array_values(array_map(
                    static fn (mixed $tool): ToolReference => new ToolReference((string) (is_array($tool) ? ($tool['name'] ?? '') : '')),
                    $entry['toolsRemoved'],
                )) : null,
                $timestamp ?? 0,
            ),
            'user' => new UserMessage(self::decodeContent($entry['content'] ?? []), $timestamp),
            'assistant' => new AssistantMessage(
                self::decodeContent($entry['content'] ?? []),
                Api::tryFrom((string) ($entry['api'] ?? '')) ?? Api::AnthropicMessages,
                (string) ($entry['provider'] ?? ''),
                (string) ($entry['model'] ?? ''),
                self::decodeUsage((array) ($entry['usage'] ?? [])),
                StopReason::tryFrom((string) ($entry['stopReason'] ?? '')) ?? StopReason::Stop,
                $entry['errorMessage'] ?? null,
                $timestamp,
                isset($entry['rawStopReason']) ? (string) $entry['rawStopReason'] : null,
                isset($entry['responseId']) ? (string) $entry['responseId'] : null,
                isset($entry['responseModel']) ? (string) $entry['responseModel'] : null,
                isset($entry['endTurn']) ? (bool) $entry['endTurn'] : null,
                is_array($entry['diagnostics'] ?? null) ? self::decodeDiagnostics($entry['diagnostics']) : null,
                isset($entry['providerThinkingLevel']) ? (string) $entry['providerThinkingLevel'] : null,
                is_array($entry['deferred'] ?? null) ? $entry['deferred'] : null,
            ),
            'toolResult' => new ToolResultMessage(
                (string) ($entry['toolCallId'] ?? ''),
                (string) ($entry['toolName'] ?? ''),
                self::decodeContent($entry['content'] ?? []),
                (bool) ($entry['isError'] ?? false),
                $entry['details'] ?? null,
                $timestamp,
            ),
            default => null,
        };
    }

    /**
     * A tool declaration as upstream's `JSON.stringify` writes a `Tool`: name, description,
     * parameters, and `constrainedSampling` when it has one. The one shape for a tool on every
     * wire pig writes — the session file's `toolsAdded`, `Agent\StreamProxy`'s request, the
     * estimate of what the declarations cost.
     *
     * @return array<string, mixed>
     */
    public static function encodeTool(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters === [] ? new stdClass() : $tool->parameters,
        ] + ($tool->constrainedSampling === null ? [] : ['constrainedSampling' => $tool->constrainedSampling]);
    }

    /**
     * A tool declaration back from JSON.
     *
     * **Empty objects are put back where a schema has one**, which upstream never has to think
     * about: a session line is decoded into PHP arrays, where `{}` and `[]` are the same thing,
     * and a declaration replayed from the file is what the provider is sent. An MCP tool with no
     * arguments declares `"properties": {}`, and sent back as `"properties": []` the request is
     * refused. `schemaObjects()` restores the object for the keywords whose value is one.
     *
     * @param array<string, mixed> $tool
     */
    public static function decodeTool(array $tool): Tool
    {
        $constrainedSampling = $tool['constrainedSampling'] ?? null;

        return new Tool(
            (string) ($tool['name'] ?? ''),
            (string) ($tool['description'] ?? ''),
            is_array($tool['parameters'] ?? null) ? self::schemaObjects($tool['parameters']) : [],
            is_array($constrainedSampling) || $constrainedSampling === false ? $constrainedSampling : null,
        );
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function schemaObjects(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
                $schema[$key] = $value === [] ? new stdClass() : array_map(
                    static fn (mixed $child): mixed => is_array($child) ? ($child === [] ? new stdClass() : self::schemaObjects($child)) : $child,
                    $value,
                );

                continue;
            }

            if (in_array($key, ['items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains', 'propertyNames'], true)) {
                $schema[$key] = $value === [] ? new stdClass() : (array_is_list($value) ? array_map(
                    static fn (mixed $child): mixed => is_array($child) ? self::schemaObjects($child) : $child,
                    $value,
                ) : self::schemaObjects($value));

                continue;
            }

            if (in_array($key, ['anyOf', 'oneOf', 'allOf', 'prefixItems'], true) && array_is_list($value)) {
                $schema[$key] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? ($child === [] ? new stdClass() : self::schemaObjects($child)) : $child,
                    $value,
                );
            }
        }

        return $schema;
    }

    /**
     * @param array<mixed> $sections
     * @return array<string, string|null>
     */
    private static function decodeSections(array $sections): array
    {
        $out = [];

        foreach ($sections as $name => $value) {
            $out[(string) $name] = $value === null ? null : (string) $value;
        }

        return $out;
    }

    /**
     * The optional fields — `rawStopReason`, `responseId`, `responseModel`, `endTurn`,
     * `diagnostics`, `providerThinkingLevel`, `deferred` — each one present or not there at all.
     *
     * Upstream's fields are optional and `JSON.stringify` drops an undefined one, so a message
     * without them is written without the keys rather than with nulls — the shape a session file
     * from before the fields existed already has.
     *
     * @return array<string, mixed>
     */
    private static function optional(AssistantMessage $message): array
    {
        return array_filter([
            'rawStopReason' => $message->rawStopReason,
            'responseId' => $message->responseId,
            'responseModel' => $message->responseModel,
            'endTurn' => $message->endTurn,
            'diagnostics' => $message->diagnostics === null
                ? null
                : array_map(self::encodeDiagnostic(...), $message->diagnostics),
            'providerThinkingLevel' => $message->providerThinkingLevel,
            'deferred' => $message->deferred,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> upstream's `AssistantMessageDiagnostic`, absent fields left out */
    private static function encodeDiagnostic(AssistantMessageDiagnostic $diagnostic): array
    {
        $error = $diagnostic->error === null ? null : array_filter([
            'name' => $diagnostic->error->name,
            'message' => $diagnostic->error->message,
            'stack' => $diagnostic->error->stack,
            'code' => $diagnostic->error->code,
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'type' => $diagnostic->type,
            'timestamp' => $diagnostic->timestamp,
            'error' => $error,
            // `{}` and not `[]`, for the same reason as a tool call's arguments: it is an object.
            'details' => $diagnostic->details === null
                ? null
                : ($diagnostic->details === [] ? new stdClass() : $diagnostic->details),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<mixed> $diagnostics
     * @return list<AssistantMessageDiagnostic>
     */
    private static function decodeDiagnostics(array $diagnostics): array
    {
        $out = [];

        foreach ($diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            $error = is_array($diagnostic['error'] ?? null) ? $diagnostic['error'] : null;
            $code = $error['code'] ?? null;

            $out[] = new AssistantMessageDiagnostic(
                (string) ($diagnostic['type'] ?? ''),
                (int) ($diagnostic['timestamp'] ?? 0),
                $error === null ? null : new DiagnosticErrorInfo(
                    (string) ($error['message'] ?? ''),
                    isset($error['name']) ? (string) $error['name'] : null,
                    isset($error['stack']) ? (string) $error['stack'] : null,
                    is_string($code) || is_int($code) ? $code : null,
                ),
                is_array($diagnostic['details'] ?? null) ? $diagnostic['details'] : null,
            );
        }

        return $out;
    }

    /**
     * Content blocks as JSON.
     *
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    public static function encodeContent(array $content): array
    {
        $blocks = [];

        foreach ($content as $block) {
            $encoded = match (true) {
                // `textSignature` only when there is one, as `JSON.stringify` leaves out an undefined
                // field: the Responses APIs' message id and phase, which a replay sends back.
                $block instanceof TextContent => ['type' => 'text', 'text' => $block->text]
                    + ($block->textSignature === null ? [] : ['textSignature' => $block->textSignature]),
                $block instanceof ThinkingContent => [
                    'type' => 'thinking',
                    'thinking' => $block->thinking,
                    'thinkingSignature' => $block->thinkingSignature,
                ] + ($block->redacted === null ? [] : ['redacted' => $block->redacted]),
                $block instanceof ImageContent => [
                    'type' => 'image',
                    'data' => $block->data,
                    'mimeType' => $block->mimeType,
                ],
                $block instanceof ToolCall => [
                    'type' => 'toolCall',
                    'id' => $block->id,
                    'name' => $block->name,
                    // `{}` and not `[]`: this file is pi's, and pi hands the history straight
                    // back to a provider — Anthropic refuses an `input` that is a list.
                    'arguments' => $block->arguments === [] ? new stdClass() : $block->arguments,
                    'thoughtSignature' => $block->thoughtSignature,
                    // Upstream writes the key only when the call has one (`...(item.namespace !==
                    // undefined ? { namespace } : {})`), so a call without one reads as before.
                ] + ($block->namespace === null ? [] : ['namespace' => $block->namespace]),
                default => null,
            };

            if ($encoded !== null) {
                $blocks[] = $encoded;
            }
        }

        return $blocks;
    }

    /**
     * Content blocks back from JSON.
     *
     * @param list<mixed> $blocks
     * @return list<mixed>
     */
    public static function decodeContent(array $blocks): array
    {
        $content = [];

        foreach ($blocks as $block) {
            $decoded = match ($block['type'] ?? null) {
                'text' => new TextContent((string) ($block['text'] ?? ''), is_string($block['textSignature'] ?? null) ? $block['textSignature'] : null),
                'thinking' => new ThinkingContent(
                    (string) ($block['thinking'] ?? ''),
                    $block['thinkingSignature'] ?? null,
                    isset($block['redacted']) ? (bool) $block['redacted'] : null,
                ),
                'image' => new ImageContent(
                    (string) ($block['data'] ?? ''),
                    (string) ($block['mimeType'] ?? ''),
                ),
                'toolCall' => new ToolCall(
                    (string) ($block['id'] ?? ''),
                    (string) ($block['name'] ?? ''),
                    (array) ($block['arguments'] ?? []),
                    $block['thoughtSignature'] ?? null,
                    is_string($block['namespace'] ?? null) ? $block['namespace'] : null,
                ),
                default => null,
            };

            if ($decoded !== null) {
                $content[] = $decoded;
            }
        }

        return $content;
    }

    /** @return array<string, mixed> */
    public static function encodeUsage(Usage $usage): array
    {
        return [
            'input' => $usage->input,
            'output' => $usage->output,
            'cacheRead' => $usage->cacheRead,
            'cacheWrite' => $usage->cacheWrite,
        ] + array_filter([
            // Upstream's optional splits, left out when the provider reported none.
            'cacheWrite1h' => $usage->cacheWrite1h,
            'reasoning' => $usage->reasoning,
        ], static fn (?int $value): bool => $value !== null) + [
            'totalTokens' => $usage->totalTokens,
            'cost' => [
                'input' => $usage->cost->input,
                'output' => $usage->cost->output,
                'cacheRead' => $usage->cost->cacheRead,
                'cacheWrite' => $usage->cost->cacheWrite,
                'total' => $usage->cost->total,
            ],
        ];
    }

    /** @param array<string, mixed> $usage */
    public static function decodeUsage(array $usage): Usage
    {
        $cost = (array) ($usage['cost'] ?? []);

        return new Usage(
            (int) ($usage['input'] ?? 0),
            (int) ($usage['output'] ?? 0),
            (int) ($usage['cacheRead'] ?? 0),
            (int) ($usage['cacheWrite'] ?? 0),
            (int) ($usage['totalTokens'] ?? 0),
            new Cost(
                (float) ($cost['input'] ?? 0),
                (float) ($cost['output'] ?? 0),
                (float) ($cost['cacheRead'] ?? 0),
                (float) ($cost['cacheWrite'] ?? 0),
                (float) ($cost['total'] ?? 0),
            ),
            isset($usage['reasoning']) ? (int) $usage['reasoning'] : null,
            isset($usage['cacheWrite1h']) ? (int) $usage['cacheWrite1h'] : null,
        );
    }

    /**
     * A tool's `details`, flattened to something JSON holds.
     *
     * Tools put whatever they like in there — an array for `edit`, a `Truncation` object for the
     * ones that truncate. Objects come back as arrays after a resume, because a file cannot hold a
     * PHP object; the one thing anything reads out of `details` is `edit`'s diff, which is strings
     * and integers and survives the trip exactly.
     */
    public static function plain(mixed $details): mixed
    {
        if (is_object($details)) {
            return self::plain(get_object_vars($details));
        }

        if (!is_array($details)) {
            return $details;
        }

        return array_map(self::plain(...), $details);
    }
}
