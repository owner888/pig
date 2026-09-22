<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * A replacement for what a tool produced.
 *
 * Every field is optional and null means "leave it alone", which is why `isError` is a
 * nullable bool rather than a bool: `false` has to be able to mean "this was not an
 * error after all", and a plain `false` default would say that about every result.
 */
final readonly class ToolResultEventResult
{
    /** @param list<\Pig\Ai\UserContent>|null $content */
    public function __construct(
        public ?array $content = null,
        public mixed $details = null,
        public ?bool $isError = null,
    ) {
    }
}
