<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\Model;
use Pig\CodingAgent\Hooks\HookEvent;

/** The session's model changed — `/model`, ctrl+p, a resume, or a hook's own `setModel()`. Upstream's `model_select`. */
final readonly class ModelSelectEvent implements HookEvent
{
    public function __construct(public Model $model, public ?Model $previous = null)
    {
    }

    public function type(): string
    {
        return 'model_select';
    }
}
