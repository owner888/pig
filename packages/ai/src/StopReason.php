<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Why the assistant stopped generating. */
enum StopReason: string
{
    /**
     * Upstream's `"pending"`: the turn has not said why it stopped, because it has not stopped yet.
     * What a provider that starts its output this way carries until the stream's own stop reason
     * arrives — `Anthropic` and `OpenAiResponses`, as upstream's do — so a stream that ends without
     * one is told apart from one that ended cleanly, and fails instead of passing for a `stop`.
     * Never on a finished message: those providers throw on it, which `fail()` turns into `error`.
     */
    case Pending = 'pending';
    case Stop = 'stop';
    case Length = 'length';
    case ToolUse = 'toolUse';
    case Error = 'error';
    case Aborted = 'aborted';

    /** Error and Aborted end the agent loop; the rest let it continue. */
    public function isFailure(): bool
    {
        return $this === self::Error || $this === self::Aborted;
    }
}
