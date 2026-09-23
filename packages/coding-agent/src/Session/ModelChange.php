<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * Somebody changed model, here, and the conversation carried on.
 *
 * A line in the session file that is not a message: the model never sees it, and it is not a
 * point in the conversation anyone could go back to. What it is for is resuming — without it,
 * `--continue` after an afternoon on opus comes back on whatever the settings say, which is
 * the wrong answer to "carry on where I left off".
 *
 * Recorded per session rather than only in the settings because those are two different
 * questions. The settings hold what to open a *new* conversation with; this holds what *this*
 * conversation was being had with, and resuming it should answer the second.
 *
 * Ported from upstream's `ModelChangeEntry`.
 */
final readonly class ModelChange
{
    public int $timestamp;

    public function __construct(
        public string $provider,
        public string $modelId,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
