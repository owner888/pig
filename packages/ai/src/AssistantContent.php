<?php

declare(strict_types=1);

namespace Pig\Ai;

/** A block an assistant message may carry: text, thinking, or a tool call. */
interface AssistantContent extends Content
{
}
