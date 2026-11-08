<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

/**
 * Something wrong with an extension, and enough to find it: at load (unreadable, a syntax error,
 * did not return a callable) or at run time (a handler that threw or answered the wrong type,
 * a command that failed). `$stage` is `load`, `reload`, or the event or command the fault was in.
 *
 * Not an exception: a handler throwing is a fact about one extension, not a reason to end the
 * run, so the throw is caught at the boundary and becomes one of these. The one place that is
 * not true is `tool_call`, where an extension that cannot answer must not be read as consent —
 * see `ExtensionGuardedTool`.
 */
final readonly class ExtensionError
{
    public function __construct(
        public string $path,
        public string $stage,
        public string $message,
    ) {
    }

    public function toText(): string
    {
        return "{$this->path} ({$this->stage}): {$this->message}";
    }
}
