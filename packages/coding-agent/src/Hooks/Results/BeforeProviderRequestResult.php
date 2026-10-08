<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * The payload to send instead. Upstream's handler returns it directly ("handlerResult !==
 * undefined"); pig's handlers answer with a result object, as every pig hook does.
 */
final readonly class BeforeProviderRequestResult
{
    public function __construct(public mixed $payload)
    {
    }
}
