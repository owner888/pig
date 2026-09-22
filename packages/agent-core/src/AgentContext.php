<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * The conversation as the agent sees it, before anything is shown to a model.
 *
 * Distinct from `Pig\Ai\Context`: that one holds only what the LLM understands, and the
 * loop produces it from this one on every turn, via `convertToLlm`.
 *
 * @phpstan-import-type Message from \Pig\Ai\Context
 * @phpstan-type Entry Message|AgentMessage
 */
final class AgentContext
{
    /**
     * Mutable, unlike everything else here: the loop appends to it as the run proceeds,
     * exactly as upstream does.
     *
     * @param list<Entry>            $messages
     * @param list<AgentTool>        $tools
     */
    public function __construct(
        public array $messages = [],
        public string $systemPrompt = '',
        public array $tools = [],
    ) {
    }

    public function append(mixed $message): void
    {
        $this->messages[] = $message;
    }

    public function toolNamed(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->definition()->name === $name) {
                return $tool;
            }
        }

        return null;
    }
}
