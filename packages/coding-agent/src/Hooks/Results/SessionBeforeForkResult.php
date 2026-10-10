<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/** Whether to go through with forking this conversation. */
final readonly class SessionBeforeForkResult
{
    public function __construct(public bool $cancel = false)
    {
    }
}
