<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/** A saved session, as a list of them needs to describe it. */
final readonly class SessionInfo
{
    public function __construct(
        public string $path,
        public string $id,
        public string $cwd,
        public int $timestamp,
        public int $messages,
        public string $opening,
        /**
         * Every word said in it, run together, for searching.
         *
         * Upstream's `allMessagesText`, and the reason the search in `--resume` is worth
         * having: what anybody remembers about a conversation three days later is something
         * from the middle of it, not how it opened. Matching the opening alone would find the
         * sessions that are easiest to recognise from the list anyway.
         *
         * Not for showing. It is the whole transcript on one line with the formatting gone.
         */
        public string $text,
    ) {
    }

    /** How long ago it was, in the words someone would use out loud. */
    public function when(?int $now = null): string
    {
        $seconds = max(0, ($now ?? time()) - intdiv($this->timestamp, 1000));

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ago',
            $seconds < 604800 => intdiv($seconds, 86400) . 'd ago',
            default => date('j M', intdiv($this->timestamp, 1000)),
        };
    }
}
