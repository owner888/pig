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
