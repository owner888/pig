<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\ShortHash;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Utf8;

/**
 * What the three Responses-shaped APIs share — upstream's `api/openai-responses-shared.ts`:
 * `convertResponsesMessages()`, `convertResponsesTools()` and `processResponsesStream()`.
 *
 * `OpenAiResponses` (`openai-responses`), `AzureOpenAiResponses` (`azure-openai-responses`) and
 * `OpenAiCodexResponses` (`openai-codex-responses`) differ in how the request is addressed and
 * authenticated and in a few body fields; the conversation they send and the stream they read are
 * this file. It used to live inside `OpenAiResponses`, when that was the only one of the three.
 *
 * Two things here have no counterpart in the other providers:
 *
 * - **A reasoning item is sent back whole.** What arrives as text is a *summary*; the reasoning
 *   itself is encrypted and opaque, and the model wants its own item back verbatim on the next turn
 *   or it re-reasons from nothing. So the whole item is kept as the thinking block's signature and
 *   replayed as it came.
 * - **A tool call has two ids.** `call_id` is what a result is addressed to, `id` is the item's own.
 *   Both have to go back, so they travel joined as `call_id|id` and are split again on the way out.
 */
final class OpenAiResponsesShared
{
    /** OpenAI rejects an id over this, and its own ids can arrive longer. */
    private const int MAX_ID_LENGTH = 64;

    // ---- message conversion ----------------------------------------------------------------

    /**
     * Upstream's `convertResponsesMessages(model, context, allowedToolCallProviders, options)`.
     *
     * `$options` is upstream's `ConvertResponsesMessagesOptions`:
     *
     * - `includeSystemPrompt` (default true) — false leaves the leading system message out, for the
     *   Codex backend, which takes it as `instructions`;
     * - `grammarToolInputProperties` — tool name => the property a grammar tool's raw input lives in;
     * - `supportsMidConvoSystemMessages` — "Whether later system messages are sent in place;
     *   otherwise they are folded into the leading prompt";
     * - `supportsAdditionalTools`, `supportsToolSearch` — how a later system message's tools load;
     * - `toolOptions` — what `convertResponsesTools()` is given for those tools.
     *
     * @param list<string> $allowedToolCallProviders upstream's `OPENAI_TOOL_CALL_PROVIDERS` and its
     *        Azure and Codex copies: the providers whose `call_id|item_id` pair is kept as a pair
     * @param array{includeSystemPrompt?: bool, grammarToolInputProperties?: array<string, string>, supportsMidConvoSystemMessages?: bool, supportsAdditionalTools?: bool, supportsToolSearch?: bool, toolOptions?: array<string, mixed>} $options
     * @return list<array<string, mixed>>
     */
    public static function convertResponsesMessages(Model $model, TranscriptContext $context, array $allowedToolCallProviders, array $options = []): array
    {
        $items = [];
        $grammar = $options['grammarToolInputProperties'] ?? [];
        $context = Transcript::resolveTranscript($context, $options['supportsMidConvoSystemMessages'] ?? false);
        $supportsAdditionalTools = $options['supportsAdditionalTools'] ?? false;
        $supportsToolSearch = $options['supportsToolSearch'] ?? false;
        $toolOptions = $options['toolOptions'] ?? [];
        $transcriptTools = Transcript::resolveTranscriptTools($context->messages, $supportsAdditionalTools || $supportsToolSearch);
        $includeInitialSystemMessage = $options['includeSystemPrompt'] ?? true;
        // Upstream's `instructionRole`: `model.reasoning && compat?.supportsDeveloperRole !== false ?
        // "developer" : "system"` — a model whose compat says it has no developer role gets
        // `system`, which an OpenAI-compatible Responses endpoint may be the only one of.
        $compat = $model->compat instanceof OpenAiCompat ? $model->compat : null;
        $instructionRole = $model->reasoning && $compat?->developerRole !== false ? 'developer' : 'system';

        // Upstream's `appendSystemToolAdditions()`: the tools a later system message adds, loaded
        // where it stands — as an `additional_tools` item where the model takes those, or else as a
        // completed client-executed tool search, a `tool_search_call` and the `tool_search_output`
        // that answers it, whose tools are marked `defer_loading`. Nothing when the transcript does
        // not anchor additions (a removal or a redefinition), since `tools` is the current set then.
        $appendSystemToolAdditions = static function (SystemMessage $message, string $seed) use (&$items, $transcriptTools, $supportsAdditionalTools, $supportsToolSearch, $toolOptions): void {
            $tools = $transcriptTools['anchorsAdditions'] ? ($message->toolsAdded ?? []) : [];

            if ($tools === []) {
                return;
            }

            if ($supportsAdditionalTools) {
                $items[] = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => self::convertResponsesTools($tools, $toolOptions)];

                return;
            }

            if (!$supportsToolSearch) {
                return;
            }

            $names = array_map(static fn (Tool $tool): string => $tool->name, $tools);
            $callId = 'pi_tool_load_' . ShortHash::of("{$seed}:" . implode(',', $names));
            $items[] = [
                'type' => 'tool_search_call',
                'call_id' => $callId,
                'execution' => 'client',
                'status' => 'completed',
                'arguments' => ['query' => implode(' ', $names), 'limit' => count($names)],
            ];
            $items[] = [
                'type' => 'tool_search_output',
                'call_id' => $callId,
                'execution' => 'client',
                'status' => 'completed',
                'tools' => self::convertResponsesTools($tools, [...$toolOptions, 'toolSearchResult' => true]),
            ];
        };

        // Upstream's `msgIndex`. It counts the messages that went out, not the messages that came
        // in: upstream's `continue` for a user turn with no content and an assistant turn with no
        // output skips its `msgIndex++`, so neither moves the count, and the leading system message
        // does not count either. A later system message does.
        $msgIndex = 0;
        $sourceIndex = 0;
        $normalizeToolCallId = static fn (string $id, Model $target, AssistantMessage $source): string
            => self::normalizeToolCallId($id, $model, $source, $allowedToolCallProviders);

        foreach (TransformMessages::apply($context->messages, $model, $normalizeToolCallId) as $message) {
            $isLeadingSystemMessage = $sourceIndex++ === 0 && $message instanceof SystemMessage;

            if ($message instanceof SystemMessage) {
                if (!$isLeadingSystemMessage) {
                    $appendSystemToolAdditions($message, "system:{$msgIndex}");
                }

                // `if (!isLeadingSystemMessage || includeInitialSystemMessage)`.
                if (!$isLeadingSystemMessage || $includeInitialSystemMessage) {
                    $text = $isLeadingSystemMessage ? Text::getSystemMessageText($message) : Text::renderSystemMessageUpdate($message);

                    if ($text !== '') {
                        $items[] = ['role' => $instructionRole, 'content' => Utf8::sanitize($text)];
                    }
                }

                if (!$isLeadingSystemMessage) {
                    $msgIndex++;
                }

                continue;
            }

            $converted = self::convert($message, $model, $msgIndex, $grammar);

            foreach ($converted as $item) {
                $items[] = $item;
            }

            // Upstream tests the user content *before* conversion (`content.length === 0`) and the
            // assistant output after it, so these are the two tests, each where upstream has it.
            $skipped = ($message instanceof UserMessage && $message->content === [])
                || ($message instanceof AssistantMessage && $converted === []);

            if (!$skipped) {
                $msgIndex++;
            }
        }

