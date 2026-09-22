<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

/**
 * Something that happened, which a hook may have asked to hear about.
 *
 * Upstream's events are a discriminated union on a `type` string. PHP has no unions, so
 * the tag becomes a method and the union becomes this interface: `type()` is still the
 * name a hook subscribes with, and `instanceof` does what narrowing on `type` did there.
 */
interface HookEvent
{
    /** The name a hook passes to `$pi->on()` to receive this. */
    public function type(): string;
}
