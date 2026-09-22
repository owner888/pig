<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Why the assistant stopped generating. */
enum StopReason: string
{
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
