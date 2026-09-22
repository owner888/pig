<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * One step of an assistant response arriving.
 *
 * Upstream this is a twelve-arm union discriminated on `type`; here each arm is its own
 * class named after its wire string, so `match (true)` over them is exhaustive and the
 * payload of each arm is typed instead of optional.
 *
 * The sequence is: StartEvent, then per content block a *StartEvent, any number of
 * *DeltaEvent, and a *EndEvent, then exactly one DoneEvent or ErrorEvent.
 */
interface AssistantMessageEvent
{
}
