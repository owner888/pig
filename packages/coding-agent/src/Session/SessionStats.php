<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/** What a session has cost so far, for the footer and for `/session`. */
final readonly class SessionStats
{
    public function __construct(
        public int $userMessages = 0,
        public int $assistantMessages = 0,
        public int $toolCalls = 0,
        public int $toolResults = 0,
        public int $totalMessages = 0,
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public float $cost = 0.0,
    ) {
    }

    /**
     * Everything the model was billed for.
     *
     * Not `Usage::$totalTokens`, which is per request and which some providers do not
     * send at all — this is the sum over the whole conversation.
     */
    public function totalTokens(): int
    {
        return $this->input + $this->output + $this->cacheRead + $this->cacheWrite;
    }
}
