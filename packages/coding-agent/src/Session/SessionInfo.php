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
        /**
         * The name `/name` (or the picker's rename) gave it, or null — upstream's `SessionInfo.name`.
         * `$opening` already shows it in place of the first line; this is for telling a named
         * session from an unnamed one, which the picker's `app.session.toggleNamedFilter` and its
         * colouring need.
         */
        public ?string $name = null,
        /** The file this one was forked from — the header's `parentSession`; upstream's `parentSessionPath`. */
        public ?string $parentSessionPath = null,
        /** When the file was last written to, in milliseconds — upstream's `modified`, which the threaded sort orders by. */
        public int $modified = 0,
    ) {
    }

    /** The same session under another name, for a list that has just renamed it. */
    public function withName(string $name): self
    {
        return new self($this->path, $this->id, $this->cwd, $this->timestamp, $this->messages, $name, $this->text, $name, $this->parentSessionPath, $this->modified);
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
