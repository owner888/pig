<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;
use Pig\Ai\Usage;

/**
 * Something a model was paid for that is not part of the conversation — upstream's `UsageEntry`.
 *
 * What the cache warmer writes after each refresh (`kind: "cache_warm"`), and what pi writes for
 * the same reason, so a pi session opened here costs what it cost. Not said: the model never sees
 * it and `/tree` does not offer it. Counted: `AgentSession::stats()` adds it to the bill.
 */
final readonly class UsageEntry
{
    public int $timestamp;

    public function __construct(
        public string $kind,
        public string $provider,
        public string $model,
        public Usage $usage,
        public ?string $note = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
