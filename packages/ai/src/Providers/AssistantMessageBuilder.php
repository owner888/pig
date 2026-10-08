<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantContent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Timestamp;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\Utils\PartialJson;

/**
 * Accumulates a response as it streams, handing out an immutable snapshot per event.
 *
 * Upstream mutates one `output` object all the way through and pushes that same object
 * as every event's `partial`, so its partials all alias each other and the agent loop
 * shallow-copies to cope. AssistantMessage is readonly here, so each event carries a
 * real snapshot instead — more allocation per token, and no aliasing to reason about.
 *
 * Blocks are addressed by the provider's own index, which need not match their position
 * in the content list.
 *
 * @internal
 */
final class AssistantMessageBuilder
{
    /**
     * @var list<array{wire: int, type: string, text: string, signature: string,
     *      id: string, name: string, json: string, arguments: array<string, mixed>, redacted: bool}>
     */
    private array $blocks = [];

    private Usage $usage;

    private StopReason $stopReason = StopReason::Stop;

    private ?string $errorMessage = null;

    private ?string $rawStopReason = null;

    private ?string $responseId = null;

    private ?string $responseModel = null;

    /** @var list<AssistantMessageDiagnostic>|null */
    private ?array $diagnostics = null;

    private ?string $providerThinkingLevel = null;

    private readonly int $timestamp;

    public function __construct(private readonly Model $model)
    {
        $this->usage = new Usage();
        $this->timestamp = Timestamp::nowMs();
    }

    /** @return int the position in the content list */
    public function startText(int $wire): int
    {
        return $this->push($wire, 'text');
    }

    public function startThinking(int $wire): int
    {
        return $this->push($wire, 'thinking');
    }

    /**
     * A thinking block the provider withheld — Anthropic's `redacted_thinking`.
     *
     * Upstream's shape: the placeholder is the thinking, so whatever shows thinking shows that and
     * never the payload, and the encrypted payload is the signature, which is what goes back. It
     * arrives whole in the event that opens the block; no delta follows.
     */
    public function startRedactedThinking(int $wire, string $data): int
    {
        $index = $this->push($wire, 'thinking');
        $this->blocks[$index]['text'] = '[Reasoning redacted]';
        $this->blocks[$index]['signature'] = $data;
        $this->blocks[$index]['redacted'] = true;

        return $index;
    }

    public function startToolCall(int $wire, string $id, string $name): int
    {
        return $this->push($wire, 'toolCall', id: $id, name: $name);
    }

    /**
     * The next unused wire number.
     *
     * Anthropic numbers its blocks and this echoes those numbers back. OpenAI does not
     * number anything, so its provider asks for one — the number is then only ever an
     * internal handle, which is what it always was for everything but the lookup.
     */
    public function nextWire(): int
    {
        return count($this->blocks);
    }

