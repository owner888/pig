<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

/**
 * Something wrong with an extension file: unreadable, syntax error, did not return a callable.
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
