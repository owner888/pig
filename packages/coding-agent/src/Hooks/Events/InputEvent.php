<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\ImageContent;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Something the person (or a host, or an extension) is about to send. Upstream's `input`.
 *
 * Fired after a hook's slash command has had its chance and **before** a stored prompt is
 * expanded, so a handler sees `/review foo.php` and not forty lines of template. Handlers are
 * chained: each sees the text the one before it transformed. `InputEventResult::handled()`
 * stops it there and nothing is sent.
 *
 * `$source` is `interactive`, `rpc` or `extension`; `$streamingBehavior` is `steer` or
 * `followUp` when the agent is working and the text is being queued, and null when it is idle.
 */
final readonly class InputEvent implements HookEvent
{
    /**
     * @param list<ImageContent>         $images
     * @param 'interactive'|'rpc'|'extension' $source
     * @param 'steer'|'followUp'|null    $streamingBehavior
     */
    public function __construct(
        public string $text,
        public array $images = [],
        public string $source = 'interactive',
        public ?string $streamingBehavior = null,
    ) {
    }

    public function type(): string
    {
        return 'input';
    }
}
