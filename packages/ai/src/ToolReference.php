<?php

declare(strict_types=1);

namespace Pig\Ai;

/** A tool named without its definition — upstream's `ToolReference`, what `SystemMessage::$toolsRemoved` lists. */
final readonly class ToolReference
{
    public function __construct(public string $name)
    {
    }
}
