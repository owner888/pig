<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A turn from the model: text, reasoning, and tool calls, plus what it cost.
 *
 * Also the shape a stream carries while it is still filling up — the `partial` on
 * every event is one of these, and the same class is the final result.
 */
final readonly class AssistantMessage implements Message
{
    public int $timestamp;

    /**
     * @param list<AssistantContent> $content
     * @param string                 $provider anthropic, openai, groq, … — free-form,
     *        since providers can be added without touching this package
     */
    public function __construct(
        public array $content,
        public Api $api,
        public string $provider,
        public string $model,
        public Usage $usage,
        public StopReason $stopReason,
        public ?string $errorMessage = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** @return list<ToolCall> */
    public function toolCalls(): array
    {
        return array_values(array_filter(
            $this->content,
            static fn (AssistantContent $block): bool => $block instanceof ToolCall,
        ));
    }
}
