<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A turn from the model: text, reasoning, and tool calls, plus what it cost.
 *
 * Also the shape a stream carries while it is still filling up — the `partial` on
 * every event is one of these, and the same class is the final result.
 *
 * One arm of the Message union — see Context for the alias.
 */
final readonly class AssistantMessage
{
    public int $timestamp;

    /**
     * @param list<AssistantContent> $content
     * @param string                 $provider anthropic, openai, groq, … — free-form,
     *        since providers can be added without touching this package
     * @param string|null            $rawStopReason the provider's own word for why it stopped
     *        (`end_turn`, `length`, `incomplete.max_output_tokens`, `MALFORMED_FUNCTION_CALL`, …),
     *        kept beside the mapped `stopReason` for debugging. Last so the positional calls that
     *        predate it keep working; null when the provider sent none.
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
        public ?string $rawStopReason = null,
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
