<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** Fired when pig starts waiting on a blocking user-facing extension UI prompt. */
final readonly class UiPromptStartEvent implements ExtensionEvent
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
        return 'ui_prompt_start';
    }
}
