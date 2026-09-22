<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/** Whether to go through with leaving this conversation. */
final readonly class SessionBeforeSwitchResult
{
    public function __construct(public bool $cancel = false)
    {
    }
}
