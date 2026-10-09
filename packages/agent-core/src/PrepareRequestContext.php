<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Model;

/**
 * What a provider request is about to be made with — upstream's `PrepareRequestContext`, handed
 * to `AgentLoopConfig::$prepareRequest` immediately before every request, the first included.
 * Pending messages have already been appended and emitted when it runs.
 */
final readonly class PrepareRequestContext
{
    public function __construct(
        public AgentContext $context,
        public Model $model,
        public ThinkingLevel $thinkingLevel,
    ) {
    }
}
