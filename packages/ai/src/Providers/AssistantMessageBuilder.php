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

    public function toolCallOf(int $index): ToolCall
    {
        $block = $this->blocks[$index];

        return new ToolCall($block['id'], $block['name'], $block['arguments']);
    }

    public function setUsage(Usage $usage): void
    {
        $this->usage = $usage->withTotalTokens()->withCost($this->model);
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
            default => new TextContent($block['text']),
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