        return $items;
    }

    /**
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private static function convert(mixed $message, Model $model, int $msgIndex, array $grammar = []): array
    {
        return match (true) {
            $message instanceof UserMessage => self::user($message, $model),
            $message instanceof AssistantMessage => self::assistant($message, $model, $msgIndex, $grammar),
            $message instanceof ToolResultMessage => self::toolResult($message, $model, $grammar),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function user(UserMessage $message, Model $model): array
    {
        $parts = self::parts($message->content, $model);

        return $parts === [] ? [] : [['role' => 'user', 'content' => $parts]];
    }

    /**
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function parts(array $content, Model $model): array
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = ['type' => 'input_text', 'text' => Utf8::sanitize($block->text)];

                continue;
            }

            if ($block instanceof ImageContent && $model->acceptsImages()) {
                $parts[] = [
                    'type' => 'input_image',
                    'detail' => 'auto',
                    'image_url' => "data:{$block->mimeType};base64,{$block->data}",
                ];
            }
        }

        return $parts;
    }

    /**
     * Upstream's assistant arm of `convertResponsesMessages()`.
     *
     * A turn that errored or was aborted never reaches here — `TransformMessages` drops it whole,
     * calls and all — so nothing in this arm asks how the turn ended.
     *
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private static function assistant(AssistantMessage $message, Model $model, int $msgIndex, array $grammar = []): array
    {
        $items = [];
        $textBlockIndex = 0;

        // Upstream's `isDifferentModel`: this provider and API, another model; and `isSameModel`,
        // the only case a call's `namespace` goes back.
        $differentModel = $message->provider === $model->provider
            && $message->api === $model->api
            && $message->model !== $model->id;
        $sameModel = $message->provider === $model->provider
            && $message->api === $model->api
            && $message->model === $model->id;

        foreach ($message->content as $block) {
            if ($block instanceof ThinkingContent) {
                // `JSON.parse(block.thinkingSignature)`, pushed as it parses: a signature that is not
                // JSON fails the request with V8's message, where pig used to drop it in silence.
                if ($block->thinkingSignature !== null && $block->thinkingSignature !== '') {
                    $items[] = JsJson::parse($block->thinkingSignature);
                }

                continue;
            }

            if ($block instanceof TextContent) {
                $parsedSignature = self::parseTextSignature($block->textSignature);
                $fallbackMessageId = $textBlockIndex === 0 ? "msg_pi_{$msgIndex}" : "msg_pi_{$msgIndex}_{$textBlockIndex}";
                $textBlockIndex++;

                // Upstream's comment: OpenAI requires id to be max 64 characters.
                $msgId = $parsedSignature['id'] ?? null;

                if ($msgId === null || $msgId === '') {
                    $msgId = $fallbackMessageId;
                } elseif (strlen($msgId) > self::MAX_ID_LENGTH) {
                    $msgId = 'msg_' . ShortHash::of($msgId);
                }

                $items[] = [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => Utf8::sanitize($block->text), 'annotations' => []]],
                    'status' => 'completed',
                    'id' => $msgId,
                    // Upstream's `phase: parsedSignature?.phase`, which is `undefined` — and so not
                    // in the JSON at all — unless the signature carried one.
                    ...(isset($parsedSignature['phase']) ? ['phase' => $parsedSignature['phase']] : []),
                ];

                continue;
            }

            if ($block instanceof ToolCall) {
                [$callId, $itemId] = self::splitIds($block->id);
                $customInputProperty = $grammar[$block->name] ?? null;

                // Upstream's comment: OpenAI tracks which item ids were paired with an `rs_…`
                // reasoning item, and another model's reasoning is not sent back — so for a
                // different model the id is left out, which avoids that pairing check the way a
                // foreign call does. "Also drop ids that do not match the replayed item type:
                // function_call ids must be fc_* and custom_tool_call ids must be ctc_*. Foreign tool
                // call ids are normalized to fc_*, and a call can switch between the two types when
                // grammar tool support differs."
                $itemIdPrefix = $customInputProperty === null ? 'fc_' : 'ctc_';

                if ($differentModel || $itemId === null || !str_starts_with($itemId, $itemIdPrefix)) {
                    $itemId = null;
                }

                if ($customInputProperty !== null) {
                    $items[] = [
                        'type' => 'custom_tool_call',
                        ...($itemId === null ? [] : ['id' => $itemId]),
                        'call_id' => $callId,
                        'name' => $block->name,
                        'input' => Utf8::sanitize(ConstrainedSampling::getGrammarToolInput($block->name, $block->arguments, $customInputProperty)),
                        ...($sameModel && $block->namespace !== null ? ['namespace' => $block->namespace] : []),
                    ];

                    continue;
                }

                $items[] = [
                    'type' => 'function_call',
                    // Left out for a call this API never issued — see `splitIds()`.
                    ...($itemId === null ? [] : ['id' => $itemId]),
                    'call_id' => $callId,
                    'name' => $block->name,
                    // `{}` and not `[]` — see the same line in `OpenAiCompletions`.
                    'arguments' => $block->arguments === [] ? '{}' : self::encode($block->arguments),
                    ...($sameModel && $block->namespace !== null ? ['namespace' => $block->namespace] : []),
                ];
            }
        }

        return $items;
    }

    /**
     * A grammar tool's result goes back as a `custom_tool_call_output`, the rest as a
     * `function_call_output` — upstream's `grammarToolInputProperties.has(msg.toolName)`.
     *
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private static function toolResult(ToolResultMessage $message, Model $model, array $grammar = []): array
    {
        [$callId] = self::splitIds($message->toolCallId);

        $text = [];
        $images = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            } elseif ($block instanceof ImageContent) {
                $images[] = $block;
            }
        }

        $joined = implode("\n", $text);

        return [[
            'type' => isset($grammar[$message->toolName]) ? 'custom_tool_call_output' : 'function_call_output',
            'call_id' => $callId,
            'output' => self::toolResultOutput($joined, $images, $model),
        ]];
    }

    /**
     * Upstream's `convertToolResultOutput()`.
     *
     * With no image, or a model that takes none, the output is a string: the joined text, else
     * `(see attached image)` when there were images, else `(no tool output)` — `hasText` being the
     * joined text non-empty. Otherwise it is a **content list inside the `function_call_output`
     * itself**: an `input_text` when there is text, then one `input_image` per image, `detail:
     * "auto"`, as a data URL. pig used to send the text alone there and the images after it as a
     * separate user turn ("Attached image(s) from tool result:"), which put a user message between a
     * call's output and the next turn and showed the model the image as something a person said.
     *
     * @param list<ImageContent> $images
     * @return string|list<array<string, string>>
     */
    private static function toolResultOutput(string $joined, array $images, Model $model): string|array
    {
        if ($images === [] || !$model->acceptsImages()) {
            return Utf8::sanitize($joined !== '' ? $joined : ($images !== [] ? '(see attached image)' : '(no tool output)'));
        }

        $output = [];

        if ($joined !== '') {
            $output[] = ['type' => 'input_text', 'text' => Utf8::sanitize($joined)];
        }

        foreach ($images as $image) {
            $output[] = [
                'type' => 'input_image',
                'detail' => 'auto',
                'image_url' => "data:{$image->mimeType};base64,{$image->data}",
            ];
        }

        return $output;
    }

    /**
     * Upstream's `normalizeToolCallId` in `convertResponsesMessages()`: another model's call id,
     * made into one this API takes. Handed to `TransformMessages`, so this model's own ids never
     * pass through it, and a result follows its call.
     *
     * Each part is sanitised to `[a-zA-Z0-9_-]`, cut to 64 and stripped of trailing `_`. Only for
     * the providers in `$allowedToolCallProviders` is a `call_id|item_id` pair kept as a pair — and
     * then a call from another provider or API gets an item id of `fc_` and a hash, since the one it
     * carries was minted elsewhere, and any item id is made to start `fc_`. For every other
     * provider, Copilot included, the whole id is sanitised as one, so its `|` becomes `_` and
     * no item id is sent.
     *
     * @param list<string> $allowedToolCallProviders
     */
    private static function normalizeToolCallId(string $id, Model $model, AssistantMessage $source, array $allowedToolCallProviders): string
    {
        if (!in_array($model->provider, $allowedToolCallProviders, true) || !str_contains($id, '|')) {
            return self::normalizeIdPart($id);
        }

        // `id.split("|")` destructured into two: a third part, if any, is dropped.
        [$callId, $itemId] = explode('|', $id, 3);
        $normalizedCallId = self::normalizeIdPart($callId);
        $isForeignToolCall = $source->provider !== $model->provider || $source->api !== $model->api;
        $normalizedItemId = $isForeignToolCall ? self::foreignItemId($itemId) : self::normalizeIdPart($itemId);

        // OpenAI Responses API requires item id to start with "fc" (upstream's comment).
        if (!str_starts_with($normalizedItemId, 'fc_')) {
            $normalizedItemId = self::normalizeIdPart("fc_{$normalizedItemId}");
        }

        return "{$normalizedCallId}|{$normalizedItemId}";
    }

    /** Upstream's `normalizeIdPart()`. */
    private static function normalizeIdPart(string $part): string
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $part);

        return rtrim(substr($sanitized, 0, self::MAX_ID_LENGTH), '_');
    }

    /** Upstream's `buildForeignResponsesItemId()`: `fc_` and upstream's `shortHash()`, cut to 64. */
    private static function foreignItemId(string $itemId): string
    {
        return substr('fc_' . ShortHash::of($itemId), 0, self::MAX_ID_LENGTH);
    }

    /**
     * The call id, and the item's own id when this call came from here.
     *
     * **A call from another provider has one id, and the item id is then null rather than a copy
     * of it** — because OpenAI validates the *shape* of `id`: it must begin with `fc`. Reusing the
     * call id gets `400 Invalid 'input[1].id': 'toolu_…'. Expected an ID that begins with 'fc'`,
     * which is a whole conversation refused, and it is what pig did until a live run said so.
     *
     * Upstream writes `id: toolCall.id.split("|")[1]`, and for an id with no `|` that is
     * **`undefined`**, which `JSON.stringify` leaves out of the object entirely. So upstream sends
     * no `id` at all here and OpenAI accepts the item. PHP has no `undefined` and no index that
     * evaluates to a missing field, so the omission has to be deliberate.
     *
     * Which conversations this reaches: a dangling call `TransformMessages` invented a result for,
     * and any history carried over from another provider — `/model` from Anthropic to gpt-5 with a
     * tool call in it, which is the case `TransformMessages` exists to make possible.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitIds(string $id): array
    {
        $at = strpos($id, '|');

        return $at === false ? [$id, null] : [substr($id, 0, $at), substr($id, $at + 1)];
    }

    /**
     * Upstream's `parseTextSignature()`: a `TextSignatureV1` JSON, or the plain id string that
     * sessions written before it hold.
     *
     * Null for no signature. A JSON with `v: 1` and a string `id` gives that id, and its phase
     * only when it is `commentary` or `final_answer`; anything else — including text that starts
     * with `{` and is not that JSON — is a legacy plain id, whole.
     *
     * @return array{id: string, phase?: string}|null
     */
    private static function parseTextSignature(?string $signature): ?array
    {
        if ($signature === null || $signature === '') {
            return null;
        }

        if (str_starts_with($signature, '{')) {
            $parsed = json_decode($signature, true);

            // `parsed.v === 1`: a JSON number, which PHP may decode as int or float.
            if (is_array($parsed)
                && (is_int($parsed['v'] ?? null) || is_float($parsed['v'] ?? null)) && $parsed['v'] == 1
                && is_string($parsed['id'] ?? null)
            ) {
                $phase = $parsed['phase'] ?? null;

                if ($phase === 'commentary' || $phase === 'final_answer') {
                    return ['id' => $parsed['id'], 'phase' => $phase];
                }

                return ['id' => $parsed['id']];
            }
        }

        return ['id' => $signature];
    }

    /**
     * Upstream's `encodeTextSignatureV1()`: a finished message item's id and phase, as the JSON
     * `TextSignatureV1` (`{"v":1,"id":…,"phase":…}`) that `parseTextSignature()` reads back.
     *
     * `phase` is written only when it is truthy, as upstream's `if (phase)`. An item with no `id`
     * gives `{"v":1}`, which is what `JSON.stringify` makes of `id: undefined`.
     *
     * @param array<string, mixed> $item
     */
    private static function encodeTextSignatureV1(array $item): string
    {
        $payload = ['v' => 1];

        if (array_key_exists('id', $item)) {
            $payload['id'] = $item['id'];
        }

        $phase = $item['phase'] ?? null;

        if ($phase !== null && $phase !== '' && $phase !== false) {
            $payload['phase'] = $phase;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    // ---- tool conversion -------------------------------------------------------------------

    /**
     * Upstream's `convertResponsesTools(tools, options)`. A grammar tool the endpoint takes goes out
     * as an OpenAI custom tool, `{type: "custom", name, description, format: {type: "grammar",
     * syntax, definition}}`; every other tool is a function tool. A tool loaded by tool search
     * (`toolSearchResult`) is marked `defer_loading: true`, as upstream marks it.
     *
     * `$options` is upstream's `ConvertResponsesToolsOptions`:
     *
     * - `strict` — the default a tool's own strictness falls back to: **absent is `false`, and a
     *   present `null` is `null`** (`options?.strict === undefined ? false : options.strict`), which
     *   is what the Codex backend is sent (`"strict": null`);
     * - `supportsStrictMode` (default true) — whether `strict` is written at all;
     * - `supportsOpenAIGrammarTools` (default false);
     * - `toolSearchResult`.
     *
     * `strict = resolveJsonSchemaStrictSampling(tool, supportsStrictMode) ?? defaultStrict`, and the
     * strict schema only when that is `true`.
     *
     * @param list<Tool> $tools
     * @param array{strict?: bool|null, supportsStrictMode?: bool, supportsOpenAIGrammarTools?: bool, toolSearchResult?: bool} $options
     * @return list<array<string, mixed>>
     */
    public static function convertResponsesTools(array $tools, array $options = []): array
    {
        $defaultStrict = array_key_exists('strict', $options) ? $options['strict'] : false;
        $supportsStrictMode = $options['supportsStrictMode'] ?? true;
        $supportsGrammarTools = $options['supportsOpenAIGrammarTools'] ?? false;
        $toolSearchResult = $options['toolSearchResult'] ?? false;

        return array_map(static function (Tool $tool) use ($defaultStrict, $supportsStrictMode, $supportsGrammarTools, $toolSearchResult): array {
            $grammar = ConstrainedSampling::resolveGrammarConstrainedSampling($tool, $supportsGrammarTools);

            if ($grammar !== null) {
                return [
                    'type' => 'custom',
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'format' => [
                        'type' => 'grammar',
                        'syntax' => $grammar['format'],
                        'definition' => $grammar['definition'],
                    ],
                    ...($toolSearchResult ? ['defer_loading' => true] : []),
                ];
            }

            $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, $supportsStrictMode) ?? $defaultStrict;
            $function = [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict === true),
                ...($toolSearchResult ? ['defer_loading' => true] : []),
            ];

            if ($supportsStrictMode) {
                $function['strict'] = $strict;
            }

            return $function;
        }, $tools);
    }

    // ---- stream processing -----------------------------------------------------------------

    /**
     * Upstream's `processResponsesStream(openaiStream, output, stream, model, options)`: every event,
     * routed to the item it is about, then the two refusals a stream that ended badly earns.
     *
     * `$events` is the decoded events in order — what the `openai` SDK's `Stream` yields for
     * `openai-responses` and Azure, and what `mapCodexEvents()` yields for the Codex backend.
     *
     * `$options` is upstream's `OpenAIResponsesStreamOptions`:
     *
     * - `onProviderStreamEvent` — each event, before it is read;
     * - `serviceTier` — the request's, which `resolveServiceTier` (or `response.service_tier ??`
     *   it) turns into the tier the cost is scaled by;
     * - `grammarToolInputProperties`;
     * - `resolveServiceTier`, `applyServiceTierPricing` — the API's own tier rules. Without
     *   `applyServiceTierPricing` no tier is applied.
     *
     * @param iterable<array<string, mixed>> $events
     * @param array{onProviderStreamEvent?: Closure|null, serviceTier?: string|null, grammarToolInputProperties?: array<string, string>, resolveServiceTier?: (Closure(?string, ?string): ?string)|null, applyServiceTierPricing?: (Closure(AssistantMessageBuilder, ?string): void)|null} $options
     */
    public static function processResponsesStream(
        iterable $events,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        Model $model,
        array $options = [],
    ): void {
        $grammar = $options['grammarToolInputProperties'] ?? [];
        // Upstream's `outputSlots`: the items still streaming, by `output_index` — see `dispatch()`.
        $slots = [];
        // Upstream's per-call scratch buffers (`partialJson`, `customInput`), which only a finished
        // `output_item.done` removes: the content indexes of the tool calls still waiting for theirs.
        $unfinished = [];
        // Upstream's `reasoningBlocksById`: a finished reasoning item's id => its thinking block's
        // content index, for `backfillReasoningSignatures()`.
        $reasoningById = [];
        // Upstream's `sawTerminalResponseEvent`: `response.completed`, `.incomplete` or `.failed`.
        $sawTerminal = false;

        foreach ($events as $data) {
            if (($options['onProviderStreamEvent'] ?? null) !== null) {
                ($options['onProviderStreamEvent'])($data, $model);
            }

            self::dispatch($data, $builder, $stream, $model, $slots, $grammar, $unfinished, $reasoningById, $sawTerminal, $options);
        }

        // Upstream: a body that ended with no `response.completed`, `.incomplete` or `.failed` is a
        // cut connection, not an answer — it used to come back as a clean `stop` with whatever
        // half-message had streamed and no usage.
        if (!$sawTerminal) {
            throw new ProviderError('OpenAI Responses stream ended before a terminal response event');
        }

        // "The agent runs every tool call in the final message. Refuse to hand over calls whose
        // output_item.done never arrived: their arguments may be cut off or mixed up, e.g. when a
        // non-compliant server omits output_index."
        if ($builder->stopReason() === StopReason::ToolUse) {
            foreach (array_keys($unfinished) as $index) {
                $call = $builder->toolCallOf($index);

                throw new ProviderError("OpenAI Responses stream completed with an unfinished tool call: {$call->name} ({$call->id})");
            }
        }
    }

    /** JavaScript truthiness for a decoded JSON value: an object or array is truthy even when empty. */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }

    /**
     * A decoded JSON value as a JavaScript template literal writes it (`${value}`): a missing one is
     * `undefined`, null `null`, a list its elements joined by commas, an object `[object Object]`.
     *
     * @param array<string, mixed> $data
     */
    public static function template(array $data, string $key): string
    {
        if (!array_key_exists($key, $data)) {
            return 'undefined';
        }

        $value = $data[$key];

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e21 ? (string) (int) $value : (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(
                static fn (mixed $item): string => $item === null ? '' : self::template(['v' => $item], 'v'),
                $value,
            )),
            default => '[object Object]',
        };
    }

    /**
     * Upstream's `processResponsesStream()` loop body: one event, routed to the item it is about by
     * its `output_index`.
     *
     * **A slot per `output_index`, as upstream keeps `outputSlots`.** The Responses API may stream
     * several items at once — a reasoning item and a message, or two function calls — and every
     * delta names the item it belongs to. pig used to keep one open item and route every delta to
     * it, so a delta for the other item was dropped or appended to the wrong block, and an
     * `output_item.done` closed whichever item happened to be open. A slot is `[content index, kind]`,
     * and for a custom (grammar) tool call a third entry: its input property and upstream's
     * `GrammarToolInputJsonBuffer` — see `openedCustomCall()`. A missing `output_index` is
     * upstream's `undefined` key, which every such event shares.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, array{0: int, 1: string, 2?: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}> $slots
     * @param array<string, string> $grammar
     * @param array<int, true> $unfinished the tool calls opened and not yet finished, by content index
     * @param array<string, int> $reasoningById a finished reasoning item's id => its content index
     * @param array<string, mixed> $options see `processResponsesStream()`
     */
    private static function dispatch(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        Model $model,
        array &$slots,
        array $grammar,
        array &$unfinished,
        array &$reasoningById,
        bool &$sawTerminal,
        array $options,
    ): void {
        $key = self::outputIndex($data);

        match ($data['type'] ?? '') {
            // Upstream takes the response id from here and again from the terminal event.
            'response.created' => self::onCreated($data, $builder),
            'response.output_item.added' => self::createSlot($key, is_array($data['item'] ?? null) ? $data['item'] : [], $builder, $stream, $slots, $grammar, $unfinished),
            'response.reasoning_summary_text.delta' => self::onDelta($data, $builder, $stream, $slots[$key] ?? null, 'thinking'),
            // One summary part ending and the next beginning is a paragraph break, and
            // nothing else in the stream says so.
            'response.reasoning_summary_part.done' => self::onBreak($builder, $stream, $slots[$key] ?? null),
            // Raw reasoning text (models that expose it rather than a summary) streams as a
            // thinking delta too, as upstream's `response.reasoning_text.delta` arm does.
            'response.reasoning_text.delta' => self::onDelta($data, $builder, $stream, $slots[$key] ?? null, 'thinking'),
            'response.output_text.delta', 'response.refusal.delta' => self::onDelta($data, $builder, $stream, $slots[$key] ?? null, 'text'),
            'response.function_call_arguments.delta' => self::onArguments($data, $builder, $stream, $slots[$key] ?? null),
            'response.function_call_arguments.done' => self::onArgumentsDone($data, $builder, $stream, $slots[$key] ?? null),
            // Upstream's two custom-tool-call input events: the raw text a grammar tool writes,
            // streamed, then whole.
            'response.custom_tool_call_input.delta' => self::onCustomInput($data, $builder, $stream, $slots, $key, false),
            'response.custom_tool_call_input.done' => self::onCustomInput($data, $builder, $stream, $slots, $key, true),
            'response.output_item.done' => self::onItemEnd($key, $data, $builder, $stream, $slots, $grammar, $unfinished, $reasoningById),
            // **Both terminal events, and `response.incomplete` is the one that was missing.** It
            // is what the Responses API sends when the answer was cut off — `max_output_tokens`
            // reached, most often — and without it here the stream simply ended: the status was
            // never read and **the usage on that event was never taken**. A turn truncated by the
            // output cap therefore came back as a clean `stop` carrying **no usage at all**.
            // Measured against the real API with a 16-token budget: `stop` after 0 output tokens.
            // Upstream's `finalizeResponse()` takes both.
            'response.completed', 'response.incomplete' => self::finalizeResponse($data, $builder, $model, $reasoningById, $sawTerminal, $options),
            // `throw new Error(`Error Code ${event.code}: ${event.message}` || "Unknown error")` — the
            // template is never empty, so the fallback never applies and a missing field reads
            // `undefined`. Only a flat event without an `event: error` line gets here; see `ErrorBody::openAiStreamEvent()`.
            // It has no response, so the raw stop reason stays as it was, as upstream's does.
            'error' => throw new ProviderError('Error Code ' . self::template($data, 'code') . ': ' . self::template($data, 'message')),
            'response.failed' => self::failed($data, $builder, $sawTerminal),
            default => null,
        };
    }

    /**
     * The event's `output_index`, or upstream's `undefined` key for an event that has none.
     *
     * @param array<string, mixed> $data
     */
    private static function outputIndex(array $data): int|string
    {
        $index = $data['output_index'] ?? null;

        return is_int($index) ? $index : 'undefined';
    }

    /**
     * Upstream's `createSlot()`: a block for the item, its start event, and the slot under the
     * item's `output_index`. A tool call is unfinished until its `output_item.done`. An item of a
     * kind that has no block (a web search, say) gets no slot.
     *
     * @param array<string, mixed> $item
     * @param array<int|string, array<int, mixed>> $slots
     * @param array<string, string> $grammar
     * @param array<int, true> $unfinished
     * @return array{0: int, 1: string, 2?: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}|null
     */
    private static function createSlot(
        int|string $key,
        array $item,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        array &$slots,
        array $grammar,
        array &$unfinished,
    ): ?array {
        $wire = $builder->nextWire();

        $slot = match ($item['type'] ?? '') {
            'reasoning' => self::opened('thinking', $builder->startThinking($wire), $stream, $builder),
            // Upstream's `createSlot()` runs `applyMessagePhaseStopReason(item)` for a message.
            'message' => self::openedMessage($item, $builder, $stream, $wire),
            'function_call' => self::openedCall($item, $builder, $stream, $wire),
            'custom_tool_call' => self::openedCustomCall($item, $builder, $stream, $wire, $grammar),
            default => null,
        };

        if ($slot === null) {
            return null;
        }

        if ($slot[1] === 'toolCall') {
            $unfinished[$slot[0]] = true;
        }

        return $slots[$key] = $slot;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0: int, 1: string}
     */
    private static function openedMessage(array $item, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $wire): array
    {
        self::applyMessagePhaseStopReason($item, $builder);

        return self::opened('text', $builder->startText($wire), $stream, $builder);
    }

    /**
     * Upstream's `custom_tool_call` arm of `createSlot()`: a grammar tool's call, whose input is one
     * raw string rather than JSON. pig keeps it as an ordinary tool call whose arguments are
     * `{<property>: <input>}` — the property the tool's schema requires, `input` for a tool this
     * request did not send as a grammar tool — and streams it as the JSON deltas of that object
     * (`ConstrainedSampling::appendGrammarToolInputJsonDelta()`), so nothing downstream needs to know.
     *
     * @param array<string, mixed> $item
     * @param array<string, string> $grammar
     * @return array{0: int, 1: string, 2: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}
     */
    private static function openedCustomCall(array $item, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $wire, array $grammar): array
    {
        $name = (string) ($item['name'] ?? '');
        $property = $grammar[$name] ?? 'input';
        $index = $builder->startToolCall(
            $wire,
            self::joinIds((string) ($item['call_id'] ?? ''), (string) ($item['id'] ?? '')),
            $name,
        );

        // `arguments: { [inputProperty]: item.input || "" }`, with the JSON buffer still empty: the
        // input already on the item goes out with the first delta.
        $input = $item['input'] ?? null;
        $builder->setJson($index, self::customArguments($property, is_string($input) ? $input : ''));
        // `...(item.namespace !== undefined ? { namespace: item.namespace } : {})`.
        $builder->setNamespace($index, is_string($item['namespace'] ?? null) ? $item['namespace'] : null);
        $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));

        return [$index, 'toolCall', ['property' => $property, 'buffer' => ConstrainedSampling::newGrammarToolInputJsonBuffer()]];
    }

    /**
     * Upstream's `response.custom_tool_call_input.delta` and `.done` arms: the input so far plus the
     * delta, or the whole input, run through the JSON buffer, with a delta event when it says
     * anything. Only for a tool-call slot that is a custom call (`slot.block.customInput`).
     *
     * The slot is changed in place: its JSON buffer carries over to the next event.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, array<int, mixed>> $slots
     */
    private static function onCustomInput(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array &$slots, int|string $key, bool $done): void
    {
        $slot = $slots[$key] ?? null;

        if ($slot === null || $slot[1] !== 'toolCall' || !isset($slot[2])) {
            return;
        }

        $next = $done
            ? (string) ($data['input'] ?? '')
            : self::customInput($builder, $slot) . (string) ($data['delta'] ?? '');

        self::appendCustomInput($builder, $stream, $slots[$key], $next, $done);
    }

    /**
     * Upstream's `appendCustomToolCallInput()` plus `pushToolCallDelta()`.
     *
     * @param array{0: int, 1: string, 2: array{property: string, buffer: array{input: string, started: bool, closed: bool}}} $slot
     */
    private static function appendCustomInput(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array &$slot, string $next, bool $close): void
    {
        $delta = ConstrainedSampling::appendGrammarToolInputJsonDelta($slot[2]['buffer'], $slot[2]['property'], $next, $close);
        $builder->setJson($slot[0], self::customArguments($slot[2]['property'], $next));

        if ($delta !== null) {
            $stream->push(new ToolCallDeltaEvent($slot[0], $delta, $builder->snapshot()));
        }
    }

    /**
     * Upstream's `getCustomToolCallInput()`: the input so far, read back from the arguments.
     *
     * @param array{0: int, 1: string, 2: array{property: string, buffer: array{input: string, started: bool, closed: bool}}} $slot
     */
    private static function customInput(AssistantMessageBuilder $builder, array $slot): string
    {
        $value = $builder->toolCallOf($slot[0])->arguments[$slot[2]['property']] ?? null;

        return is_string($value) ? $value : '';
    }

    /** `{ [property]: input }` as the JSON the builder keeps a call's arguments in. */
    private static function customArguments(string $property, string $input): string
    {
        $object = new \stdClass();
        $object->{$property} = $input;

        return (string) json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array{0: int, 1: string} */
    private static function opened(string $kind, int $index, AssistantMessageEventStream $stream, AssistantMessageBuilder $builder): array
    {
        $stream->push($kind === 'thinking'
            ? new ThinkingStartEvent($index, $builder->snapshot())
            : new TextStartEvent($index, $builder->snapshot()));

        return [$index, $kind];
    }

    /**
     * Upstream's `function_call` arm of `createSlot()`: `partialJson: item.arguments || ""` — the
     * arguments can arrive complete on the opening item rather than as deltas.
     *
     * @param array<string, mixed> $item
     * @return array{0: int, 1: string}
     */
    private static function openedCall(array $item, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $wire): array
    {
        $index = $builder->startToolCall(
            $wire,
            self::joinIds((string) ($item['call_id'] ?? ''), (string) ($item['id'] ?? '')),
            (string) ($item['name'] ?? ''),
        );

        // `arguments: {}, partialJson: item.arguments || ""`: what the item already carries is the
        // start of the JSON, read with the first delta — not parsed here, so the call opens with
        // `{}` as upstream's does. pig used to parse it on the spot.
        $arguments = $item['arguments'] ?? null;
        $builder->seedJson($index, is_string($arguments) ? $arguments : '');

        // Upstream's `namespace` for a dynamically loaded or namespaced tool, when the item has one.
        $builder->setNamespace($index, is_string($item['namespace'] ?? null) ? $item['namespace'] : null);

        $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));

        return [$index, 'toolCall'];
    }

    /**
     * A text or thinking delta for the slot it names. **An empty delta is still a delta**:
     * upstream appends `event.delta` and pushes the event whatever it holds, and pig used to drop
     * an empty one, so a listener counting deltas saw fewer than upstream's.
     *
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $slot
     */
    private static function onDelta(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $slot,
        string $kind,
    ): void {
        $delta = $data['delta'] ?? null;

        if ($slot === null || $slot[1] !== $kind || !is_string($delta)) {
            return;
        }

        $builder->append($slot[0], 'text', $delta);
        $stream->push($kind === 'thinking'
            ? new ThinkingDeltaEvent($slot[0], $delta, $builder->snapshot())
            : new TextDeltaEvent($slot[0], $delta, $builder->snapshot()));
    }

    /** @param array{0: int, 1: string}|null $slot */
    private static function onBreak(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $slot): void
    {
        if ($slot === null || $slot[1] !== 'thinking') {
            return;
        }

        $builder->append($slot[0], 'text', "\n\n");
        $stream->push(new ThinkingDeltaEvent($slot[0], "\n\n", $builder->snapshot()));
    }

    /**
     * Upstream's `response.function_call_arguments.delta` arm: a function-call slot (one with a JSON
     * buffer, so not a custom call) takes the delta, and the event goes out even when the delta is
     * empty — `pushToolCallDelta()` skips only an undefined one.
     *
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string, 2?: mixed}|null $slot
     */
    private static function onArguments(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $slot,
    ): void {
        $delta = $data['delta'] ?? null;

        if ($slot === null || $slot[1] !== 'toolCall' || isset($slot[2]) || !is_string($delta)) {
            return;
        }

        $builder->append($slot[0], 'json', $delta);
        $stream->push(new ToolCallDeltaEvent($slot[0], $delta, $builder->snapshot()));
    }

    /**
     * Upstream's `response.function_call_arguments.done` arm: the event's whole `arguments` replace
     * what the deltas built, and when they extend it the missing tail goes out as one more delta —
     * so a stream that dropped or never sent deltas still ends with the full arguments, and a
     * listener rebuilding them from deltas gets the same text. Not for a custom (grammar) call,
     * which has no JSON buffer upstream (`partialJson === undefined`).
     *
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string, 2?: mixed}|null $slot
     */
    private static function onArgumentsDone(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $slot): void
    {
        if ($slot === null || $slot[1] !== 'toolCall' || isset($slot[2])) {
            return;
        }

        $arguments = is_string($data['arguments'] ?? null) ? $data['arguments'] : '';
        $previous = $builder->jsonOf($slot[0]);
        $builder->setJson($slot[0], $arguments);

        if (str_starts_with($arguments, $previous)) {
            $delta = substr($arguments, strlen($previous));

            if ($delta !== '') {
                $stream->push(new ToolCallDeltaEvent($slot[0], $delta, $builder->snapshot()));
            }
        }
    }

    /**
     * Upstream's `response.output_item.done` arm: the item's slot — **created now when no
     * `output_item.added` came for it** (`getOrCreateSlot()`), so an endpoint that sends only the
     * finished item still produces its block — finished from the item, and removed. The item's
     * type and the slot's kind must agree; when they do not, nothing happens and the slot stays.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, array<int, mixed>> $slots
     * @param array<string, string> $grammar
     * @param array<int, true> $unfinished
     * @param array<string, int> $reasoningById
     */
    private static function onItemEnd(
        int|string $key,
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        array &$slots,
        array $grammar,
        array &$unfinished,
        array &$reasoningById,
    ): void {
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $type = $item['type'] ?? null;

        self::applyMessagePhaseStopReason($item, $builder);

        $slot = $slots[$key] ?? self::createSlot($key, $item, $builder, $stream, $slots, $grammar, $unfinished);

        if ($slot === null) {
            return;
        }

        [$index, $kind] = $slot;

        if ($type === 'reasoning' && $kind === 'thinking') {
            // Upstream rebuilds the thinking text from the finished item:
            // `summaryText = item.summary?.map((s) => s.text).join("\n\n") || ""`, the same for
            // `item.content`, then `summaryText || contentText || slot.block.thinking`. The
            // summary wins, the raw reasoning content is next, and only an item carrying
            // neither keeps what the deltas built.
            $text = self::joinReasoningTexts($item['summary'] ?? null);

            if ($text === '') {
                $text = self::joinReasoningTexts($item['content'] ?? null);
            }

            if ($text !== '') {
                $builder->setText($index, $text);
            }

            // The whole item, kept verbatim: the summary is what a person reads, and the
            // model wants its own encrypted reasoning back or it starts over.
            $builder->setSignature($index, (string) json_encode($item));

            if (is_string($item['id'] ?? null)) {
                $reasoningById[$item['id']] = $index;
            }

            $stream->push(new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()));
            unset($slots[$key]);

            return;
        }

        if ($type === 'message' && $kind === 'text') {
            // Upstream rebuilds the text from the finished item rather than trusting the deltas:
            // `item.content?.map((c) => (c.type === "output_text" ? c.text : c.refusal)).join("") || ""`.
            // Every `output_text` part and every `refusal` part, in order, joined with nothing —
            // the refusal is text the person must see. **The streamed text is discarded**, even
            // when the item carries no content at all, which upstream turns into "".
            $text = '';

            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                $piece = !is_array($part) ? null
                    : (($part['type'] ?? null) === 'output_text' ? ($part['text'] ?? null) : ($part['refusal'] ?? null));
                // JS `join()` writes undefined and null as ''.
                $text .= is_string($piece) ? $piece : '';
            }

            $builder->setText($index, $text);

            // Upstream's `encodeTextSignatureV1(item.id, item.phase ?? undefined)`: the id *and* the
            // phase, so `assistant()` can send the phase back on the same message next turn.
            $builder->setSignature($index, self::encodeTextSignatureV1($item));
            $stream->push(new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()));
            unset($slots[$key]);

            return;
        }

        if ($type === 'function_call' && $kind === 'toolCall' && !isset($slot[2])) {
            // `parseStreamingJson(item.arguments || slot.block.partialJson || "{}")`: the finished
            // item's own arguments are the call — a stream that sent no deltas, which this API
            // allows and a compatible endpoint does, otherwise leaves it with none. The id and the
            // name are the ones the slot was opened with; upstream does not read them again here.
            // Always reparsed: the JSON seeded when the slot opened has not been read yet when no
            // delta followed it.
            $arguments = $item['arguments'] ?? null;
            $builder->setJson($index, is_string($arguments) && $arguments !== '' ? $arguments : $builder->jsonOf($index));

            // `if (item.namespace !== undefined) slot.block.namespace = item.namespace`, and the call
            // is finished — upstream deletes its scratch buffer here.
            if (is_string($item['namespace'] ?? null)) {
                $builder->setNamespace($index, $item['namespace']);
            }

            unset($unfinished[$index]);
            $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));
            unset($slots[$key]);

            return;
        }

        if ($type === 'custom_tool_call' && $kind === 'toolCall' && isset($slot[2])) {
            // Upstream's `custom_tool_call` arm: the item's own input closes the JSON (`item.input ??`
            // the input so far), then the call ends like any other.
            $input = $item['input'] ?? null;
            self::appendCustomInput($builder, $stream, $slot, is_string($input) ? $input : self::customInput($builder, $slot), true);

            if (is_string($item['namespace'] ?? null)) {
                $builder->setNamespace($index, $item['namespace']);
            }

            unset($unfinished[$index]);
            $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));
            unset($slots[$key]);
        }
    }

    /** @param array<string, mixed> $data */
    private static function onCreated(array $data, AssistantMessageBuilder $builder): void
    {
        $id = $data['response']['id'] ?? null;

        if (is_string($id)) {
            $builder->setResponseId($id);
        }
    }

    /**
     * Upstream's `finalizeResponse(response)`: the id, the usage priced (`calculateCost()`, which
     * `setUsage()` does), the service tier's scaling, the raw and mapped stop reasons.
     *
     * @param array<string, mixed> $data
     * @param array<string, int> $reasoningById
     * @param array<string, mixed> $options see `processResponsesStream()`
     */
    private static function finalizeResponse(array $data, AssistantMessageBuilder $builder, Model $model, array $reasoningById, bool &$sawTerminal, array $options): void
    {
        $sawTerminal = true;
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        self::backfillReasoningSignatures($builder, is_array($response['output'] ?? null) ? $response['output'] : [], $reasoningById);

        if (is_string($response['id'] ?? null) && $response['id'] !== '') {
            $builder->setResponseId($response['id']);
        }

        if (is_array($response['usage'] ?? null)) {
            $builder->setUsage(self::usage($response['usage']));
        }

        // `if (options?.applyServiceTierPricing) { serviceTier = options.resolveServiceTier ?
        // options.resolveServiceTier(response?.service_tier, options.serviceTier) :
        // (response?.service_tier ?? options.serviceTier); options.applyServiceTierPricing(...) }`.
        $apply = $options['applyServiceTierPricing'] ?? null;

        if ($apply !== null) {
            $responseTier = is_string($response['service_tier'] ?? null) ? $response['service_tier'] : null;
            $requestTier = $options['serviceTier'] ?? null;
            $resolve = $options['resolveServiceTier'] ?? null;
            $apply($builder, $resolve !== null ? $resolve($responseTier, $requestTier) : ($responseTier ?? $requestTier));
        }

        $status = (string) ($response['status'] ?? '');
        $incomplete = is_array($response['incomplete_details'] ?? null) ? ($response['incomplete_details']['reason'] ?? null) : null;

        // Upstream's `rawStopReason`: the status, and for a cut-off answer why it was cut off —
        // `incomplete.max_output_tokens` or `incomplete.content_filter`, which the mapped `length`
        // cannot tell apart.
        $builder->setRawStopReason(is_string($incomplete) && $incomplete !== '' ? "{$status}.{$incomplete}" : ($status === '' ? null : $status));

        [$reason, $errorMessage] = self::mapStopReason($status, is_string($incomplete) && $incomplete !== '' ? $incomplete : null);
        // `if (mappedStop.errorMessage === undefined) delete output.errorMessage; else …`.
        $builder->setErrorMessage($errorMessage);

        // A response that made tool calls is finished with the turn, not with the task,
        // and the status alone does not say which.
        if ($reason === StopReason::Stop && self::hasToolCall($builder->snapshot())) {
            $reason = StopReason::ToolUse;
        }

        $builder->setStopReason($reason);
    }

    /**
     * Upstream's `backfillReasoningSignatures()`: "Azure OpenAI can omit reasoning.encrypted_content
     * from response.output_item.done and provide it only in response.completed.response.output.
     * Backfill the persisted reasoning signature from the terminal response to keep store:false
     * multi-turn replay stateless." A block that has no signature, or whose stored item already
     * carries the encrypted content, is left alone.
     *
     * @param list<mixed> $output the terminal response's `output`
     * @param array<string, int> $reasoningById
     */
    private static function backfillReasoningSignatures(AssistantMessageBuilder $builder, array $output, array $reasoningById): void
    {
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'reasoning' || !self::truthy($item['encrypted_content'] ?? null)) {
                continue;
            }

            $index = is_string($item['id'] ?? null) ? ($reasoningById[$item['id']] ?? null) : null;
            $signature = $index === null ? '' : $builder->signatureOf($index);

            if ($signature === '') {
                continue;
            }

            $stored = json_decode($signature, true);

            if (!is_array($stored) || self::truthy($stored['encrypted_content'] ?? null)) {
                continue;
            }

            $builder->setSignature($index, (string) json_encode([...$stored, 'encrypted_content' => $item['encrypted_content']]));
        }
    }

    /**
     * Upstream's `applyMessagePhaseStopReason(item)`: a message item marked `final_answer` sets
     * `stop`. The terminal event's own mapping overwrites it afterwards (`finalizeResponse()`), so
     * the net effect is nil whenever the stream ends properly.
     *
     * @param array<string, mixed> $item
     */
    private static function applyMessagePhaseStopReason(array $item, AssistantMessageBuilder $builder): void
    {
        if (($item['type'] ?? null) === 'message' && ($item['phase'] ?? null) === 'final_answer') {
            $builder->setStopReason(StopReason::Stop);
        }
    }

    /**
     * Upstream's `response.failed` arm: the response's status as the raw stop reason, then
     * `${error.code || "unknown"}: ${error.message || "no message"}` when it has an error,
     * `incomplete: <reason>` when it has only `incomplete_details`, else
     * `Unknown error (no error details in response)`.
     *
     * @param array<string, mixed> $data
     */
    private static function failed(array $data, AssistantMessageBuilder $builder, bool &$sawTerminal): never
    {
        $sawTerminal = true;
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $builder->setRawStopReason(is_string($response['status'] ?? null) ? $response['status'] : null);
        $error = $response['error'] ?? null;
        $reason = is_array($response['incomplete_details'] ?? null) ? ($response['incomplete_details']['reason'] ?? null) : null;

        if (self::truthy($error)) {
            $error = is_array($error) ? $error : [];
            $message = (self::truthy($error['code'] ?? null) ? self::template($error, 'code') : 'unknown')
                . ': '
                . (self::truthy($error['message'] ?? null) ? self::template($error, 'message') : 'no message');
        } elseif (self::truthy($reason)) {
            $message = 'incomplete: ' . self::template(['reason' => $reason], 'reason');
        } else {
            $message = 'Unknown error (no error details in response)';
        }

        throw new ProviderError($message);
    }

    private static function hasToolCall(AssistantMessage $message): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $usage */
    private static function usage(array $usage): Usage
    {
        $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['input_tokens_details']['cache_write_tokens'] ?? 0);

        // Upstream: "OpenAI includes cached and cache-write tokens in input_tokens, so subtract
        // both." Reasoning is a part of `output_tokens` already, and kept beside it — 0 when not
        // reported, as upstream does.
        return new Usage(
            max(0, (int) ($usage['input_tokens'] ?? 0) - $cached - $cacheWrite),
            (int) ($usage['output_tokens'] ?? 0),
            $cached,
            $cacheWrite,
            (int) ($usage['total_tokens'] ?? 0),
            reasoning: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
        );
    }

    /**
     * Upstream's `mapStopReason(status, incompleteReason)`: **only `max_output_tokens` is `length`.**
     * Any other `incomplete` — `content_filter`, say — is an error, `Response incomplete: <reason>`,
     * or `Response incomplete without a provider reason` when there is none; pig used to read every
     * one of them as a cut-off answer. No status at all is `stop`.
     *
     * @return array{0: StopReason, 1: string|null}
     */
    private static function mapStopReason(string $status, ?string $incompleteReason = null): array
    {
        return match ($status) {
            'completed', '' => [StopReason::Stop, null],
            'incomplete' => $incompleteReason === 'max_output_tokens'
                ? [StopReason::Length, null]
                : [StopReason::Error, $incompleteReason !== null
                    ? "Response incomplete: {$incompleteReason}"
                    : 'Response incomplete without a provider reason'],
            'failed', 'cancelled' => [StopReason::Error, null],
            // "These two are wonky ..."
            'in_progress', 'queued' => [StopReason::Stop, null],
            default => throw new ProviderError("Unhandled stop reason: {$status}"),
        };
    }

    /**
     * Upstream's `parts?.map((p) => p.text).join("\n\n") || ""` for a reasoning item's
     * `summary` or `content` list. JS `join()` writes undefined and null as "", so a part with
     * no string text still contributes its separator.
     */
    private static function joinReasoningTexts(mixed $parts): string
    {
        if (!is_array($parts)) {
            return '';
        }

        $texts = [];

        foreach ($parts as $part) {
            $piece = is_array($part) ? ($part['text'] ?? null) : null;
            $texts[] = is_string($piece) ? $piece : '';
        }

        return implode("\n\n", $texts);
    }

    /** The two ids a tool call has, as one that pig can carry. */
    private static function joinIds(string $callId, string $itemId): string
    {
        return $callId . '|' . $itemId;
    }
}
