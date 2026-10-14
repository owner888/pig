<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * Whether a tool call that a stopped process left without a result may be run again.
 *
 * pi-durable's `replay?: "safe" | "unsafe"` on a tool, omitted meaning `unsafe` — here a tool says
 * `Safe` by implementing `ReplayableTool`, and every other tool is `Unsafe`. Only a call that may have
 * been running when the process stopped is asked: `Safe` runs it again, `Unsafe` answers it with an
 * `interrupted` error result and leaves what to do to the model (`AgentLoop::resume()`).
 *
 * `Safe` is a claim about the tool, not the call: running it twice must do no more than running it
 * once — reading, listing, searching. A tool that writes, sends or runs a command is `Unsafe`, even
 * when a particular call would be harmless twice.
 */
enum ToolReplay: string
{
    case Safe = 'safe';
    case Unsafe = 'unsafe';

    /** What a tool declares, `Unsafe` when it declares nothing. */
    public static function of(object $tool): self
    {
        return $tool instanceof ReplayableTool ? $tool->replay() : self::Unsafe;
    }
}
