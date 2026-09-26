<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantContent;
use Pig\Ai\AssistantMessage;
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
     *      id: string, name: string, json: string, arguments: array<string, mixed>}>
     */
    private array $blocks = [];

    private Usage $usage;

    private StopReason $stopReason = StopReason::Stop;

    private ?string $errorMessage = null;

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
        $block = $this->blocks[$index];

        return new ToolCall($block['id'], $block['name'], $block['arguments']);
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
     */
    public function setUsage(Usage $usage): void
    {
        $counted = $usage->totalTokens > 0 ? $usage : $usage->withTotalTokens();

        $this->usage = $counted->withCost($this->model);
    }

    public function setStopReason(StopReason $reason): void
    {
        $this->stopReason = $reason;
    }

    public function stopReason(): StopReason
    {
        return $this->stopReason;
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
        );
    }

    /** @param array{type: string, text: string, signature: string, id: string, name: string, arguments: array<string, mixed>} $block */
    private function toContent(array $block): AssistantContent
    {
        return match ($block['type']) {
            'thinking' => new ThinkingContent(
                $block['text'],
                $block['signature'] === '' ? null : $block['signature'],
            ),
            'toolCall' => new ToolCall($block['id'], $block['name'], $block['arguments']),
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
        ];

        return count($this->blocks) - 1;
    }
}
