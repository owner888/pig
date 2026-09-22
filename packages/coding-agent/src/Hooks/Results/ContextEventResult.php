<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/** The conversation a `context` handler wants sent in place of the one it was given. */
final readonly class ContextEventResult
{
    /** @param list<mixed> $messages */
    public function __construct(public array $messages)
    {
    }
}
