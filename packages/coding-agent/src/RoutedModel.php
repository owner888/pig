<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;

/**
 * A physical model a request went to — `ModelRouteRequest::$previous` and `$failed`.
 *
 * `$thinkingLevel` is the response's own `AssistantMessage::$thinkingLevel`, which the agent loop
 * stamps; null for a response written before it existed or made outside the loop.
 */
final readonly class RoutedModel
{
    /** @param AssistantMessage|null $message the failed response, for `$failed` only */
    public function __construct(
        public Model $model,
        public ?ThinkingLevel $thinkingLevel = null,
        public ?AssistantMessage $message = null,
    ) {
    }
}
