<?php

declare(strict_types=1);

namespace Pig\CodingAgent\VirtualModels;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;

/** The router's answer for one request — upstream's `ModelRoute`. */
final readonly class ModelRoute
{
    /**
     * @param Model $model a physical catalogue model whose provider has credentials
     * @param ThinkingLevel $thinkingLevel clamped to what $model has
     * @param mixed $state new router state, written on the session branch unless it is the
     *        request's own state; null keeps the current one. JSON-serialisable. Ignored for
     *        `direct` requests
     */
    public function __construct(
        public Model $model,
        public ThinkingLevel $thinkingLevel,
        public mixed $state = null,
    ) {
    }
}
