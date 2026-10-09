<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** Fired when pig is no longer waiting on a blocking user-facing extension UI prompt. */
final readonly class UiPromptEndEvent implements HookEvent
{
    public string $reason;

    /** @param 'select'|'confirm'|'input'|'editor'|'custom' $kind */
    public function __construct(
        public string $kind,
        public ?string $title = null,
    ) {
        $this->reason = 'ui_prompt';
    }

    public function type(): string
    {
        return 'ui_prompt_end';
    }
}
