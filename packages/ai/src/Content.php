<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A block inside a message.
 *
 * Upstream this is a TypeScript union discriminated on `type`. PHP interfaces are
 * nominal, so the union becomes this marker plus UserContent / AssistantContent,
 * which record where each block is actually allowed to appear.
 */
interface Content
{
}