    /** @return int|null null when the provider names a block it never opened */
    public function indexOf(int $wire): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['wire'] === $wire) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The first block of this type, if any.
     *
     * For a provider that keeps something for the whole message on one block — chat completions'
     * `reasoning_details`, which upstream stores on the message's thinking block.
     */
    public function firstIndexOf(string $type): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['type'] === $type) {
                return $index;
            }
        }

        return null;
    }

    public function append(int $index, string $field, string $delta): void
    {
        $this->blocks[$index][$field] .= $delta;

        if ($field === 'json') {
            // Reparsed here rather than in snapshot(), so an unrelated text delta does
            // not re-run the repair over every tool call in the message.
            $this->blocks[$index]['arguments'] = PartialJson::parse($this->blocks[$index]['json']);
        }
    }

    /** Text and thinking both accumulate here; only the block's type tells them apart. */
    public function textOf(int $index): string
    {
        return $this->blocks[$index]['text'];
    }

    /**
     * Replace a block's signature, rather than adding to it.
     *
     * Anthropic streams a thinking signature in pieces, which is why `append()` exists.
     * OpenAI's responses API hands over the whole reasoning item at the end instead, and
     * appending that to the deltas would produce a signature that is neither.
     */
    public function setSignature(int $index, string $signature): void
    {
        $this->blocks[$index]['signature'] = $signature;
    }

    /**
     * Replace a text block's text, rather than adding to it.
     *
     * The same shape as `setSignature()`: OpenAI's responses API streams a message as deltas and
     * then hands over the finished item, whose parts are the text upstream keeps.
     */
    public function setText(int $index, string $text): void
    {
        $this->blocks[$index]['text'] = $text;
    }

    /**
     * Replace a tool call's arguments with the authoritative JSON, rather than adding to it.
     *
     * The same shape as `setSignature()` and for the same reason: OpenAI's responses API streams
     * the arguments as deltas *and* repeats them whole on `response.output_item.done`. Appending
     * the whole to the pieces would give `{"a":1}{"a":1}`, so the finished item replaces what was
     * accumulated — and a stream that sent no deltas at all, which a compatible endpoint may do,
     * ends up with the arguments it did send instead of none.
     */
    public function setJson(int $index, string $json): void
    {
        $this->blocks[$index]['json'] = $json;
        $this->blocks[$index]['arguments'] = PartialJson::parse($json);
    }

    /**
     * Fill in a tool call's id and name after it was opened.
     *
     * Anthropic names a tool call in the event that opens it. OpenAI does not have to:
     * the id and the name arrive in whichever delta they arrive in, and a stream that
     * sends the name second would otherwise call a tool with no name.
     */
    public function setToolCall(int $index, string $id, string $name): void
    {
        if ($id !== '') {
            $this->blocks[$index]['id'] = $id;
        }

        if ($name !== '') {
            $this->blocks[$index]['name'] = $name;
        }
    }

    public function toolCallOf(int $index): ToolCall
    {
        return self::call($this->blocks[$index]);
    }

    /**
     * One tool call, signature included.
     *
     * **The signature was being dropped here**, in the one place that builds the object, while
     * three others handled it: Google's provider reads a `thoughtSignature` off the part and
     * `setSignature()` stores it, `GoogleShared::messages()` writes it back out, and
     * `TransformMessages` carries it across. `ToolCall::thoughtSignature` was therefore always null
     * for a call that came from a stream, so Gemini never got its own thought context back with the
     * call it came out of — which is what that field is for, and what Gemini 3 expects.
     *
     * @param array{type: string, text: string, signature: string, id: string, name: string, arguments: array<string, mixed>, json: string, wire: int} $block
     */
    private static function call(array $block): ToolCall
    {
        return new ToolCall(
            $block['id'],
            $block['name'],
            $block['arguments'],
            $block['signature'] === '' ? null : $block['signature'],
        );
    }

    /**
     * Only a provider that reported no total gets one worked out for it.
     *
     * This used to add the parts up for every provider, which is Anthropic's rule — it reports the
     * components and no total — applied to the three that do report one. For Anthropic and
     * OpenAI's completions the sum is the same number either way, so it looked harmless. **For
     * Google it is not:** `promptTokenCount` *includes* the cached tokens, so prompt + output +
     * cacheRead counts them twice, and `totalTokenCount` — the figure Google sends, which does not
     * — was thrown away. A conversation with most of its prompt cached therefore reported a
     * context far fuller than it was, and `Compaction::contextTokens()` believes that figure: it
     * compacted early, which costs a summarisation and shortens the window it was trying to save.
     *
     * Upstream computes the total in exactly the two providers where the API gives none, and reads
     * it in the two where it does. That is the same rule as this line.
     *
     * @param bool $priced the cost came with the usage, so it is kept rather than worked out from
     *        the model's price list. **False for every provider** — none of them reports a cost, so
     *        the list price is the only figure there is. True for `Agent\StreamProxy`, whose
     *        gateway made the call and knows what it actually paid; pricing that from the public
     *        list reports a number nobody was charged, which is what upstream avoids by assigning
     *        the usage whole.
     */
    public function setUsage(Usage $usage, bool $priced = false): void
    {
        $counted = $usage->totalTokens > 0 ? $usage : $usage->withTotalTokens();

        $this->usage = $priced ? $counted : $counted->withCost($this->model);
    }

    public function setStopReason(StopReason $reason): void
    {
        $this->stopReason = $reason;
    }

    public function stopReason(): StopReason
    {
        return $this->stopReason;
    }

    /**
     * The provider's own stop reason, unmapped — upstream's `output.rawStopReason`.
     *
     * Set separately from `setStopReason()` because the two do not always move together: Google's
     * `STOP` with a tool call is pig's `toolUse`, and its `MALFORMED_FUNCTION_CALL` ends up as an
     * error through `fail()`, which leaves this alone so the failed message still says why.
     */
    public function setRawStopReason(?string $reason): void
    {
        $this->rawStopReason = $reason;
    }

    public function rawStopReason(): ?string
    {
        return $this->rawStopReason;
    }

    /** Upstream's `output.responseId`: the provider's id for this response. */
    public function setResponseId(?string $id): void
    {
        $this->responseId = $id;
    }

    public function responseId(): ?string
    {
        return $this->responseId;
    }

    /** Upstream's `output.responseModel`; the provider decides when it differs, not this. */
    public function setResponseModel(?string $model): void
    {
        $this->responseModel = $model;
    }

    public function responseModel(): ?string
    {
        return $this->responseModel;
    }

    /** Upstream's `output.providerThinkingLevel`: the native effort this response was asked for. */
    public function setProviderThinkingLevel(?string $level): void
    {
        $this->providerThinkingLevel = $level;
    }

    /** Upstream's `appendAssistantMessageDiagnostic()`. */
    public function addDiagnostic(AssistantMessageDiagnostic $diagnostic): void
    {
        $this->diagnostics = [...($this->diagnostics ?? []), $diagnostic];
    }

    public function fail(string $message, bool $aborted): void
    {
        $this->stopReason = $aborted ? StopReason::Aborted : StopReason::Error;
        $this->errorMessage = $message;
    }

    public function snapshot(): AssistantMessage
    {
        return new AssistantMessage(
            array_map($this->toContent(...), $this->blocks),
            $this->model->api,
            $this->model->provider,
            $this->model->id,
            $this->usage,
            $this->stopReason,
            $this->errorMessage,
            $this->timestamp,
            $this->rawStopReason,
            $this->responseId,
            $this->responseModel,
            null,
            $this->diagnostics,
            $this->providerThinkingLevel,
        );
    }

    /** @param array{type: string, text: string, signature: string, id: string, name: string, arguments: array<string, mixed>, redacted: bool} $block */
    private function toContent(array $block): AssistantContent
    {
        return match ($block['type']) {
            'thinking' => new ThinkingContent(
                $block['text'],
                $block['signature'] === '' ? null : $block['signature'],
                $block['redacted'] ? true : null,
            ),
            'toolCall' => self::call($block),
            // Text carries a signature too on the responses API: the message's own id,
            // which has to go back with it or the turn is a different message.
            default => new TextContent($block['text'], $block['signature'] === '' ? null : $block['signature']),
        };
    }

    private function push(int $wire, string $type, string $id = '', string $name = ''): int
    {
        $this->blocks[] = [
            'wire' => $wire,
            'type' => $type,
            'text' => '',
            'signature' => '',
            'id' => $id,
            'name' => $name,
            'json' => '',
            'arguments' => [],
            'redacted' => false,
        ];

        return count($this->blocks) - 1;
    }
}
