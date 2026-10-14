<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * A tool that says whether an interrupted call to it may be run again — `ToolReplay`.
 *
 * Optional, as pi-durable's `replay` field is: a tool that does not implement it is `Unsafe`. A
 * wrapper implements it and answers `ToolReplay::of()` of what it wraps, or the declaration is lost
 * one layer down. An extension's tool says it with `CustomTool`'s `replay` argument.
 */
interface ReplayableTool
{
    public function replay(): ToolReplay;
}
