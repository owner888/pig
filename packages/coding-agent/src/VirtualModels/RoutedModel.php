<?php

declare(strict_types=1);

namespace Pig\CodingAgent\VirtualModels;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;

/**
 * A physical model a request went to — `ModelRouteRequest::$previous` and `$failed`.
 *
 * `$thinkingLevel` is null until pig's assistant messages record the level they were asked
 * with, which upstream's do (`AssistantMessage.thinkingLevel`) and pig's do not yet.
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
