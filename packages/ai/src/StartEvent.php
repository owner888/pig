<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The response has opened. `$partial` already carries the model, provider and api;
 * its content is still empty.
 */
final readonly class StartEvent implements AssistantMessageEvent
{
    public function __construct(
        public AssistantMessage $partial,
    ) {
    }
}
