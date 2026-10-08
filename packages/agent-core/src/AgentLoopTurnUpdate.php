<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Model;

/**
 * Replacement runtime state the loop uses before starting another provider request — upstream's
 * `AgentLoopTurnUpdate`, what `AgentLoopConfig::$prepareNextTurn` answers.
 */
final readonly class AgentLoopTurnUpdate
{
    /**
     * @param AgentContext|null $context  context for the next provider request
     * @param list<mixed>|null  $messages messages to append before the next provider request, with
     *        normal lifecycle events
     * @param Model|null        $model    model for the next provider request
     * @param ThinkingLevel|null $thinkingLevel thinking level for the next provider request
     */
    public function __construct(
        public ?AgentContext $context = null,
        public ?array $messages = null,
        public ?Model $model = null,
        public ?ThinkingLevel $thinkingLevel = null,
    ) {
    }
}
