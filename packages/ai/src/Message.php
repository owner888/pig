<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * An entry in a conversation the LLM can be shown: a user turn, an assistant turn,
 * or a tool result.
 *
 * Upstream this is a union discriminated on `role`; here it is a marker interface and
 * the discrimination is `instanceof` / `match (true)`. The interface declares nothing,
 * because PHP 8.3 interfaces cannot require a property and a `timestamp()` getter next
 * to a public `$timestamp` would be one shape too many.
 */
interface Message
{
}
